# P03 identity and hash compatibility (#685)

## Scope and executable boundary

`candidate/lhie_candidate/compat/identity.py` contains reviewed, explicit primitives. It is not a Django authentication backend, Customer registration/login implementation, authorization service, session importer or automatic password-upgrade hook. No production key, stored password hash, token, contact or secret-derived value belongs in public evidence. The synthetic values live only in fixture source and the verifier's captured subprocess exchange.

The runnable qualification entry point is:

```sh
candidate/.venv/bin/python tools/migration_compat/identity_vectors.py
```

It requires the source Composer dependencies and a local PHP executable (`--php` selects it), including bcrypt and Argon2i/Argon2id support. Python uses the candidate's pinned `bcrypt` and `argon2-cffi` dependencies. It launches `identity_vectors.php` without Laravel's application bootstrap, ENV loading, database access, cache/session workers or provider calls. The PHP side constructs an in-memory configuration container containing synthetic keys only. The CLI accepts no real dataset, key file or plaintext-input argument, writes no vector artifact, and publishes only counts/status. Direct PHP oracle stdout is an internal synthetic protocol, not a publishable evidence command.

`candidate/tests/test_legacy_identity.py` includes the bidirectional runner and focused negative/consumer tests. Parent verification, after all edits land:

```sh
cd candidate
.venv/bin/python -m unittest tests.test_legacy_identity
```

Observed integration outcomes and exact runtime versions are recorded in [verification](verification.md).

## Passwords: scheme before KDF

Source: `src/app/Services/Customer/CustomerPasswordHasher.php:9-40`, `src/app/Storefront/StorefrontOrderAccess.php:19-25,107-125`, and the installed Laravel `Hashing` classes/configuration.

| Consumer | Exact input to the configured KDF | Boundary |
| --- | --- | --- |
| Customer, `sha384-v1` | Base64 of the binary SHA-384 of the versioned prefix `customer-password-v1`, a NUL separator, then every password byte | Prehash is 64 ASCII bytes, so bcrypt preserves input past its 72-byte limit. No hex intermediate, Unicode normalization or prehash of a stored hash. |
| Customer, `raw` | Original supplied bytes | If the stored hash starts with `$2` and original input exceeds 72 **bytes**, reject. A 72-byte multi-byte UTF-8 input is not 72 characters. Argon raw values do not receive this bcrypt-only limit. |
| Customer, unknown or absent scheme | No fallback | Reject; never guess the scheme from the KDF prefix. Source additionally performs dummy KDF work for rejected schemes/overlong raw bcrypt input. That timing policy belongs to the eventual authentication service, not this offline primitive. |
| Admin/Owner `User.password` | Original password bytes | No Customer prehash/length guard. PHP bcrypt checks retain crypt's 72-byte/C-string behavior. The compatibility verifier truncates only at this actual KDF boundary; it does not invent Customer equivalence. |
| Order Recovery Key | PHP `strtoupper(trim(key))`, then Laravel Hash check | ASCII uppercase and PHP trim characters only. No password prehash or SHA-256 substitute. Historical null/absent hashes remain no-key Orders, not invented credentials. |

`verify_legacy_password` requires the reviewed algorithm explicitly: `bcrypt`, `argon2i` or `argon2id`. Customer and recovery wrappers retain that explicit choice. There is no algorithm downgrade, hash rewrite, `needsRehash` side effect, Django password prefix conversion or persistence. Driver/hash mismatch returns false; unsupported algorithm selection raises. Source Laravel's configured `HASH_VERIFY` may instead throw on a mismatch, which the eventual service must handle according to its source error policy. Default installed configuration is bcrypt with verification enabled and 12 rounds; Argon defaults are 65536 KiB, four passes, one thread. Effective production algorithms/costs remain unknown.

