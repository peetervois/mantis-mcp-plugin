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

DEFAULT_MAX_BYTES = None
CHUNK_BYTES = 262144


def prepare(path, issue_id, mime_type=None, max_bytes=DEFAULT_MAX_BYTES, note_id=None):
    path = Path(path)
    if type(issue_id) is not int or issue_id < 1:
        raise ValueError("The issue id must be a positive integer.")
    if max_bytes is not None and (type(max_bytes) is not int or max_bytes < 1):
        raise ValueError("The optional upload limit must be a positive byte count.")
    if note_id is not None and (type(note_id) is not int or note_id < 1):
        raise ValueError("The note id must be a positive integer.")
    name = path.name
    if name in {"", ".", ".."} or len(name.encode("utf-8")) > 250 or re.search(r"[\\/:\x00-\x1f\x7f]", name):
        raise ValueError("Unsafe filename or filename exceeds 250 UTF-8 bytes.")
    if not path.is_file():
        raise ValueError("Select a regular original file.")
    with path.open("rb") as stream:
        data = stream.read() if max_bytes is None else stream.read(max_bytes + 1)
    if not data or (max_bytes is not None and len(data) > max_bytes):
        raise ValueError("The file is empty or exceeds the configured upload limit.")
    mime_type = (mime_type or mimetypes.guess_type(name)[0] or "application/octet-stream").lower()
    if len(mime_type) > 127 or not re.fullmatch(r"[a-z0-9!#$&^_.+\-]+/[a-z0-9!#$&^_.+\-]+", mime_type):
        raise ValueError("Invalid MIME type.")
    result = dict(issue_id=issue_id, filename=name, mime_type=mime_type,
                  content_base64=base64.b64encode(data).decode("ascii"), sha256=hashlib.sha256(data).hexdigest())
    if note_id is not None:
        result["note_id"] = note_id
    return result


def manifest(path, issue_id, mime_type=None, max_bytes=None, note_id=None):
    """Hash the original incrementally; the manifest never includes file bytes."""
    path = Path(path)
    if type(issue_id) is not int or issue_id < 1 or not path.is_file():
        raise ValueError("Select a regular original file and positive issue id.")
    if max_bytes is not None and (type(max_bytes) is not int or max_bytes < 1):
        raise ValueError("The optional upload limit must be a positive byte count.")
    if note_id is not None and (type(note_id) is not int or note_id < 1):
        raise ValueError("The note id must be positive.")
    name = path.name
    if name in {"", ".", ".."} or len(name.encode("utf-8")) > 250 or re.search(r"[\\/:\x00-\x1f\x7f]", name):
        raise ValueError("Unsafe filename.")
    mime = (mime_type or mimetypes.guess_type(name)[0] or "application/octet-stream").lower()
    if len(mime) > 127 or not re.fullmatch(r"[a-z0-9!#$&^_.+\-]+/[a-z0-9!#$&^_.+\-]+", mime):
        raise ValueError("Invalid MIME type.")
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        before = os.fstat(stream.fileno())
        if before.st_size < 1 or (max_bytes is not None and before.st_size > max_bytes):
            raise ValueError("The file is empty or exceeds the selected policy limit.")
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
        after = os.fstat(stream.fileno())
        if (before.st_size, before.st_mtime_ns) != (after.st_size, after.st_mtime_ns):
            raise ValueError("The source file changed while preparing the manifest.")
    result = dict(issue_id=issue_id, filename=name, mime_type=mime, size=before.st_size, sha256=digest.hexdigest())
    if note_id is not None:
        result["note_id"] = note_id
    return result


def chunk(path, upload_id, offset=0, length=CHUNK_BYTES):
    path = Path(path)
    if not isinstance(upload_id, str) or not re.fullmatch(r"[a-f0-9]{64}", upload_id) or type(offset) is not int or offset < 0:
        raise ValueError("Invalid upload id or offset.")
    if type(length) is not int or not 1 <= length <= CHUNK_BYTES or not path.is_file():
        raise ValueError("Select a regular file and a bounded chunk length.")
    with path.open("rb") as stream:
        stream.seek(offset)
        data = stream.read(length)
    if not data:
        raise ValueError("No file bytes remain at this offset.")
    return dict(upload_id=upload_id, offset=offset, content_base64=base64.b64encode(data).decode("ascii"),
                sha256=hashlib.sha256(data).hexdigest())


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
    parser.add_argument("--issue-id", type=int)
    parser.add_argument("--note-id", type=int, help="Attach to an existing editable note on the issue")
    parser.add_argument("--mime-type")
    parser.add_argument("--max-bytes", type=int, default=DEFAULT_MAX_BYTES)
    modes = parser.add_mutually_exclusive_group()
    modes.add_argument("--manifest", action="store_true", help="Prepare begin-upload metadata without encoding the whole file")
    modes.add_argument("--upload-id", help="Prepare one original-file chunk for an existing session")
    parser.add_argument("--offset", type=int, default=0)
    parser.add_argument("--length", type=int, default=CHUNK_BYTES)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()
    try:
        if args.upload_id:
            payload = chunk(args.file, args.upload_id, args.offset, args.length)
        elif args.manifest:
            payload = manifest(args.file, args.issue_id, args.mime_type, args.max_bytes, args.note_id)
        else:
            payload = prepare(args.file, args.issue_id, args.mime_type, args.max_bytes, args.note_id)
        print(save(payload, args.output))
    except (ValueError, OSError) as error:
        parser.exit(1, "Upload not prepared: " + str(error) + "\n")


if __name__ == "__main__":
    main()
