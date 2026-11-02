import hashlib
import importlib.util
import io
import tarfile
import tempfile
import unittest
from pathlib import Path

module = importlib.util.spec_from_file_location("importer", Path(__file__).resolve().parents[1] / "deploy/tools/import-build.py")
importer = importlib.util.module_from_spec(module)
module.loader.exec_module(importer)


def bundle(items, include_parents=True):
    stream = io.BytesIO()
    with tarfile.open(fileobj=stream, mode="w:gz") as archive:
        directories = set()
        for name, kind, value in items:
            if include_parents:
                for parent in reversed(Path(name).parents):
                    if parent != Path(".") and parent not in directories:
                        directories.add(parent)
                        directory = tarfile.TarInfo(str(parent))
                        directory.type = tarfile.DIRTYPE
                        archive.addfile(directory)
            item = tarfile.TarInfo(name)
            item.type = kind
            if kind == tarfile.REGTYPE:
                data = value.encode()
                item.size = len(data)
                item.mode = 0o6755
                archive.addfile(item, io.BytesIO(data))
            else:
                item.linkname = value
                archive.addfile(item)
    stream.seek(0)
    return stream


def deliver(items, dest, project):
    payload = bundle(items)
    importer.import_bundle(payload, dest, project, hashlib.sha256(payload.getvalue()).hexdigest())


class BuildArchiveTest(unittest.TestCase):
    api = [("build/manifest.json", tarfile.REGTYPE, "{}"), ("build/assets/site.js", tarfile.REGTYPE, "ok")]
    front = [(".next/BUILD_ID", tarfile.REGTYPE, "ready"), ("node_modules/next/dist/bin/next", tarfile.REGTYPE, "js")]

    def test_runtime_outputs_and_internal_links(self):
        with tempfile.TemporaryDirectory() as tmp:
            dest = Path(tmp)
            files = self.front + [("node_modules/.bin/next", tarfile.SYMTYPE, "../next/dist/bin/next"),
                                  (".next/node_modules/next", tarfile.SYMTYPE, "../../node_modules/next")]
            deliver(files, dest, "front")
            self.assertEqual((dest / ".next/node_modules/next/dist/bin/next").read_text(), "js")
            self.assertEqual((dest / "node_modules/.bin/next").stat().st_mode & 0o6777, 0o755)
            deliver(self.api, dest, "backend")
            self.assertEqual((dest / "build/assets/site.js").stat().st_mode & 0o777, 0o644)

    def test_tampering_with_signed_payload_is_rejected(self):
        with tempfile.TemporaryDirectory() as tmp, self.assertRaisesRegex(ValueError, "signed delivery"):
            importer.import_bundle(bundle(self.api), Path(tmp), "backend", "0" * 64)

    def test_rejects_implicit_directories(self):
        payload = bundle([("build/" + "a/" * 70 + "b.js", tarfile.REGTYPE, "a")], include_parents=False)
        with tempfile.TemporaryDirectory() as tmp, self.assertRaisesRegex(ValueError, "unexpected build path"):
            importer.import_bundle(payload, Path(tmp), "backend", hashlib.sha256(payload.getvalue()).hexdigest())

    def test_rejects_escapes_links_and_special_files_without_promoting(self):
        for target, attack in [
            ("backend", ("build/../.env", tarfile.REGTYPE, "injected")),
            ("backend", ("build/assets/evil.php", tarfile.REGTYPE, "bad")),
            ("backend", ("build/assets/bad.js", tarfile.LNKTYPE, "/etc/passwd")),
            ("backend", ("build/assets/fifo.js", tarfile.FIFOTYPE, "")),
            ("front", (".next/server/route-cache", tarfile.SYMTYPE, "/etc")),
            ("front", ("node_modules/.bin/outside", tarfile.SYMTYPE, "../../../canary")),
            ("front", ("node_modules/.bin/absolute", tarfile.SYMTYPE, "/etc/passwd")),
            ("front", (".env.production", tarfile.REGTYPE, "fake")),
            ("front", (".next/BUILD_ID", tarfile.REGTYPE, "duplicate")),
        ]:
            with self.subTest(attack=attack), tempfile.TemporaryDirectory() as tmp:
                dest = Path(tmp) / "out"
                dest.mkdir()
                canary = Path(tmp) / "canary"
                canary.write_text("protected")
                files = self.front if target == "front" else self.api
                with self.assertRaises((ValueError, OSError, RuntimeError)):
                    deliver(files + [attack], dest, target)
                self.assertEqual(list(dest.iterdir()), [])
                self.assertEqual(canary.read_text(), "protected")


if __name__ == "__main__":
    unittest.main()
