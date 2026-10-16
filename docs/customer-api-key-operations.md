# Customer API credentials and admission (S04, #621)

This implements the credential/admission foundation from [#617](https://github.com/Alhiefikri/telegram-bot/issues/617), not the later catalog, checkout, delivery, documentation website or Store Bot menu tickets. No production exposure, CI, deployment or financial activation is authorized.

## Credential ownership

`CustomerApiCredentials` owns backend credentials, separate from Admin `User`, Customer browser sessions, supplier connection secrets and Recovery Keys. The token format is `capi_<32 lowercase hex selector>.<64 lowercase hex secret>`. The secret has 256 random bits; the independent selector has 128. Authentication performs one unique-index selector lookup and `hash_equals` against SHA-256 of the random secret. There is no positive-auth cache, password KDF or credential-table scan.

`customer_api_credentials` stores only selector, digest, Customer binding, creation/revocation/coalesced last-use metadata and a hashed short-lived rotation confirmation. A partial unique index enforces one unrevoked credential per Customer. Lifecycle transactions serialize on the Customer; PostgreSQL also rejects identity/digest rebinding, history deletion and reactivation. The Eloquent model hides everything except safe timestamps and rejects identity changes/deletion. Render only the explicit `summary()` projection, not whole models. Its request count is account-scoped across rotations.

`IssuedCustomerApiKey` holds the full value in private process memory. `takeSecret()` consumes it once; JSON/debug output contains only the mask, serialization throws, and cloning is disabled. No service can recover an old value. If the first display fails, the credential remains active: disable or confirm rotation. Never queue/cache this result or the string returned by `takeSecret()`.

## Private Store Bot adapter contract

`TelegramCustomerApiActions` accepts trusted update actor/chat/type/message facts. It resolves an existing exact numeric Telegram Customer without registration or username lookup; groups, cross-actor chats, unknown actors and replaced roots fail closed. The mutation transaction locks Customer, rechecks its canonical Telegram identity and takes a shared current-navigation-root lock before credential work. Limiter calls run before these DB transactions, never as replayable transaction side effects.

- `generate(...)` creates only when no key is active; concurrent double taps do not rotate an existing key.
- `prepareRotation(...)` returns a five-minute, 128-bit confirmation nonce bound to the current key/message. Persist only its digest. The 32-character nonce fits a Telegram callback with an action prefix; it is not an API key.
- The future S08 adapter must render an explicit destructive confirmation before passing that nonce to `rotate(...)`. Consumption and old-key revocation/new-key creation commit together. Stale, wrong-message, expired and replayed confirmations cannot replace the new key.
- `disable(...)` revokes the current credential, including an undisplayed one. It has a separate anti-flood budget, so exhausted generation quota does not prevent emergency disable.
- `summary(...)` returns the mask, timestamps and eventual account request count; it never returns a public selector suitable for reconstructing the token or a full key.

### Narrow exception to ADR-0007

The future S08 first-display adapter must send the consumed full key once through the existing Telegram client, outside every DB/retried transaction. This is a deliberately non-durable private secret display, not an Outbound Effect. Do not put it in an effect row, queue, navigation state, callback, URL, response replay cache, trace or error report. Do not retry definite or ambiguous sends; instruct the Customer to regenerate. The original message can remain in Telegram history; this does not promise deletion or screenshot protection. This foundation does not add a Telegram send path.

## HTTP integration and revocation

`CustomerApiBoundary` applies to **all** `/api/v1` paths, including unknown paths, before CORS, request-body normalization and route dispatch, but after the existing trusted-proxy/host initialization. Future routes in `routes/api.php` automatically inherit it. It accepts only the Authorization Bearer header, removes that value from the downstream request, rejects browser Origin requests, and never logs a user into any guard. Query/cookie/session values cannot authenticate. Controllers consume the immutable `customer_api_principal` request attribute and must scope every resource to its Customer ID. A credential is not authority to use Admin or supplier webhook routes.

All responses use no-store/private, no-referrer and an application-generated `X-Request-ID`. Errors have `error.code`, `error.message`, `error.request_id`. Missing/invalid/revoked keys return 401; browser use returns 403; oversized bodies return 413; exhausted admission returns 429; unavailable shared admission returns 503 before authenticated work. The API exception renderer suppresses SQL/provider/debug details even with debug enabled. Future endpoint DTOs still own allowlisted success/error payloads and must not serialize credential-bearing models.

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
