# Customer API credentials and admission (S04, #621)

This implements the credential/admission foundation from [#617](https://github.com/Alhiefikri/telegram-bot/issues/617) and its private Store Bot management adapter (#627). Catalog, checkout, delivery and public documentation retain their own owners. No production exposure, CI, deployment or financial activation is authorized.

## Credential ownership

`CustomerApiCredentials` owns backend credentials, separate from Admin `User`, Customer browser sessions, supplier connection secrets and Recovery Keys. The token format is `capi_<32 lowercase hex selector>.<64 lowercase hex secret>`. The secret has 256 random bits; the independent selector has 128. Authentication performs one unique-index selector lookup and `hash_equals` against SHA-256 of the random secret. There is no positive-auth cache, password KDF or credential-table scan.

`customer_api_credentials` stores only selector, digest, Customer binding, creation/revocation/coalesced last-use metadata and a hashed short-lived rotation confirmation. A partial unique index enforces one unrevoked credential per Customer. Lifecycle transactions serialize on the Customer; PostgreSQL also rejects identity/digest rebinding, history deletion and reactivation. The Eloquent model hides everything except safe timestamps and rejects identity changes/deletion. Render only the explicit `summary()` projection, not whole models. Its request count is account-scoped across rotations.

`IssuedCustomerApiKey` holds the full value in private process memory. `takeSecret()` consumes it once; JSON/debug output contains only the mask, serialization throws, and cloning is disabled. No service can recover an old value. If the first display fails, the credential remains active: disable or confirm rotation. Never queue/cache this result or the string returned by `takeSecret()`.

## Private Store Bot adapter contract

`TelegramCustomerApiActions` accepts trusted update actor/chat/type/message facts. It resolves an existing exact numeric Telegram Customer without registration or username lookup; groups, cross-actor chats, unknown actors and replaced roots fail closed. The mutation transaction locks Customer, rechecks its canonical Telegram identity and takes a shared current-navigation-root lock before credential work. Limiter calls run before these DB transactions, never as replayable transaction side effects.

- `generate(...)` creates only when no key is active; concurrent double taps do not rotate an existing key.
- `prepareRotation(...)` returns a five-minute, 128-bit confirmation nonce bound to the current key/message. Persist only its digest. The 32-character nonce fits a Telegram callback with an action prefix; it is not an API key.
- `TelegramCustomerApiCallbackHandler` renders an explicit destructive confirmation before passing that nonce to `rotate(...)`. Consumption and old-key revocation/new-key creation commit together. Stale, wrong-message, expired and replayed confirmations cannot replace the new key.
- `prepareDisable(...)` and `disable(..., confirmation)` use a separate anti-flood budget, so exhausted generation quota does not prevent emergency disable. The credential owner provides `disableConfirmed(...)`; its five-minute nonce is bound to the active key and canonical message. It shares the existing confirmation slot with rotation but hashes a `disable:` purpose prefix. Preparing either operation invalidates the previous confirmation, and a disable nonce cannot authorize rotation or revoke a replacement key. Trusted non-Telegram lifecycle callers retain `CustomerApiCredentials::disable(Customer)`.
- `summary(...)` returns the mask, timestamps and eventual account request count; it never returns a public selector suitable for reconstructing the token or a full key.

### Narrow exception to ADR-0007

The S08 adapter sends the consumed full key once through the existing Telegram client, outside every DB/retried transaction. This is a deliberately non-durable private secret display, not an Outbound Effect. Do not put it in an effect row, queue, navigation state, callback, URL, response replay cache, trace or error report. The send has no custom emoji or keyboard and uses no automatic HTTP retries. Exceptions are caught without forwarding secret-bearing objects to logs or error reporting. Missing positive message-ID proof, definite rejection and ambiguous timeout all leave the key active and offer regeneration/disable on the canonical root, never recovery or resend. The original message can remain in Telegram history; this does not promise deletion or screenshot protection.

### Store Bot screens (#627)

Private `/start` exposes `api:menu` through the editable `menu.api` DB icon slot (Unicode fallback `🔌`) and primary/blue buttons. The migration adds only the slot; existing Admin Dashboard icon editing and Store Bot `menuicon` capture/clear paths own edits. No ENV or callback changes are needed for icon updates. Groups do not expose the entry.

`TelegramCustomerApiIntents` renders the no-key screen, masked active-key screen, confirmation and four payment-preference choices in Indonesian, English and Malay. `TelegramCustomerApiCallbackHandler` matches the originating canonical root and exact private actor/chat before calling `TelegramCustomerApiActions`; the action boundary rechecks Customer identity and the root under locks. Back/Home reuse normal navigation. Secret display is a separate message and never becomes the root.

Preference writes update the existing `Customer.balance_payment_preference` enum under Customer/current-root authority. Per-order API overrides and explicit Bot payment-rail selection remain unchanged. No wallet, Order or idempotency owner is called by this adapter. Documentation uses `url('/api-docs/')` on the existing application origin and resolves to the public S10 route.

Metadata comes from the bounded S04 summary, with ISO timestamps and real account admission count across rotations. The screen explicitly labels the count as account-scoped, eventually consistent and potentially incomplete; last admission is minute-rounded, not fulfillment. Usage buckets persist UTC minute timestamps, so the raw account timestamp is parsed explicitly as UTC rather than accidentally shifted by the application timezone.


## HTTP integration and revocation

`CustomerApiBoundary` applies to **all** `/api/v1` paths, including unknown paths, before CORS, request-body normalization and route dispatch, but after the existing trusted-proxy/host initialization. Future routes in `routes/api.php` automatically inherit it. It accepts only the Authorization Bearer header, removes that value from the downstream request, rejects browser Origin requests, and never logs a user into any guard. Query/cookie/session values cannot authenticate. Controllers consume the immutable `customer_api_principal` request attribute and must scope every resource to its Customer ID. A credential is not authority to use Admin or supplier webhook routes.

All responses use no-store/private, no-referrer and an application-generated `X-Request-ID`. Errors have `error.code`, `error.message`, `error.request_id`. Missing/invalid/revoked keys return 401; browser use returns 403; oversized bodies return 413; exhausted admission returns 429; unavailable shared admission returns 503 before authenticated work. The API exception renderer suppresses SQL/provider/debug details even with debug enabled. Future endpoint DTOs still own allowlisted success/error payloads and must not serialize credential-bearing models.

The implemented S05 balance/catalog endpoints and their allowlisted schemas are documented in [Customer API reads](customer-api-reads.md). They inherit this boundary without a separate authentication, limiter or financial cache.

### Financial revalidation and lock order

Admission is not financial authorization. S06 must call `CustomerApiPrincipal::revalidateForMutation()` **inside its owning transaction, after acquiring every canonical financial/resource lock and before any financial write**. It refuses calls outside a transaction. It acquires a shared credential-row lock scoped to both admitted credential and Customer. Acquire no new unrelated resource locks after this terminal authentication fence, do no network work, and retain the shared lock through commit.

Lock graph: existing deterministic checkout resources -> shared credential row -> already-owned resource writes/commit. Key lifecycle: Customer -> current navigation root (adapter only) -> credential update. Key lifecycle never locks wallets, inventory, Orders or supplier resources. It does not hold a key lock and then ask for financial resources.

Revocation linearizes at the credential update transaction's commit. Reads authenticated earlier may finish. Mutations that acquired/revalidated the key lock before revocation may finish; revocation waits for them. A request merely admitted before revocation but not yet revalidated must fail its later mutation fence. A request after revocation never authenticates. Rotation does not erase account Orders/idempotency. This ticket does not implement checkout; the fence is a required S06 integration seam, not a substitute for its durable claims.

## Shared limits

Admission uses the existing configured `cache.limiter` store (or the existing default cache store when unset), which **must be Redis**. Array, file, database, failover stores, Redis errors, capacity errors or a missing application key produce 503. Do not introduce a fallback. Keys use the existing cache/Redis prefixes and HMAC the relevant account/IP identity. Atomic Redis scripts enforce all applicable budgets across workers; only Redis server time defines budget/permit expiry.

| Budget | Bound |
| --- | --- |
| All v1 traffic per trusted real IP | 600 / 60 seconds |
| Missing/invalid auth per IP | 30 / 60 seconds |
| Reads per Customer, shared across endpoints and rotations | 120 / 60 seconds |
| Delivery reads, additionally | 10 / 60 seconds |
| Mutation/order attempts per Customer, including retries/conflicts/concurrency misses | 10 / 60 seconds |
| Global reads | 300 / second |
| Global mutations | 20 / second |
| Concurrent mutations per Customer | 2 |
| Generate/rotate attempts per Customer | 3 / 600 seconds |
| Disable attempts / rotation-confirmation preparations, independently | 30 / 600 seconds each |

Budgets are first-hit fixed windows, not sliding-window guarantees. A rejected quota does not extend its expiry. `X-RateLimit-Limit`, `X-RateLimit-Remaining` and `X-RateLimit-Reset` report the applicable budget with the lowest remaining fraction (or the blocking budget on rejection); reset is an epoch-second conservative ceiling. A 429 adds a positive `Retry-After` in seconds. Limits are initial admission policy, not measured production capacity. An IP quota runs before authentication; a global rejection can conservatively consume an earlier IP admission.

Mutations, including unknown non-read methods/paths, share the order budgets; endpoint switching cannot obtain another bucket. Delivery classification uses Laravel's decoded path, matching routing even when clients encode `delivery` characters or slashes. Each concurrency permit has an unguessable owner token, a 90-second lifetime (longer than the existing 60-second FPM request termination), and token-specific release in `finally`. Crashes expire; old/foreign releases cannot free a replacement token. Release/usage failures after a committed outcome do not undo it. Leases are admission only: expiry, Redis restart or crash must never permit duplicate financial effects; PostgreSQL checkout/idempotency/ledger constraints remain mandatory.

## Bounded, lossy usage

An admitted authenticated request contributes one non-financial count, even when downstream validation/not-found fails. Unauthorized, oversized, rate-limited and unavailable requests do not count as authenticated admissions. Last-use means admission, not completed fulfillment.

`CustomerApiUsage` keeps at most 10,000 Customer/credential fields per minute bucket in the existing Redis cache connection. Each bucket expires 600 seconds after its last contribution. Fields contain only integer IDs/counts, never payloads or keys. The scheduled `php artisan customer-api:flush-usage --limit=500` drains at most 500 aggregates across the latest ten minute buckets (hard command/service cap 1000); it does not scan the credential table or all Redis keys. Its maximum retained shape is ten active minute buckets plus an expiring boundary bucket. Full buckets drop new identities, and backlog expires. Operators may run additional bounded drains when necessary.

The drain atomically removes each batch from Redis **before** applying per-Customer/credential DB aggregates. A crash/DB failure after removal loses those counts; retry cannot double them. Redis loss, full buckets, expiry or a failed contribution also loses telemetry. There is no durable telemetry queue. Account counts are honest eventual lower bounds since this feature began, not billable/exact lifetime totals. Last-used timestamps conservatively round down to the contributing minute. Multiple workers can drain safely; no request synchronously updates a Customer/global/credential DB counter. No money or entitlement decision may read these values.

## Logging and transport boundaries

The Nginx v1 location routes directly to the front controller, caps the body at 32 KiB, disables access/error request logging there, and returns a safe JSON/no-store 413 with request ID. The application also bounds the input stream without trusting Content-Length. Do not enable body/header tracing at an upstream TLS proxy, CDN, APM, FPM access logger or packet-capture layer; those layers require separate operator verification.

Standard application log channels install `RedactCustomerApiSecrets`: v1 request details/context are suppressed wholesale, while out-of-request token-shaped values and sensitive context fields are redacted. Arbitrary exception/serializer objects do not enter these logs. The Telegram transport redactor also removes Customer API token patterns from provider error text. Do not add custom logging channels or third-party tracing that bypass this boundary. Application exception reporting for v1 is suppressed rather than forwarding secret-bearing exception objects. Safe status/request IDs remain available on HTTP responses; there is no new analytics system.

## Verification entry points

- `CustomerApiCredentialsTest`: one-time result, rotation/replay/expiry, old admitted authority rejected after disable/rotate, private actor rejection and fail-closed non-shared stores.
- `CustomerApiCredentialsPostgresTest`: three-process generate/rotate winners, shared mutation fence versus revocation, independent DB uniqueness/immutability.
- `CustomerApiAdmissionPostgresTest`: real Redis plus PostgreSQL requests/workers, account/endpoint/rotation/IP bounds, delivery/body limits, owner-fenced crash permits, usage drain and outage, and key-operation cooldowns. Requires `RUN_CONCURRENCY_TESTS=1`, `RUN_CUSTOMER_API_REDIS_TESTS=1` and an isolated test database/cache.
- `CustomerApiLoggingTest`: synthetic unique tokens and private delivery payloads through actual log and Telegram-error redaction.

SQLite tests are not PostgreSQL or multi-worker Redis evidence. A disposable HTTP/proxy walkthrough is required separately; fixture handlers prove middleware/service behavior, not purchase fulfillment, live Telegram transport, production proxy settings or production capacity.

## Disposable verification recorded for S04

Local verification used PHP 8.4.26, PostgreSQL 16, Redis 7, four PHP HTTP workers and Nginx 1.27/PHP-FPM with this repository's proxy configuration. Runtime workers were restarted with opcode caching disabled before the final walkthrough. All data, credentials, Redis state and handlers were disposable. The HTTP handlers exercised real admission, authentication, revocation fencing and non-financial fixture writes; they did not implement or claim S06 checkout.

Commands exercised:

```sh
php vendor/bin/phpunit tests/Feature/CustomerApiCredentialsTest.php \
  tests/Feature/CustomerApiLoggingTest.php tests/Feature/CustomerAuthContractTest.php \
  tests/Feature/CustomerAccountTest.php tests/Feature/CustomerAuthenticationCompatibilityTest.php \
  tests/Feature/Telegram/WebAuthenticationWebhookTest.php

# In the disposable PostgreSQL/Redis runtime, with the two opt-in flags above:
php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php \
  tests/Feature/CustomerApiCredentialsPostgresTest.php \
  tests/Feature/CustomerApiAdmissionPostgresTest.php

php artisan customer-api:flush-usage --limit=500
nginx -t
```

Results: SQLite/compatibility 62 tests, 1040 assertions; PostgreSQL/shared Redis 11 tests, 252 assertions. The full-bucket regression failed before correcting Lua's numeric `HEXISTS` comparison, then passed in the complete focused lane. An encoded-delivery regression also reproduced a 200 after quota exhaustion before switching classification to Laravel's decoded path; it now returns 429. Shared Redis tests used database 2, separate from HTTP smoke database 1; the existing test base flushes its configured cache database.

The final four-worker HTTP run admitted 120 reads across a confirmed rotation and different paths, rejected the next with 429/Retry-After, and rejected the old key with 401. Disable also returned 401 across workers. Concurrent delayed fixture mutations produced 201/201/429, with two active permits observed. Two permits deliberately abandoned by an exited CLI worker blocked HTTP admission, then a request succeeded after their real 90-second expiry without manually changing lease state. A stopped Redis returned 503 through both HTTP and Nginx/FPM; fixture writes stayed 9 before/9 after. Restart remained fail-closed until Redis became ready.

| Middleware fixture measurement | Observed value |
| --- | --- |
| Accepted-read p50 | 71.15 ms |
| Accepted-read p95 | 88.05 ms |
| SQL queries per accepted read | 1 |
| Framework outbound HTTP requests per accepted read | 0 |
| Active mutation permits sampled | 2 |
| DB lock waiters in that sample | 0 |
| Disposable DB cumulative deadlocks | 0 |

These are local middleware-fixture measurements, not catalog/checkout benchmarks or production capacity. With 10,000 additional revoked credentials, PostgreSQL `EXPLAIN (ANALYZE, BUFFERS)` selected `customer_api_credentials_selector_unique` via Index Scan, returned one row and reported 0.066 ms execution in that sample. The separate revocation race proves blocking until a pre-revocation shared fence commits; zero sampled waiters above does not claim that revocation never waits.

Application HTTP accepted an exact 32 KiB body and rejected 32 KiB + 1 byte. Nginx rejected both oversized Content-Length and chunked bodies with safe 413 JSON, no-store, request ID and security headers. Changing untrusted forwarded addresses did not bypass the invalid-IP budget. A synthetic private query on the oversized-body path did not enter proxy logs. The application emitted its suppression marker, while a scan found no token-shaped fixture secrets or the synthetic private payload. Log/serialization regressions also exercised unique synthetic tokens outside HTTP and Telegram provider-error redaction.

The final encoded-path HTTP walkthrough admitted ten canonical delivery reads, then returned 429 with limit 10 for canonical, `%64elivery` and encoded-slash paths. This uses the actual middleware and route decoder, not a source-text assertion.

Usage drained one new aggregate and zero on replay; the maintenance command also ran successfully. Full-bucket behavior preserves existing fields while dropping new identities. Redis restart discarded volatile counters as documented without changing committed fixture work. Temporary handlers and containers were removed. No production TLS/CDN/APM transport, real Telegram send, financial purchase fulfillment, CI or deployment was exercised or authorized.

## Disposable verification recorded for S08 (#627)

Final local verification used host PHP 8.5.9 for SQLite and the existing application image's PHP 8.4.26 for PostgreSQL 16.15/Redis 7. All database/cache state and Telegram credentials were disposable. The host has neither phpredis nor Predis; its initial shared-admission attempt failed closed with 503. The same lane passed using the Redis-capable application image. A PostgreSQL startup connection failure preceded its ready state and did not indicate an application defect.

```sh
php vendor/bin/phpunit \
  tests/Feature/Telegram/TelegramCustomerApiManagementTest.php \
  tests/Feature/CustomerApiCredentialsTest.php \
  tests/Feature/CustomerApiLoggingTest.php \
  tests/Feature/Telegram/TelegramRoutineNavigationTest.php \
  tests/Feature/Telegram/MenuIconCaptureTest.php \
  tests/Feature/Telegram/TelegramCustomerLocaleTest.php \
  tests/Feature/Telegram/TelegramTranslationAuditTest.php \
  tests/Feature/CustomerApiOrderTest.php \
  tests/Feature/UsdtBalanceCheckoutTest.php \
  tests/Feature/StorefrontApiDocsTest.php

# Disposable PostgreSQL/Redis with both opt-in flags:
php vendor/bin/phpunit --do-not-cache-result --no-configuration \
  --bootstrap vendor/autoload.php \
  tests/Feature/CustomerApiCredentialsPostgresTest.php \
  tests/Feature/CustomerApiAdmissionPostgresTest.php
```

| Final lane | Result |
| --- | --- |
| SQLite adapter, localization, icons, logging, API Orders, explicit balance checkout and public docs | 153 tests, 3573 assertions passed |
| PostgreSQL credential races and shared Redis admission | 12 tests, 261 assertions passed |

The adapter suite exercises real webhook/processor/callback/client navigation with disposable transport responses. It checks double Generate, masked reopening, rotation replay, purpose/key/message/expiry-bound disable, replaced roots, foreign actors, groups, all four preferences, fail-closed admission and no secret in credential/navigation/update/outbound/job rows. Secret-display timeout, definite HTTP rejection and missing message-ID proof each produce one attempt, leave the key active and expose regeneration rather than secret recovery. Admin capture/clear edits the actual `menu.api` slot; fallback retains the callback and primary styling. The obsolete locale test's hard-coded home-row ordering assertion was removed rather than repinned.

The lifecycle regression seeds an existing Order, an accepted account idempotency claim, Rp 25,000 through the IDR ledger and 2 USDT through the typed USDT funding owner. Complete Order, both wallet projection/entry and accepted claim snapshots remain identical after adapter rotation and disable.

The PostgreSQL lane runs independent three-process Generate, Rotate and confirmed Disable races. Each produces one winner. Reusing a consumed disable confirmation after generating a replacement leaves that replacement authenticated. Existing shared mutation fencing, DB uniqueness/immutability, cross-worker quotas, generation cooldown versus emergency disable and Redis outage checks also pass.

A separate throwaway CLI walkthrough bootstrapped the real application, explicitly bound `TelegramBotClient` (not the testing fake client), then sent `/start` and callback update envelopes through the HTTP kernel, ingress, sync processor and actual navigation/client stack. Only the remote Telegram HTTP transport was substituted. The walkthrough inspected rendered no-key, masked, confirmation, timeout and all three localized screens. It exercised all four preferences, Back/Home, rapid Generate, Rotate, stale Disable, group/foreign/stale-message denial, custom icon and unsupported-custom-emoji fallback. `/api-docs` returned 200 at the same local application origin. Real Bearer HTTP-kernel requests to `/api/v1/products` returned 200 before rotation, 401 for the rotated old key, 200 for the replacement and 401 after Disable.

Two admitted requests were drained through `CustomerApiUsage::flush(500)` and appeared as account count `2` with a minute-rounded admission instant. This exposed and fixed UTC timestamp interpretation: the original raw usage minute was shifted by the app timezone; the final summary returned the correct `+00:00` instant, checked against the current minute. Existing Order and IDR ledger credit counts remained one each across rotation/disable in the walkthrough. The primary account ended with zero active keys; a second account's timed-out display left its one key active with no retry. Three total secret-send attempts were observed (Generate, Rotate, timeout), and full-secret/raw-secret-component scans found no secret in durable rows or the walkthrough log.

UI delivery gate, scoped to the new Telegram surface:

- Hard gate PASS: real adapter navigation and all new controls exercised; no dead recovery control, invented usage, secret replay or fabricated claims. Telegram owns native layout, theme, contrast, focus and keyboard rendering; these were not independently qualified on a live client.
- Purpose gate PASS: primary blue follows the existing navigation convention; the plug identifies API integration, wallet identifies preference, link identifies documentation, and Back/Home reuse their existing icon slots. No new visual assets, framework or presentation ENV.
- Liveliness PASS: ENERGY 1 / RHYTHM 1 / MOTION 1; state-first text, separated metadata and one action per row follow the existing compact Store Bot identity. No animation or decorative dashboard.
- Craftsmanship PASS: no-key, active-key, destructive confirmation, limiter error and ambiguous-display recovery paths work; ID/EN/MS catalogs pass the translation audit. The first-secret warning states backend-only storage, save-now and Telegram-history persistence without promising deletion.

Limitations: in-process HTTP-kernel requests and deterministic Telegram transport fixtures are not live network/Telegram-client proof. No production origin, deployment, CI, throughput benchmark, TLS/CDN/APM inspection or custody/provider qualification was performed. Existing fail-closed admission and financial release gates remain in force. Disposable containers and the throwaway walkthrough were removed after verification.
