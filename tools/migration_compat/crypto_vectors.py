#!/usr/bin/env python3
"""Offline synthetic PHP/Python envelope qualification; stdout is safe evidence."""

import argparse
import base64
import hashlib
import json
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "candidate"))

from lhie_candidate.compat.crypto import CryptoError, LaravelEncrypter


class VectorFailure(RuntimeError):
    pass


def _require(condition, scenario):
    if not condition:
        raise VectorFailure(scenario)


def _php(action, data=None, php="php"):
    try:
        result = subprocess.run(
            [php, str(Path(__file__).with_suffix(".php")), action],
            input=None if data is None else json.dumps(data),
            text=True, capture_output=True, check=True, timeout=30,
            cwd=ROOT,
        )
        return json.loads(result.stdout)
    except (OSError, subprocess.SubprocessError, ValueError):
        raise VectorFailure("php-oracle") from None


def _wrap(payload):
    return base64.b64encode(json.dumps(payload, separators=(",", ":")).encode()).decode()


def _rejected(codec, variant, envelope):
    try:
        getattr(codec, "decrypt_" + variant)(envelope)
    except CryptoError:
        return True
    return False


def negative_scenarios(codec):
    valid = codec.encrypt_string("synthetic-negative-probe")
    payload = json.loads(base64.b64decode(valid))
    results = []

    def reject(identifier, envelope, variant="string", decoder=codec):
        _require(_rejected(decoder, variant, envelope), identifier)
        results.append({"id": identifier, "outcome": "pass"})

    reject("wrong-key", valid, decoder=LaravelEncrypter(b"w" * 32))
    reject("missing-previous-key", LaravelEncrypter(b"p" * 32).encrypt_string("synthetic"), decoder=LaravelEncrypter(b"c" * 32))
    for field in ("iv", "value", "mac"):
        changed = dict(payload)
        changed[field] = ("A" if changed[field][0] != "A" else "B") + changed[field][1:]
        reject("tamper-" + field, _wrap(changed))
    reject("malformed-outer-base64", "!")
    reject("malformed-outer-json", base64.b64encode(b"not-json").decode())
    reject("malformed-shape", _wrap([]))
    reject("unsupported-tag", _wrap({**payload, "tag": "AAAA"}))
    reject("unsupported-extra-field", _wrap({**payload, "cipher": "AES-256-GCM"}))
    reject("malformed-inner-base64", _wrap({**payload, "value": "!"}))
    reject("malformed-json-plaintext", codec.encrypt_string("{"), "json")
    reject("unsupported-json-nan", codec.encrypt_string("NaN"), "json")
    reject("duplicate-json-key", codec.encrypt_string('{"a":1,"a":2}'), "json")
    for identifier, raw in (
        ("php-object", 'O:8:"stdClass":0:{}'),
        ("php-custom-object", 'C:1:"X":0:{}'),
        ("php-reference", "R:1;"),
        ("php-enum", 'E:3:"X:Y";'),
        ("php-trailing", "N;N;"),
        ("php-string-length", 's:9:"short";'),
        ("php-duplicate-key", 'a:2:{i:0;N;i:0;N;}'),
        ("php-nonfinite", "d:INF;"),
    ):
        reject(identifier, codec.encrypt_string(raw), "php")
    # Optional absent tag is used by older Laravel CBC writers.
    legacy = dict(payload)
    del legacy["tag"]
    _require(codec.decrypt_string(_wrap(legacy)) == "synthetic-negative-probe", "legacy-absent-tag")
    results.append({"id": "legacy-absent-tag", "outcome": "pass"})
    return results


def run_vectors(php="php"):
    codec = LaravelEncrypter("base64:" + base64.b64encode(b"c" * 32).decode(), [b"p" * 32])
    source = _php("generate", php=php)
    outbound = []
    results = []
    for row in source:
        identifier, variant = row["id"], row["variant"]
        actual = getattr(codec, "decrypt_" + variant)(row["envelope"])
        _require(type(actual) is type(row["expected"]) and actual == row["expected"], identifier)
        outbound.append({"id": identifier, "envelope": getattr(codec, "encrypt_" + variant)(actual)})
        results.append({"id": "php-to-python/" + identifier, "outcome": "pass"})
    returned = _php("verify", outbound, php=php)
    _require([item["id"] for item in returned] == [item["id"] for item in outbound], "php-response-shape")
    for row in returned:
        _require(row["outcome"] == "pass", row["id"])
        results.append({"id": "python-to-php/" + row["id"], "outcome": "pass"})
    results.extend(negative_scenarios(codec))
    sources = {
        "codec": ROOT / "candidate/lhie_candidate/compat/crypto.py",
        "python-oracle": Path(__file__),
        "php-oracle": Path(__file__).with_suffix(".php"),
        "framework-encrypter": ROOT / "src/vendor/laravel/framework/src/Illuminate/Encryption/Encrypter.php",
        "framework-casts": ROOT / "src/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php",
        "framework-key-provider": ROOT / "src/vendor/laravel/framework/src/Illuminate/Encryption/EncryptionServiceProvider.php",
    }
    return {
        "id": "p03-synthetic-crypto-v1",
        "outcome": "pass",
        "scenarios": results,
        "source_checksums": {identifier: hashlib.sha256(path.read_bytes()).hexdigest() for identifier, path in sources.items()},
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", default="php", help="PHP CLI executable (not an input/key path)")
    args = parser.parse_args()
    try:
        manifest = run_vectors(args.php)
    except Exception:
        print(json.dumps({"id": "p03-synthetic-crypto-v1", "outcome": "fail"}))
        return 1
    print(json.dumps(manifest, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
