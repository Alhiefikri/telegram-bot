# P03 Laravel envelope compatibility

## Scope and source contract

`candidate/lhie_candidate/compat/crypto.py` implements the configured Laravel AES-256-CBC envelope. It takes explicit keys and performs no environment, Django, database, network or application bootstrap work. This slice uses synthetic values and keys only. Successful synthetic qualification does not establish deployed framework versions, production key coverage, production row shapes or permission to import real data.

The reviewed installed sources are:

- `src/vendor/laravel/framework/src/Illuminate/Encryption/Encrypter.php`: `encrypt`, `encryptString`, `decrypt`, `getJsonPayload`, `validPayload`, `validMacForKey`, `ensureTagIsValid`, and `previousKeys`.
- `src/vendor/laravel/framework/src/Illuminate/Encryption/EncryptionServiceProvider.php`: `parseKey` and current/previous key construction.
- `src/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php`: null handling, JSON-before-encryption writes, encrypted reads, and `encryptUsing`.
- `src/app/Services/Customer/CustomerBalanceActivityCursorToken.php`: ordered JSON carried inside an encrypted string. The fixture covers byte preservation, not cursor authorization or customer/currency/rank validation.
- [P02 encrypted-field and key-family contract](../p02/contracts.md#3-encrypted-fields-and-explicit-crypt-call-sites).

The envelope is canonical base64 of a JSON object containing base64 `iv`, base64 `value`, lowercase hexadecimal `mac`, and empty `tag`. CBC uses a fresh random 16-byte IV and PKCS7 padding. The MAC is HMAC-SHA256 over the ASCII concatenation of the stored base64 IV and ciphertext strings, using the decoded cipher key. It is not an HMAC using the literal `base64:` configuration string.

The codec validates envelope shape and bounds, checks the MAC against every configured key using `compare_digest`, and only then constructs the CBC decryptor or padding decoder. Writes always use the current key. Previous keys authorize reads only. Invalid authentication, padding, UTF-8, JSON, unsupported PHP tokens and bounds violations raise `CryptoError` with a fixed message; callers must reject the row, not use a fallback value. This is not a constant-time endpoint claim for all malformed inputs: structural rejection and key selection still execute Python control flow.

## Explicit API

Import `LaravelEncrypter` and `CryptoError` from `lhie_candidate.compat.crypto`.

`LaravelEncrypter(current_key, previous_keys=())` accepts raw 32-byte keys, literal UTF-8 key strings encoding to 32 bytes, or canonical Laravel `base64:` key configuration strings. It accepts at most 16 previous keys. It never reads `APP_KEY` or `APP_PREVIOUS_KEYS`; the caller supplies the approved key set. Do not pass comma-separated previous-key configuration directly.

| Methods | Value contract |
| --- | --- |
| `encrypt_string` / `decrypt_string` | UTF-8 string with no PHP serialization. `None` passes through as an unencrypted SQL-null column, matching the Eloquent `encrypted` cast. Embedded NULs and Unicode survive. |
| `encrypt_json` / `decrypt_json` | JSON encoding inside the encrypted-string envelope, matching `encrypted:array` / `encrypted:json` storage. Supports JSON scalars, lists, and string-keyed dictionaries. `None` on write means a SQL-null column. An existing authenticated envelope containing JSON `null` also decodes to `None`. |
| `encrypt_php` / `decrypt_php` | Laravel's default `encrypt` serialization variant, restricted to null, booleans, signed 64-bit integers, finite floats, UTF-8 strings and PHP arrays. PHP null is serialized and encrypted, not passed through. |

JSON representation need not have byte-identical escaping to PHP. Decrypted types and values must agree; random IVs make ciphertext equality unsuitable as a parity test. The cursor string fixture preserves exact JSON bytes because it uses the string API instead of parsing and re-encoding the payload.

PHP serialization uses UTF-8 byte lengths, never character counts. Lists encode as consecutive integer-key PHP arrays; dictionaries encode as associative arrays with explicit integer or string keys. Numeric strings that PHP would coerce into integer keys are rejected on both read and write. Consecutive integer-key arrays decode to lists; other arrays decode to dictionaries preserving insertion order. PHP's empty array has no list-versus-map distinction and decodes to `[]`, including after encoding `{}`. Duplicate keys are rejected rather than overwritten. No Python pickle, object hook, third-party PHP deserializer, class lookup or user code execution is involved.

## Limits and intentional rejection

- Plaintext or serialized content: at most 1,048,576 bytes; outer envelope: at most 2,097,152 characters, all canonical base64.
- Structure depth: at most 32 edges from the root. Structure budget: 10,000 nodes. PHP array keys count as nodes, including list indices; JSON object keys count, but list indices do not. Aggregate UTF-8 string/key bytes also have a 1,048,576-byte budget before serialization.
- Integers must fit signed 64-bit PHP integers. Floats must be finite. JSON dictionary keys must be strings. PHP arrays allow only integer and UTF-8 string keys; booleans are not integer keys in this API.
- UTF-8 only, including authenticated serialized strings. Arbitrary PHP byte strings, malformed surrogates, nonfinite floats, arbitrary objects, custom serializers, enums, references, cycles and trailing serialized bytes are unsupported. Fail qualification rather than silently converting them.
- No AES-128, GCM, arbitrary cipher selector, outer base64url transport, automatic format detection or automatic PHP unserialization. Callers must choose the source field's variant. Additional wrapping such as `CustomerApiOrders` base64url remains the caller's explicit transport responsibility.
- Missing `tag` is accepted for older CBC envelopes; any nonempty tag, unknown envelope field, duplicate JSON key, noncanonical base64 or malformed JSON is rejected. These choices are stricter than the installed framework's permissive decoder. JSON parse failures and empty decrypted JSON strings reject rather than reproducing permissive/null-returning framework behavior.
- SQL-null columns and encrypted JSON `null` both decode to Python `None`; callers needing storage-level provenance must retain column-null metadata before decoding.

## Synthetic oracle and public evidence

`tools/migration_compat/crypto_vectors.php` loads only Composer's installed autoloader. It does not boot Laravel or load application ENV. PHP generates randomized synthetic envelopes with the real installed `Encrypter` and real Eloquent `encrypted` / `encrypted:array` casts through an in-memory model. Python authenticates and decodes each fixture, compares its type/value with the independent PHP fixture definition, then writes a new envelope. PHP decrypts the Python envelope, compares it with its own fixture definition using strict equality, and confirms that the current key alone decrypts every new non-null envelope.

Fixtures cover Unicode/NUL/empty strings, SQL-null columns, JSON scalars and arrays, nested structured credentials, a long legacy delimited secret, PHP scalar/array serialization and previous-key reads for every variant. The Python runner additionally rejects wrong keys, absent previous keys, IV/value/MAC tampering, malformed encodings, unsupported fields/tags, invalid JSON, objects, custom serializers, enums, references and malformed serialization. Regression tests add authentication-before-cipher-construction, authenticated padding/UTF-8 failure, fresh IVs, size/depth/node boundaries, cycles and invalid key forms.

The PHP subprocess protocol contains synthetic expected values and envelopes only in captured local pipes. Do not publish its raw `generate` output, invoke it against imported input, or adapt its bounded synthetic `verify` path into a general-purpose deserializer. Its explicit `unserialize(..., ['allowed_classes' => false])` is only a fixture oracle; the candidate codec has its own restricted parser.

Only the Python runner's final manifest is public evidence. Its schema contains scenario IDs, pass/fail outcomes and SHA-256 checksums of reviewed source files. It contains no values, keys, envelopes, plaintext hashes or ciphertext-derived secret hashes. A failed run emits only the fixed manifest ID and failure outcome and returns nonzero. The runner accepts no fixture, production input or key path. No PHP stderr or raw exception enters its manifest. Source checksums identify code, not fixture plaintext or encrypted data.

## Dependencies and exact commands

Run from the repository root using the parent's locked candidate environment:

```sh
candidate/.venv/bin/python tools/migration_compat/crypto_vectors.py
PYTHONPATH=candidate candidate/.venv/bin/python -m unittest discover -s candidate/tests -p test_legacy_crypto.py -v
```

For a non-default PHP binary, use:

```sh
candidate/.venv/bin/python tools/migration_compat/crypto_vectors.py --php /usr/bin/php
```

Python requires `cryptography==46.0.7`, pinned by the parent in `candidate/requirements.in` and `candidate/requirements.lock`; the remaining codec/runner dependencies are standard-library modules. PHP requires a 64-bit PHP CLI compatible with the installed Composer dependencies, OpenSSL, mbstring and the installed `src/vendor/autoload.php`. The in-memory model uses framework code only; it needs no database or application services. The regression integration test requires PHP and installed Composer dependencies and deliberately does not skip qualification when either is missing.

Observed outcomes are recorded in [verification](verification.md); the commands above are the qualification interface.

## Context7 reference

Context7 `/laravel/docs` (13.x), consulted on 2026-10-30, confirms [MAC-authenticated encryption, decrypt failures and current-write/previous-read key rotation](https://github.com/laravel/docs/blob/13.x/encryption.md). Installed framework source and the bidirectional Eloquent/Encrypter oracle determine exact serialization and envelope bytes; documentation alone is not format proof.
