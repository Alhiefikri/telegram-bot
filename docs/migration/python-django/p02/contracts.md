# P02 acquisition, field, schema and file contract (#682)

## Scope and evidence boundary

This contract applies only to `migration/python-django-experiment`. P02 starts with local synthetic data, not a production export. Actual database, file and secret acquisition belongs to the H0 operator under a separate explicit authorization. No production connection, SSH, ENV inspection, webhook change, provider call, CI operation or deployment is authorized here.

[P00](../p00/README.md) freezes source baseline `d830709bb98cffacc6369847383c850ff89088f2`. [inventory.json](inventory.json) records current SHA-256 values, P00 SHA-256 values, line numbers, source text and discovery classifications for 466 matched source files and 1,938 matching lines. All those matched source hashes agree with P00 at inventory creation. Installed framework files are separately hashed supporting evidence, not proof of the deployed dependency versions. This is a **lexical discovery inventory, not complete semantic classification**. Its matches locate review obligations; they do not establish runtime reachability, production activation, current catalog objects or exhaustive indirect dataflow.

Production row counts, deployed schema drift, effective role privileges, effective keys and key history, storage contents, external media availability and actual feature activation remain **unknown**. A reference in source is not evidence that an object or file is present in production. Historical migration `down()` paths are inventory evidence, not restore instructions.

Canonical decisions: [ADR-0001](../../../adr/0001-web-storefront-order-access.md) owns guest Recovery Keys and Customer auth; [ADR-0007](../../../adr/0007-telegram-outbound-effects.md) owns outbound ambiguity and credential-bearing delivery; [ADR-0008](../../../adr/0008-store-balance-ledger.md) owns IDR history; [ADR-0016](../../../adr/0016-private-supplier-fulfillment-and-capacity.md) and [ADR-0017](../../../adr/0017-per-account-warranty-and-partial-refunds.md) preserve supplier purchases and per-unit history; [ADR-0022](../../../adr/0022-external-money-receipt-backfill-and-reservations.md) owns external receipt claims; [ADR-0025](../../../adr/0025-api-order-idempotency-claims.md) owns API claim identity. [ADR-0031](../../../adr/0031-credential-pin-outside-receipt-critical-path.md) is superseded: retained pin history does not authorize automatic pin jobs.

## 1. Artifact custody and admission

Keep three artifact classes separate:

1. **Private immutable source**: encrypted dump, encrypted file bundle and private catalog/acquisition metadata. Use a private directory with owner-only access, immutable content-addressed identity and an access log. Never overwrite the source during sanitization. Encryption at rest does not make a raw restored database safe to expose.
2. **Secret transfer**: archive decryption material, current and previous Laravel keys, credential identity fingerprint key, optional waitlist key and operator database access credentials. Transfer through the operator's approved secret channel; do not place values in the source archive, repository, CLI transcript, public provenance, derived manifest or logs. Provider tokens and production database credentials are not candidate runtime configuration.
3. **Derived output**: transformed database/archive, synthetic files, per-bot mapping and a sanitized provenance report. Restrict access even after sanitization. Publish only after every required check succeeds. A failed attempt must not leave a usable success manifest or release quarantine.

For each source artifact the operator records an opaque dataset ID, authorized purpose/actor, acquisition time and consistent snapshot identity, source revision/image/version evidence, format, encrypted-byte size/SHA-256, authenticated decryption outcome and private schema/file manifest identities. A hash identifies bytes; it does not authenticate an attacker-supplied archive by itself. Require authenticated encryption and trusted custody or a separately authenticated manifest. Compare plaintext dump/file checksums only inside the private boundary; raw paths, identifiers and low-entropy content checksums can themselves disclose information.

A derived report records source encrypted-artifact checksum, transformer revision and source hashes, policy version, synthetic fixture identity, destination namespace, PostgreSQL/tool/dependency versions, transformation and audit outcomes, derived archive/file checksums, remaining unavailable cases, retention deadline and approval identity. It contains no decrypted credential, contact, real file ID, secret key, password hash or raw exception body.

