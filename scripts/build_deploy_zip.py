#!/usr/bin/env python3
"""Build the Elastic Beanstalk deploy zip for PRISM from committed files.

Usage: python3 scripts/build_deploy_zip.py [output.zip] [previous-working.zip]

What it does
- Zips every git-tracked file (current contents on disk) except data/ and docs/ (same as past deploys).
- Stores files as 644 and scripts (artisan, *.sh) as 755. Owner-only modes
  (600/700) make the web server unable to read CSS, fonts and brand files,
  and the live site shows up unstyled. This script never uses them.
- Checks the branding and layout files are inside, that no secrets or
  vendor folders are inside, and (if given) compares with a previous zip.

Run it after you commit, so the zip matches GitHub. Edited tracked files are zipped as they are on disk. New untracked files are not zipped.
"""
import datetime
import stat
import subprocess
import sys
import zipfile

MUST_HAVE = [
    "public/css/prism.css", "public/index.php", "public/.htaccess",
    "public/favicon.ico", "public/brand/favicon.svg",
    "public/brand/site.webmanifest", "public/brand/apple-touch-icon.png",
    "public/fonts/ibm-plex-sans-latin-400-normal.woff2",
    "public/fonts/ibm-plex-sans-latin-600-normal.woff2",
    "public/build/manifest.json", "resources/views/partials/brand-head.blade.php",
    ".ebextensions/01-laravel.config", ".platform/hooks/postdeploy/01_override_log_db.sh",
    ".platform/nginx/conf.d/elasticbeanstalk/laravel.conf",
    ".ebextensions/02-https.config", ".platform/hooks/postdeploy/02_https.sh",
    ".platform/nginx/conf.d/elasticbeanstalk/acme.conf",
    "composer.json", "composer.lock", "artisan", "bootstrap/app.php",
]
FORBIDDEN_PREFIXES = (".env", ".git-credentials", "vendor/", "node_modules/", ".git/")


def main():
    out = sys.argv[1] if len(sys.argv) > 1 else "deploy-%s.zip" % datetime.date.today().isoformat()
    previous = sys.argv[2] if len(sys.argv) > 2 else None

    dirty = subprocess.check_output(["git", "status", "--short"], text=True).strip()
    if dirty:
        print("WARNING: uncommitted changes. Edited tracked files ARE in the zip as they are on disk. New untracked files (??) are NOT. Commit first so the zip matches GitHub:\n" + dirty)

    files = subprocess.check_output(["git", "ls-files"], text=True).splitlines()
    files = [f for f in files if not f.startswith(("data/", "docs/"))]

    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        for f in files:
            info = zipfile.ZipInfo.from_file(f, f)
            info.compress_type = zipfile.ZIP_DEFLATED
            mode = 0o755 if (f == "artisan" or f.endswith(".sh")) else 0o644
            info.external_attr = (stat.S_IFREG | mode) << 16
            with open(f, "rb") as fh:
                z.writestr(info, fh.read())

    problems = []
    with zipfile.ZipFile(out) as z:
        names = [i.filename for i in z.infolist()]
        for m in MUST_HAVE:
            if m not in names:
                problems.append("missing: " + m)
        for n in names:
            if n.startswith(FORBIDDEN_PREFIXES) and n != ".env.example":
                problems.append("must not be inside: " + n)
        for i in z.infolist():
            perm = (i.external_attr >> 16) & 0o777
            if perm & 0o044 != 0o044:
                problems.append("not world-readable (%o): %s" % (perm, i.filename))
        print("%d files in %s" % (len(names), out))
    if previous:
        with zipfile.ZipFile(previous) as p, zipfile.ZipFile(out) as n:
            old, new = set(p.namelist()), set(n.namelist())
            print("added vs previous:", sorted(new - old))
            print("removed vs previous:", sorted(old - new))
            if old - new:
                problems.append("files removed vs previous zip")

    if problems:
        print("\nPROBLEMS:\n- " + "\n- ".join(problems))
        sys.exit(1)
    print("OK: branding, layout, config and permissions checks passed.")


if __name__ == "__main__":
    main()
