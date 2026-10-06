"""Exercise release safety failures against a disposable Git repository."""

import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
import zipfile


class ReleaseGuards(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / "tools").mkdir()
        shutil.copy(
            Path(__file__).resolve().parent.parent / "tools/release.py", self.root / "tools"
        )
        package = {"name": "pagenest-compatibility", "version": "0.5.1"}
        lock = {"version": "0.5.1", "packages": {"": package}}
        for name, value in [("package.json", package), ("package-lock.json", lock)]:
            (self.root / name).write_text(json.dumps(value))
        for name, value in {
            "pagenest-compatibility.php": "<?php // Version: 0.5.1\n",
            "index.php": "<?php\n",
            "README.md": "Read me\n",
            "LICENSE": "GPL\n",
            "CHANGELOG.md": "# Changes\n\n## 0.5.1\n\n- Current functionality.\n",
            "theme.json": "{}\n",
            "screenshot.png": "fixture",
        }.items():
            (self.root / name).write_text(value)
        for directory in ["tests", "node_modules", ".github"]:
            (self.root / directory).mkdir()
            (self.root / directory / "development.txt").write_text("excluded")
        self.git("init", "-q")
        self.git("add", ".")
        self.git(
            "-c",
            "user.name=Fixture",
            "-c",
            "user.email=fixture@example.invalid",
            "commit",
            "-qm",
            "fixture",
        )
        self.sha = self.git("rev-parse", "HEAD").strip()

    def git(self, *args):
        return subprocess.check_output(["git", *args], cwd=self.root, text=True)

    def run_tool(self, *args, ok=True):
        result = subprocess.run(
            ["python3", "tools/release.py", *args], cwd=self.root, capture_output=True, text=True
        )
        self.assertEqual(result.returncode == 0, ok, result.stdout + result.stderr)
        return result

    def test_identity_and_repository_guards(self):
        for version in ["v0.5.1", "0.5.2", "0.5.1;evil", "../0.5.1"]:
            self.run_tool("--version", version, ok=False)
        for sha in ["bad", "0" * 40]:
            self.run_tool("--sha", sha, ok=False)
        self.run_tool("--repository", "https://example.invalid/repo", ok=False)
        (self.root / "package-lock.json").write_text("{}")
        self.run_tool(ok=False)

    def test_determinism_and_installation_allowlist(self):
        self.run_tool()
        archive = self.root / "dist/pagenest-compatibility-0.5.1.zip"
        first = hashlib.sha256(archive.read_bytes()).hexdigest()
        self.run_tool()
        self.assertEqual(first, hashlib.sha256(archive.read_bytes()).hexdigest())
        with zipfile.ZipFile(archive) as bundle:
            self.assertFalse(
                any(
                    "/tests/" in name
                    or "/node_modules/" in name
                    or "/.github/" in name
                    or "/tools/" in name
                    for name in bundle.namelist()
                )
            )
        with zipfile.ZipFile(archive) as bundle:
            self.assertNotIn("pagenest-compatibility/theme.json", bundle.namelist())
            self.assertNotIn("pagenest-compatibility/screenshot.png", bundle.namelist())
        self.run_tool("--check-package")

    def test_tampered_bundle_manifest_and_checksum(self):
        for name in [
            "pagenest-compatibility-0.5.1.zip",
            "pagenest-compatibility-0.5.1.manifest.json",
            "pagenest-compatibility-0.5.1.zip.sha256",
            "release-notes.md",
        ]:
            with self.subTest(name=name):
                self.run_tool()
                with (self.root / "dist" / name).open("ab") as output:
                    output.write(b"tampered")
                self.run_tool("--check-package", ok=False)

    def test_uncommitted_installation_source_rejected(self):
        (self.root / "index.php").write_text("<?php // dirty source\n")
        self.run_tool(ok=False)

    def test_current_changelog_required(self):
        for contents in ["## 0.5.2\n\nFuture notes\n\n## 0.5.1\n\nOld notes\n", "## 0.5.1\n\n"]:
            with self.subTest(contents=contents):
                (self.root / "CHANGELOG.md").write_text(contents)
                self.git("add", "CHANGELOG.md")
                self.git(
                    "-c",
                    "user.name=Fixture",
                    "-c",
                    "user.email=fixture@example.invalid",
                    "commit",
                    "-qm",
                    "changelog fixture",
                )
                result = self.run_tool(ok=False)
                self.assertRegex(result.stderr, "current changelog section|Empty release notes")