Retention is an H0 decision, not an inferred duration: the operator must assign named custodians and explicit expiry dates for raw archives, private raw restores, secret access, private mapping tables and derived artifacts before accepting real input. Keep each only for its approved purpose; expire disposable restores and revoked access first, destroy private mapping/decryption copies when the approved window ends, and record deletion/access-revocation evidence without recording secrets. Financial source-of-record retention is unaffected; this does not authorize deleting production history. No production-retention policy is claimed to exist today.

## 2. Restore and transformation boundary

[P01](../p01/README.md) owns isolated namespaces/storage, fake transports, default-deny egress, a mail sink and sticky quarantine. Its fake firewall is a local admission control, not an OS/network security proof. H0 real input additionally requires operator-enforced network isolation and private host/storage custody. Neither raw nor derived imports may start an application worker, scheduler, webhook consumer, mail sender or provider adapter. Never mount production Compose, ENV, storage or Redis into this process.

The P02 runnable lane is deliberately **synthetic-only**, with an authenticated encrypted `pg_dump` archive and separate private synthetic keys. It uses P01's disposable quarantine database. Restore raw synthetic data into `p02_raw` and read it through read-only source transactions. H0 real-input isolation must additionally deny the derivation role mutation access. Read-only settings plus unchanged source checksums must be audited; a database owner/superuser is not a read-only security boundary. Production dumps, additional columns and unknown schema objects are rejected, not heuristically anonymized.

The entry point is `python tools/migration_data/rehearse.py`, with dependencies in `tools/migration_data/requirements.txt` and synthetic DDL in `tools/migration_data/schema.sql`. It accepts no input path or production-import option and requires an admitted P01 candidate in default fake mode with `--quarantine`. It leaves quarantine engaged; there is no application release command.

Each run creates `.local/migration-p02/synthetic-<id>/` containing `source.dump.aesgcm` (authenticated AES-GCM encrypted `pg_dump`, mode 0400), `derived.dump` (sanitized synthetic dump, mode 0400), synthetic assets, `files.json`, `manifest.json` and `manifest.sha256`. Keys live in the separate sibling `synthetic-<id>-keys/`: `source.json` holds source field/fingerprint/archive keys; `derived.json` holds only derived field/fingerprint keys. The raw database uses `default_transaction_read_only=on`; the trusted local Docker/database administrator can override it. This setting and source comparisons are not an adversarial owner-proof immutability claim.

The local synthetic manifest sets a seven-day raw expiry and thirty-day derived expiry, owned by the local operator. At raw expiry delete `source.dump.aesgcm` and `source.json`; at derived expiry delete the remaining run/key directory and record deletion. Reset P01 disposable volumes after the rehearsal. These are local fixture retention defaults, not approval of real-data retention. The implementation checks restored catalog guards/grants, role attributes, FK rollback, runtime-role mutation denial, source immutability and ledger/identity trigger rejection. Only the main owner's recorded execution establishes which probes passed.

`files.json` records `asset_sha256`, synthetic source/target bot identity, synthetic source file ID and `target_file_id = null`, with `status = requires-reupload`. A mapping is not ready merely because a placeholder exists. No actual upload happens. A synthetic in-memory uploaded mapping exercises validation, but persisted pending entries remain blocked.

The transformation is **read source -> authenticate/decode -> map -> validate -> INSERT into a fresh derived schema**. Install the approved schema, constraints, triggers, ownership/grants and required dependencies before inserting transformed rows. Insert explicit IDs in dependency order; use deferred checks only where the actual constraint is declared deferrable. Preserve sequence values and `is_called` rather than assuming `max(id) + 1` describes the source. If insert-time guards cannot accept a faithful historical state, block output and record the exact incompatible object privately. Do not disable, drop or rewrite a guard to make an export succeed.

Never sanitize an immutable row by updating a restored table. Never use `session_replication_role = replica`, `DISABLE TRIGGER`, `pg_restore --disable-triggers`, `TRUNCATE`, direct ledger/projection repairs or application events that replay business transitions. There is no re-credit, refund, customer merge, supplier repurchase, receipt replay or delivery resend in the import. A full historical source retained unchanged is not a reason to execute its pending work.

The source and derived schema/column allowlists are explicit. Unexpected relation, column, enum, trigger/function definition, row shape, payload key, missing source key, invalid ciphertext/MAC, unresolved reference, duplicate mapping, digest mismatch or unsafe artifact path fails closed. Arbitrary JSON is not made safe by encrypting it again. Future H0/P03 production mapping must classify nested payloads and immutable binding dependencies, rather than treating today's fixture as a generic Laravel sanitizer.

