#!/usr/bin/env python3
"""Build an allowlisted, reproducible install ZIP, never a parent application tree."""
import hashlib
from pathlib import Path
import re
import zipfile

ROOT = Path(__file__).resolve().parents[1]
VERSION = '1.0.1'
KIND = 'prestashop'
SLUG = 'whollycrypto-' + KIND
FILES = {'README.md', 'LICENSE', 'CHANGELOG.md', 'SECURITY.md', 'whollycrypto.php'}
DIRECTORIES = {'lib', 'controllers', 'views', 'vendor', 'docs'}

def selected():
    paths = []
    for name in sorted(FILES | DIRECTORIES):
        target = ROOT / name
        if target.is_symlink() or not target.exists():
            raise SystemExit('Missing or linked release input: ' + name)
        for file in sorted(target.rglob('*')) if target.is_dir() else [target]:
            if file.is_symlink():
                raise SystemExit('Symlinks cannot be packaged')
            if file.is_dir():
                continue
            if not file.is_file() or file.stat().st_size > 500000:
                raise SystemExit('Unexpected release file')
            relative = file.relative_to(ROOT)
            if file.suffix not in ('.php', '.js', '.svg', '.md', '.json', '.txt', '.twig', ''):
                raise SystemExit('Unexpected extension: ' + str(relative))
            raw = file.read_bytes()
            if re.search(rb'github_pat_[A-Za-z0-9_]{20,}|ghp_[A-Za-z0-9]{20,}|-----BEGIN [^-]*PRIVATE KEY-----|\x2froot\x2f', raw, re.I):
                raise SystemExit('Private material pattern in ' + str(relative))
            paths.append((relative.as_posix(), raw))
    return paths

if __name__ == '__main__':
    files = selected()
    destination = ROOT / 'dist'
    destination.mkdir(exist_ok=True)
    archive = destination / (SLUG + '-' + VERSION + '.zip')
    with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as output:
        for relative, raw in sorted(files):
            info = zipfile.ZipInfo('whollycrypto/' + relative, (2026, 10, 6, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            output.writestr(info, raw)
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    (destination / 'SHA256SUMS').write_text(digest + '  ' + archive.name + '\n')
    with zipfile.ZipFile(archive) as check:
        if check.testzip() is not None or len(check.namelist()) != len(files):
            raise SystemExit('ZIP verification failed')
    print(f'{archive.name}: {len(files)} reviewed public files, {archive.stat().st_size} bytes, SHA256 {digest}')
