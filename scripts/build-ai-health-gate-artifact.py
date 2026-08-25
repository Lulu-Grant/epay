#!/usr/bin/env python3
"""Create a deterministic regular-file TAR from a frozen gate candidate."""

import argparse
import os
import stat
import tarfile
from pathlib import Path


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--candidate-root", required=True)
    parser.add_argument("--artifact", required=True)
    args = parser.parse_args()
    root = Path(args.candidate_root).expanduser().resolve()
    artifact = Path(args.artifact).expanduser().resolve(strict=False)
    if root.is_symlink() or not root.is_dir():
        raise SystemExit("candidate root must be a regular directory")
    if artifact.exists() or artifact.is_symlink():
        raise SystemExit("artifact must not exist")
    artifact.parent.mkdir(parents=True, exist_ok=True)
    files = sorted(path for path in root.rglob("*") if path.is_file() and not path.is_symlink())
    if not files or any(path.is_symlink() for path in root.rglob("*")):
        raise SystemExit("candidate must contain only regular files and directories")
    with tarfile.open(artifact, "w", format=tarfile.PAX_FORMAT) as archive:
        for path in files:
            relative = path.relative_to(root).as_posix()
            info = tarfile.TarInfo(relative)
            info.size = path.stat().st_size
            info.mode = stat.S_IMODE(path.stat().st_mode)
            info.mtime = 0
            info.uid = 0
            info.gid = 0
            info.uname = "root"
            info.gname = "root"
            with path.open("rb") as handle:
                archive.addfile(info, handle)
    os.chmod(artifact, 0o444)
    print(str(artifact))


if __name__ == "__main__":
    main()