The verifier generates 30 password records on each side: short/long/NUL-containing Customer prehash, raw ASCII and multi-byte 72-/73-byte boundaries, direct Admin short/long values and normalized Recovery Keys, across all three KDF variants. It verifies both correct consumer decisions and wrong-password rejection in the other language. Fixture costs are intentionally low (bcrypt four rounds; Argon 1024 KiB/two passes/one thread), not candidate authentication policy. Unit coverage additionally checks malformed hashes, unknown schemes, version-prefix retention, raw/prehashed confusion, case, NUL behavior, and cross-algorithm rejection. Runtime evidence is required before describing any vector as passed.

## Key representations and identity normalization

| Family | Source and candidate operation | Required preservation |
| --- | --- | --- |
| Cipher | P02 contract section 3 and P03 envelope implementation | Laravel decodes a `base64:` configuration prefix for cipher bytes. This is **not** application-HMAC representation. |
| Application HMAC | `application_hmac`, source `hash_hmac` callsites | Literal configured APP_KEY text, including prefix and whitespace. No automatic decode, trim, previous-key trial or key-family substitution. Output is lowercase hex for these primitives. |
| Credential fingerprint | `CredentialIdentityFingerprint.php:91-156`; `credential_fingerprint` and `credential_key_digest` | Trim override/persisted key with PHP's trim set, then HMAC normalized identity with that literal key. Readiness is SHA-256 of the same trimmed string; blank key has no readiness digest. Python requires the already resolved key and does not initialize or read a key file. Source `ensureKey()` owns exclusive creation and configured/persisted mismatch rejection. |
| Waitlist | `ProductWaitlistEmailHasher.php:9-27`; `waitlist_email_hash` | `Str::lower(trim(email))`; trimmed optional waitlist key, otherwise **untrimmed literal APP_KEY**. Encryption of remapped email and its hash must change together. Blank email has no subscription identity; the combined helper rejects it. |

`CredentialIdentityNormalizer.php` is not the same normalizer as Customer/waitlist email handling:

- Credential email uses PHP ASCII `strtolower(trim(...))` and default `FILTER_VALIDATE_EMAIL`, without IDNA conversion or a Unicode-email flag. The helper preserves quoted local components, domain literals, plus tags and byte distinctions rather than using an email library that rewrites identity. Its grammar follows the ASCII/default branch of PHP's [email filter](https://github.com/php/php-src/blob/PHP-8.4/ext/filter/logical_filters.c), including quoted/escaped character counting, label/TLD restrictions and address-literal validation. The local PHP oracle covers ordinary, quoted, IPv4, IPv6 and invalid forms. HD must qualify the deployed PHP/filter version and any historical edge forms, not extrapolate from this finite fixture.
- Credential auth tokens reject only PHP-trim-blank values. A nonblank token is returned byte-for-byte: no trim, lowercase, Unicode normalization, split or truncation. Non-ASCII whitespace is not PHP default trim.
- Identity kind is a separate registry dimension. Source HMAC input contains **no kind prefix**. Equal normalized strings in `email` and `auth_token` can yield the same digest without becoming the same registry identity. Preserve `(kind, fingerprint)` and associated stock/registry/readiness relations.
- Customer and waitlist use Unicode-aware `Str::lower` after PHP trim, mirrored by `normalize_customer_email`. The vectors include a non-ASCII case mapping. Unicode-table/version edge cases require deployed-runtime/historical qualification, not a blanket assertion that every PHP and Python release lowercases identically.

The primitive vectors compare source normalizer/fingerprint/waitlist methods, key-readiness digest, and application HMAC under a literal encoded-looking key. A negative Python check ensures decoded cipher bytes cannot accidentally replace the literal HMAC key. Full key lifecycle/persistence/readiness workflows remain owned by their downstream services.

## Tokens, confirmations, OTP and browser challenges

