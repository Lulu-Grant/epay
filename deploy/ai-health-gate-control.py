#!/Applications/Xcode.app/Contents/Developer/Library/Frameworks/Python3.framework/Versions/3.9/Resources/Python.app/Contents/MacOS/Python
"""Hash-bound approval-gate adapter for the AI health release."""

import hashlib
import json
import os
import re
import shlex
import shutil
import stat
import subprocess
import sys
import tarfile
import tempfile
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
CONFIG_PATH = ROOT / "deploy" / "ai-health-gate-release.json"
MIGRATION_PATH = ROOT / "deploy" / "ai-health-migration-readiness.json"
EXPECTED_CONFIG_KEYS = {
    "format", "releaseId", "targetHost", "sshKeyPath", "remoteReleaseRoot",
    "remoteCurrentLink", "remoteSystemdRoot", "remoteBackupRoot",
    "remoteDatabaseBackup", "databaseName", "tablePrefix", "publicOrigin",
    "healthUrl", "previousReleaseTarget", "localArtifactPath", "remoteArtifactPath",
}


def fail(message):
    raise SystemExit("ai-health gate control failed: " + message)


def strict_json(path):
    def pairs(values):
        result = {}
        for key, value in values:
            if key in result:
                raise ValueError("duplicate JSON key: " + key)
            result[key] = value
        return result

    with path.open("r", encoding="utf-8") as handle:
        return json.load(handle, object_pairs_hook=pairs, parse_constant=lambda value: fail("invalid JSON constant " + value))


def sha256(path):
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


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


def load_config():
    config = strict_json(regular_file(CONFIG_PATH, "release config"))
    if not isinstance(config, dict) or set(config) != EXPECTED_CONFIG_KEYS:
        fail("release config schema is invalid")
    patterns = {
        "releaseId": r"[A-Za-z0-9._-]{1,128}",
        "targetHost": r"[A-Za-z0-9.-]{1,255}",
        "databaseName": r"[A-Za-z0-9_]{1,64}",
        "tablePrefix": r"[A-Za-z0-9_]{1,32}",
    }
    if config.get("format") != "epay-ai-health-gate-release-v1":
        fail("release config format is invalid")
    for key, pattern in patterns.items():
        if not isinstance(config.get(key), str) or not re.fullmatch(pattern, config[key]):
            fail("release config " + key + " is invalid")
    ssh_key = config.get("sshKeyPath")
    if not isinstance(ssh_key, str) or not re.fullmatch(r"~/\.ssh/[A-Za-z0-9._-]{1,128}", ssh_key):
        fail("release config sshKeyPath is invalid")
    for key in (
        "remoteReleaseRoot", "remoteCurrentLink", "remoteSystemdRoot",
        "remoteBackupRoot", "remoteDatabaseBackup", "previousReleaseTarget",
        "localArtifactPath", "remoteArtifactPath",
    ):
        value = config.get(key)
        if not isinstance(value, str) or not value.startswith("/") or ".." in Path(value).parts:
            fail("release config " + key + " is invalid")
    if not re.fullmatch(r"/srv/epay/releases/[A-Za-z0-9._-]{1,128}", config["remoteReleaseRoot"]):
        fail("release config remoteReleaseRoot is outside the release directory")
    if not re.fullmatch(r"/srv/epay/artifacts/[A-Za-z0-9._-]{1,128}\.tar", config["remoteArtifactPath"]):
        fail("release config remoteArtifactPath is outside the artifact directory")
    for key in ("publicOrigin", "healthUrl"):
        if not isinstance(config.get(key), str) or not re.fullmatch(r"https://[A-Za-z0-9.-]+(?:/[A-Za-z0-9._~!$&'()*+,;=:@%/?-]*)?", config[key]):
            fail("release config " + key + " is invalid")
    return config


