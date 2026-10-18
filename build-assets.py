"""Write immutable resources before replacing their manifest."""

from pathlib import Path
import hashlib

assets = Path(__file__).resolve().parent / "assets"
mapping = {}
for key, name in [
    ("admin_design_css", "admin-design.css"),
    ("admin_design_js", "admin-design.js"),
    ("performance_admin_css", "performance-admin.css"),
    ("performance_admin_js", "performance-admin.js"),
    ("discovery_admin_js", "discovery-admin.js"),
    ("reading_js", "reading-center.js"),
    ("reading_css", "reading-center.css"),
    ("guide_admin_js", "guide-admin.js"),
    ("guide_css", "guide.css"),
    ("comments_js", "paragraph-comments.js"),
    ("comments_css", "paragraph-comments.css"),
    ("likes_js", "likes.js"),
    ("services_turnstile_js", "services-turnstile.js"),
    ("social_qrcode_js", "social-qrcode.js"),
    ("social_share_js", "social-share.js"),
    ("social_ui_css", "social-ui.css"),
    ("content_css", "content.css"),
    ("content_js", "content.js"),
    ("content_spacing_js", "content-spacing.js"),
    ("media_js", "media-compatibility.js"),
    ("media_css", "media-compatibility.css"),
    ("media_editor_js", "media-editor.js"),
    ("media_library_js", "media-library.js"),
    ("media_library_css", "media-library.css"),
    ("media_folders_js", "media-folders.js"),
    ("media_folders_css", "media-folders.css"),
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