| Protocol | Source format and primitive | Consumer conditions not implemented here |
| --- | --- | --- |
| Customer magic link | `CustomerAuthenticationService.php:18-92`: 32 random bytes rendered as 64 hex characters; persisted `SHA256(token)`; normalized email; 15-minute lifetime | Lock/upsert identity ownership, unused/future grant, consume once, verified email/enrollment version changes, invalidate enrollment challenges, audit/mail obligations, regenerate session. |
| Email verification/reset/Telegram-link grants | `CustomerPasswordAuthenticationService.php:269-325`, `CustomerAuthToken.php:27-43`, `CustomerAuthTokenPurpose.php` | Purpose/Customer/email/auth/enrollment bindings; immutable grant fields and terminal consumed state; Customer-first lock order. Verification/reset TTL 1800 seconds, OTP 600, Telegram-link 300. No recovering a raw token from its digest. |
| OTP | `CustomerEmailOtpService.php:23-145`: challenge is 32 random bytes as hex; challenge and browser-binding fields are separate SHA-256 digests. Code is exactly six decimal digits, retaining leading zeroes. `email_otp_digest` is HMAC-SHA256 over `customer-email-otp:v1`, NUL, `email_otp`, NUL, already normalized email, NUL, stored challenge hash, NUL, code. | Rate limits, resend cooldown, consume active challenge, verified ownership, strict lower-hex challenge shape, browser binding and auth/enrollment versions, future expiry, maximum five attempts, lock/consume atomically and clear OTP session data only on successful authentication. Primitive intentionally does not normalize its stored email/challenge/code inputs. |
| Google/Telegram browser claims | `CustomerGoogleAuthenticationService.php:169-183,301-316,349-352`; `CustomerTelegramAuthenticationService.php:118-176,307-361`: state/capability/confirmation use distinct SHA-256 fields; browser binding is SHA256(session ID + NUL + nonce), not concatenation without separation. | Five-minute claim expiry/fresh authentication, one-use and exact browser/operation/customer/proof ownership, configuration fingerprint, unlink/enrollment policy and provider proof. `browser_nonce_binding` proves only the separated digest. |
| Google configuration fingerprint | `GoogleOAuthProvider.php:24-51`: HMAC-SHA256 under literal APP_KEY of byte-length-prefixed client ID, byte-length-prefixed secret, then the HTTPS callback URL. | HTTPS root-origin constraints, provider exchange/claim checks, configuration changes and downstream challenge invalidation. Format is mapped; no provider adapter or secret lookup is ported. |
| Customer API bearer credential | `CustomerApiCredentials.php:12-23,135-142`: case-insensitive shape match for `Bearer capi_<32 hex selector>.<64 hex secret>`, but selector and secret are captured **case-preserved**. Index by selector; SHA256 of the exact secret, not the bearer header or lowercased secret. | Revocation, active-key uniqueness, Telegram Customer ownership, request admission/usage and credential DB lookup. `parse_api_authorization` returns values only; it is not authentication. |
| API management confirmation | `CustomerApiCredentials.php:38-94`: 16 random bytes as 32 hex characters. Rotation is SHA256(nonce), disable is SHA256(`disable:` + nonce). Source consumption checks 32 **bytes**, not an invented lowercase-hex-only rule. | Current active credential, exact Customer and message ID, future five-minute expiry, lock/consume/rotate/revoke and old-token rejection. `api_confirmation_digest` keeps action domains distinct but makes no admission decision. |

`sha256_digest` operates on exact bytes for these token, session and stored-password-hash bindings. A digest's field purpose remains mandatory even where the byte-level algorithm is the same. The vectors exercise challenge/session binding, leading-zero OTP, API secret and distinct management domains. These are primitive vectors, not a runnable mail/OTP/OAuth/Telegram login flow.

## Signed URLs: mapped consumer policy, proven MAC bytes

Source `Illuminate/Routing/UrlGenerator.php:366-386,460-514` sorts route-generation parameters, constructs the route/query using Laravel encoding, and HMACs the full unsigned absolute or relative URL with literal application key text. Verification rebuilds the path/base URL according to mode, retains raw query order/encoding, removes exact `signature` query entries plus explicit ignores, strips a trailing empty `?`, then constant-time compares. Current installed verifier can try its configured key-resolver list; this is not permission to try cipher previous keys for every keyed index. Expiry is separately checked and accepts equality with the current timestamp (`now > expires` rejects). Malformed expiry/query/host behavior requires the actual framework consumer, not a Python URL parser guess.

