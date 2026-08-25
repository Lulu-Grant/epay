#!/usr/bin/env python3
"""Build a minimal immutable source candidate for the formal release gate."""

import argparse
import hashlib
import json
import os
import re
import shutil
import stat
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent


def sha256(path):
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def parse_mapping():
    records = []
    for line_number, line in enumerate((ROOT / "deploy" / "ai-health-release-files.txt").read_text(encoding="utf-8").splitlines(), 1):
        if not line.strip() or line.lstrip().startswith("#"):
            continue
        parts = line.split("\t")
        if len(parts) != 2:
            raise SystemExit("invalid release mapping line " + str(line_number))
        source = Path(parts[0])
        if source.is_absolute() or ".." in source.parts:
            raise SystemExit("unsafe release mapping source")
        records.append(source)
    if len(records) != 39 or len(set(records)) != 39:
        raise SystemExit("unexpected release mapping shape")
    return records


def copy_file(source, destination):
    if source.is_symlink() or not source.is_file():
        raise SystemExit("candidate source is not a regular file: " + str(source))
    destination.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(source, destination)
    os.chmod(destination, stat.S_IMODE(source.stat().st_mode) & ~0o222)


def definition_sha256(definition):
    normalized = " ".join(definition.strip().split())
    return hashlib.sha256(normalized.encode("utf-8")).hexdigest()