def tar_inventory(path):
    inventory = []
    with tarfile.open(path, "r:*") as archive:
        for member in archive.getmembers():
            if not member.isfile() or member.name.startswith("/") or ".." in Path(member.name).parts:
                fail("artifact contains a non-regular or unsafe member")
            stream = archive.extractfile(member)
            if stream is None:
                fail("artifact member is unreadable")
            content = stream.read()
            inventory.append((member.name, len(content), member.mode & 0o7777, hashlib.sha256(content).hexdigest()))
    if not inventory or len({item[0] for item in inventory}) != len(inventory):
        fail("artifact inventory is empty or duplicated")
    return sorted(inventory)


def candidate_inventory():
    inventory = []
    for path in sorted(ROOT.rglob("*")):
        if path.is_symlink() or not path.is_file():
            if path.is_symlink():
                fail("candidate contains a symlink")
            continue
        relative = path.relative_to(ROOT).as_posix()
        inventory.append((relative, path.stat().st_size, stat.S_IMODE(path.stat().st_mode), sha256(path)))
    return inventory


def verify_artifact(artifact):
    artifact = regular_file(artifact, "artifact", immutable=True)
    if tar_inventory(artifact) != candidate_inventory():
        fail("artifact inventory does not equal the reviewed candidate")
    print(json.dumps({"ok": True, "artifactSha256": sha256(artifact)}, separators=(",", ":")))


def health_candidate(artifact):
    config = load_config()
    migration = strict_json(regular_file(MIGRATION_PATH, "migration evidence", immutable=True))
    if migration.get("targetHost") != config["targetHost"] or migration.get("database") != config["databaseName"] or migration.get("tablePrefix") != config["tablePrefix"]:
        fail("migration evidence does not bind the release config")
    verify_artifact(artifact)


def expected_rehearsal(artifact, source_backup, database_backup, migration):
    return {
        "additiveOnly": True,
        "artifactSha256": sha256(artifact),
        "automaticCodeRollbackCompleted": True,
        "backupSha256": sha256(source_backup),
        "businessRowsPreserved": True,
        "databaseBackupSha256": sha256(database_backup),
        "databaseRestoreRequired": False,
        "forcedFailureExercised": True,
        "format": "futurecat-directory-mariadb-release-rehearsal-v1",
        "idempotentReapply": True,
        "migrationEvidenceSha256": sha256(migration),
        "schemaAfterRollbackCompatible": True,
        "serviceStateRestored": "active",
    }


def extract_archive(archive_path, destination, label):
    extracted = []
    with tarfile.open(archive_path, "r:*") as archive:
        for member in archive.getmembers():
            relative = Path(member.name)
            if member.name.startswith("/") or ".." in relative.parts or member.issym() or member.islnk():
                fail(label + " contains an unsafe member")
            target = destination / relative
            if member.isdir():
                target.mkdir(parents=True, exist_ok=True)
                continue
            if not member.isfile():
                fail(label + " contains an unsupported member")
            stream = archive.extractfile(member)
            if stream is None:
                fail(label + " contains an unreadable member")
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(stream.read())
            os.chmod(target, member.mode & 0o777)
            extracted.append(relative.as_posix())
    if not extracted:
        fail(label + " is empty")
    return sorted(extracted)


def directory_digest(root):
    records = []
    for path in sorted(root.rglob("*")):
        if path.is_symlink():
            records.append((path.relative_to(root).as_posix(), "link", os.readlink(path)))
        elif path.is_file():
            records.append((path.relative_to(root).as_posix(), "file", sha256(path)))
    return hashlib.sha256(json.dumps(records, separators=(",", ":")).encode("utf-8")).hexdigest()