`StorefrontInvoiceUrl.php` generates relative signed show/status/QR/cancel/Binance-ID/BSC-hash URLs, with expiry at Order creation plus configured days (minimum one, default seven). `ValidateStorefrontSignedInvoice.php` requires an expiry outside testing and accepts relative, current absolute, or the explicit legacy configured-APP_URL absolute signature. The legacy-host fallback is specific to that middleware, not a global verifier feature. Waitlist confirmation/unsubscribe (`ProductWaitlistSubscriptionService.php`) and admin proof links (`AdminBalanceReportReader.php`) use their own routes/expiry and ownership policy. An invoice signature is not a Recovery Key grant or permission to read credentials.

P03 deliberately exposes only `signed_url_digest(unsigned_url, app_key)`: callers must supply already canonical bytes. The oracle uses real Laravel `signedRoute` with a synthetic named route, fixed future expiry, relative mode, a slash, Unicode and a space in the query. Both languages compare the resulting MAC. Unit tests distinguish percent-encoded space from `+` and relative from absolute input. No route parser, host fallback, expiry authority or arbitrary deserialization was added to the candidate.

## Cursor and short-callback format map

| Format | Bytes and scope | Remaining consumer work |
| --- | --- | --- |
| Web Customer balance cursor | `CustomerBalanceActivityCursorToken.php`: encrypted-string envelope containing ordered JSON `version`=1, `customer_id` integer, `currency` (`IDR`/`USDT`), `occurred_at` integer microseconds, `rank` integer, `id` integer | Strict version, exact Customer/currency, positive integer values (booleans are not PHP ints), rank mapping and ownership/query ordering. P03 envelope vectors include matching JSON as a byte-preservation fixture only. |
| Rank map | `StoreBalanceActivityCursor.php` / `CustomerUsdtActivityCursor.php` | IDR rank 1=top-up, 2=ledger entry; USDT rank 1=target intent, 2=ledger entry. Unknown rank rejects. Preserve integer microseconds and source-specific IDs; do not substitute timestamps rounded through floats. |
| Admin report cursor | `AdminBalanceReportCursor.php`: encrypted-string JSON with version 1, scope, occurred-at string and ID string. Scope is SHA256 of **ordered** default PHP JSON: actor as string, section, currency, nullable telegram ID, nullable state, integer limit. | Constant-time scope equality, actor/filter binding, ID nonempty and at most 64 bytes, report query/order/authorization. `admin_cursor_scope` proves default Unicode/slash escapes and null-vs-empty distinction with the source private scope method. It does not decode/admit a cursor. |
| Telegram IDR/USDT cursor | `TelegramBalanceActivityToken.php` / `TelegramUsdtActivityToken.php`: unpadded base64url of `pack('CJJCJ', version, customer, timestampMicros, rank, id)` (26 payload bytes) plus first 12 binary HMAC-SHA256 bytes. `C` is one byte, `J` is big-endian uint64. Version 1=IDR, 2=USDT. | Exact decoded lengths, version/rank/positive IDs, constant-time truncated-MAC validation, requesting Customer and callback actor/message checks. IDR detail token is `pack('CJJC', version, customer, id, rank)` (18 payload bytes), same MAC rule. These binary formats are mapped, not added as an unused generic token API. |
| Family callback | `TelegramFamilyCallbackToken.php`: outer `v` plus unpadded base64url of payload and first six binary HMAC bytes. Options payload is `pack('qNN', family, page, returnPage)`; selection is `pack('qqNN', family, product, page, returnPage)`. `q` is **machine-endian** signed 64-bit; `N` is big-endian uint32. | Preserve deployed architecture/byte order, positive values, variant length and origin/callback authorization. Do not reinterpret this as the activity cursor format. |
| Funding preset | `StoreBalanceFundingPolicyManager.php:147-156`: first eight binary HMAC bytes of `version|index|amount`, unpadded base64url. `UsdtFundingAmountPreset.php:131-149` additionally includes rail: `version|rail|index|amountMicro`. | Policy version/current amount/rail/index and actor/root-message authorization; no replay of historical funding actions. |
| API delivery unit token | `CustomerApiOrders.php:172-178`: `Crypt::encryptString(unitId)`, then replace `+/` with `-_` and strip outer `=` padding | This is a wrapped Laravel envelope, not SHA-256, JWT or PHP serialized object. Ownership, reveal authorization, terminal delivery/account-unit policy and handler semantics remain downstream work. |

