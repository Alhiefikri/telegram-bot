import base64
import hashlib
import hmac
import json
from pathlib import Path
import subprocess
import sys
import unittest

import bcrypt
from argon2.low_level import Type, hash_secret

from lhie_candidate.compat.identity import (
    admin_cursor_scope, api_confirmation_digest, application_hmac,
    browser_nonce_binding, credential_fingerprint, credential_key_digest,
    customer_password_input, email_otp_digest, normalize_credential_identity,
    normalize_customer_email, parse_api_authorization, sha256_digest,
    signed_url_digest, verify_customer_password, verify_legacy_password,
    verify_recovery_key, waitlist_email_hash,
)


class LegacyIdentityTests(unittest.TestCase):
    @staticmethod
    def bcrypt_hash(value):
        stored = bcrypt.hashpw(value, bcrypt.gensalt(rounds=4)).decode()
        return "$2y$" + stored[4:]

    @staticmethod
    def argon_hash(value, algorithm):
        return hash_secret(value, b"p03-synthetic-salt", time_cost=2, memory_cost=1024,
                           parallelism=1, hash_len=32,
                           type=Type.I if algorithm == "argon2i" else Type.ID).decode()

    def test_customer_prehash_retains_bytes_after_bcrypt_boundary(self):
        first = "é" * 36 + "first-suffix"
        second = "é" * 36 + "second-suffix"
        stored = self.bcrypt_hash(customer_password_input(first))
        self.assertTrue(verify_customer_password(first, stored, "sha384-v1", algorithm="bcrypt"))
        self.assertFalse(verify_customer_password(second, stored, "sha384-v1", algorithm="bcrypt"))
        self.assertFalse(verify_customer_password(first, stored, "raw", algorithm="bcrypt"))
        self.assertTrue(len(customer_password_input(first)) == 64)
        expected = base64.b64encode(hashlib.sha384(b"customer-password-v1\0" + first.encode()).digest())
        self.assertTrue(hmac.compare_digest(customer_password_input(first), expected))

    def test_raw_customer_bcrypt_boundary_is_bytes_not_characters(self):
        for index, prefix in enumerate(("x" * 72, "é" * 36)):
            with self.subTest(case=index):
                stored = self.bcrypt_hash(prefix.encode())
                self.assertTrue(verify_customer_password(prefix, stored, "raw", algorithm="bcrypt"))
                self.assertFalse(verify_customer_password(prefix + "x", stored, "raw", algorithm="bcrypt"))
                # Admin/Owner direct Hash::check retains PHP's truncation, not Customer's guard.
                self.assertTrue(verify_legacy_password(prefix + "x", stored, algorithm="bcrypt"))

    def test_customer_scheme_is_not_inferred_from_hash_prefix(self):
        value = "p03-scheme-boundary"
        raw = self.bcrypt_hash(value.encode())
        prehashed = self.bcrypt_hash(customer_password_input(value))
        self.assertTrue(verify_customer_password(value, raw, "raw", algorithm="bcrypt"))
        self.assertFalse(verify_customer_password(value, raw, "sha384-v1", algorithm="bcrypt"))
        self.assertFalse(verify_customer_password(value, prehashed, "raw", algorithm="bcrypt"))
        self.assertFalse(verify_customer_password(value, raw, "unknown", algorithm="bcrypt"))
        self.assertFalse(verify_customer_password(value, raw, "", algorithm="bcrypt"))
        self.assertFalse(verify_legacy_password(value, raw, algorithm="argon2id"))
        for index, malformed in enumerate(("", "not-a-kdf", "$2y$04$short", "$argon2id$invalid")):
            with self.subTest(case=index):
                self.assertFalse(verify_legacy_password(value, malformed, algorithm="bcrypt"))
                self.assertFalse(verify_legacy_password(value, malformed, algorithm="argon2id"))
        with self.assertRaises(ValueError):
            verify_legacy_password(value, raw, algorithm="sha256")

    def test_argon_variants_keep_long_and_nul_password_bytes(self):
        value = "é" * 60 + "\0tail"
        for algorithm in ("argon2i", "argon2id"):
            with self.subTest(algorithm=algorithm):
                raw = self.argon_hash(value.encode(), algorithm)
                wrapped = self.argon_hash(customer_password_input(value), algorithm)
                self.assertTrue(verify_customer_password(value, raw, "raw", algorithm=algorithm))
                self.assertTrue(verify_customer_password(value, wrapped, "sha384-v1", algorithm=algorithm))
                self.assertFalse(verify_customer_password(value + "different", raw, "raw", algorithm=algorithm))
                self.assertFalse(verify_legacy_password(customer_password_input(value), raw, algorithm=algorithm))

    def test_recovery_normalizes_ascii_case_but_admin_password_does_not(self):
        stored = self.bcrypt_hash(b"LHIE-P03SYNTHETIC")
        self.assertTrue(verify_recovery_key(" \tlhie-p03synthetic\n", stored, algorithm="bcrypt"))
        self.assertFalse(verify_legacy_password("lhie-p03synthetic", stored, algorithm="bcrypt"))
        # Unicode whitespace is not part of PHP trim's default character list.
        self.assertFalse(verify_recovery_key("\u00a0lhie-p03synthetic", stored, algorithm="bcrypt"))
        nul_hash = self.bcrypt_hash(b"p03-prefix")
        self.assertTrue(verify_legacy_password(b"p03-prefix\0suffix", nul_hash, algorithm="bcrypt"))

    def test_literal_app_key_is_not_cipher_key_and_overrides_only_trim(self):
        key = "base64:" + base64.b64encode(b"p03-synthetic-key").decode()
        digest = application_hmac("p03-input", key)
        self.assertTrue(hmac.compare_digest(digest, hmac.new(key.encode(), b"p03-input", "sha256").hexdigest()))
        self.assertFalse(hmac.compare_digest(digest, hmac.new(base64.b64decode(key[7:]), b"p03-input", "sha256").hexdigest()))
        self.assertFalse(hmac.compare_digest(digest, application_hmac("p03-input", " " + key)))
        self.assertTrue(hmac.compare_digest(credential_key_digest(" \t" + key + "\n"), sha256_digest(key)))
        self.assertIsNone(credential_key_digest(" \0\t\n"))
        with self.assertRaises(ValueError):
            credential_fingerprint("auth_token", "p03-token", " \t")

    def test_credential_token_case_whitespace_and_kind_are_preserved(self):
        key = "p03-fingerprint-key"
        token = "  P03-Token-AbC  "
        normalized = normalize_credential_identity("auth_token", token)
        self.assertTrue(normalized == token)
        first = credential_fingerprint("auth_token", token, key)
        self.assertFalse(hmac.compare_digest(first, credential_fingerprint("auth_token", token.strip(), key)))
        self.assertFalse(hmac.compare_digest(first, credential_fingerprint("auth_token", token.lower(), key)))
        email = "p03@example.invalid"
        # Equal digest does not merge the separate (kind, fingerprint) registry keys.
        self.assertTrue(hmac.compare_digest(credential_fingerprint("email", email, key), credential_fingerprint("auth_token", email, key)))
        for index, (kind, value) in enumerate((("auth_token", " \t\0"), ("unknown", token))):
            with self.subTest(case=index), self.assertRaises(ValueError):
                normalize_credential_identity(kind, value)
        self.assertTrue(normalize_credential_identity("auth_token", "\u00a0") == "\u00a0")

    def test_credential_email_uses_php_validation_without_unicode_rewriting(self):
        valid = ((" \tCase.Tag+P03@Example.INVALID\n", "case.tag+p03@example.invalid"),
                 ('"A"."B"@Example.INVALID', '"a"."b"@example.invalid'),
                 ("p03@[IPv6:2001:db8::1]", "p03@[ipv6:2001:db8::1]"),
                 ("p03@[192.0.2.1]", "p03@[192.0.2.1]"))
        for index, (value, expected) in enumerate(valid):
            with self.subTest(case=index):
                self.assertTrue(normalize_credential_identity("email", value) == expected)
        invalid = ("é@example.invalid", "a..b@example.invalid", "a@localhost", "a@example.123",
                   "a@-bad.invalid", "x" * 65 + "@example.invalid", "\u00a0a@example.invalid",
                   "a@[IPv6:fe80::1%eth0]", "a@[192.000.2.1]")
        for index, value in enumerate(invalid):
            with self.subTest(case=index), self.assertRaises(ValueError):
                normalize_credential_identity("email", value)

    def test_waitlist_key_fallback_and_unicode_lower_are_distinct(self):
        email = " \tP03.É@Example.INVALID\n"
        key = " base64:p03-literal-app-key "
        normalized = normalize_customer_email(email)
        self.assertTrue(normalized == "p03.é@example.invalid")
        self.assertTrue(hmac.compare_digest(waitlist_email_hash(email, key, waitlist_key=" \t"), application_hmac(normalized, key)))
        self.assertTrue(hmac.compare_digest(waitlist_email_hash(email, key, waitlist_key=" p03-override\n"), application_hmac(normalized, "p03-override")))
        with self.assertRaises(ValueError):
            waitlist_email_hash(" \t", key)

    def test_api_shape_matching_does_not_lowercase_selector_or_secret(self):
        selector, secret = "Ab" * 16, "Cd" * 32
        parsed = parse_api_authorization("bEaReR CAPI_" + selector + "." + secret)
        self.assertTrue(parsed == (selector, secret))
        self.assertFalse(hmac.compare_digest(sha256_digest(secret), sha256_digest(secret.lower())))
        invalid = (None, "Bearer capi_" + selector + "." + secret + "\n",
                   " Bearer capi_" + selector + "." + secret,
                   "Bearer  capi_" + selector + "." + secret,
                   "Bearer capi_" + selector[:-1] + "." + secret)
        for index, value in enumerate(invalid):
            with self.subTest(case=index):
                self.assertIsNone(parse_api_authorization(value))

    def test_confirmation_challenge_and_session_domains_cannot_be_substituted(self):
        nonce = "Ac" * 16
        rotate = api_confirmation_digest(nonce, action="rotate")
        disable = api_confirmation_digest(nonce, action="disable")
        self.assertTrue(hmac.compare_digest(rotate, sha256_digest(nonce)))
        self.assertTrue(hmac.compare_digest(disable, sha256_digest("disable:" + nonce)))
        self.assertFalse(hmac.compare_digest(rotate, disable))
        self.assertFalse(hmac.compare_digest(rotate, sha256_digest(nonce.lower())))
        for value in (nonce + "x", "é" * 32):
            with self.assertRaises(ValueError):
                api_confirmation_digest(value, action="rotate")
        with self.assertRaises(ValueError):
            api_confirmation_digest(nonce, action="unknown")
        challenge = sha256_digest("a1" * 32)
        digest = email_otp_digest("p03@example.invalid", challenge, "000042", "p03-app-key")
        wrong_domains = (("other@example.invalid", challenge, "000042"),
                         ("p03@example.invalid", sha256_digest(challenge), "000042"),
                         ("p03@example.invalid", challenge, "42"))
        for index, (email, challenge_hash, code) in enumerate(wrong_domains):
            with self.subTest(case=index):
                self.assertFalse(hmac.compare_digest(digest, email_otp_digest(email, challenge_hash, code, "p03-app-key")))
        self.assertFalse(hmac.compare_digest(browser_nonce_binding("ab", "c"), browser_nonce_binding("a", "bc")))
        with self.assertRaises(ValueError):
            email_otp_digest("p03@example.invalid", challenge, "000042", "")

    def test_signed_url_and_admin_cursor_primitives_keep_serialization_bytes(self):
        url = "/p03/example?expires=2000000000&label=A%20B"
        key = "p03-literal-app-key"
        self.assertFalse(hmac.compare_digest(signed_url_digest(url, key), signed_url_digest(url.replace("%20", "+"), key)))
        self.assertFalse(hmac.compare_digest(signed_url_digest(url, key), signed_url_digest("https://candidate.invalid" + url, key)))
        args = dict(section="proof/é", currency="IDR", telegram_id=None, state="pending", limit=25)
        first = admin_cursor_scope("37", **args)
        self.assertFalse(hmac.compare_digest(first, admin_cursor_scope("38", **args)))
        self.assertFalse(hmac.compare_digest(first, admin_cursor_scope("37", **(args | {"limit": 50}))))
        self.assertFalse(hmac.compare_digest(first, admin_cursor_scope("37", **(args | {"telegram_id": ""}))))

    def test_real_php_bidirectional_vectors_emit_only_sanitized_counts(self):
        script = Path(__file__).resolve().parents[2] / "tools/migration_compat/identity_vectors.py"
        result = subprocess.run([sys.executable, str(script)], text=True, capture_output=True,
                                timeout=180, check=False)
        self.assertTrue(result.returncode == 0, "Synthetic PHP/Python identity verifier failed; output withheld.")
        summary = json.loads(result.stdout)
        self.assertTrue(summary["status"] == "passed")
        self.assertTrue(summary["php_to_python_password_vectors"] == 30)
        self.assertTrue(summary["python_to_php_password_vectors"] == 30)
        self.assertTrue(summary["wrong_password_vectors_each_direction"] == 30)
        self.assertTrue(summary["primitive_groups_each_direction"] == 17)
        self.assertIs(summary["synthetic_only"], True)
