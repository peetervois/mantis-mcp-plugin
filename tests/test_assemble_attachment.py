"""Exercise client materialization without contacting MantisBT or handling credentials."""

import base64
from copy import deepcopy
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parent.parent
SPEC = importlib.util.spec_from_file_location("assemble_attachment", ROOT / "scripts/assemble-attachment.py")
ASSEMBLER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(ASSEMBLER)


def chunks_for(data, name="Näide-测试.bin", length=65536):
    chunks = []
    for offset in range(0, max(1, len(data)), length):
        piece = data[offset:offset + length]
        end = offset + len(piece)
        chunks.append(dict(issue_id=1006, attachment_id=220, note_id=1303,
                           filename=name, mime_type="application/octet-stream", size=len(data),
                           sha256=hashlib.sha256(data).hexdigest(), offset=offset,
                           bytes_returned=len(piece), next_offset=end if end < len(data) else None,
                           complete=end == len(data), content_base64=base64.b64encode(piece).decode()))
    return chunks


class AssembleAttachmentTests(unittest.TestCase):
    @unittest.skipUnless(os.environ.get("MANTISBT_SOURCE"), "Set MANTISBT_SOURCE for the PHP MCP contract test")
    def test_materializes_actual_php_mcp_responses(self):
        completed = subprocess.run(
            [os.environ.get("PHP_BINARY", "php"), str(ROOT / "tests/export-download-fixture.php")],
            check=True, capture_output=True, text=True,
        )
        chunks = json.loads(completed.stdout)
        self.assertGreater(len(chunks), 1)
        with tempfile.TemporaryDirectory() as directory:
            target = ASSEMBLER.save(chunks, directory)
            self.assertEqual(target.name, "IconLDC-bt3.png")
            self.assertEqual(target.read_bytes(), (ROOT / "chatgpt/tauria-tasks/assets/IconLDC-bt3.png").read_bytes())

    def test_binary_fidelity_and_unicode_filename(self):
        data = bytes(range(256)) * 2000
        name, actual = ASSEMBLER.assemble(chunks_for(data))
        self.assertEqual(name, "Näide-测试.bin")
        self.assertEqual(actual, data)

    def test_original_png_is_saved_without_modification(self):
        original = (ROOT / "chatgpt/tauria-tasks/assets/IconLDC-bt3.png").read_bytes()
        with tempfile.TemporaryDirectory() as directory:
            target = ASSEMBLER.save(chunks_for(original, "IconLDC-bt3.png"), directory)
            self.assertEqual(target.read_bytes(), original)

    def test_refuses_missing_duplicated_or_reordered_chunks(self):
        chunks = chunks_for(b"a" * 140000)
        for candidate in (chunks[:-1], chunks[1:], chunks[:1] + chunks,
                          list(reversed(chunks)), chunks + chunks[-1:]):
            with self.subTest(candidate=len(candidate)), self.assertRaises(ValueError):
                ASSEMBLER.assemble(candidate)

    def test_refuses_changed_identity_and_corrupted_bytes(self):
        chunks = chunks_for(b"a" * 140000)
        for key, value in (("sha256", "0" * 64), ("issue_id", 1007), ("filename", "other.bin")):
            changed = deepcopy(chunks)
            changed[1][key] = value
            with self.subTest(key=key), self.assertRaises(ValueError):
                ASSEMBLER.assemble(changed)
        chunks[0]["content_base64"] = base64.b64encode(b"b" * 65536).decode()
        with self.assertRaises(ValueError):
            ASSEMBLER.assemble(chunks)

    def test_refuses_unsafe_names_and_existing_outputs(self):
        for name in ("../file", "/tmp/file", "C:\\file", "bad\nfile", ".", ".."):
            with self.subTest(name=name), self.assertRaises(ValueError):
                ASSEMBLER.assemble(chunks_for(b"test", name))
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / "existing.bin"
            target.write_bytes(b"original")
            with self.assertRaises(FileExistsError):
                ASSEMBLER.save(chunks_for(b"changed", "existing.bin"), directory)
            self.assertEqual(target.read_bytes(), b"original")

    def test_refuses_malformed_base64_ranges_and_completion_flags(self):
        for key, value in (("content_base64", "!"), ("offset", 1), ("bytes_returned", 0),
                           ("next_offset", 999), ("complete", False)):
            chunks = chunks_for(b"test")
            chunks[0][key] = value
            with self.subTest(key=key), self.assertRaises(ValueError):
                ASSEMBLER.assemble(chunks)

    def test_hash_failure_creates_no_file(self):
        chunks = chunks_for(b"test")
        chunks[0]["sha256"] = "0" * 64
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaises(ValueError):
                ASSEMBLER.save(chunks, directory)
            self.assertEqual(list(Path(directory).iterdir()), [])

    def test_accepts_a_zero_byte_attachment(self):
        _, actual = ASSEMBLER.assemble(chunks_for(b""))
        self.assertEqual(actual, b"")


if __name__ == "__main__":
    unittest.main()