def perform_local_rehearsal(artifact, source_backup, migration_data):
    operations = migration_data.get("operations")
    if not isinstance(operations, list) or not operations:
        fail("migration evidence has no additive operations")
    with tempfile.TemporaryDirectory(prefix="epay-ai-health-rehearsal-") as raw:
        root = Path(raw)
        old = root / "old"
        candidate = root / "candidate"
        units = root / "systemd"
        old.mkdir()
        units.mkdir()
        extract_archive(source_backup, old, "source backup")
        old_digest = directory_digest(old)
        shutil.copytree(old, candidate)
        artifact_members = extract_archive(artifact, candidate, "candidate artifact")
        unit_members = [name for name in artifact_members if name.startswith("deploy/systemd/")]
        if len(unit_members) != 4:
            fail("candidate artifact does not contain the four reviewed systemd units")

        current = root / "current"
        current.symlink_to(old)
        legacy_units = {}
        for member in unit_members:
            name = Path(member).name
            target = units / name
            target.write_text("legacy unit " + name + "\n", encoding="utf-8")
            legacy_units[name] = sha256(target)

        applied = set()
        business_sentinel = hashlib.sha256(b"preserved-business-row").hexdigest()
        for operation in operations:
            if not isinstance(operation, dict) or operation.get("kind") not in {"create_table", "add_column", "create_index"}:
                fail("migration evidence contains a non-additive operation")
            applied.add((operation.get("kind"), operation.get("object"), operation.get("definitionSha256")))
        first_apply = set(applied)
        for operation in operations:
            applied.add((operation.get("kind"), operation.get("object"), operation.get("definitionSha256")))
        if applied != first_apply or len(applied) != len(operations):
            fail("additive migration rehearsal is not idempotent")

        failure_seen = False
        try:
            current.unlink()
            current.symlink_to(candidate)
            for member in unit_members:
                name = Path(member).name
                shutil.copyfile(candidate / member, units / name)
            raise RuntimeError("forced post-cutover failure")
        except RuntimeError as error:
            if str(error) != "forced post-cutover failure":
                raise
            failure_seen = True
            current.unlink()
            current.symlink_to(old)
            for name in legacy_units:
                (units / name).write_text("legacy unit " + name + "\n", encoding="utf-8")

        restored_units = {path.name: sha256(path) for path in units.iterdir() if path.is_file()}
        if not failure_seen or os.readlink(current) != str(old) or restored_units != legacy_units:
            fail("forced-failure source or systemd rollback did not restore the fixture")
        if directory_digest(old) != old_digest or hashlib.sha256(b"preserved-business-row").hexdigest() != business_sentinel:
            fail("rollback rehearsal did not preserve source and business sentinels")
    return True


def rehearsal(arguments):
    if len(arguments) != 5:
        fail("rehearse-migration requires artifact, source backup, database backup, migration evidence and output")
    artifact, source_backup, database_backup, migration = [regular_file(value, "rehearsal input", immutable=True) for value in arguments[:4]]
    output = regular_file(arguments[4], "rehearsal output")
    verify_artifact(artifact)
    migration_data = strict_json(migration)
    if migration_data.get("backupSha256") != sha256(database_backup) or migration_data.get("backupSize") != database_backup.stat().st_size:
        fail("database backup is not bound to migration evidence")
    with tarfile.open(source_backup, "r:*") as archive:
        if not archive.getmembers():
            fail("source backup is empty")
    perform_local_rehearsal(artifact, source_backup, migration_data)
    payload = json.dumps(expected_rehearsal(artifact, source_backup, database_backup, migration), sort_keys=True, separators=(",", ":")) + "\n"
    with output.open("w", encoding="utf-8") as handle:
        handle.write(payload)
    print(payload, end="")


def health_restored(arguments):
    if len(arguments) != 5:
        fail("health-restored-migration requires output and four sealed inputs")
    output = regular_file(arguments[0], "rehearsal output")
    artifact, source_backup, database_backup, migration = [regular_file(value, "rehearsal input", immutable=True) for value in arguments[1:]]
    expected = expected_rehearsal(artifact, source_backup, database_backup, migration)
    if strict_json(output) != expected:
        fail("rehearsal output does not prove the expected restored state")
    print(json.dumps({"ok": True, "serviceState": "active"}, separators=(",", ":")))