Binary/truncated formats are explicitly mapped rather than claiming that a 64-character hex helper ports their consumers. P03 proves the underlying HMAC/SHA/envelope primitives and reviewed serialized examples; downstream adapters must port the actual versioned formats and actor/currency/message policies before admitting those tokens. No pickle, PHP `unserialize`, dynamic class construction or arbitrary JSON-as-auth-state mechanism is used.

## Sessions, immutable bindings and HD acceptance

No Laravel session/cookie/cache is admitted into the candidate. P02 forbids restoring sessions, runnable queues and runtime caches; P03 does not override that boundary. A successful password/MAC comparison is never session authority. Any future cutover must explicitly choose expiration/re-authentication behavior instead of silently importing a PHP session into Django.

The source policy that downstream auth must preserve includes:

- `CustomerSessionAuthentication.php` clears provider-proof fields on ordinary authentication, logs in through the Customer guard, regenerates/destroys the old session, and records strict `customer_auth_version` and authentication timestamp. Logout clears those fields/proofs and regenerates again.
- `ValidateCustomerSession.php` requires current auth version and either verified email or a still-owned Telegram/Google identity proof; stale proof/version logs out. Customer responses carry private/no-store and no-referrer headers.
- Sensitive linking/enrollment/unlink operations enforce a fresh, non-future authentication timestamp and exact current Customer/auth version/provider ownership. Merely copying a timestamp or matching token digest cannot establish proof.
- Recovery Key grants are Order-scoped session expiries, minimum five minutes/default thirty, separate from signed invoice access. The issued-key session value is encrypted and removed when it cannot decrypt. Existing grants are not remapped into usable candidate sessions.
- Auth grant binding fields are immutable; attempt counters cannot decrease and consumed state cannot reopen (`CustomerAuthToken.php`). Password/enrollment changes invalidate applicable outstanding challenges and versioned sessions through the source transactions.
- Owner approvals/reviews elsewhere bind SHA256(session ID) and SHA256(the **stored** Owner password hash), as inventoried in P02. Rehashing an unchanged plaintext password changes that stored-hash binding. Do not blanket-replace fingerprint columns or rewrite immutable history when replacing usable derived credentials.

HD (#684), after H1 continues and before full acceptance #710, must privately establish actual historical coverage: effective/deployed PHP/framework/KDF versions and costs; every observed Customer scheme and nullable enrollment state; bcrypt/Argon variants and malformed/legacy edge cases; key-family identifiers/lineage; credential registry kind/equality and original readiness-key continuity; optional waitlist key history; current/previous envelope keys; all observed signed-link/cursor variants, host/encoding and machine-endian histories; and immutable session/password-hash-bound financial/review/auth records. Missing keys or unsupported historical forms remain explicit blockers, not normalized guesses.

Synthetic derived datasets need fresh unusable-or-isolated test credentials and internally consistent identity/encrypted-field mappings, while retaining source private evidence unchanged and preserving economic fingerprints, IDs, ownership edges, statuses, timestamps, challenge terminal state and durable obligations. Historical password/token hashes stay protected private acquisition material; do not print them to demonstrate coverage. Public evidence contains opaque coverage categories/counts and pass/fail, not plaintext, key strings, credential/password hashes or low-entropy value digests.

This contract proves only what the integration runner actually exercises. It does not claim a complete downstream authentication port, deployed-runtime qualification, production historical coverage, provider access, CI/deployment authorization or permission to unquarantine.
