#!/usr/bin/env python3
"""
Builds the site update zip from this project, ready for Admin → Settings → Update.

    python make_update.py                 # bumps the version (YYYY.MM.DD-N) and builds the zip
    python make_update.py --no-bump       # keeps the current version
    python make_update.py --version 2.0   # sets a specific version

Output: dist/site-update-<version>.zip

What goes in: everything in public_html/ (code, styles, .htaccess files).
What never goes in: your uploaded images (public_html/media/<model>/...), private/,
editor/OS junk. The Update page on the site skips media/ and private/ anyway.
"""
import argparse
import datetime as dt
import re
import shutil
import subprocess
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent
SITE = ROOT / "public_html"
VERSION_FILE = SITE / "app" / "version.php"
DIST = ROOT / "dist"
JUNK = {".DS_Store", "Thumbs.db", "desktop.ini"}
ALLOWED = {".php", ".js", ".css", ".svg", ".png", ".jpg", ".jpeg", ".webp", ".gif", ".ico", ".txt", ".md", ".json", ".woff2"}


def read_version() -> str:
    m = re.search(r"M3D_VERSION = '([^']+)'", VERSION_FILE.read_text(encoding="utf-8"))
    if not m:
        sys.exit(f"Could not find M3D_VERSION in {VERSION_FILE}")
    return m.group(1)


def next_version(current: str) -> str:
    today = dt.date.today().strftime("%Y.%m.%d")
    m = re.fullmatch(r"(\d{4}\.\d{2}\.\d{2})-(\d+)", current)
    n = int(m.group(2)) + 1 if m and m.group(1) == today else 1
    return f"{today}-{n}"


def write_version(version: str) -> None:
    text = VERSION_FILE.read_text(encoding="utf-8")
    VERSION_FILE.write_text(re.sub(r"M3D_VERSION = '[^']+'", f"M3D_VERSION = '{version}'", text), encoding="utf-8")


def included(rel: Path) -> bool:
    parts = rel.parts
    if rel.name in JUNK or rel.name.endswith((".tmp", ".bak", ".swp")) or ".upd-" in rel.name:
        return False
    if parts[0] == "media":                      # uploads stay on the server
        return rel.as_posix() == "media/.htaccess"
    if parts[0] == "private" or rel.name.startswith("zz_"):
        return False
    return rel.name == ".htaccess" or rel.suffix.lower() in ALLOWED


def php_lint(files) -> None:
    php = shutil.which("php")
    if not php:
        print("  (php not found on this computer: skipping the syntax check)")
        return
    bad = []
    for f in files:
        if f.suffix == ".php":
            r = subprocess.run([php, "-l", str(f)], capture_output=True, text=True)
            if r.returncode != 0:
                bad.append((f, (r.stdout + r.stderr).strip()))
    if bad:
        for f, msg in bad:
            print(f"  ✕ {f.relative_to(ROOT)}: {msg}")
        sys.exit("PHP syntax errors found: fix them before uploading (nothing was built).")
    print("  PHP syntax check passed")


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--no-bump", action="store_true", help="keep the current version number")
    ap.add_argument("--version", help="set this version instead of bumping")
    args = ap.parse_args()

    if not (SITE / "app" / "bootstrap.php").is_file():
        sys.exit("Run this from the project folder (public_html/app/bootstrap.php not found).")

    files = sorted(p for p in SITE.rglob("*") if p.is_file() and included(p.relative_to(SITE)))
    php_lint(files)

    old = read_version()
    new = args.version or (old if args.no_bump else next_version(old))
    if new != old:
        write_version(new)
    print(f"  Version: {old} → {new}" if new != old else f"  Version: {new}")

    DIST.mkdir(exist_ok=True)
    out = DIST / f"site-update-{new}.zip"
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        for p in files:
            z.write(p, p.relative_to(SITE).as_posix())
    print(f"  Built {out.relative_to(ROOT)} ({len(files)} files, {out.stat().st_size // 1024} KB)")
    print("  Install it from the site: Admin → Settings → Update → choose this file → Install update.")


if __name__ == "__main__":
    main()
