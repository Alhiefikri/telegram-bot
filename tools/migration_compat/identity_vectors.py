#!/usr/bin/env python3
"""Synthetic PHP/Python identity vectors. No files, ENV or network are imported."""

import argparse
import base64
import json
from pathlib import Path
import secrets
import subprocess
import sys

import bcrypt
from argon2.low_level import Type, hash_secret

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "candidate"))
from lhie_candidate.compat.identity import (
    admin_cursor_scope, api_confirmation_digest, application_hmac,
    browser_nonce_binding, credential_fingerprint, credential_key_digest,
    customer_password_input, email_otp_digest, normalize_credential_identity,
    normalize_customer_email, sha256_digest, signed_url_digest,
    verify_customer_password, verify_legacy_password, verify_recovery_key,
    waitlist_email_hash,
)

APP_KEY = "base64:cDAzLXN5bnRoZXRpYy1hcHAta2V5LW9ubHktMDAwMDA="
IDENTITY_KEY = " \tp03-synthetic-identity-key\n"
ALGORITHMS = ("bcrypt", "argon2i", "argon2id")
FIXTURES = {
    "customer-short": ("P03-only password!é", "sha384-v1", "customer"),
    "customer-long": ("long-p03-" * 20, "sha384-v1", "customer"),
    "customer-nul": ("synthetic\0suffix", "sha384-v1", "customer"),
    "raw-72": ("x" * 72, "raw", "customer"),
    "raw-73": ("x" * 73, "raw", "customer"),
    "raw-utf8-72": ("é" * 36, "raw", "customer"),
    "raw-utf8-73": ("é" * 36 + "x", "raw", "customer"),
    "admin": ("P03 AdminCase!", "raw", "admin"),
    "admin-long": ("admin-p03-" * 12, "raw", "admin"),
    "recovery": (" \tlhie-p03syntheticrecovery\n", "raw", "recovery"),
}


class VectorFailure(Exception):
    pass


def primitive_vectors() -> dict:
    values = [
        ("email", " \tCase.Tag+P03@Example.INVALID\n"),
        ("email", '"case"."tag"@example.invalid'),
        ("email", "case@[IPv6:2001:db8::1]"),
        ("email", "case@[192.0.2.1]"),
        ("auth_token", "  P03-AbC-token  "),
        ("auth_token", "p03-abc-token"),
        ("auth_token", "Case.Tag+P03@Example.INVALID"),
    ]
    invalid = ["user@localhost", "double..dot@example.invalid", "é@example.invalid",
               "user@example.123", "user@-bad.invalid", "x" * 65 + "@example.invalid",
               "\u00a0user@example.invalid"]
    invalid_results = []
    for value in invalid:
        try:
            normalize_credential_identity("email", value)
        except ValueError:
            invalid_results.append([value, True])
        else:
            invalid_results.append([value, False])
    email = " \tP03.É@Example.INVALID\n"
    challenge = sha256_digest("a1" * 32)
    unsigned_url = "/p03/synthetic-order?expires=2000000000&label=A%20B%2F%C3%A9"
    return {
        "identities": [[kind, value, normalize_credential_identity(kind, value), credential_fingerprint(kind, value, IDENTITY_KEY)] for kind, value in values],
        "invalid_emails": invalid_results,
        "key_digest": credential_key_digest(IDENTITY_KEY),
        "waitlist_normalized": normalize_customer_email(email),
        "waitlist_fallback": waitlist_email_hash(email, APP_KEY),
        "waitlist_override": waitlist_email_hash(email, APP_KEY, waitlist_key=" \tp03-waitlist-override\n"),
        "api_secret": sha256_digest("Ab" * 32),
        "api_rotate": api_confirmation_digest("Ac" * 16, action="rotate"),
        "api_disable": api_confirmation_digest("Ac" * 16, action="disable"),
        "challenge": challenge,
        "otp": email_otp_digest("p03@example.invalid", challenge, "000042", APP_KEY),
        "browser_nonce_binding": browser_nonce_binding("p03-session", "p03-nonce"),
        "session_hash": sha256_digest("p03-session"),
        "admin_cursor_scope": admin_cursor_scope("37", section="proof/é", currency="IDR", telegram_id=None, state="pending", limit=25),
        "signed_url_unsigned": unsigned_url,
        "signed_url_digest": signed_url_digest(unsigned_url, APP_KEY),
        "literal_hmac": application_hmac("p03-literal-key", APP_KEY),
    }


