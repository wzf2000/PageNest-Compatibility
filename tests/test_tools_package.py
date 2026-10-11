"""The public Companion ships only the generic host, never implementation/game packs."""

import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location("release", ROOT / "tools/release.py")
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class ToolsPackage(unittest.TestCase):
    def test_host_assets_and_implementation_boundary(self):
        names = release.source_files()
        self.assertIn("modules/tools/assets/classic-preview.js", names)
        self.assertIn("modules/tools/bootstrap.php", names)
        self.assertFalse(release.installation_name("modules/tools/assets/game-engine-2048.js"))
        self.assertFalse(release.installation_name("examples/pagenest-basic-tools.php"))
        self.assertFalse(any("game-engine" in name or "verify.cjs" in name for name in names))
        self.assertFalse(any(name.startswith("providers/") for name in names))


if __name__ == "__main__":
    unittest.main()
