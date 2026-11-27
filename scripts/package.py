#!/usr/bin/env python3
"""Build an installable plugin ZIP using only the Python standard library."""

import argparse
from datetime import datetime, timezone
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import time
import zipfile


def build(root, output_dir, epoch=None):
    root = Path(root)
    output_dir = Path(output_dir)
    source = root / "MantisMcp"
    protocol = (source / "core/Protocol.php").read_text(encoding="utf-8")
    match = re.search(r"const VERSION = '([0-9]+\.[0-9]+\.[0-9]+)';", protocol)
    if not match:
        raise SystemExit("Cannot read plugin version.")
    output = output_dir / f"MantisMcp-{match.group(1)}.zip"
    output_dir.mkdir(parents=True, exist_ok=True)
    entries = {
        f"MantisMcp/{path.relative_to(source).as_posix()}": path
        for path in source.rglob("*") if path.is_file()
    }
    entries["MantisMcp/LICENSE"] = root / "LICENSE"
    entries["MantisMcp/README.md"] = root / "README.md"
    entries.update({
        f"MantisMcp/docs/{path.name}": path
        for path in (root / "docs").glob("*.md")
    })
    if any(path.is_symlink() for path in entries.values()):
        raise ValueError("Refusing to package a source symlink.")
    contents = {name: path.read_bytes() for name, path in sorted(entries.items())}
    if epoch is None:
        if os.environ.get("SOURCE_DATE_EPOCH"):
            epoch = int(os.environ["SOURCE_DATE_EPOCH"])
        else:
            try:
                epoch = int(subprocess.check_output(
                    ["git", "show", "-s", "--format=%ct", "HEAD"], cwd=root,
                    text=True, stderr=subprocess.DEVNULL,
                ).strip())
            except (OSError, ValueError, subprocess.CalledProcessError):
                epoch = int(time.time())
    # Revision timestamps preserve reproducibility without reusing one timestamp
    # across every release, which can prevent OPcache noticing replaced files.
    timestamp = datetime.fromtimestamp(max(315532800, min(epoch, 4354819198)), timezone.utc).timetuple()[:6]
    install = output_dir / "install"
    if install.is_symlink() or (install.exists() and not install.is_dir()):
        raise ValueError("Refusing to replace a non-directory installation staging path.")
    with tempfile.TemporaryDirectory(prefix=".mantis-build-", dir=output_dir) as directory:
        temporary = Path(directory)
        tree = temporary / "tree"
        with zipfile.ZipFile(temporary / "package.zip", "w", compression=zipfile.ZIP_DEFLATED) as archive:
            for name, data in contents.items():
                info = zipfile.ZipInfo(name, date_time=timestamp)
                info.compress_type = zipfile.ZIP_DEFLATED
                info.external_attr = 0o100644 << 16
                archive.writestr(info, data)
                target = tree / name
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(data)
                target.chmod(0o644)
                os.utime(target, (epoch, epoch))
        if install.exists():
            shutil.rmtree(install)
        os.replace(tree, install)
        os.replace(temporary / "package.zip", output)
    return output


def main():
    root = Path(__file__).resolve().parent.parent
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output-dir", type=Path, default=root / "dist")
    args = parser.parse_args()
    print(build(root, args.output_dir))


if __name__ == "__main__":
    main()
