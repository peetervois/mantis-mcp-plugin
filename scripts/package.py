#!/usr/bin/env python3
"""Build an installable plugin ZIP using only the Python standard library."""

from pathlib import Path
import re
import zipfile


def main():
    root = Path(__file__).resolve().parent.parent
    source = root / "MantisMcp"
    protocol = (source / "core/Protocol.php").read_text(encoding="utf-8")
    match = re.search(r"const VERSION = '([0-9]+\.[0-9]+\.[0-9]+)';", protocol)
    if not match:
        raise SystemExit("Cannot read plugin version.")
    output = root / "dist" / f"MantisMcp-{match.group(1)}.zip"
    output.parent.mkdir(exist_ok=True)
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
    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        for name, path in sorted(entries.items()):
            if path.is_symlink():
                raise SystemExit(f"Refusing to package a symlink: {path}")
            info = zipfile.ZipInfo(name, date_time=(2026, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            archive.writestr(info, path.read_bytes())
    print(output)


if __name__ == "__main__":
    main()