def fixture_input(fixture: tuple[str, str, str]) -> bytes:
    password, scheme, consumer = fixture
    if consumer == "recovery":
        return password.strip(" \t\n\r\0\v").upper().encode()
    return customer_password_input(password) if scheme == "sha384-v1" else password.encode()


def python_records() -> list[dict]:
    records = []
    for fixture_id, fixture in FIXTURES.items():
        for algorithm in ALGORITHMS:
            value = fixture_input(fixture)
            if algorithm == "bcrypt":
                stored = bcrypt.hashpw(value[:72], bcrypt.gensalt(rounds=4)).decode()
                # PHP's algorithm guard recognizes its native $2y$ identifier.
                stored = "$2y$" + stored[4:]
            else:
                stored = hash_secret(value, secrets.token_bytes(16), time_cost=2,
                                     memory_cost=1024, parallelism=1, hash_len=32,
                                     type=Type.I if algorithm == "argon2i" else Type.ID).decode()
            records.append({"id": fixture_id, "algorithm": algorithm, "hash": stored})
    return records


def verify_record(fixture: tuple[str, str, str], record: dict) -> bool:
    password, scheme, consumer = fixture
    arguments = {"algorithm": record["algorithm"]}
    if consumer == "customer":
        return verify_customer_password(password, record["hash"], scheme, **arguments)
    if consumer == "recovery":
        return verify_recovery_key(password, record["hash"], **arguments)
    return verify_legacy_password(password, record["hash"], **arguments)


def check_php_records(records: list[dict]) -> int:
    seen = set()
    for record in records:
        pair = (record["id"], record["algorithm"])
        if pair in seen or pair[0] not in FIXTURES or pair[1] not in ALGORITHMS:
            raise VectorFailure("Unexpected PHP password vector.")
        seen.add(pair)
        fixture = FIXTURES[pair[0]]
        password, scheme, consumer = fixture
        expected = not (consumer == "customer" and scheme == "raw" and pair[1] == "bcrypt" and len(password.encode()) > 72)
        if verify_record(fixture, record) != expected:
            raise VectorFailure("PHP password consumer mismatch.")
        if verify_record(("wrong-" + password, scheme, consumer), record):
            raise VectorFailure("Wrong password accepted.")
    if seen != {(fixture_id, algorithm) for fixture_id in FIXTURES for algorithm in ALGORITHMS}:
        raise VectorFailure("PHP password coverage missing.")
    return len(seen)


def php_call(php: str, payload: dict) -> dict:
    result = subprocess.run([php, str(Path(__file__).with_suffix(".php"))],
                            input=json.dumps(payload), text=True, capture_output=True,
                            cwd=ROOT, timeout=180, check=False)
    if result.returncode != 0:
        raise VectorFailure("Synthetic PHP identity oracle failed; output withheld.")
    try:
        return json.loads(result.stdout)
    except json.JSONDecodeError:
        raise VectorFailure("Synthetic PHP identity oracle returned invalid JSON.") from None


def run_vectors(php: str = "php") -> dict:
    source = php_call(php, {"operation": "emit"})
    primitives = primitive_vectors()
    if source["primitives"] != primitives:
        raise VectorFailure("PHP/Python identity primitive mismatch.")
    checked = check_php_records(source["records"])
    target = php_call(php, {"operation": "check", "records": python_records(), "primitives": primitives})
    if target != {"password_vectors": checked, "primitive_groups": len(primitives)}:
        raise VectorFailure("PHP reverse identity coverage mismatch.")
    # Decoded cipher bytes must never accidentally become a literal APP_KEY HMAC.
    decoded = base64.b64decode(APP_KEY[7:])
    if application_hmac("p03-literal-key", APP_KEY) == application_hmac("p03-literal-key", decoded.decode()):
        raise VectorFailure("Literal and decoded key families collapsed.")
    return {"status": "passed", "php_to_python_password_vectors": checked,
            "python_to_php_password_vectors": checked, "primitive_groups_each_direction": len(primitives),
            "wrong_password_vectors_each_direction": checked, "synthetic_only": True}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", default="php", help="Local PHP executable with source Composer dependencies")
    args = parser.parse_args()
    try:
        result = run_vectors(args.php)
    except (VectorFailure, OSError, subprocess.SubprocessError, KeyError, TypeError, ValueError):
        print("Synthetic identity compatibility failed; secret-bearing details withheld.", file=sys.stderr)
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
