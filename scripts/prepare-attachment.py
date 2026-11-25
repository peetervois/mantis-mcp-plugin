#!/usr/bin/env python3
"""Prepare one original local file for the MCP upload tool; no network or credentials."""

import argparse
import base64
import hashlib
import json
import mimetypes
import os
from pathlib import Path
import re

DEFAULT_MAX_BYTES = 524288
HARD_MAX_BYTES = 5242880


def prepare(path, issue_id, mime_type=None, max_bytes=DEFAULT_MAX_BYTES):
    path = Path(path)
    if type(issue_id) is not int or issue_id < 1:
        raise ValueError("The issue id must be a positive integer.")
    if type(max_bytes) is not int or not 1024 <= max_bytes <= HARD_MAX_BYTES:
        raise ValueError("The upload limit must be between 1 KiB and 5 MiB.")
    name = path.name
    if name in {"", ".", ".."} or len(name.encode("utf-8")) > 250 or re.search(r"[\\/:\x00-\x1f\x7f]", name):
        raise ValueError("Unsafe filename or filename exceeds 250 UTF-8 bytes.")
    with path.open("rb") as stream:
        data = stream.read(max_bytes + 1)
    if not data or len(data) > max_bytes:
        raise ValueError("The file is empty or exceeds the configured upload limit.")
    mime_type = (mime_type or mimetypes.guess_type(name)[0] or "application/octet-stream").lower()
    if len(mime_type) > 127 or not re.fullmatch(r"[a-z0-9!#$&^_.+\-]+/[a-z0-9!#$&^_.+\-]+", mime_type):
        raise ValueError("Invalid MIME type.")
    return dict(issue_id=issue_id, filename=name, mime_type=mime_type,
                content_base64=base64.b64encode(data).decode("ascii"), sha256=hashlib.sha256(data).hexdigest())


def save(payload, target):
    target = Path(target)
    descriptor = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8") as stream:
            json.dump(payload, stream, ensure_ascii=False, separators=(",", ":"))
            stream.write("\n")
    except BaseException:
        target.unlink()
        raise
    return target


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("file", type=Path)
    parser.add_argument("--issue-id", required=True, type=int)
    parser.add_argument("--mime-type")
    parser.add_argument("--max-bytes", type=int, default=DEFAULT_MAX_BYTES)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()
    try:
        payload = prepare(args.file, args.issue_id, args.mime_type, args.max_bytes)
        print(save(payload, args.output))
    except (ValueError, OSError) as error:
        parser.exit(1, "Upload not prepared: " + str(error) + "\n")


if __name__ == "__main__":
    main()
