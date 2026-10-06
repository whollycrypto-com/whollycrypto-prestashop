#!/usr/bin/env python3
"""Check reproducible install archives and the bundled public SDK provenance."""
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import zipfile

root = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("builder", root / "tools/build.py")
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)
prefix = "whollycrypto/" if builder.KIND == "prestashop" else ""
vendor = root / ("vendor/whollycrypto" if prefix else "system/library/whollycrypto/vendor/whollycrypto")
upstream = json.loads((vendor / "UPSTREAM.json").read_text())
namespace = b"WhollyCrypto\\PrestaShop\\Sdk"
for name, digest in upstream["upstream_sha256"].items():
    content = (vendor / name).read_bytes().replace(namespace, b"WhollyCrypto")
    assert hashlib.sha256(content).hexdigest() == digest, "Bundled SDK differs beyond namespace: " + name
subprocess.run(["python3", str(root / "tools/build.py")], check=True)
archive = root / "dist" / ("whollycrypto-prestashop-" + builder.VERSION + ".zip" if prefix else "whollycrypto.ocmod.zip")
first = archive.read_bytes()
subprocess.run(["python3", str(root / "tools/build.py")], check=True)
assert archive.read_bytes() == first, "Archive must reproduce byte-for-byte"
with tempfile.TemporaryDirectory(prefix="wholly-package-test-") as temporary:
    with zipfile.ZipFile(archive) as package:
        expected = {prefix + name: data for name, data in builder.selected()}
        assert set(package.namelist()) == set(expected)
        assert package.testzip() is None
        for name, data in expected.items():
            assert not name.startswith("/") and ".." not in Path(name).parts
            assert package.read(name) == data, "Unexpected packaged bytes"
        package.extractall(temporary)
    loader = Path(temporary) / prefix / ("lib/autoload.php" if prefix else "system/library/whollycrypto/lib/autoload.php")
    subprocess.run(["php", "-r", "require $argv[1]; if (!class_exists($argv[2]) || $argv[2]::decimal('25.00') !== '25') exit(1);",
                    str(loader), "WhollyCrypto\\PrestaShop\\Protocol"], check=True)
print("Package: allowlist, CRC, reproducible bytes, SDK provenance and extracted autoloader passed.")