### Required invariant comparison

Audit the complete source and derived synthetic datasets, not sampled rows:

- Same table cardinalities, primary IDs, UUIDs, FK/ownership edges, allocation relations, unique claim equivalence classes and nullable/legacy shapes. No merging Customers or collapsing equal identities from different domains.
- Same exact integer IDR and micro-USDT amounts, currencies, quantities, prices, fees, balance projections/cursors, ledger and accounting rows. No floats and no recalculation from current catalog/configuration.
- Same statuses, reason categories, timestamps/time zones/order, expiry windows, lease/attempt/history state, pending/unknown/manual-review/late cases and durable obligations. Test contact/credential replacement must not erase a difficult case.
- Economic source fingerprints, canonical receipt/alias/parent transaction claims and immutable checkout/API idempotency identities remain unchanged. Do not globally rehash every column called `fingerprint`.
- Decrypted synthetic mapped values agree with their ciphertexts, identity registries, keyed lookup hashes and readiness-key digests; duplicate and distinct-value cases preserve their intended equality. Tampered or wrong-key data cannot produce an output dataset.
- Source ciphertext/fingerprint audit proves the original synthetic source did not change. Derived contains no source secrets, source contact/provider targets or production-reusable media references.
- Installed catalog, constraints, trigger enabled state, runtime grants and sequence state match the approved local mapping. Quarantine remains sticky after success and after failure; the dataset report is never unquarantine authority.

## 3. Encrypted fields and explicit Crypt call sites

`src/config/app.php:110-118` selects `AES-256-CBC`, `APP_KEY` and `APP_PREVIOUS_KEYS`. Inventory `encrypted_fields` lists each model/field/source line, including multiple fields declared on one line:

| Model | Encrypted fields |
| --- | --- |
| `CustomerAuthMailWork` | `payload` (array) |
| `Order` | `support_contact_value`, `notification_email` |
| `OrderNotificationEvent` | `recipient_email` |
| `OrderRefund` | `external_reference` |
| `Payment` | `provider_qris_url`, `provider_presentation` (array), `provider_payment_number`, `klikqris_transaction_signature`, `payment_proof_file_id` |
| `ProductStockItem` | `credential_payload` (array), `secret` |
| `ProductWaitlistSubscription` | `email` |
| `StoreBalanceTopUp` | `provider_qris_url`, `provider_qris_payload`, `provider_signature` |
| `Supplier` | `api_key`, `webhook_secret` |
| `SupplierDeposit` | `payment_url` |
| `SupplierFulfillment` | `sources_snapshot` (array) |
| `SupplierOrderHistoryReconciliation` | `candidate_order_ids`, `candidate_facts` (arrays) |
| `SupplierPurchase` | `delivery_payload`, `trusted_delivery_payload`, `account_units` (arrays) |
| `SupplierWebhookEvent` | `payload` (array) |
| `WarrantyClaim` | `note`, `provider_chat_url` |

Do not infer encryption from a similar name. In particular `StoreBalanceTopUp.payment_proof_file_id` is not an encrypted cast in the current model, whereas `Payment.payment_proof_file_id` is. `provider_payload`, supplier metadata and decision history can contain sensitive nested values without an encrypted cast. Inspect their actual schemas and writers before real mapping.

Explicit `Crypt::encryptString/decryptString` sites are `AdminBalanceReportCursor`, `BscReceiverPendingMutationStore`, `CustomerBalanceActivityCursorToken`, `CustomerApiOrders` delivery unit tokens, `TelegramWebAuthenticationPayload` and `StorefrontOrderAccess` session-held issued Recovery Keys. They include transient/cache/session/protocol payloads, not six additional database encrypted columns. `CustomerApiOrders` adds unpadded base64url wrapping around the Laravel envelope; preserve that outer transport encoding when testing compatibility.

