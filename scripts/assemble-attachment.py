#!/usr/bin/env python3
"""Validate MCP attachment chunks and save original bytes; makes no network calls."""

import argparse
import base64
import binascii
import hashlib
import json
from pathlib import Path
import re

MAX_FILE_BYTES = 8388608
MAX_INPUT_BYTES = 33554432
CHUNK_BYTES = 65536
IDENTITY = ("issue_id", "attachment_id", "note_id", "filename", "mime_type", "size", "sha256")


def assemble(chunks):
    if not isinstance(chunks, list) or not chunks:
        raise ValueError("Expected a nonempty JSON array of structuredContent chunk objects.")
    first = chunks[0]
    if not isinstance(first, dict) or any(key not in first for key in IDENTITY):
        raise ValueError("Missing attachment metadata.")
    name = first["filename"]
    if (not isinstance(name, str) or name in {"", ".", ".."} or len(name.encode("utf-8")) > 255
            or re.search(r"[\\/\x00-\x1f\x7f]", name)):
        raise ValueError("Unsafe or unsupported output filename.")
    if type(first["size"]) is not int or not 0 <= first["size"] <= MAX_FILE_BYTES:
        raise ValueError("Invalid attachment size.")
    if not isinstance(first["sha256"], str) or not re.fullmatch(r"[a-f0-9]{64}", first["sha256"]):
        raise ValueError("Invalid SHA-256 metadata.")
    output = bytearray()
    finished = False
    for chunk in chunks:
        if finished or not isinstance(chunk, dict) or any(chunk.get(key) != first[key] for key in IDENTITY):
            raise ValueError("Mixed files, changed metadata, or bytes after completion.")
        offset = chunk.get("offset")
        if type(offset) is not int or offset != len(output):
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
                or len(data) > CHUNK_BYTES or len(output) + len(data) > first["size"]):
            raise ValueError("Invalid decoded chunk size.")
        output.extend(data)
        finished = len(output) == first["size"]
        if type(chunk.get("complete")) is not bool or chunk["complete"] != finished:
            raise ValueError("Invalid completion flag.")
        next_offset = chunk.get("next_offset")
        if (finished and next_offset is not None) or (not finished and (
                type(next_offset) is not int or next_offset != len(output) or not data)):
            raise ValueError("Invalid next offset.")
    if not finished or hashlib.sha256(output).hexdigest() != first["sha256"]:
        raise ValueError("Incomplete download or SHA-256 mismatch; discard partial bytes.")
    return name, bytes(output)


def save(chunks, directory):
    name, data = assemble(chunks)
    target = Path(directory) / name
    # Exclusive creation also rejects existing files and symlinks.
    with target.open("xb") as stream:
        try:
            stream.write(data)
        except BaseException:
            target.unlink()
            raise
    return target


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("chunks", type=Path, help="JSON array of downloaded structuredContent objects")
    parser.add_argument("--output-dir", type=Path, default=Path.cwd())
    args = parser.parse_args()
    try:
        with args.chunks.open("rb") as stream:
            raw = stream.read(MAX_INPUT_BYTES + 1)
        if len(raw) > MAX_INPUT_BYTES:
            raise ValueError("Chunk input exceeds the local input-size limit.")
        target = save(json.loads(raw), args.output_dir)
    except (ValueError, OSError) as error:
        parser.exit(1, "Download not saved: " + str(error) + "\n")
    print(target)


if __name__ == "__main__":
    main()
