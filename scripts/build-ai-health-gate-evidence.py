#!/usr/bin/env python3
"""Build immutable readiness and local rehearsal expectations for the gate."""

import argparse
import hashlib
import json
import os
from pathlib import Path


def regular_file(raw, label):
    path = Path(raw).expanduser().resolve()
    if path.is_symlink() or not path.is_file():
        raise SystemExit(label + " must be a regular file")
    return path


def sha256(path):
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def write_immutable(path, payload):
    path = Path(path).expanduser().resolve(strict=False)
    if path.exists() or path.is_symlink():
        raise SystemExit("evidence output already exists: " + str(path))
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    os.chmod(path, 0o444)
    return path


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--candidate-root", required=True)
    parser.add_argument("--artifact", required=True)
    parser.add_argument("--source-backup", required=True)
    parser.add_argument("--database-backup", required=True)
    parser.add_argument("--readiness-output", required=True)
    parser.add_argument("--expected-output", required=True)
    args = parser.parse_args()

    candidate = Path(args.candidate_root).expanduser().resolve()
    artifact = regular_file(args.artifact, "artifact")
    source_backup = regular_file(args.source_backup, "source backup")
    database_backup = regular_file(args.database_backup, "database backup")
    control = regular_file(candidate / "deploy" / "ai-health-gate-control.py", "release control")
    migration = regular_file(candidate / "deploy" / "ai-health-migration-readiness.json", "migration evidence")
    config_path = regular_file(candidate / "deploy" / "ai-health-gate-release.json", "release config")
    config = json.loads(config_path.read_text(encoding="utf-8"))

    readiness = {
        "productionWrites": False,
        "requiresFormalGo": True,
        "target": {
            "host": config["targetHost"],
            "applicationPath": config["remoteReleaseRoot"],
            "service": "php8.4-fpm.service",
            "publicOrigin": config["publicOrigin"],
        },
        "candidate": {"artifact": str(artifact), "artifactSha256": sha256(artifact)},
        "controls": {"releaseControl": str(control), "releaseControlSha256": sha256(control)},
        "database": {
            "engine": "mariadb",
            "name": config["databaseName"],
            "tablePrefix": config["tablePrefix"],
            "rollbackPolicy": "retain-additive-schema-on-code-rollback-v1",
            "backupEvidence": str(database_backup),
            "backupEvidenceSha256": sha256(database_backup),
            "migrationEvidence": str(migration),
            "migrationEvidenceSha256": sha256(migration),
            "credentialValuesPresent": False,
            "livePreMigrationSchemaRecheckRequired": True,
        },
    }
    expected = {
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
    readiness_path = write_immutable(args.readiness_output, readiness)
    expected_path = write_immutable(args.expected_output, expected)
    print(json.dumps({"ok": True, "readiness": str(readiness_path), "expected": str(expected_path)}, separators=(",", ":")))


if __name__ == "__main__":
    main()
