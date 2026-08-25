#!/usr/bin/env python3
"""Build the immutable request consumed by the independent MariaDB runner."""

import argparse
import hashlib
import json
import os
import stat
import tarfile
import uuid
from datetime import datetime, timedelta, timezone
from pathlib import Path, PurePosixPath


def sha256(path):
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def canonical(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")


def inventory(path):
    entries = []
    seen = set()
    with tarfile.open(path, "r:*") as archive:
        for member in archive.getmembers():
            name = PurePosixPath(member.name).as_posix()
            if not member.isfile() or name != member.name or name in seen:
                raise SystemExit("artifact inventory is unsafe")
            stream = archive.extractfile(member)
            if stream is None:
                raise SystemExit("artifact member is unreadable")
            content = stream.read()
            seen.add(name)
            entries.append({"path": name, "kind": "file", "sha256": hashlib.sha256(content).hexdigest(), "size": len(content), "mode": format(stat.S_IMODE(member.mode), "04o")})
    return sorted(entries, key=lambda item: item["path"])


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--packet", required=True)
    parser.add_argument("--artifact", required=True)
    parser.add_argument("--source-backup", required=True)
    parser.add_argument("--database-backup", required=True)
    parser.add_argument("--readiness", required=True)
    parser.add_argument("--migration", required=True)
    parser.add_argument("--control", required=True)
    parser.add_argument("--pre-schema", required=True)
    parser.add_argument("--publish-directory", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()
    packet = Path(args.packet).resolve()
    policy = json.loads((packet / "review-policy.json").read_text(encoding="utf-8"))
    manifest = json.loads((packet / "candidate-manifest.json").read_text(encoding="utf-8"))
    artifact = Path(args.artifact).resolve()
    source = Path(args.source_backup).resolve()
    database = Path(args.database_backup).resolve()
    readiness = Path(args.readiness).resolve()
    migration_path = Path(args.migration).resolve()
    control = Path(args.control).resolve()
    pre_schema = Path(args.pre_schema).resolve()
    migration = json.loads(migration_path.read_text(encoding="utf-8"))
    expires = min(datetime.now(timezone.utc) + timedelta(hours=4), datetime.fromisoformat(policy["expiresAt"]).astimezone(timezone.utc) - timedelta(minutes=2))
    request = {
        "format": "epay-real-mariadb-attestation-request-v1",
        "attestationId": str(uuid.uuid4()),
        "attestorKeyId": "epay-real-mariadb-r50",
        "expiresAt": expires.isoformat(),
        "expectedRunnerImplementationSha256": "c015a6bfc3bd3a770f98a2ab6fc3644081a6cb89d510b9a83005482abdc6ad36",
        "expectedEnvironmentImageSha256": "349763da1c8706d57d911526c3a3e0b9a68e49b5b6a1d75a5dee6ffc93cfcf1e",
        "isolatedDatabase": "epay_health_attest_r50x_test",
        "publishDirectory": str(Path(args.publish_directory).resolve(strict=False)),
        "paths": {
            "artifact": "/root/epay-release-attestor-r50/input/20260720-ai-health-1203-r50.tar",
            "sourceBackup": "/root/epay-release-attestor-r50/input/source-before.tar",
            "databaseBackup": "/root/epay-release-attestor-r50/input/database-before.sql",
            "deploymentReadiness": "/root/epay-release-attestor-r50/input/deployment-readiness.json",
            "migrationEvidence": "/root/epay-release-attestor-r50/input/ai-health-migration-readiness.json",
            "releaseControl": "/root/epay-release-attestor-r50/input/ai-health-gate-control.py",
            "preMigrationSchema": "/root/epay-release-attestor-r50/input/schema-before.txt"
        },
        "bindings": {
            "packetId": policy["packetId"],
            "candidatePayloadSha256": manifest["payloadSha256"],
            "artifactSha256": sha256(artifact),
            "artifactInventorySha256": hashlib.sha256(canonical(inventory(artifact))).hexdigest(),
            "releaseControlSha256": sha256(control),
            "migrationEvidenceSha256": sha256(migration_path),
            "databaseBackupSha256": sha256(database),
            "databaseBackupSize": database.stat().st_size,
            "sourceBackupSha256": sha256(source),
            "deploymentReadinessEvidenceSha256": sha256(readiness),
            "databaseName": migration["database"],
            "tablePrefix": migration["tablePrefix"],
            "targetHost": migration["targetHost"],
            "targetPath": "/srv/epay/releases/20260720-ai-health-1203-r50",
            "targetService": "php8.4-fpm.service",
            "publicOrigin": "https://manage.xuanfanpay.top",
            "releaseId": "20260720-ai-health-1203-r50",
            "mariadbVersion": migration["serverVersion"],
            "preMigrationSchemaSha256": migration["schemaFingerprintSha256"]
        }
    }
    output = Path(args.output).resolve(strict=False)
    if output.exists() or output.is_symlink():
        raise SystemExit("request output already exists")
    output.write_text(json.dumps(request, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    os.chmod(output, 0o400)
    print(json.dumps({"ok": True, "attestationId": request["attestationId"], "expiresAt": request["expiresAt"]}, separators=(",", ":")))


if __name__ == "__main__":
    main()