Installed framework evidence (`Encrypter.php:104-143`, `EncryptionServiceProvider.php:62-66`, `HasAttributes.php:1452-1466`) shows base64-encoded JSON containing `iv`, `value`, `mac`, `tag`; CBC uses a random IV and authenticated MAC. Cipher keys with `base64:` prefix are decoded by the encryption provider. `encryptString` and Eloquent encrypted-string storage do not PHP-serialize the plaintext. Array casts add JSON serialization before encryption; do not substitute Python repr/pickle or treat every envelope as PHP serialized data. Authentication and padding/JSON/type failures must reject the row. Ciphertext equality is not a plaintext identity comparison. `APP_PREVIOUS_KEYS` support decryption, not arbitrary HMAC lookup under old keys. The deployed framework and key coverage still need H0 confirmation.

## 4. Keys, identities, fingerprints and authentication

### Distinct key families

- **Cipher key**: decoded `APP_KEY` material for Laravel encryption; prior keys only where configured. Derived encryption uses a fresh isolated key, never a copied production APP_KEY.
- **Application HMAC**: most `hash_hmac` call sites pass the literal configured `app.key` string, including its `base64:` text when present. Do not substitute the decoded cipher bytes. Output is lowercase hex unless the call requests binary output/truncation/base64url.
- **Credential identity**: `CredentialIdentityFingerprint.php:115-156` uses the trimmed `services.credentials.identity_fingerprint_key` override, otherwise the persisted private key at `storage/app/private/credential-identity-fingerprint.key` (path configurable). `ensureKey()` generates hex from 32 random bytes, writes with exclusive creation and refuses a configured/persisted mismatch. `keyDigest()` is SHA-256 of the configured string, used by `CredentialIdentityBackfillReadinessService` to bind readiness to that key. Never delete the persisted key or claim readiness under a new key while retaining old fingerprints.
- **Waitlist**: `ProductWaitlistEmailHasher.php:9-27` lowercases/trims email, uses a trimmed optional `services.storefront.waitlist_hash_key`, otherwise literal APP_KEY. Recompute `email_hash` together with the encrypted mapped email.
- **Provider signing**: Binance signs an RFC3986 query with `services.binance.api_secret`; Sakurupiah signs its exact concatenated invoice fields/raw response body with its provider API key. Supplier webhook/API keys are another family. These are neither identity-fingerprint keys nor archive keys. Replace them with inert synthetic values; source provider signatures are historical evidence, not permission to contact the provider.
- **Archive and DB access**: archive encryption/decryption credentials and PostgreSQL roles are separate from every application key. Keep them out of both public assets and provenance.

`CredentialIdentityNormalizer.php:9-38` applies PHP `strtolower(trim(email))` and email validation. Auth tokens are byte-for-byte values: reject a blank token, but do not trim, lowercase, Unicode-normalize or truncate a nonblank token. Identity kind is a separate registry dimension; the HMAC input is the normalized value without an added kind prefix. Derived identity remapping must preserve `(identity_kind, equality)` and every registry/stock/readiness relation. Mixed legacy secret formats and structured credentials must both remain represented; a split-on-one-delimiter sanitizer is not sufficient.

The inventory separately classifies every HMAC/digest callsite. Important non-interchangeable examples:

| Source | Meaning and handling |
| --- | --- |
| `TelegramCheckoutCallbackHandler`, `StorefrontShopCheckout`, `StorefrontGuestCart`, `ProductStockIntake` | Channel-specific checkout/intake payload HMACs; preserve original economic identities in history, generate new synthetic actions with their exact canonical encoding. |
| `UsdtBalanceSource`, `VerifiedMoneyReceipt`, `CustomerApiOrderRequest` | Versioned canonical JSON SHA-256 financial/claim fingerprints, not secret keyed indexes; do not alter recorded economic fingerprints. |
| `PakasirNativeQrisService` | `provider_payment_number_hash = SHA256(paymentNumber)`; coupled encrypted number/index mapping requires explicit coverage even for a disabled/unqualified rail. |
| `CustomerEmailOtpService:126-133` | HMAC-SHA256 over `customer-email-otp:v1\0email_otp\0<email>\0<challenge_hash>\0<code>` using literal APP_KEY; challenge/session binding use SHA-256. Preserve purpose/version/attempt/expiry semantics. |
| `CustomerAuthenticationService`, `CustomerPasswordAuthenticationService` | Random 32-byte tokens encoded as 64 hex characters, persisted SHA-256 digests; normalized Customer email is `Str::lower(trim(...))`. Raw tokens are not recoverable from the digest. |
| `CustomerApiCredentials:14-19,135-142` | `capi_<32 hex selector>.<64 hex secret>`; selector and secret remain case-sensitive despite case-insensitive shape matching. Persist SHA-256 of secret, not a bearer token. Rotation is SHA-256(nonce), disable confirmation SHA-256(`disable:` + nonce). |
| `StorefrontOrderAccess:20-25,112-125` | Recovery Key `LHIE-` + uppercase random suffix, normalized uppercase/trim on checking and stored with Laravel `Hash`; not SHA-256. Old Orders may have no Recovery Key. |
| `BalanceAdjustmentService`, `ApprovedBalanceAdjustment`, USDT review controllers | Session/credential bindings include SHA-256 of session ID and of the stored Owner password hash. Replacing a password invalidates those bindings; mapping requires explicit treatment of bound immutable history, not blanket string replacement. |
| `TelegramFamilyCallbackToken`, balance/USDT activity tokens, funding presets | Binary/truncated HMAC plus base64url formats; not all HMAC values are 64 hex characters. Preserve version, scope, actor/message binding and length. |

