#!/usr/bin/env python3
"""Local regression checks for the immutable AI health release adapter."""

import argparse
import json
import os
import shutil
import subprocess
import tarfile
import tempfile
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
GATE_PYTHON = Path("/Applications/Xcode.app/Contents/Developer/Library/Frameworks/Python3.framework/Versions/3.9/Resources/Python.app/Contents/MacOS/Python")


def run(argv, expect_success=True):
    completed = subprocess.run(argv, cwd=str(ROOT), stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if expect_success and completed.returncode != 0:
        raise RuntimeError("command failed: " + " ".join(argv) + "\n" + completed.stderr[-2000:])
    if not expect_success and completed.returncode == 0:
        raise RuntimeError("command unexpectedly succeeded: " + " ".join(argv))
    return completed


def immutable(path):
    os.chmod(path, 0o444)
    return path


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--ssh-key", default="~/.ssh/xuanfanpay_47.106.222.150")
    args = parser.parse_args()
    ssh_key = Path(args.ssh_key).expanduser().resolve()
    if not ssh_key.is_file() or ssh_key.is_symlink():
        raise SystemExit("test SSH key is unavailable")
    if not GATE_PYTHON.is_file():
        raise SystemExit("canonical gate Python is unavailable")

    with tempfile.TemporaryDirectory(prefix="epay-ai-health-gate-test-") as raw:
        work = Path(raw)
        database = work / "epay.sql"
        database.write_text("-- MariaDB dump\n-- Host: 47.106.222.150    Database: epay\n-- Dump completed on 2026-08-06 08:00:00\n", encoding="utf-8")
        immutable(database)
        schema = work / "schema.txt"
        schema.write_text("health_report_exists\t0\nhealth_snapshot_exists\t0\n", encoding="utf-8")
        immutable(schema)
        source_backup = work / "source.tar"
        source_member = work / "previous.txt"
        source_member.write_text("previous release\n", encoding="utf-8")
        with tarfile.open(source_backup, "w") as archive:
            archive.add(source_member, arcname="previous.txt")
        immutable(source_backup)

        candidate = work / "candidate"
        artifact = work / "candidate.tar"
        run([
            str(GATE_PYTHON), str(ROOT / "scripts" / "build-ai-health-gate-candidate.py"),
            "--candidate-root", str(candidate), "--release-id", "ai-health-gate-regression",
            "--artifact-path", str(artifact), "--database-backup", str(database),
            "--schema-capture", str(schema), "--captured-at", "2026-07-20T08:00:00Z",
            "--source-backup", str(source_backup), "--ssh-key", str(ssh_key),
        ])
        run([
            str(GATE_PYTHON), str(ROOT / "scripts" / "build-ai-health-gate-artifact.py"),
            "--candidate-root", str(candidate), "--artifact", str(artifact),
        ])
        control = candidate / "deploy" / "ai-health-gate-control.py"
        run([str(GATE_PYTHON), str(control), "verify-artifact", str(artifact)])
        run([str(GATE_PYTHON), str(control), "health-candidate", str(artifact)])

        output = work / "rehearsal.json"
        output.write_text('{"prestate":true}\n', encoding="utf-8")
        migration = candidate / "deploy" / "ai-health-migration-readiness.json"
        run([
            str(GATE_PYTHON), str(control), "rehearse-migration", str(artifact),
            str(source_backup), str(database), str(migration), str(output),
        ])
        run([
            str(GATE_PYTHON), str(control), "health-restored-migration", str(output),
            str(artifact), str(source_backup), str(database), str(migration),
        ])
        restored = json.loads(output.read_text(encoding="utf-8"))
        if restored.get("forcedFailureExercised") is not True or restored.get("serviceStateRestored") != "active":
            raise RuntimeError("rehearsal output is incomplete")

        tampered_root = work / "tampered-candidate"
        shutil.copytree(candidate, tampered_root)
        tampered_config = tampered_root / "deploy" / "ai-health-gate-release.json"
        os.chmod(tampered_config, 0o644)
        tampered_config.write_text(tampered_config.read_text(encoding="utf-8") + "\n", encoding="utf-8")
        immutable(tampered_config)
        tampered = work / "tampered.tar"
        run([
            str(GATE_PYTHON), str(ROOT / "scripts" / "build-ai-health-gate-artifact.py"),
            "--candidate-root", str(tampered_root), "--artifact", str(tampered),
        ])
        run([str(GATE_PYTHON), str(control), "verify-artifact", str(tampered)], expect_success=False)

    print(json.dumps({"ok": True, "checks": 6}, separators=(",", ":")))


if __name__ == "__main__":
    main()
