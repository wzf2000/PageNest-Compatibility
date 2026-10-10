"""Import the pinned upstream PDF viewer without sample PDFs or development maps."""

import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parent.parent
VERSION = "6.4.299"
SHA256 = "c894bce53b2f4c3e13141e088c89cc051a1f7118ba4a2c02ec058798ae1303d1"
WORKER = "build/pdf.worker.mjs"
# Packaging-only escapes in embedded statistical-model strings. Runtime values
# remain identical, verified with the JavaScript parser before any files are copied.
ESCAPE_OFFSETS = [473728, 501919, 514016, 533972, 546816, 596470]


def escaped_worker(original):
    text = original.decode()
    rewritten = text
    for offset in reversed(ESCAPE_OFFSETS):
        assert rewritten[offset] == "w", "Unexpected upstream literal"
        rewritten = rewritten[:offset] + "\\u0077" + rewritten[offset + 1 :]
    script = """
const fs = require('fs');
const parser = require('prettier/plugins/babel').parsers.babel;
function strings(file) {
  const result = [];
  function visit(node) {
    if (!node || typeof node !== 'object') return;
    if (node.type === 'StringLiteral') result.push([node.start, node.end, node.value]);
    for (const [key, value] of Object.entries(node)) {
      if (!['loc', 'extra', 'comments', 'tokens'].includes(key)) {
        if (Array.isArray(value)) value.forEach(visit); else visit(value);
      }
    }
  }
  visit(parser.parse(fs.readFileSync(file, 'utf8')));
  return result;
}
const original = strings(process.argv[1]);
const rewritten = strings(process.argv[2]);
const offsets = JSON.parse(process.argv[3]);
if (!offsets.every(offset => original.some(([start, end]) => start < offset && offset < end)))
  throw new Error('Escape outside a string literal');
if (JSON.stringify(original.map(item => item[2])) !== JSON.stringify(rewritten.map(item => item[2])))
  throw new Error('Decoded upstream string changed');
"""
    with tempfile.TemporaryDirectory() as directory:
        before = Path(directory) / "before.mjs"
        after = Path(directory) / "after.mjs"
        before.write_text(text)
        after.write_text(rewritten)
        subprocess.run(
            ["node", "-e", script, str(before), str(after), json.dumps(ESCAPE_OFFSETS)],
            cwd=ROOT,
            check=True,
            capture_output=True,
        )
    return rewritten.encode()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("archive", type=Path)
    args = parser.parse_args()
    assert hashlib.sha256(args.archive.read_bytes()).hexdigest() == SHA256
    output = ROOT / "assets" / "vendor" / ("pdfjs-" + VERSION)
    files = {}
    with zipfile.ZipFile(args.archive) as archive:
        assert archive.testzip() is None, "Corrupt upstream archive"
        for info in archive.infolist():
            path = Path(info.filename)
            assert not path.is_absolute() and ".." not in path.parts
            assert (info.external_attr >> 16) & 0o170000 != 0o120000, "Symlink in archive"
            if info.is_dir() or path.suffix in {".map", ".pdf"}:
                continue
            assert path.parts[0] in {"LICENSE", "build", "web"}
            data = archive.read(info)
            files[info.filename] = escaped_worker(data) if info.filename == WORKER else data
    # Preserve ES module semantics while using widely supported JavaScript MIME mappings.
    renamed = {name: name[:-4] + ".js" for name in files if name.endswith(".mjs")}
    rewritten = {}
    for name, data in files.items():
        if Path(name).suffix in {".mjs", ".js", ".html"}:
            for old, new in renamed.items():
                data = data.replace(Path(old).name.encode(), Path(new).name.encode())
        rewritten[renamed.get(name, name)] = data
    files = rewritten
    # Keep the license and make the packaging modification explicit.
    files["NOTICE.md"] = (
        "# PDF.js viewer\n\n"
        f"Mozilla PDF.js {VERSION}, legacy distribution, Apache-2.0.\n\n"
        f"Source: https://github.com/mozilla/pdf.js/releases/tag/v{VERSION}\n\n"
        "All upstream license files are retained. Sample PDFs and development source maps "
        "are excluded. Six ASCII letters in embedded worker string literals use equivalent "
        "Unicode escapes for package text hygiene; the JavaScript parser verifies identical "
        "decoded strings. Module files and their references use .js extensions so standard "
        "web servers serve JavaScript MIME types; ES module semantics are retained. Reproduce with "
        "`python3 tools/vendor-pdfjs.py /path/to/pdfjs-6.4.299-legacy-dist.zip`.\n"
    ).encode()
    manifest = {
        "version": VERSION,
        "variant": "legacy",
        "upstream_archive_sha256": SHA256,
        "worker_unicode_escape_offsets": ESCAPE_OFFSETS,
        "module_filename_mapping": renamed,
        "files": {name: hashlib.sha256(data).hexdigest() for name, data in sorted(files.items())},
    }
    files["vendor-manifest.json"] = (json.dumps(manifest, indent=2, sort_keys=True) + "\n").encode()
    for old in renamed:
        (output / old).unlink(missing_ok=True)
    for name, data in files.items():
        target = output / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)
    print(f"Imported {len(files)} pinned viewer files; decoded worker literals verified.")


if __name__ == "__main__":
    main()