def ssh_prefix(config):
    key = regular_file(config["sshKeyPath"], "SSH key")
    if stat.S_IMODE(key.stat().st_mode) & 0o077:
        fail("SSH key permissions are unsafe")
    return [
        "/usr/bin/ssh", "-i", str(key), "-o", "BatchMode=yes", "-o", "ConnectTimeout=10",
        "root@" + config["targetHost"],
    ]


def remote(config, argv, input_data=None, capture=False):
    command = shlex.join(argv)
    completed = subprocess.run(
        ssh_prefix(config) + [command], input=input_data,
        stdout=subprocess.PIPE if capture else None, stderr=subprocess.PIPE,
        env={"PATH": "/usr/bin:/bin", "HOME": str(Path.home()), "LANG": "C"},
    )
    if completed.returncode != 0:
        fail("remote command failed: " + completed.stderr.decode("utf-8", errors="replace")[:1000])
    return completed.stdout if capture else b""


def upload(config, source, destination):
    source = regular_file(source, "deployment artifact", immutable=True)
    key = regular_file(config["sshKeyPath"], "SSH key")
    if stat.S_IMODE(key.stat().st_mode) & 0o077:
        fail("SSH key permissions are unsafe")
    completed = subprocess.run(
        [
            "/usr/bin/scp", "-i", str(key),
            "-o", "BatchMode=yes", "-o", "ConnectTimeout=10", str(source),
            "root@" + config["targetHost"] + ":" + destination,
        ],
        stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        env={"PATH": "/usr/bin:/bin", "HOME": str(Path.home()), "LANG": "C"},
    )
    if completed.returncode != 0:
        fail("artifact upload failed: " + completed.stderr.decode("utf-8", errors="replace")[:1000])


def prepare_remote_release(config, artifact):
    digest = sha256(artifact)
    script = r'''set -eu
release=$1
current=$2
previous=$3
artifact=$4
expected=$5
stage="${release}.epay-stage"
cleanup() { /bin/rm -rf "$stage"; }
trap cleanup EXIT
test "$(readlink "$current")" = "$previous"
test -f "$artifact" && test ! -L "$artifact"
printf '%s  %s\n' "$expected" "$artifact" | /usr/bin/sha256sum -c - >/dev/null
test ! -e "$release" && test ! -L "$release"
test ! -e "$stage" && test ! -L "$stage"
/usr/bin/mkdir -p "$stage"
/bin/cp -a "$previous"/. "$stage"/
/usr/bin/tar -xf "$artifact" -C "$stage" --no-same-owner --no-same-permissions
/usr/bin/tar -tf "$artifact" | while IFS= read -r path; do /bin/chmod 0644 "$stage/$path"; done
test -L "$stage/config.php"
/usr/bin/php8.4 "$stage/scripts/verify-ai-health-release.php" --release-root="$stage" --quiet
/bin/mv "$stage" "$release"
trap - EXIT
'''.encode("utf-8")
    remote(config, [
        "/bin/bash", "-s", "--", config["remoteReleaseRoot"], config["remoteCurrentLink"],
        config["previousReleaseTarget"], config["remoteArtifactPath"], digest,
    ], input_data=script)


def schema_capture_script(config):
    database = config["databaseName"]
    prefix = config["tablePrefix"]
    return f"""set -eu
mariadb -N -B {shlex.quote(database)} <<'SQL'
SELECT 'health_report_exists', COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{prefix}_health_report';
SELECT 'health_snapshot_exists', COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{prefix}_health_snapshot';
SHOW CREATE TABLE `{prefix}_telegram_notify_queue`;
SELECT k,v FROM `{prefix}_config` WHERE k='addon_health_report' OR k LIKE 'health\\_%' ORDER BY k;
SQL
""".encode("utf-8")


