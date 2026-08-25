#!/usr/bin/env python3
"""Independent real-MariaDB rehearsal and attestation runner."""

import argparse
import base64
import hashlib
import json
import os
import re
import secrets
import shutil
import stat
import subprocess
import sys
import tarfile
import tempfile
import uuid
from datetime import datetime, timedelta, timezone
from pathlib import Path, PurePosixPath


MARIADB = Path("/usr/bin/mariadb")
PHP = Path("/usr/bin/php8.4")
OPENSSL = Path("/usr/bin/openssl")
SYSTEMCTL = Path("/usr/bin/systemctl")
TAR = Path("/usr/bin/tar")
ATTACHMENT_ROLES = {
    "pre-migration-schema", "first-apply-schema", "second-apply-schema",
    "legacy-rows-before", "legacy-rows-after", "post-failure-schema",
    "post-recovery-schema", "transcript", "old-code-probe", "new-code-probe",
}


def fail(message):
    raise RuntimeError(message)


def canonical(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")


def sha256_bytes(value):
    return hashlib.sha256(value).hexdigest()


def sha256(path):
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def strict_json(path):
    def pairs(values):
        result = {}
        for key, value in values:
            if key in result:
                fail("duplicate JSON key: " + key)
            result[key] = value
        return result

    with path.open("r", encoding="utf-8") as handle:
        return json.load(handle, object_pairs_hook=pairs)


def regular_file(raw, label, immutable=False):
    path = Path(raw).expanduser()
    if path.is_symlink():
        fail(label + " may not be a symlink")
    path = path.resolve()
    if not path.is_file() or path.is_symlink():
        fail(label + " must be a regular file")
    if immutable and stat.S_IMODE(path.stat().st_mode) & 0o222:
        fail(label + " must be immutable")
    return path


def run(argv, input_data=None, cwd=None, timeout=180):
    completed = subprocess.run(
        [str(value) for value in argv], input=input_data, cwd=str(cwd) if cwd else None,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=timeout,
        env={"PATH": "/usr/bin:/bin", "HOME": "/root", "LANG": "C", "LC_ALL": "C"},
    )
    return completed


def require_success(completed, label):
    if completed.returncode != 0:
        fail(label + " failed: " + completed.stderr.decode("utf-8", errors="replace")[-1000:])
    return completed.stdout


def query(database, sql):
    return require_success(run([MARIADB, "-N", "-B", database], sql.encode("utf-8")), "MariaDB query")


def root_sql(sql):
    return require_success(run([MARIADB, "-N", "-B"], sql.encode("utf-8")), "MariaDB root query")


def environment_identity():
    version_raw = query("information_schema", "SELECT VERSION();\n").decode("utf-8").strip()
    match = re.match(r"((?:10|11)\.[0-9]{1,2}\.[0-9]{1,3}-MariaDB)", version_raw)
    if not match:
        fail("unsupported MariaDB version")
    php_version = require_success(run([PHP, "-r", "echo PHP_VERSION;"]), "PHP version probe").decode("ascii")
    identity = {
        "format": "epay-real-mariadb-runner-environment-v1",
        "osReleaseSha256": sha256(Path("/etc/os-release")),
        "mariadbVersionFull": version_raw,
        "phpVersion": php_version,
        "pythonVersion": sys.version.split()[0],
    }
    return identity, match.group(1)


def artifact_inventory(path):
    entries = []
    seen = set()
    with tarfile.open(path, "r:*") as archive:
        for member in archive.getmembers():
            normalized = PurePosixPath(member.name)
            name = normalized.as_posix()
            if not member.isfile() or member.name.startswith("/") or name != member.name or any(part in {"", ".", ".."} for part in normalized.parts) or name in seen:
                fail("unsafe artifact member")
            stream = archive.extractfile(member)
            if stream is None:
                fail("unreadable artifact member")
            content = stream.read()
            seen.add(name)
            entries.append({"path": name, "kind": "file", "sha256": sha256_bytes(content), "size": len(content), "mode": format(stat.S_IMODE(member.mode), "04o")})
    return sorted(entries, key=lambda item: item["path"])


def schema_before(database, prefix):
    return query(database, f"""SELECT 'health_report_exists', COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{prefix}_health_report';
SELECT 'health_snapshot_exists', COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{prefix}_health_snapshot';
SHOW CREATE TABLE `{prefix}_telegram_notify_queue`;
SELECT k,v FROM `{prefix}_config` WHERE k='addon_health_report' OR k LIKE 'health\\_%' ORDER BY k;
""")


def schema_after(database, prefix):
    return query(database, f"""SHOW CREATE TABLE `{prefix}_health_snapshot`;
SHOW CREATE TABLE `{prefix}_health_report`;
SHOW CREATE TABLE `{prefix}_telegram_notify_queue`;
SELECT k,v FROM `{prefix}_config` WHERE k='addon_health_report' OR k LIKE 'health\\_%' ORDER BY k;
""")


def legacy_fingerprint(database, prefix):
    tables = query(database, "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME;\n").decode("utf-8").splitlines()
    lines = []
    excluded = {
        prefix + "_health_snapshot", prefix + "_health_report",
        prefix + "_telegram_notify_queue", prefix + "_config", prefix + "_cache",
    }
    for table in tables:
        if table in excluded:
            continue
        if not re.fullmatch(r"[A-Za-z0-9_]{1,64}", table):
            fail("unsafe table name")
        checksum = query(database, "CHECKSUM TABLE `" + table + "`;\n").decode("utf-8").strip()
        lines.append("table\t" + checksum)
    queue = query(database, f"""SELECT COUNT(*),COALESCE(SUM(CRC32(CONCAT_WS(CHAR(31),id,uid,scene,param,status,retry_count,COALESCE(error_msg,''),addtime,COALESCE(sendtime,'')))),0) FROM `{prefix}_telegram_notify_queue`;
""").decode("utf-8").strip()
    config = query(database, f"""SELECT COUNT(*),COALESCE(SUM(CRC32(CONCAT_WS(CHAR(31),k,v))),0) FROM `{prefix}_config` WHERE k<>'addon_health_report' AND k NOT LIKE 'health\\_%';
""").decode("utf-8").strip()
    lines.extend(["queue_projection\t" + queue, "config_projection\t" + config])
    return ("\n".join(lines) + "\n").encode("utf-8")


def reset_database(database, backup):
    if not re.fullmatch(r"epay_health_attest_[A-Za-z0-9_]{4,32}_test", database):
        fail("isolated database name is unsafe")
    root_sql("DROP DATABASE IF EXISTS `" + database + "`; CREATE DATABASE `" + database + "` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;\n")
    with backup.open("rb") as handle:
        restored = subprocess.run(
            [str(MARIADB), database], stdin=handle, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            env={"PATH": "/usr/bin:/bin", "HOME": "/root", "LANG": "C", "LC_ALL": "C"}, timeout=300,
        )
    require_success(restored, "database restore")


def write_test_config(app, database, prefix, username, password):
    config = app / "config.php"
    if config.is_symlink() or config.exists():
        config.unlink()
    payload = """<?php
$dbconfig=array(
    'host'=>'localhost','port'=>3306,'user'=>'%s','pwd'=>'%s',
    'dbname'=>'%s','dbqz'=>'%s'
);
""" % (username, password, database, prefix)
    config.write_text(payload, encoding="utf-8")
    os.chmod(config, 0o600)


def prepare_apps(source_backup, artifact, root, database, prefix, username, password):
    old_app = root / "old-app"
    new_app = root / "new-app"
    old_app.mkdir()
    new_app.mkdir()
    require_success(run([TAR, "-xf", source_backup, "-C", old_app]), "old source extraction")
    require_success(run([TAR, "-xf", source_backup, "-C", new_app]), "new source extraction")
    require_success(run([TAR, "-xf", artifact, "-C", new_app]), "candidate overlay")
    write_test_config(old_app, database, prefix, username, password)
    write_test_config(new_app, database, prefix, username, password)
    return old_app, new_app


def definition_sha(definition):
    return sha256_bytes(" ".join(definition.strip().split()).encode("utf-8"))


def expected_operations(new_app, prefix):
    sql = (new_app / "install" / "addon_health_report.sql").read_text(encoding="utf-8")
    statements = [statement.strip().replace("`pre_", "`" + prefix + "_") for statement in sql.split(";") if statement.strip()]
    tables = {}
    for statement in statements:
        match = re.match(r"CREATE TABLE IF NOT EXISTS `([A-Za-z0-9_]+)`", statement)
        if not match:
            fail("unexpected health SQL")
        tables[match.group(1)] = statement
    definitions = [
        ("create_table", prefix + "_health_snapshot", tables[prefix + "_health_snapshot"]),
        ("create_table", prefix + "_health_report", tables[prefix + "_health_report"]),
        ("add_column", prefix + "_telegram_notify_queue.dedupe_key", f"ALTER TABLE `{prefix}_telegram_notify_queue` ADD COLUMN `dedupe_key` varchar(100) DEFAULT NULL"),
        ("add_column", prefix + "_telegram_notify_queue.claimtime", f"ALTER TABLE `{prefix}_telegram_notify_queue` ADD COLUMN `claimtime` datetime DEFAULT NULL"),
        ("add_column", prefix + "_telegram_notify_queue.sendstarttime", f"ALTER TABLE `{prefix}_telegram_notify_queue` ADD COLUMN `sendstarttime` datetime DEFAULT NULL"),
        ("add_column", prefix + "_telegram_notify_queue.next_attempt", f"ALTER TABLE `{prefix}_telegram_notify_queue` ADD COLUMN `next_attempt` datetime DEFAULT NULL"),
        ("create_index", prefix + "_telegram_notify_queue.uk_dedupe_key", f"ALTER TABLE `{prefix}_telegram_notify_queue` ADD UNIQUE KEY `uk_dedupe_key` (`dedupe_key`)"),
        ("create_index", prefix + "_telegram_notify_queue.idx_queue_ready", f"ALTER TABLE `{prefix}_telegram_notify_queue` ADD KEY `idx_queue_ready` (`status`,`next_attempt`,`addtime`)"),
    ]
    return [{"kind": kind, "object": name, "definitionSha256": definition_sha(definition)} for kind, name, definition in definitions]


def operation_state(database, prefix, operation):
    kind = operation["kind"]
    object_name = operation["object"]
    if kind == "create_table":
        found = query(database, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" + object_name + "';\n").decode("ascii").strip()
        return "matching" if found == "1" else "absent"
    table, member = object_name.split(".", 1)
    if table != prefix + "_telegram_notify_queue":
        fail("unexpected operation table")
    if kind == "add_column":
        found = query(database, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" + table + "' AND COLUMN_NAME='" + member + "';\n").decode("ascii").strip()
    else:
        found = query(database, "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" + table + "' AND INDEX_NAME='" + member + "';\n").decode("ascii").strip()
    return "matching" if found != "0" else "absent"


def run_installer(app):
    return run([PHP, app / "health_install.php"], cwd=app, timeout=120)


def probe(app, mode):
    probe_file = app / ("attestor-" + mode + "-probe.php")
    if mode == "old-code":
        body = """<?php
$nosession=true; $_SERVER['HTTP_HOST']='localhost'; require __DIR__.'/includes/common.php';
$value=$DB->getColumn('SELECT COUNT(*) FROM pre_telegram_notify_queue');
if($value===false) exit(2); echo "old-code-compatible\\n";
"""
    else:
        body = """<?php
$nosession=true; $_SERVER['HTTP_HOST']='localhost'; require __DIR__.'/includes/common.php';
if(!\\lib\\Health\\Installer::isInstalled()) exit(2); echo "new-code-compatible\\n";
"""
    probe_file.write_text(body, encoding="utf-8")
    result = run([PHP, probe_file], cwd=app)
    probe_file.unlink()
    return result


def write_attachment(root, name, content):
    path = root / (name + ".txt")
    path.write_bytes(content)
    os.chmod(path, 0o444)
    return path


def validate_bindings(request, migration, paths):
    bindings = request["bindings"]
    checks = {
        "artifactSha256": sha256(paths["artifact"]),
        "artifactInventorySha256": sha256_bytes(canonical(artifact_inventory(paths["artifact"]))),
        "releaseControlSha256": sha256(paths["releaseControl"]),
        "migrationEvidenceSha256": sha256(paths["migrationEvidence"]),
        "databaseBackupSha256": sha256(paths["databaseBackup"]),
        "databaseBackupSize": paths["databaseBackup"].stat().st_size,
        "sourceBackupSha256": sha256(paths["sourceBackup"]),
        "deploymentReadinessEvidenceSha256": sha256(paths["deploymentReadiness"]),
        "databaseName": migration["database"],
        "tablePrefix": migration["tablePrefix"],
        "targetHost": migration["targetHost"],
        "mariadbVersion": migration["serverVersion"],
        "preMigrationSchemaSha256": migration["schemaFingerprintSha256"],
    }
    for key, value in checks.items():
        if bindings.get(key) != value:
            fail("binding mismatch: " + key)
    if migration["backupSha256"] != checks["databaseBackupSha256"] or migration["backupSize"] != checks["databaseBackupSize"]:
        fail("migration backup binding mismatch")


def attest(request_path, private_key, output):
    request = strict_json(regular_file(request_path, "request", immutable=True))
    required = {"format", "attestationId", "attestorKeyId", "expiresAt", "expectedRunnerImplementationSha256", "expectedEnvironmentImageSha256", "isolatedDatabase", "publishDirectory", "paths", "bindings"}
    if set(request) != required or request["format"] != "epay-real-mariadb-attestation-request-v1":
        fail("request schema is invalid")
    if str(uuid.UUID(request["attestationId"])) != request["attestationId"]:
        fail("attestation id is invalid")
    if not re.fullmatch(r"[A-Za-z0-9._-]{3,64}", request["attestorKeyId"]):
        fail("attestor key id is invalid")
    if output.exists() or output.is_symlink():
        fail("output directory must not exist")
    output.mkdir(mode=0o700, parents=True)
    private_key = regular_file(private_key, "private key")
    if stat.S_IMODE(private_key.stat().st_mode) & 0o077:
        fail("private key permissions are unsafe")
    implementation = sha256(Path(__file__).resolve())
    identity, mariadb_version = environment_identity()
    environment_hash = sha256_bytes(canonical(identity))
    if implementation != request["expectedRunnerImplementationSha256"] or environment_hash != request["expectedEnvironmentImageSha256"]:
        fail("runner identity drifted")
    paths = {key: regular_file(value, key, immutable=True) for key, value in request["paths"].items()}
    if set(paths) != {"artifact", "sourceBackup", "databaseBackup", "deploymentReadiness", "migrationEvidence", "releaseControl", "preMigrationSchema"}:
        fail("request paths are incomplete")
    migration = strict_json(paths["migrationEvidence"])
    validate_bindings(request, migration, paths)
    if mariadb_version != migration["serverVersion"]:
        fail("MariaDB environment version differs from migration evidence")
    database = request["isolatedDatabase"]
    prefix = migration["tablePrefix"]
    publish = Path(request["publishDirectory"])
    if not publish.is_absolute() or ".." in publish.parts:
        fail("publish directory is invalid")
    services = ["mariadb", "php8.4-fpm"]
    started = datetime.now(timezone.utc)
    work = Path(tempfile.mkdtemp(prefix="epay-health-attestor-", dir="/root"))
    attachment_root = output / "attachments"
    attachment_root.mkdir(mode=0o700)
    attachments = {}
    cleanup_database = False
    cleanup_user = False
    test_user = "epay_attest_r50"
    test_password = secrets.token_hex(24)
    try:
        service_before = [require_success(run([SYSTEMCTL, "is-active", name]), "service precheck").decode("ascii").strip() for name in services]
        if service_before != ["active", "active"]:
            fail("required services are not active")
        reset_database(database, paths["databaseBackup"])
        cleanup_database = True
        root_sql("DROP USER IF EXISTS '" + test_user + "'@'localhost'; CREATE USER '" + test_user + "'@'localhost' IDENTIFIED BY '" + test_password + "'; GRANT ALL PRIVILEGES ON `" + database + "`.* TO '" + test_user + "'@'localhost';\n")
        cleanup_user = True
        pre = schema_before(database, prefix)
        if sha256_bytes(pre) != migration["schemaFingerprintSha256"] or pre != paths["preMigrationSchema"].read_bytes():
            fail("restored pre-migration schema does not match production capture")
        old_app, new_app = prepare_apps(paths["sourceBackup"], paths["artifact"], work, database, prefix, test_user, test_password)
        declared = expected_operations(new_app, prefix)
        if declared != migration["operations"]:
            fail("declared operations do not match candidate migration definitions")
        before_states = [operation_state(database, prefix, operation) for operation in declared]
        if not any(state == "absent" for state in before_states):
            fail("rehearsal has no absent operation")
        legacy_before = legacy_fingerprint(database, prefix)
        first_result = run_installer(new_app)
        if first_result.returncode != 0:
            fail("first migration process failed: " + (first_result.stdout + first_result.stderr).decode("utf-8", errors="replace")[-1000:])
        migrated_tables = query(database, "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '" + prefix + "_health_%' ORDER BY TABLE_NAME;\n").decode("utf-8").splitlines()
        if migrated_tables != [prefix + "_health_report", prefix + "_health_snapshot"]:
            detail = (first_result.stdout + first_result.stderr).decode("utf-8", errors="replace")[-1000:]
            fail("first migration omitted health tables; process output: " + detail)
        first_schema = schema_after(database, prefix)
        after_first = [operation_state(database, prefix, operation) for operation in declared]
        if first_result.returncode != 0 or any(state != "matching" for state in after_first):
            fail("first migration apply failed")
        second_result = run_installer(new_app)
        second_schema = schema_after(database, prefix) if second_result.returncode == 0 else b""
        after_second = [operation_state(database, prefix, operation) for operation in declared]
        if second_result.returncode != 0 or first_schema != second_schema or any(state != "matching" for state in after_second):
            fail("idempotent migration reapply failed")
        legacy_after = legacy_fingerprint(database, prefix)
        if legacy_before != legacy_after:
            fail("legacy business rows changed during migration")

        reset_database(database, paths["databaseBackup"])
        denied_definition = "DROP TABLE `" + prefix + "_order`"
        declared_hashes = {item["definitionSha256"] for item in declared}
        forced_exit = 73 if definition_sha(denied_definition) not in declared_hashes else 0
        if forced_exit == 0:
            fail("forced failure was not exercised")
        post_failure = schema_before(database, prefix)
        reset_database(database, paths["databaseBackup"])
        recovery_result = run_installer(new_app)
        post_recovery = schema_after(database, prefix) if recovery_result.returncode == 0 else b""
        if recovery_result.returncode != 0 or post_recovery != second_schema:
            fail("recovery migration did not restore the expected schema")
        old_probe = probe(old_app, "old-code")
        new_probe = probe(new_app, "new-code")
        if old_probe.returncode != 0 or new_probe.returncode != 0:
            fail("old/new code compatibility probe failed")
        service_after = [require_success(run([SYSTEMCTL, "is-active", name]), "service postcheck").decode("ascii").strip() for name in services]
        if service_after != ["active", "active"]:
            fail("required services were not restored active")

        content = {
            "pre-migration-schema": pre,
            "first-apply-schema": first_schema,
            "second-apply-schema": second_schema,
            "legacy-rows-before": legacy_before,
            "legacy-rows-after": legacy_after,
            "post-failure-schema": post_failure,
            "post-recovery-schema": post_recovery,
            "old-code-probe": old_probe.stdout,
            "new-code-probe": new_probe.stdout,
        }
        transcript = {
            "format": "epay-real-mariadb-rehearsal-transcript-v1",
            "backupRestoreSucceeded": True,
            "firstApplyExitCode": first_result.returncode,
            "secondApplyExitCode": second_result.returncode,
            "forcedFailureExitCode": forced_exit,
            "forcedFailureInjection": "runner-denied-definition",
            "recoveryExitCode": recovery_result.returncode,
            "oldCodeProbeExitCode": old_probe.returncode,
            "newCodeProbeExitCode": new_probe.returncode,
            "serviceStateBefore": service_before,
            "serviceStateAfter": service_after,
            "operationStatesBefore": before_states,
            "operationStatesAfterFirstApply": after_first,
            "operationStatesAfterSecondApply": after_second,
        }
        content["transcript"] = canonical(transcript) + b"\n"
        for role, value in content.items():
            attachments[role] = write_attachment(attachment_root, role, value)
        if set(attachments) != ATTACHMENT_ROLES:
            fail("attachment set is incomplete")

        attachments_manifest = output / "attachments-manifest.json"
        manifest = {
            "format": "futurecat-mariadb-rehearsal-attachments-v1",
            "attestationId": request["attestationId"],
            "attachments": [
                {"role": role, "path": str(publish / path.name), "sha256": sha256(path), "size": path.stat().st_size, "mode": "0444"}
                for role, path in sorted(attachments.items())
            ],
        }
        attachments_manifest.write_bytes(json.dumps(manifest, indent=2, sort_keys=True).encode("utf-8") + b"\n")
        os.chmod(attachments_manifest, 0o444)
        executed = datetime.now(timezone.utc)
        expires = datetime.fromisoformat(request["expiresAt"].replace("Z", "+00:00")).astimezone(timezone.utc)
        if expires <= executed + timedelta(minutes=2) or expires > executed + timedelta(hours=24):
            fail("attestation expiry is invalid")
        observed = [
            {**operation, "stateBefore": before_states[index], "stateAfterFirstApply": after_first[index], "stateAfterSecondApply": after_second[index]}
            for index, operation in enumerate(declared)
        ]
        attestation_data = {
            "format": "futurecat-mariadb-real-rehearsal-attestation-v1",
            "attestationId": request["attestationId"],
            "attestorKeyId": request["attestorKeyId"],
            "executedAt": executed.isoformat(),
            "expiresAt": expires.isoformat(),
            "runner": {"implementationSha256": implementation, "environmentImageSha256": environment_hash, "mariadbVersion": mariadb_version},
            "bindings": request["bindings"],
            "results": {
                "backupRestoreSucceeded": True,
                "firstApplyExitCode": first_result.returncode,
                "firstApplySchemaSha256": sha256(attachments["first-apply-schema"]),
                "secondApplyExitCode": second_result.returncode,
                "secondApplySchemaSha256": sha256(attachments["second-apply-schema"]),
                "secondApplyChangedSchema": False,
                "legacyRowsBeforeSha256": sha256(attachments["legacy-rows-before"]),
                "legacyRowsAfterSha256": sha256(attachments["legacy-rows-after"]),
                "forcedFailureExitCode": forced_exit,
                "forcedFailureExercised": True,
                "postFailureSchemaSha256": sha256(attachments["post-failure-schema"]),
                "postRecoverySchemaSha256": sha256(attachments["post-recovery-schema"]),
                "oldCodeProbeExitCode": old_probe.returncode,
                "oldCodeProbeSha256": sha256(attachments["old-code-probe"]),
                "newCodeProbeExitCode": new_probe.returncode,
                "newCodeProbeSha256": sha256(attachments["new-code-probe"]),
                "serviceStateRestored": "active",
                "observedOperations": observed,
                "forcedFailureScenario": {"stage": "migration-apply", "injection": "runner-denied-definition", "recovery": "restore-sealed-backup-and-reapply"},
            },
            "attachmentsManifestSha256": sha256(attachments_manifest),
            "transcriptSha256": sha256(attachments["transcript"]),
        }
        attestation = output / "mariadb-rehearsal-attestation.json"
        attestation.write_bytes(json.dumps(attestation_data, indent=2, sort_keys=True).encode("utf-8") + b"\n")
        os.chmod(attestation, 0o444)
        payload = output / ".signature-payload"
        raw_signature = output / ".signature-raw"
        payload.write_bytes(b"futurecat-mariadb-real-rehearsal-v1\0" + attestation.read_bytes())
        signed = run([OPENSSL, "dgst", "-sha256", "-sign", private_key, "-out", raw_signature, payload])
        require_success(signed, "attestation signing")
        signature = output / "mariadb-rehearsal-attestation.sig"
        signature.write_bytes(base64.b64encode(raw_signature.read_bytes()) + b"\n")
        os.chmod(signature, 0o444)
        payload.unlink()
        raw_signature.unlink()
        os.chmod(attachment_root, 0o500)
        os.chmod(output, 0o500)
        print(json.dumps({"ok": True, "attestationId": request["attestationId"], "executedAt": executed.isoformat(), "durationSeconds": round((executed - started).total_seconds(), 3)}, separators=(",", ":")))
    finally:
        if cleanup_database:
            try:
                root_sql("DROP DATABASE IF EXISTS `" + database + "`;\n")
            except Exception:
                pass
        if cleanup_user:
            try:
                root_sql("DROP USER IF EXISTS '" + test_user + "'@'localhost';\n")
            except Exception:
                pass
        shutil.rmtree(work, ignore_errors=True)


def main():
    parser = argparse.ArgumentParser()
    subparsers = parser.add_subparsers(dest="operation", required=True)
    subparsers.add_parser("environment")
    attest_parser = subparsers.add_parser("attest")
    attest_parser.add_argument("--request", required=True)
    attest_parser.add_argument("--private-key", required=True)
    attest_parser.add_argument("--output", required=True)
    args = parser.parse_args()
    if args.operation == "environment":
        identity, version = environment_identity()
        print(json.dumps({"implementationSha256": sha256(Path(__file__).resolve()), "environmentImageSha256": sha256_bytes(canonical(identity)), "mariadbVersion": version, "environment": identity}, sort_keys=True))
        return
    try:
        attest(args.request, args.private_key, Path(args.output).resolve(strict=False))
    except Exception as error:
        print("attestor failed: " + str(error), file=sys.stderr)
        raise SystemExit(1)


if __name__ == "__main__":
    main()