def health_table_definitions():
    sql = (ROOT / "install" / "addon_health_report.sql").read_text(encoding="utf-8")
    statements = [statement.strip().replace("`pre_", "`pay_") for statement in sql.split(";") if statement.strip()]
    definitions = {}
    for statement in statements:
        match = re.match(r"CREATE TABLE IF NOT EXISTS `([A-Za-z0-9_]+)`", statement)
        if not match:
            raise SystemExit("unexpected health schema statement")
        definitions[match.group(1)] = statement
    if set(definitions) != {"pay_health_snapshot", "pay_health_report"}:
        raise SystemExit("health schema table definitions are incomplete")
    return definitions


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--candidate-root", required=True)
    parser.add_argument("--release-id", required=True)
    parser.add_argument("--artifact-path", required=True)
    parser.add_argument("--database-backup", required=True)
    parser.add_argument("--schema-capture", required=True)
    parser.add_argument("--captured-at", required=True)
    parser.add_argument("--source-backup", required=True)
    parser.add_argument("--ssh-key", required=True)
    args = parser.parse_args()
    if not re.fullmatch(r"[A-Za-z0-9._-]{1,128}", args.release_id):
        raise SystemExit("invalid release id")
    candidate = Path(args.candidate_root).expanduser().resolve(strict=False)
    if candidate.exists() or candidate.is_symlink():
        raise SystemExit("candidate root must not exist")
    candidate.parent.mkdir(parents=True, exist_ok=True)
    candidate.mkdir(mode=0o755)
    for relative in parse_mapping():
        copy_file(ROOT / relative, candidate / relative)
    supporting_files = [
        Path("deploy/ai-health-release-control.php"),
        Path("deploy/ai-health-release-sha256.txt"),
        Path("deploy/ai-health-release-files.txt"),
        Path("scripts/verify-ai-health-release.php"),
        Path("scripts/health-report-regression.php"),
        Path("scripts/ai-health-release-control-rollback-regression.php"),
        Path("docs/ai-health-daily-report-development-plan.md"),
        Path("docs/ai-health-daily-report-operations.md"),
        Path("docs/ai-health-daily-report-verification.md"),
        Path("docs/ai-health-production-rollout.md"),
        Path("docs/ai-health-release-control.md"),
        Path("docs/ai-health-release-rehearsal.md"),
    ]
    for relative in supporting_files:
        copy_file(ROOT / relative, candidate / relative)
    gate_control = candidate / "deploy" / "ai-health-gate-control.py"
    copy_file(ROOT / "deploy" / "ai-health-gate-control.py", gate_control)

    database_backup = Path(args.database_backup).expanduser().resolve()
    schema_capture = Path(args.schema_capture).expanduser().resolve()
    source_backup = Path(args.source_backup).expanduser().resolve()
    ssh_key = Path(args.ssh_key).expanduser().resolve()
    for path, label in ((database_backup, "database backup"), (schema_capture, "schema capture"), (source_backup, "source backup"), (ssh_key, "SSH key")):
        if path.is_symlink() or not path.is_file():
            raise SystemExit(label + " is unavailable")
    if ssh_key.parent != (Path.home() / ".ssh").resolve() or not re.fullmatch(r"[A-Za-z0-9._-]{1,128}", ssh_key.name):
        raise SystemExit("SSH key must be a direct regular file below ~/.ssh")
    for path, label in ((database_backup, "database backup"), (schema_capture, "schema capture"), (source_backup, "source backup")):
        if stat.S_IMODE(path.stat().st_mode) & 0o222:
            raise SystemExit(label + " must be immutable")
    release_root = "/srv/epay/releases/" + args.release_id
    config = {
        "format": "epay-ai-health-gate-release-v1",
        "releaseId": args.release_id,
        "targetHost": "47.106.222.150",
        "sshKeyPath": "~/.ssh/" + ssh_key.name,
        "remoteReleaseRoot": release_root,
        "remoteCurrentLink": "/srv/epay/current",
        "remoteSystemdRoot": "/etc/systemd/system",
        "remoteBackupRoot": "/srv/epay/backups/" + args.release_id,
        "remoteDatabaseBackup": "/srv/epay/backups/" + args.release_id + ".sql",
        "databaseName": "epay",
        "tablePrefix": "pay",
        "publicOrigin": "https://manage.xuanfanpay.top",
        "healthUrl": "https://luckrun.xuanfanpay.top/",
        "previousReleaseTarget": "/srv/epay/releases/20260714-74d0d12",
        "localArtifactPath": str(Path(args.artifact_path).expanduser().resolve(strict=False)),
        "remoteArtifactPath": "/srv/epay/artifacts/" + args.release_id + ".tar",
    }
    config_path = candidate / "deploy" / "ai-health-gate-release.json"
    config_path.write_text(json.dumps(config, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    os.chmod(config_path, 0o444)

    tables = health_table_definitions()
    operation_definitions = [
        ("create_table", "pay_health_snapshot", tables["pay_health_snapshot"]),
        ("create_table", "pay_health_report", tables["pay_health_report"]),
        ("add_column", "pay_telegram_notify_queue.dedupe_key", "ALTER TABLE `pay_telegram_notify_queue` ADD COLUMN `dedupe_key` varchar(100) DEFAULT NULL"),
        ("add_column", "pay_telegram_notify_queue.claimtime", "ALTER TABLE `pay_telegram_notify_queue` ADD COLUMN `claimtime` datetime DEFAULT NULL"),
        ("add_column", "pay_telegram_notify_queue.sendstarttime", "ALTER TABLE `pay_telegram_notify_queue` ADD COLUMN `sendstarttime` datetime DEFAULT NULL"),
        ("add_column", "pay_telegram_notify_queue.next_attempt", "ALTER TABLE `pay_telegram_notify_queue` ADD COLUMN `next_attempt` datetime DEFAULT NULL"),
        ("create_index", "pay_telegram_notify_queue.uk_dedupe_key", "ALTER TABLE `pay_telegram_notify_queue` ADD UNIQUE KEY `uk_dedupe_key` (`dedupe_key`)"),
        ("create_index", "pay_telegram_notify_queue.idx_queue_ready", "ALTER TABLE `pay_telegram_notify_queue` ADD KEY `idx_queue_ready` (`status`,`next_attempt`,`addtime`)"),
    ]
    operations = [
        {"kind": kind, "object": object_name, "definitionSha256": definition_sha256(definition)}
        for kind, object_name, definition in operation_definitions
    ]
    migration = {
        "format": "futurecat-mariadb-additive-migration-readiness-v1",
        "productionWrites": False,
        "engine": "mariadb",
        "database": "epay",
        "tablePrefix": "pay",
        "targetHost": "47.106.222.150",
        "serverVersion": "10.11.18-MariaDB",
        "capturedAt": args.captured_at,
        "migrationPolicy": "additive-idempotent-no-destructive-dml-v1",
        "schemaFingerprintSha256": sha256(schema_capture),
        "backupSha256": sha256(database_backup),
        "backupSize": database_backup.stat().st_size,
        "releaseControlSha256": sha256(gate_control),
        "destructiveStatements": False,
        "businessDml": False,
        "credentialValuesPresent": False,
        "operations": operations,
    }
    migration_path = candidate / "deploy" / "ai-health-migration-readiness.json"
    migration_path.write_text(json.dumps(migration, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    os.chmod(migration_path, 0o444)
    print(json.dumps({
        "ok": True,
        "candidateRoot": str(candidate),
        "artifactPath": str(Path(args.artifact_path).expanduser().resolve(strict=False)),
        "sourceBackupSha256": sha256(source_backup),
        "databaseBackupSha256": sha256(database_backup),
        "schemaFingerprintSha256": sha256(schema_capture),
        "fileCount": sum(1 for path in candidate.rglob("*") if path.is_file()),
    }, sort_keys=True))


if __name__ == "__main__":
    main()
