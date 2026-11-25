"""Check the local-file -> MCP payload contract without network access."""

import base64
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("prepare_attachment", ROOT / "scripts/prepare-attachment.py")
PREPARE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PREPARE)
ICON = ROOT / "chatgpt/tauria-tasks/assets/IconLDC-bt3.png"


class PrepareAttachmentTests(unittest.TestCase):
    def test_original_png_and_hash_are_preserved(self):
        result = PREPARE.prepare(ICON, 123)
        self.assertEqual(result["issue_id"], 123)
        self.assertEqual(result["filename"], ICON.name)
        self.assertEqual(result["mime_type"], "image/png")
        self.assertEqual(base64.b64decode(result["content_base64"]), ICON.read_bytes())
        self.assertEqual(result["sha256"], hashlib.sha256(ICON.read_bytes()).hexdigest())

    def test_existing_note_target_is_explicit_and_positive(self):
        result = PREPARE.prepare(ICON, 123, note_id=456)
        self.assertEqual(result["note_id"], 456)
        self.assertEqual(base64.b64decode(result["content_base64"]), ICON.read_bytes())
        for value in (0, -1, True, "456"):
            with self.subTest(value=value), self.assertRaises(ValueError):
                PREPARE.prepare(ICON, 123, note_id=value)

    def test_unicode_filename_and_all_binary_values(self):
        with tempfile.TemporaryDirectory() as folder:
            source = Path(folder) / "Näide-测试.bin"
            source.write_bytes(bytes(range(256)) * 10)
            payload = PREPARE.prepare(source, 123)
            self.assertEqual(payload["filename"], source.name)
            self.assertEqual(base64.b64decode(payload["content_base64"]), source.read_bytes())

    def test_rejects_empty_oversized_and_unsafe_files(self):
        with tempfile.TemporaryDirectory() as folder:
            source = Path(folder) / "file.bin"
            for data in (b"", b"x" * 1025):
                source.write_bytes(data)
                with self.assertRaises(ValueError):
                    PREPARE.prepare(source, 123, max_bytes=1024)
            source = Path(folder) / "bad:name.bin"
            source.write_bytes(b"test")
            with self.assertRaises(ValueError):
                PREPARE.prepare(source, 123)

    def test_rejects_invalid_issue_mime_and_limit(self):
        for kwargs in (dict(issue_id=0), dict(issue_id=True), dict(issue_id=123, mime_type="image/png\nBad"),
                       dict(issue_id=123, max_bytes=1023), dict(issue_id=123, max_bytes=5242881)):
            with self.subTest(kwargs=kwargs), self.assertRaises(ValueError):
                PREPARE.prepare(ICON, **kwargs)

    def test_private_output_refuses_overwrite(self):
        with tempfile.TemporaryDirectory() as folder:
            target = Path(folder) / "upload.json"
            payload = PREPARE.prepare(ICON, 123)
            PREPARE.save(payload, target)
            self.assertEqual(json.loads(target.read_text()), payload)
            self.assertEqual(target.stat().st_mode & 0o777, 0o600)
            with self.assertRaises(FileExistsError):
                PREPARE.save(payload, target)

    @unittest.skipUnless(os.environ.get("MANTISBT_SOURCE"), "Set MANTISBT_SOURCE for the PHP MCP contract test")
    def test_prepared_payload_uploads_through_actual_native_php_storage(self):
        with tempfile.TemporaryDirectory() as folder:
            completed = subprocess.run(
                [os.environ.get("PHP_BINARY", "php"), "-d", "sys_temp_dir=" + folder,
                 str(ROOT / "tests/import-upload-fixture.php")],
                input=json.dumps(PREPARE.prepare(ICON, 94)), text=True, capture_output=True, check=True,
            )
        receipt = json.loads(completed.stdout)
        self.assertEqual(receipt["sha256"], hashlib.sha256(ICON.read_bytes()).hexdigest())
        self.assertEqual(receipt["size"], ICON.stat().st_size)
        self.assertEqual(receipt["mime_type"], "image/png")


if __name__ == "__main__":
    unittest.main()