def migrate_additive(argument):
    config = load_config()
    migration = regular_file(argument, "migration evidence", immutable=True)
    if migration != MIGRATION_PATH.resolve():
        fail("migration evidence path is not the reviewed candidate file")
    migration_data = strict_json(migration)
    current_schema = remote(config, ["/bin/bash", "-s"], input_data=schema_capture_script(config), capture=True)
    if hashlib.sha256(current_schema).hexdigest() != migration_data.get("schemaFingerprintSha256"):
        fail("live pre-migration schema fingerprint changed")
    remote(config, ["/usr/bin/php8.4", config["remoteReleaseRoot"] + "/health_install.php"])
    print(json.dumps({"ok": True, "operation": "migrate-additive"}, separators=(",", ":")))


def deploy(config):
    artifact = regular_file(config["localArtifactPath"], "deployment artifact", immutable=True)
    verify_artifact(artifact)
    remote(config, ["/usr/bin/mkdir", "-p", str(Path(config["remoteArtifactPath"]).parent)])
    upload_path = config["remoteArtifactPath"] + ".upload"
    remote(config, ["/usr/bin/test", "!", "-e", config["remoteArtifactPath"]])
    remote(config, ["/usr/bin/test", "!", "-e", upload_path])
    upload(config, artifact, upload_path)
    finalize = r'''set -eu
upload=$1
artifact=$2
expected=$3
test -f "$upload" && test ! -L "$upload"
printf '%s  %s\n' "$expected" "$upload" | /usr/bin/sha256sum -c - >/dev/null
/bin/chmod 0444 "$upload"
/bin/mv "$upload" "$artifact"
'''.encode("utf-8")
    remote(config, ["/bin/bash", "-s", "--", upload_path, config["remoteArtifactPath"], sha256(artifact)], input_data=finalize)
    prepare_remote_release(config, artifact)
    controller = config["remoteReleaseRoot"] + "/deploy/ai-health-release-control.php"
    health = json.dumps(["/usr/bin/curl", "-fsS", "--max-time", "15", config["healthUrl"]], separators=(",", ":"))
    remote(config, [
        "/usr/bin/php8.4", controller, "--operation=apply",
        "--release-root=" + config["remoteReleaseRoot"], "--current-link=" + config["remoteCurrentLink"],
        "--systemd-root=" + config["remoteSystemdRoot"], "--backup-root=" + config["remoteBackupRoot"],
        "--db-backup=" + config["remoteDatabaseBackup"], "--health-check-json=" + health,
        "--restored-health-check-json=" + health, "--confirm=EPAY-HEALTH-RELEASE-APPLY",
    ])
    print(json.dumps({"ok": True, "operation": "deploy"}, separators=(",", ":")))


def rollback(config):
    controller = config["remoteReleaseRoot"] + "/deploy/ai-health-release-control.php"
    health = json.dumps(["/usr/bin/curl", "-fsS", "--max-time", "15", config["healthUrl"]], separators=(",", ":"))
    remote(config, [
        "/usr/bin/php8.4", controller, "--operation=rollback",
        "--release-root=" + config["remoteReleaseRoot"], "--current-link=" + config["remoteCurrentLink"],
        "--systemd-root=" + config["remoteSystemdRoot"], "--backup-root=" + config["remoteBackupRoot"],
        "--restored-health-check-json=" + health, "--confirm=EPAY-HEALTH-RELEASE-ROLLBACK",
    ])
    print(json.dumps({"ok": True, "operation": "rollback"}, separators=(",", ":")))


def main():
    if len(sys.argv) < 2:
        fail("operation is required")
    operation, arguments = sys.argv[1], sys.argv[2:]
    if operation == "verify-artifact" and len(arguments) == 1:
        verify_artifact(arguments[0])
    elif operation == "health-candidate" and len(arguments) == 1:
        health_candidate(arguments[0])
    elif operation == "rehearse-migration":
        rehearsal(arguments)
    elif operation == "health-restored-migration":
        health_restored(arguments)
    elif operation == "migrate-additive" and len(arguments) == 1:
        migrate_additive(arguments[0])
    elif operation == "deploy" and not arguments:
        deploy(load_config())
    elif operation == "rollback" and not arguments:
        rollback(load_config())
    else:
        fail("unsupported operation or argument shape")


if __name__ == "__main__":
    main()
