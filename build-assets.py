"""Write immutable resources before replacing their manifest."""

from pathlib import Path
import hashlib

assets = Path(__file__).resolve().parent / "assets"
mapping = {}
for key, name in [
    ("comments_js", "paragraph-comments.js"),
    ("comments_css", "paragraph-comments.css"),
    ("likes_js", "likes.js"),
]:
    content = (assets / name).read_bytes()
    path = Path(name)
    target = path.stem + "-" + hashlib.sha256(content).hexdigest()[:12] + path.suffix
    (assets / target).write_bytes(content)
    mapping[key] = target
(assets / "manifest.php").write_text(
    "<?php\nreturn [\n"
    + "".join("    " + repr(key) + " => " + repr(name) + ",\n" for key, name in mapping.items())
    + "];\n"
)
