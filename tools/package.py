from pathlib import Path
import hashlib
import re
import zipfile

root = Path(__file__).resolve().parent.parent
slug = "wzf-theme-bridge"
header = root / ("style.css" if slug == "wzf-journal" else "wzf-theme-bridge.php")
version = re.search(r"Version:\s*([0-9.]+)", header.read_text()).group(1)
output = root / "dist" / f"{slug}-{version}.zip"
output.parent.mkdir(exist_ok=True)
files = [
    p
    for p in root.iterdir()
    if p.is_file()
    and (
        p.suffix == ".php"
        or p.name in {"style.css", "theme.json", "screenshot.png", "LICENSE", "README.md"}
    )
]
if (root / "assets").exists():
    files += [p for p in (root / "assets").rglob("*") if p.is_file()]
with zipfile.ZipFile(output, "w", zipfile.ZIP_DEFLATED) as archive:
    for item in sorted(files):
        archive.write(item, Path(slug) / item.relative_to(root))
with zipfile.ZipFile(output) as archive:
    assert archive.testzip() is None
print(f"{output.name}: {len(files)} files")
print(hashlib.sha256(output.read_bytes()).hexdigest())
