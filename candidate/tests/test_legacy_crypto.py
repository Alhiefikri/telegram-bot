import base64
import hashlib
import hmac
import importlib.util
import json
from pathlib import Path
import unittest
from unittest.mock import patch

from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes

from lhie_candidate.compat.crypto import (
    CryptoError,
    LaravelEncrypter,
    MAX_DEPTH,
    MAX_ENVELOPE_BYTES,
    MAX_ITEMS,
    MAX_PLAINTEXT_BYTES,
)


KEY = b"c" * 32
PREVIOUS = b"p" * 32
ROOT = Path(__file__).resolve().parents[2]


def wrap(payload):
    return base64.b64encode(json.dumps(payload, separators=(",", ":")).encode()).decode()


def unpack(envelope):
    return json.loads(base64.b64decode(envelope))


class LegacyCryptoTests(unittest.TestCase):
    def setUp(self):
        self.codec = LaravelEncrypter(KEY, [PREVIOUS])

    def assert_same(self, actual, expected):
        # Failed public test output must not render decoded values or envelopes.
        self.assertTrue(type(actual) is type(expected) and actual == expected, "legacy value mismatch")

    def test_string_unicode_nul_and_null_column(self):
        for value in (None, "", "مرحبا 日本語 🧪\0line\n", "fixture@example.invalid|" + "SYNTHETIC|" * 8192):
            self.assert_same(self.codec.decrypt_string(self.codec.encrypt_string(value)), value)
        self.assertIsNone(self.codec.encrypt_string(None))
        self.assertIsNone(self.codec.decrypt_string(None))

    def test_json_values_are_json_not_php_serialization(self):
        for value in (None, False, True, 0, 1.25, "日本語", [], {}, [None, False, 1], {"nested": {"nullable": None, "token": "synthetic" * 8192}}):
            self.assert_same(self.codec.decrypt_json(self.codec.encrypt_json(value)), value)
        self.assert_same(self.codec.decrypt_string(self.codec.encrypt_json([None])), "[null]")
        self.assertIsNone(self.codec.decrypt_json(self.codec.encrypt_string("null")))
        self.assertIsNone(self.codec.encrypt_json(None))

    def test_php_scalar_array_subset(self):
        for value in (None, False, True, -(2**63), 2**63 - 1, -0.0, 1.25e-20, "日本語\0🧪", [], [None, False, 7], {"account": "synthetic", 4: ["nested"], "04": None}):
            self.assert_same(self.codec.decrypt_php(self.codec.encrypt_php(value)), value)
        self.assertIsNotNone(self.codec.encrypt_php(None))
        self.assert_same(self.codec.decrypt_php(self.codec.encrypt_php({})), [])
        self.assert_same(self.codec.decrypt_string(self.codec.encrypt_php("日本語")), 's:9:"日本語";')

    def test_key_forms_rotation_and_current_key_writes(self):
        old = LaravelEncrypter(PREVIOUS)
        encoded = LaravelEncrypter("base64:" + base64.b64encode(KEY).decode(), ["p" * 32])
        for variant, value in (("string", "synthetic"), ("json", {"fixture": None}), ("php", [True, None])):
            prior = getattr(old, "encrypt_" + variant)(value)
            self.assert_same(getattr(encoded, "decrypt_" + variant)(prior), value)
            with self.assertRaises(CryptoError):
                getattr(LaravelEncrypter(KEY), "decrypt_" + variant)(prior)
            current = getattr(encoded, "encrypt_" + variant)(value)
            self.assert_same(getattr(LaravelEncrypter(KEY), "decrypt_" + variant)(current), value)
            with self.assertRaises(CryptoError):
                getattr(old, "decrypt_" + variant)(current)
        for key in (b"", b"x" * 31, b"x" * 33, None, "base64:!", "base64:" + base64.b64encode(b"x" * 31).decode()):
            with self.assertRaises(CryptoError):
                LaravelEncrypter(key)
        with self.assertRaises(CryptoError):
            LaravelEncrypter(KEY, [PREVIOUS] * 17)
        with self.assertRaises(CryptoError):
            LaravelEncrypter(KEY, PREVIOUS)

    def test_invalid_mac_never_constructs_cipher(self):
        valid = self.codec.encrypt_string("synthetic")
        cases = [(LaravelEncrypter(b"w" * 32), valid)]
        for field in ("iv", "value", "mac"):
            payload = unpack(valid)
            payload[field] = ("A" if payload[field][0] != "A" else "B") + payload[field][1:]
            cases.append((self.codec, wrap(payload)))
        for codec, envelope in cases:
            with patch("lhie_candidate.compat.crypto.Cipher", side_effect=AssertionError("cipher constructed before authentication")):
                with self.assertRaises(CryptoError):
                    codec.decrypt_string(envelope)

    def test_random_iv_and_legacy_missing_tag(self):
        left = self.codec.encrypt_string("synthetic")
        right = self.codec.encrypt_string("synthetic")
        self.assertTrue(left != right, "random IV was reused")
        payload = unpack(left)
        self.assertTrue(set(payload) == {"iv", "value", "mac", "tag"})
        del payload["tag"]
        self.assert_same(self.codec.decrypt_string(wrap(payload)), "synthetic")

    def test_malformed_envelopes(self):
        valid = unpack(self.codec.encrypt_string("synthetic"))
        invalid = [
            "", "!", "🌍", 1, b"bytes", wrap(None), wrap([]), wrap({}),
            base64.b64encode(b"{").decode(),
            wrap({**valid, "tag": "AAAA"}), wrap({**valid, "tag": None}),
            wrap({**valid, "extra": "unsupported"}), wrap({**valid, "iv": "!"}),
            wrap({**valid, "iv": base64.b64encode(b"short").decode()}),
            wrap({**valid, "value": ""}), wrap({**valid, "value": "!"}),
            wrap({**valid, "value": base64.b64encode(b"short").decode()}),
            wrap({**valid, "mac": 0}), wrap({**valid, "mac": "0" * 63}),
            "A" * (MAX_ENVELOPE_BYTES + 1),
        ]
        duplicate = '{"iv":"x","iv":"y","value":"x","mac":"x"}'
        invalid.append(base64.b64encode(duplicate.encode()).decode())
        for envelope in invalid:
            with self.assertRaises(CryptoError):
                self.codec.decrypt_string(envelope)

    def test_authenticated_invalid_padding_and_utf8(self):
        iv = b"i" * 16
        cipher = Cipher(algorithms.AES(KEY), modes.CBC(iv)).encryptor()
        ciphertext = cipher.update(b"\0" * 16) + cipher.finalize()
        iv_text, value = base64.b64encode(iv).decode(), base64.b64encode(ciphertext).decode()
        mac = hmac.new(KEY, (iv_text + value).encode(), hashlib.sha256).hexdigest()
        with self.assertRaises(CryptoError):
            self.codec.decrypt_string(wrap({"iv": iv_text, "value": value, "mac": mac, "tag": ""}))
        for variant in ("string", "json", "php"):
            with self.assertRaises(CryptoError):
                getattr(self.codec, "decrypt_" + variant)(self.codec._encrypt(b"\xff"))
        with self.assertRaises(CryptoError):
            self.codec.decrypt_json(self.codec._encrypt('"unicode"'.encode("utf-16")))

    def test_unsafe_or_malformed_php_never_deserializes_objects(self):
        values = (
            'O:8:"stdClass":0:{}', 'C:1:"X":0:{}', 'E:3:"X:Y";', "R:1;", "r:1;",
            "N;N;", "", "N", "b:2;", "i:9223372036854775808;", "i:+1;",
            "d:INF;", "d:NAN;", "d:1e999;", 's:1:"日本語";', 's:5:"short"',
            "a:99999999:{}", 'a:2:{i:0;N;i:0;N;}', 'a:1:{b:0;N;}',
            'a:1:{s:1:"1";N;}', "a:1:{i:0;N;", "x:1;",
        )
        for value in values:
            with self.assertRaises(CryptoError):
                self.codec.decrypt_php(self.codec.encrypt_string(value))

    def test_json_rejects_invalid_unsupported_and_over_limit(self):
        for value in ("{", "", "NaN", "Infinity", '{"x":1,"x":2}', "1e999", str(2**63), '"\\ud800"'):
            with self.assertRaises(CryptoError):
                self.codec.decrypt_json(self.codec.encrypt_string(value))
        for value in (object(), {1: "not-json-key"}, float("nan"), 2**63, "\ud800"):
            with self.assertRaises(CryptoError):
                self.codec.encrypt_json(value)
        for value in (object(), (1, 2), {True: 1}, {"1": 1}, float("inf"), 2**63):
            with self.assertRaises(CryptoError):
                self.codec.encrypt_php(value)

    def test_bounds_apply_both_directions(self):
        maximum = "x" * MAX_PLAINTEXT_BYTES
        self.assert_same(self.codec.decrypt_string(self.codec.encrypt_string(maximum)), maximum)
        with self.assertRaises(CryptoError):
            self.codec.encrypt_string(maximum + "x")
        with self.assertRaises(CryptoError):
            self.codec.encrypt_json([maximum, maximum])
        with self.assertRaises(CryptoError):
            self.codec.encrypt_php([maximum, maximum])
        nested = None
        for _ in range(MAX_DEPTH + 1):
            nested = [nested]
        for variant in ("json", "php"):
            with self.assertRaises(CryptoError):
                getattr(self.codec, "encrypt_" + variant)(nested)
        too_deep_json = "[" * (MAX_DEPTH + 1) + "null" + "]" * (MAX_DEPTH + 1)
        too_deep_php = "a:1:{i:0;" * (MAX_DEPTH + 1) + "N;" + "}" * (MAX_DEPTH + 1)
        for variant, value in (("json", too_deep_json), ("php", too_deep_php)):
            with self.assertRaises(CryptoError):
                getattr(self.codec, "decrypt_" + variant)(self.codec.encrypt_string(value))
        for variant in ("json", "php"):
            with self.assertRaises(CryptoError):
                getattr(self.codec, "encrypt_" + variant)([None] * MAX_ITEMS)
        with self.assertRaises(CryptoError):
            self.codec.decrypt_json(self.codec.encrypt_string("[" + ",".join(["null"] * MAX_ITEMS) + "]"))
        with self.assertRaises(CryptoError):
            self.codec.decrypt_php(self.codec.encrypt_string("a:5000:{" + "".join(f"i:{i};N;" for i in range(5000)) + "}"))
        cycle = []
        cycle.append(cycle)
        for variant in ("json", "php"):
            with self.assertRaises(CryptoError):
                getattr(self.codec, "encrypt_" + variant)(cycle)

    def test_real_php_framework_vectors_in_both_directions(self):
        path = ROOT / "tools/migration_compat/crypto_vectors.py"
        spec = importlib.util.spec_from_file_location("p03_crypto_vectors", path)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        manifest = module.run_vectors()
        self.assertTrue(manifest["outcome"] == "pass")
        self.assertTrue(all(row["outcome"] == "pass" for row in manifest["scenarios"]))
        allowed = {"id", "outcome", "scenarios", "source_checksums"}
        self.assertTrue(set(manifest) == allowed)
        self.assertTrue(all(set(row) == {"id", "outcome"} for row in manifest["scenarios"]))
