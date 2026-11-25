#!/usr/bin/env python3
"""Build the private Tauria Tasks ChatGPT plugin using Python's standard library."""

from pathlib import Path
import json
import re
from urllib.parse import urlsplit
import zipfile


def main():
    root = Path(__file__).resolve().parent.parent
    source = root / "chatgpt/tauria-tasks"
    manifest = json.loads((source / "plugin.json").read_text(encoding="utf-8"))
    mcp = json.loads((source / "mcp.json").read_text(encoding="utf-8"))
    assert manifest["$schema"] == "https://agent-plugins.org/schemas/1.0.0/plugin.schema.json"
    assert mcp["$schema"] == "https://agent-plugins.org/schemas/1.0.0/mcp.schema.json"
    assert manifest["name"] == source.name == "tauria-tasks"
    assert re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+", manifest["version"])
    interface = manifest["extensions"]["com.openai"]["interface"]
    assert 0 < len(interface["shortDescription"]) <= 30
    assert interface["category"] == "Productivity"
    assert set(mcp["mcpServers"]) == {"tauria-tasks"}
    server = mcp["mcpServers"]["tauria-tasks"]
    assert server["type"] == "streamable-http"
    endpoint = urlsplit(server["url"])
    assert endpoint.scheme == "https" and endpoint.hostname == "central.tauria.ee"
    assert not endpoint.username and not endpoint.password and not endpoint.query and not endpoint.fragment
    assert server["extensions"]["com.openai"]["auth"] == {
        "type": "api_key", "headerScheme": "bearer"
    }
    for field in ("logo", "composerIcon", "logoDark", "composerIconDark"):
        if field in interface:
            asset = (source / interface[field]).resolve()
            assert asset.is_relative_to(source.resolve()) and asset.is_file()
    entries = {}
    for path in source.rglob("*"):
        if path.is_symlink():
            raise SystemExit(f"Refusing to package a symlink: {path}")
        if path.is_file():
            name = path.relative_to(source).as_posix()
            if name.startswith(".") or any(part in {"vendor", "node_modules", "__pycache__"} for part in path.parts):
                raise SystemExit(f"Unexpected package file: {name}")
            entries[f"{source.name}/{name}"] = path
    entries[f"{source.name}/LICENSE"] = root / "LICENSE"
    output = root / "dist" / f"{manifest['name']}-{manifest['version']}.zip"
    output.parent.mkdir(exist_ok=True)
    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        for name, path in sorted(entries.items()):
            info = zipfile.ZipInfo(name, date_time=(2026, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            archive.writestr(info, path.read_bytes())
    with zipfile.ZipFile(output) as archive:
        assert archive.testzip() is None
        assert set(archive.namelist()) == set(entries)
        assert json.loads(archive.read(f"{source.name}/plugin.json")) == manifest
        assert json.loads(archive.read(f"{source.name}/mcp.json")) == mcp
    print(output)


if __name__ == "__main__":
    main()