### Password compatibility

`User.password` and `Customer.password` use a `hashed` cast, but Customer authentication additionally owns `CustomerPasswordHasher`. Its `SCHEME = sha384-v1` hashes `base64(SHA384("customer-password-v1\0" + password, binary))` with the configured Laravel hasher. A `raw` legacy scheme is checked as-is; a bcrypt hash with a raw input longer than 72 bytes is rejected. Unknown schemes fail, not downgrade. Preserve `password_scheme`, nullable enrollment, `auth_version`, `enrollment_version`, token consumption and identity ownership rules.

The installed framework default is bcrypt, 12 rounds (`HASH_DRIVER`/`BCRYPT_ROUNDS` can override); Argon/Argon2id are supported, with environment-controlled parameters. Effective production algorithms/costs are unknown. Do not assume every `$2...` hash is a direct password or convert it to a Django-native format without its scheme boundary. Any fixture claiming authentication parity must use the expected scheme/KDF, not a fixed fake digest. The P02 acquisition fixture deliberately uses unusable password markers and makes no authentication/KDF compatibility claim; P03 owns that qualification. Raw production password hashes are protected acquisition material and must not be retained as usable derived credentials.

## 5. Schema, ownership, grants and sequence capture

`scripts/backup-db.sh:65-90` emits GPG-encrypted gzip plain SQL using `pg_dump --no-owner --no-privileges`. It verifies decryptable gzip, not isolated restore, APP_KEY backup, storage coverage, full role/ACL capture or offsite retention. `scripts/restore-db.sh` defaults to normal Compose/DB names, accepts plaintext formats, and its `--dry-run` only prints the target; it is not P01 admission or P02 quarantine. Do not invoke either script with defaults for this experiment. Its encrypted restore path expects a passphrase; it is not a general recipient-key restore procedure.

At H0 the authorized operator must capture the actual catalog privately, alongside a consistent full database dump and file manifest:

| Capture | Required facts |
| --- | --- |
| Versions/identity | PostgreSQL server and dump/restore client versions, encoding/collation/timezone, schema/migration history, source revision/image evidence and snapshot boundary. |
| Relations/columns | Schemas, tables/partitions, columns/types, domains/enums, nullability/defaults, generated/identity columns, PK/FK actions and validation/deferrability, CHECK/UNIQUE/exclusion constraints, all indexes including expressions/predicates. |
| Executable schema | Function/procedure definitions, language/security-definer/search-path/owner, triggers and enabled state, views/materialized views/rules, RLS policies and enabled/forced state. Review executable SQL as untrusted before restore. |
| Roles/permissions | Role attributes and membership without password hashes; database/schema/relation/function/type/sequence owners and ACLs; default privileges, inherited/public grants and runtime-vs-migration role split. `pg_dumpall --roles-only --no-role-passwords` can provide role definitions when the authorized operator has the required access, but does not replace per-database ACL/default-privilege capture. |
| Extensions | Names, versions, schema and prerequisites. The `2026_09_28_000001_enable_pg_stat_statements.php` migration requests `pg_stat_statements`; its presence and preload configuration are not proven by that source. |
| Sequences | Qualified names, owner/dependencies, start/increment/min/max/cache/cycle, identity association, `last_value` and `is_called`, including empty sequences and values beyond the current maximum ID. |
| Connectivity/special objects | Tablespaces, FDWs/foreign servers/user mappings, publications/subscriptions and other extensions if present. Keep connection secrets private and never recreate production outbound connectivity locally. Unknown objects block mapping. |

