"""Verify that the independent viewer remains complete and packageable."""

import hashlib
import importlib.util
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location("release", ROOT / "tools/release.py")
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class MediaVendor(unittest.TestCase):
    def test_pinned_files_and_licenses_are_installable(self):
        vendor = ROOT / "assets/vendor/pdfjs-6.4.299"
        manifest = json.loads((vendor / "vendor-manifest.json").read_text())
        actual = {p.relative_to(vendor).as_posix() for p in vendor.rglob("*") if p.is_file()}
        self.assertEqual(actual, set(manifest["files"]) | {"vendor-manifest.json"})
        for name, digest in manifest["files"].items():
            path = vendor / name
            self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(), digest, name)
            self.assertTrue(release.installation_name(path.relative_to(ROOT).as_posix()), name)
        for name in [
            "web/viewer.html",
            "web/viewer.js",
            "build/pdf.worker.js",
            "LICENSE",
            "web/standard_fonts/LICENSE_FOXIT",
            "web/wasm/LICENSE_QCMS",
        ]:
            self.assertIn(name, actual)
        self.assertFalse(any(name.endswith((".map", ".pdf")) for name in actual))
        self.assertFalse(release.installation_name("assets/vendor/unknown/runtime.js"))


if __name__ == "__main__":
    unittest.main()
