"""Check installation artifact layout, hidden protection file and revision timestamps."""
import importlib.util
from pathlib import Path
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("package_plugin", ROOT / "scripts/package.py")
PACKAGE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PACKAGE)


class PackageTests(unittest.TestCase):
    def test_zip_and_artifact_tree_have_identical_installable_bytes(self):
        with tempfile.TemporaryDirectory() as directory:
            output = PACKAGE.build(ROOT, Path(directory), epoch=1795644000)
            with zipfile.ZipFile(output) as archive:
                names = archive.namelist()
                self.assertIn("MantisMcp/.htaccess", names)
                self.assertIn("MantisMcp/MantisMcp.php", names)
                self.assertTrue(all(name.startswith("MantisMcp/") and not name.endswith(".zip") for name in names))
                tree = Path(directory) / "install"
                self.assertEqual(sorted(names), sorted(str(p.relative_to(tree)) for p in tree.rglob("*") if p.is_file()))
                for name in names:
                    self.assertEqual(archive.read(name), (tree / name).read_bytes())

    def test_new_revision_changes_timestamps_and_cleans_obsolete_staging_files(self):
        with tempfile.TemporaryDirectory() as directory:
            output = PACKAGE.build(ROOT, Path(directory), epoch=1795644000)
            with zipfile.ZipFile(output) as archive:
                first = archive.getinfo("MantisMcp/core/Protocol.php").date_time
            (Path(directory) / "install/MantisMcp/obsolete.php").write_text("old")
            PACKAGE.build(ROOT, Path(directory), epoch=1795644060)
            with zipfile.ZipFile(output) as archive:
                self.assertNotEqual(first, archive.getinfo("MantisMcp/core/Protocol.php").date_time)
            self.assertFalse((Path(directory) / "install/MantisMcp/obsolete.php").exists())

    def test_refuses_a_symlink_for_installation_staging(self):
        with tempfile.TemporaryDirectory() as directory:
            out = Path(directory) / "output"
            out.mkdir()
            (out / "install").symlink_to(Path(directory), target_is_directory=True)
            with self.assertRaises(ValueError):
                PACKAGE.build(ROOT, out, epoch=1795644000)
