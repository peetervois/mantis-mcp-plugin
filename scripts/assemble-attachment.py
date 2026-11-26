#!/usr/bin/env python3
"""Validate MCP attachment chunks and save original bytes; makes no network calls."""

import argparse
import base64
import binascii
import hashlib
import io
import json
import os
from pathlib import Path
import re
import tempfile

CHUNK_BYTES = 65536
MAX_CHUNK_INPUT_BYTES = 262144
IDENTITY = ("issue_id", "attachment_id", "note_id", "filename", "mime_type", "size", "sha256")


def _stream(chunks, target, max_bytes=None):
    if max_bytes is not None and (type(max_bytes) is not int or max_bytes < 1):
        raise ValueError("The optional file cap must be a positive byte count.")
    try:
        chunks = iter(chunks)
    except TypeError:
        raise ValueError("Expected attachment chunk objects.") from None
    first = None
    offset = 0
    finished = False
    digest = hashlib.sha256()
    for chunk in chunks:
        if not isinstance(chunk, dict) or any(key not in chunk for key in IDENTITY):
            raise ValueError("Missing attachment metadata.")
        if first is None:
            first = {key: chunk[key] for key in IDENTITY}
            name = first["filename"]
            if (not isinstance(name, str) or name in {"", ".", ".."} or len(name.encode("utf-8")) > 255
                    or re.search(r"[\\/\x00-\x1f\x7f]", name)):
                raise ValueError("Unsafe or unsupported output filename.")
            if type(first["size"]) is not int or first["size"] < 0:
                raise ValueError("Invalid attachment size.")
            if max_bytes is not None and first["size"] > max_bytes:
                raise ValueError("Attachment exceeds the explicit local file cap.")
            if not isinstance(first["sha256"], str) or not re.fullmatch(r"[a-f0-9]{64}", first["sha256"]):
                raise ValueError("Invalid SHA-256 metadata.")
        if finished or any(chunk[key] != first[key] for key in IDENTITY):
            raise ValueError("Mixed files, changed metadata, or bytes after completion.")
        if type(chunk.get("offset")) is not int or chunk["offset"] != offset:
            raise ValueError("Missing, duplicate or out-of-order chunk.")
        encoded = chunk.get("content_base64")
        if not isinstance(encoded, str) or len(encoded) > 4 * ((CHUNK_BYTES + 2) // 3):
            raise ValueError("Missing or oversized chunk content.")
        try:
            data = base64.b64decode(encoded, validate=True)
        except (ValueError, binascii.Error):
            raise ValueError("Invalid base64 attachment chunk.") from None
        if base64.b64encode(data).decode("ascii") != encoded:
            raise ValueError("Noncanonical base64 attachment chunk.")
        if (type(chunk.get("bytes_returned")) is not int or len(data) != chunk["bytes_returned"]
                or len(data) > CHUNK_BYTES or offset + len(data) > first["size"]):
            raise ValueError("Invalid decoded chunk size.")
        target.write(data)
        digest.update(data)
        offset += len(data)
        finished = offset == first["size"]
        if type(chunk.get("complete")) is not bool or chunk["complete"] != finished:
            raise ValueError("Invalid completion flag.")
        next_offset = chunk.get("next_offset")
        if (finished and next_offset is not None) or (not finished and (
                type(next_offset) is not int or next_offset != offset or not data)):
            raise ValueError("Invalid next offset.")
    if first is None or not finished or digest.hexdigest() != first["sha256"]:
        raise ValueError("Incomplete download or SHA-256 mismatch; discard partial bytes.")
    return first["filename"]


def assemble(chunks, max_bytes=None):
    target = io.BytesIO()
    name = _stream(chunks, target, max_bytes)
    return name, target.getvalue()


def save(chunks, directory, max_bytes=None):
    descriptor, temporary = tempfile.mkstemp(prefix=".mantis-download-", dir=directory)
    try:
        with os.fdopen(descriptor, "wb") as target:
            name = _stream(chunks, target, max_bytes)
        output = Path(directory) / name
        # Atomic exclusive publication of a fully verified file, never overwrite.
        os.link(temporary, output)
        return output
    finally:
        os.unlink(temporary)


def json_lines(stream):
    while True:
        line = stream.readline(MAX_CHUNK_INPUT_BYTES + 1)
        if not line:
            return
        if len(line) > MAX_CHUNK_INPUT_BYTES:
            raise ValueError("Chunk input line exceeds its per-chunk envelope budget.")
        if line.strip():
            yield json.loads(line)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("chunks", type=Path, help="JSON array or JSON Lines of structuredContent chunk objects")
    parser.add_argument("--output-dir", type=Path, default=Path.cwd())
    parser.add_argument("--json-lines", action="store_true", help="Read and save one bounded chunk object at a time")
    parser.add_argument("--max-bytes", type=int, help="Optional explicit local total-file cap; no default cap")
    args = parser.parse_args()
    try:
        with args.chunks.open("rb") as stream:
            chunks = json_lines(stream) if args.json_lines else json.load(stream)
            target = save(chunks, args.output_dir, args.max_bytes)
    except (ValueError, OSError) as error:
        parser.exit(1, "Download not saved: " + str(error) + "\n")
    print(target)


if __name__ == "__main__":
    main()