A schema-only dump retaining ownership/ACL metadata plus catalog exports is required because the existing no-owner/no-privileges dump cannot recover omitted grants. Do not reconstruct production grants from a proposed runbook. [The USDT privileges runbook](../../../runbook-usdt-ledger-privileges.md) explicitly says roles are operator-provisioned, the documented split is not proof of current production adoption, and its sequence names must be resolved from the actual catalog.

### Local restore process

1. Admit only an allowlisted synthetic archive into the P01 disposable namespace. Record versions, dataset/checksum and quarantine state before decryption. Keep workers/schedulers/network absent throughout.
2. Authenticate/decrypt privately and verify dump/schema manifest checksums. Reject unexpected archive members, paths, formats, SQL/object definitions or schema. Never execute an arbitrary production SQL dump through the synthetic runner.
3. Provision only approved local roles with isolated credentials. Preserve role separation and privilege semantics through an explicit source-to-local name map; do not copy LOGIN passwords, superuser, replication or external endpoints. Raw read and derived write roles must be distinct; derived runtime must not own immutable history tables.
4. Restore the raw synthetic database/schema privately. Capture its catalog, row and sequence audit, then make the source read-only to the derivation role. Retain the encrypted raw bytes unchanged.
5. Create the fresh derived schema with mapped ownership/ACL/default privileges, required extensions, functions and active guards. Transform rows before insertion in FK dependency order. A historical INSERT guard mismatch is a blocker, not a trigger-disable exception.
6. Apply the mapped source sequence states after explicit-ID inserts. Compare constraints/indexes/triggers/owners/ACL/default privileges and exercise intended allowed/denied operations using the **local runtime role**, not the owner. Audit every invariant from section 2, decrypt/recompute mapped synthetic identities, and prove source immutability.
7. Export derived artifacts only when audits pass; record checksums/provenance/file mapping/retention. Keep quarantine engaged. An exception/failure never produces admitted derived output or starts workers. All runtime check evidence belongs in P02 verification, not a claim made by this source inventory.

The existing [balance restore runbook](../../../runbook-balance-restore-rollback.md) explains why external side effects are not rolled back with PostgreSQL and why unknown deliveries cannot be blindly retried. Its production replay/reconcile/reopen commands are **not** steps in this offline acquisition process. No provider scan, money repair or restore-close is authorized by a successful local import.

## 6. Files, assets and Telegram references

`src/config/filesystems.php` declares local-private `storage/app/private`, local-public `storage/app/public`, `/storage` URL linkage and an optional S3 disk. Disk declarations do not prove effective disk, bucket or physical production roots. `ProductImageOptimizer` manages `products`, `product-families`, `telegram-products`, `telegram-channel-products`, `telegram-product-families`, `storefront/placeholders`, `storefront/banners`, `telegram/welcome-banners`; `QrTemplateStorage::DIRECTORY` is `payment-qr-templates`. `StorefrontSetting.homepage_banners[].path` is a nested JSON file edge, not another flat image column.

Classify references and bytes separately:

- **Reviewed catalog/brand assets**: Product/family/category images, placeholder, homepage banners and welcome banner. Use synthetic replacements by default. A public directory is not proof that an image contains no private data. Preserve the necessary reference graph, type/dimensions and presentation cases, with acquisition/missing states recorded.
- **Payment QR assets/targets**: replace live payment numbers, QR payloads/URLs and template content with explicitly non-payable synthetic fixtures. A QR rendered on a public image is still an actionable provider target.
- **Protected customer artifacts**: payment proofs, credential-bearing receipt photos/text/documents, account exports, supplier delivery material and warranty evidence. Keep real bytes only in encrypted private source custody; synthetic output uses safe replacement content. Never copy raw proof/credential files into public assets or a testing chat merely because a database ID survived sanitization. ADR-0007 forbids carrying receipt credentials/image bytes in queue/cache payloads.
- **Telegram media references**: `Payment.payment_proof_file_id`, `StoreBalanceTopUp.payment_proof_file_id`, `TelegramBroadcast.photo_file_id`, `StorefrontSetting.telegram_welcome_banner_file_id`, plus navigation/draft/cache payload references. File IDs are not local file bytes. Preserve historical linkage in the private map, never reuse a production bot's reference as a testing-bot reference.
- **Custom emoji IDs**: catalog/menu/message custom emoji and snapshots are capability-scoped presentation identifiers, not payment-proof media. Preserve the fallback glyph/state and independently qualify testing-bot capability; do not treat a custom emoji ID as a downloadable photo file ID.
- **Private secret/runtime material**: the credential fingerprint key file belongs only to secret transfer. Do not restore sessions, queue messages, logs, compiled views, temporary credential renders or Redis wakeups as runnable state. Retain durable PostgreSQL delivery/work/history rows with their original states; omitting disposable runtime files is not permission to prune durable obligations.

Each H0 private file-manifest entry must bind opaque artifact ID, source logical disk/bot namespace, private relative path/reference, referring table/PK/column or JSON path, classification, size/media type/dimensions when known, private checksum, source availability, acquisition result, replacement policy and retention. Record unreferenced files, duplicate/shared references, dangling DB paths and remote-only media as distinct states; do not silently drop them. Reject traversal, symlink escape and unsupported file types. Do not follow a restored path or fetch an external URL during import.

Each derived entry must bind an opaque source-manifest identity, owning unchanged row/relation, synthetic destination path, replacement media type/dimensions/checksum, availability state and policy version. Do not put raw source file IDs, paths, customer names or source-content checksums into a report intended for wider sharing.

The **per-bot remap** is keyed by `(source_bot_identity, source_file_reference)` and resolves to `(testing_bot_identity, synthetic_target_reference, artifact_identity, availability, policy_version)`. Preserve one-to-many row references to a shared source artifact and distinct source artifacts without accidental deduplication. `file_unique_id`, if later acquired, is correlation only, not a send/download reference. Actual re-upload or `getFile` is outside this offline task and requires the separate approved testing-bot egress gate. Synthetic fake references are not proof of Telegram acceptance. Unknown/unavailable media remain explicitly unavailable; never label invented bytes as an acquired customer artifact.

`TelegramWelcomeBanner.php:98-164` caches the returned file ID in the setting after upload and binds it to banner version/path. Replacing only the banner path while retaining a production file ID is unsafe. Remap version-sensitive navigation/media references consistently without rewriting historical sent-delivery state or issuing Telegram sends.

## 7. H0 operator handoff and downstream gates

Before real input can be admitted, H0 must provide all of the following through the approved custody boundary:

- Explicit acquisition authorization, approved consistent snapshot/window and named custodians; no permission is inferred from this issue.
- Encrypted immutable full database artifact and authenticated manifest, source revision/image/tool versions, actual catalog inventory and role/ACL/default-privilege/sequence evidence. Any missing scope is marked unknown and blocks that mapping.
- Separately transferred Laravel current/previous keys and key identifiers sufficient to prove decryption coverage; original credential fingerprint key and readiness digest continuity; optional waitlist key lineage; archive decryption access. Do not transfer wallet seed/private keys: the watch-only source application does not own them.
- Private physical/logical storage and per-bot remote-reference inventory, acquisition/availability states, protected artifact classification and safe replacement approvals. No promise that remote Telegram artifacts remain retrievable.
- Explicit local role/object mapping, unknown-object disposition and trigger-compatible transform-before-insert plan for every sensitive immutable binding and nested payload. P03 and subsequent parity owners must expand the synthetic allowlist from actual observed schema; P02 does not claim production-wide sanitization coverage.
- Raw/secret/mapping/derived retention expiries, access-revocation/deletion procedure and evidence location. Approved test-bot identity, sandbox targets and later adapter qualifications stay separate from raw acquisition.

P02 completion is source contracts plus the parent's synthetic quarantine/sanitization evidence. It is not H0 acquisition, production activation, a grant to unquarantine, or H1 approval for a full rewrite. Context7 guidance and executed local verification are integrated by the main implementation owner in the P02 README/verification record; this contract claims no external lookup or runtime test execution of its own.
