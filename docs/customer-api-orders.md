# Customer API Orders (S06, #623)

Contract: [#617 sections 4.2, 5, 5.1, 6, 7 and 10](https://github.com/Alhiefikri/telegram-bot/issues/617),
implemented by [#623](https://github.com/Alhiefikri/telegram-bot/issues/623). Authentication,
shared admission, rate-limit headers and revocation follow
[Customer API key operations](customer-api-key-operations.md). Quote semantics follow
[Customer API reads](customer-api-reads.md). The idempotency design is
[ADR-0025](adr/0025-api-order-idempotency-claims.md).

`POST /api/v1/orders` creates exactly one paid, balance-funded Order through the canonical
`OrderCheckoutService`. A backend client that loses the response, times out, restarts or
retries concurrently can recover the same accepted Order without a second debit, a second
stock/credential allocation or any effect on the Customer's Telegram/Web Cart. It is a
server-to-server route on the existing website origin.

## Request

```
POST /api/v1/orders
Authorization: Bearer <secret>
Idempotency-Key: <visible ASCII, 1-128 characters>
Content-Type: application/json
```

| Field | Type | Rules |
| --- | --- | --- |
| `product_id` | string | Required. Canonical positive decimal string of one visible sellable Product. JSON numbers are rejected. |
| `quantity` | integer | Required. 1–100; existing stricter Product/business and overflow checks still apply. |
| `preference` | string | Optional. `idr_only`, `usd_only`, `idr_first` or `usd_first`. Wins over the account preference. |
| `expected_totals` | object | Optional. Exact decimal strings keyed by `IDR` and/or `USD`. See consent rules below. |
| `inputs` | — | Not supported. The current canonical automatic checkout accepts no buyer-supplied Product input fields, so `input_requirements` is empty in the catalog contract and any `inputs` key is 422. |

Body maximum is 32 KiB. Any other top-level field, a non-object body, an int/float/
zero-padded `product_id`, a non-integer or out-of-range `quantity`, an unknown
`preference` or `expected_totals` key, a USD value with more than six decimals or a
non-canonical amount is 422 `invalid_input`. Missing, empty, over-128-character or
non-printable-ASCII `Idempotency-Key` values are 422 `invalid_input`.

`expected_totals.IDR` is whole rupiah; `expected_totals.USD` is up to six decimals and maps
exactly to internal micro-USDT (`"2"` and `"2.000000"` are the same amount). They are
**price-change consent only**: the server always recomputes the price from the locked
canonical snapshot and never trusts a client total.

## Preference, consent and fallback

The claim resolves one preference: the request override when supplied, otherwise the
Customer's saved account preference read at admission. The resolved mode and its source
are snapshotted on the durable claim, so a later account-preference edit cannot change a
pending or retried request.

Within one locked checkout transaction the server:

1. validates every authorized candidate currency's expected total against the locked
   snapshot; a mismatch on any candidate, primary or secondary, is 409 `price_changed`
   with safe new totals, before any debit;
2. requires an expected total for the resolved primary currency whenever that currency has
   an eligible quote — otherwise 422 `invalid_input` with no Order/debit;
3. selects the first authorized candidate whose expected total matches and whose wallet
   fully covers it, and debits exactly one wallet.

Automatic preference never charges an unacknowledged secondary price: if the primary is
unaffordable and the caller did not authorize the secondary, the request is 402
`insufficient_funds` rather than a silent fallback. A frozen, drifted, paused or
unreadable wallet, stock failure and unknown external result never trigger fallback.
A currency without an eligible price is skipped; another eligible currency still requires
the caller's expected-total consent. Once committed, retries, cancellation and refund keep
that Order's original currency and amount.

## Response

Newly accepted Order: **201**. Replay of the same accepted claim: **200**, preserving
Order identity, currency and amount, with `idempotent_replay: true`. Current lifecycle
status and the HTTP request ID can differ between responses.

```json
{
  "data": {
    "id": "9a1f0c52-9d3f-4de0-9b7a-3ccf0f2c9d10",
    "invoice_number": "INV-20261016-0007",
    "origin": "api",
    "status": "processing",
    "payment_status": "paid",
    "currency": "USD",
    "amount": "2",
    "quantity": 1,
    "product": {"id": "42", "name": "Example Product"},
    "created_at": "2026-10-16T09:12:34+07:00",
    "request_id": "0f0c1c3e-6c2f-4c1d-9d4e-9d3f0d5a6b7c"
  },
  "idempotent_replay": false
}
```

`currency` is the public denomination: internal `IDR` stays `IDR`, internal micro-USDT is
reported as `USD` with up to six decimals. `status`/`payment_status` are the canonical
Order/Payment values. No credential, ledger entry, cost, supplier, acquisition or secret
internal crosses this allowlist, and no credential-bearing response body is stored for
replay: every field is reconstructed from the durable Order, its items and its Payment.

## Idempotency

Scope is Customer + `create_order` + `Idempotency-Key`, never the key version, so rotation
does not create a second purchase. A claim binds the canonical payload fingerprint
(Product, normalized quantity, supported inputs, requested preference mode, expected
totals). Claims and the accepted Order association are unique at the database level.

| Situation | Result |
| --- | --- |
| Same key, same payload, first acceptance | 201 with the new Order |
| Same key, same payload, after a lost response / restart / rotation | 200 with the same accepted Order |
| Same key, different payload | 409 `idempotency_conflict`; no effect |
| Same key, an identical request still running | bounded wait (~2 s, no locks held), then 409 `in_progress` with `Retry-After` |
| Same key, abandoned pre-order claim | one atomic takeover of the expired lease; loser gets the replay or `in_progress` |

The pre-order lease is short and bounded; a request that never committed is recoverable,
never a stuck lock. A financially rolled-back attempt releases its own lease and never
becomes an accepted claim, so the same key can retry the same local DB work after the cause
is fixed (for example, after funding the wallet). Successful claims never expire, are never
pruned and can never create a second purchase. API key rotation does not reset claims.

To change anything in the body, send a **new** `Idempotency-Key`; a changed payload on an
existing key is always a conflict.

## Errors

Errors keep `error.code`, `error.message` and `error.request_id` matching `X-Request-ID`.
`price_changed` additionally carries `error.quotes` with the safe new totals.

| Status | Code | Meaning |
| --- | --- | --- |
| 401 | `unauthenticated` | Missing, invalid or revoked key; also a key revoked before the financial fence |
| 402 | `insufficient_funds` | No authorized eligible wallet fully covers the Order |
| 403 | `backend_only` | Browser `Origin` with a Customer key |
| 404 | `not_found` | Product does not exist or is not publicly purchasable |
| 409 | `idempotency_conflict` | The key was already used with a different payload |
| 409 | `price_changed` | An authorized expected total no longer matches; `error.quotes` lists fresh IDR/USD totals |
| 409 | `stock_conflict` | The requested quantity is no longer available |
| 409 | `in_progress` | An identical request is still running; retry with the same key after `Retry-After` |
| 413 | `payload_too_large` | Body over 32 KiB |
| 422 | `invalid_input` | Malformed, unknown or missing input, including a missing expected total for the resolved primary currency |
| 429 | `rate_limited` | Shared admission quota exhausted |
| 503 | `temporarily_unavailable` | Shared admission, checkout maintenance, funding/restore gate or drift failure; nothing was charged |

No error mutates financial state: a rejected request leaves no Order, Payment, debit,
reservation, stock allocation or API cart row.

## Revocation semantics

`CustomerApiPrincipal::revalidateForMutation()` runs inside the API checkout transaction
after the shared Customer lock and before any write, including the isolated Cart insert.
Rotate/disable take the Customer row exclusively before the credential row, so:

- a mutation that acquired the Customer lock and revalidated before the revocation
  linearization point may finish, and revocation waits for it;
- a request merely admitted before revocation but not yet revalidated fails with 401 and
  performs no write;
- a request after revocation never authenticates.

## Lock order

The API order path adds one deterministic prefix to the canonical checkout chain:

```
Customer shared → credential shared (terminal authentication fence) → claim FOR UPDATE
→ isolated API Cart + item → balance account(s) → funding policy shared → restore singleton shared
→ checkout maintenance → cart items by Product → products by ID → suppliers by ID
→ SKUs by ID → payment setting → created Order/Payment/items → paid lifecycle, debit,
allocation
```

After the credential fence, checkout acquires the claim row and its own related
resources. The API never enters checkout holding Order/Payment, supplier,
policy-exclusive or restore-exclusive locks. It retains the credential shared lock
through commit and never mutates the Customer's Telegram/Web Cart.

Claim acquisition commits separately before this financial transaction. After the
credential fence, checkout locks the claim row and verifies its attempt token before
creating a Cart or touching a wallet. An expired lease cannot be stolen during financial
commit, and a suspended worker whose token was replaced returns 409 `in_progress`
without purchasing. Acceptance cannot silently succeed for a stale token.

## Verification

Local verification on 2026-10-16 used host PHP 8.5.9, application image PHP 8.4.26,
PostgreSQL 16.15 and disposable Redis 7. No CI/CD, production, deployment, commit or push
was performed.

### Behaviour and concurrency

The regression batch exercises API Orders, API reads/credentials, both balance checkout
rails, refunds, reseller pricing, Telegram carts, storefront checkout, maintenance and
supplier fulfillment:

```sh
cd src
php vendor/bin/phpunit tests/Feature/CustomerApiOrderTest.php \
  tests/Feature/CustomerApiReadTest.php tests/Feature/CustomerApiCredentialsTest.php \
  tests/Feature/UsdtBalanceCheckoutTest.php tests/Feature/OrderCartDomainTest.php \
  tests/Feature/UsdtOrderRefundTest.php tests/Feature/ProductResellerPricingTest.php \
  tests/Feature/Telegram/UsdtBalanceCheckoutTest.php tests/Feature/Telegram/CartCheckoutTest.php \
  tests/Feature/StorefrontShopCheckoutTest.php tests/Feature/StorefrontProductCheckoutTest.php \
  tests/Feature/CheckoutMaintenanceTest.php tests/Feature/SupplierFulfillmentTest.php

DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=15525 DB_DATABASE=s06_reuse_test \
  DB_USERNAME=postgres DB_PASSWORD=postgres RUN_CONCURRENCY_TESTS=1 \
  php vendor/bin/phpunit -c phpunit.postgres.xml \
  tests/Feature/CustomerApiOrderPostgresConcurrencyTest.php \
  tests/Feature/UsdtBalanceCheckoutPostgresConcurrencyTest.php
```

The SQLite regression batch passed 244 tests and 3,165 assertions. Pint passed on the
changed files.

The PostgreSQL batch passed 23 tests and 354 assertions. It reuses one disposable
database, including fresh migrations between tests. `CREATE OR REPLACE FUNCTION` in the
S04/S06 guard migrations prevents stale PL/pgSQL functions from breaking database reuse.

| Scenario | Observed invariant |
| --- | --- |
| Three concurrent matching API requests | One Order, Payment, USDT debit and allocated credential; losers replay that Order or report `in_progress` |
| Changed payload on one key | One durable claim and one purchase; the changed request conflicts |
| API versus bot, last funds and last stock | Overlapping transactions in both forced winner orderings; one paid Order, no duplicate allocation or leaked reservation, losing wallet untouched |
| Price edit versus purchase | One complete old locked quote or `price_changed` with no debit/allocation |
| Revocation versus checkout | An already fenced transaction commits once while disable waits; a later mutation with that key is rejected |
| Expired claim, three competing takeovers | One accepted Order and one debit; a live lease is not stolen |
| SIGKILL after Order insertion but before commit | No Order, Payment, debit, allocation or API Cart survives; expiry plus retry recovers the same claim |
| SIGKILL after commit before returning | One accepted Order survives; replay after credential rotation and preference/reseller-price edits returns that original USD 2 purchase |
| Database guards | Claim identity/acceptance/history, Order origin and Cart origin cannot be rewritten |

The stale-worker regression was observed failing before the fence fix: a replaced
attempt token still permitted a purchase. It now requires `in_progress` and exactly zero
Orders, Payments, API Carts, purchase debits and allocations, preserving the other token.
Changed-payload coverage includes a nonexistent replacement Product; conflict resolution
precedes catalog lookup.

The API supplier regression drops all queued wakeups, rediscovers the committed
`SupplierFulfillment`, then injects one ambiguous provider response. Recovery and API
replay retain one supplier purchase, one customer debit and the original holds; neither
repurchase nor refund is allowed. The transport assertion requires transaction depth zero.
Provider responses are fixtures, not live supplier qualification.

### Disposable HTTP postcommit timeout and retry

The real `/api/v1/orders` route ran through the application image with PostgreSQL/Redis,
bound only to `127.0.0.1:18623`. Queue connection was `null` and the Telegram token empty.
The fixture funded one Customer with 5,000,000 micro-USDT, stocked one USD 2 Product,
and created a separate active bot Cart containing that Product.

An initial HTTP request returned 201 with paid metadata. For the timeout scenario, the
disposable router registered an eight-second delay through `DB::afterCommit()`, plus a
commit marker. The client timed out at five seconds while the marker already existed.
Direct PostgreSQL inspection before retry proved the purchase had committed.

After changing the account preference to `idr_only`, changing base/reseller USD prices
to 9/7, hiding the now-empty Product, rotating the API credential and restarting the HTTP
process, the exact same body and idempotency key returned 200:

```json
{
  "id": "01a144f1-bbe2-73c8-a8dc-c2c2f170b150",
  "origin": "api",
  "payment_status": "paid",
  "currency": "USD",
  "amount": "2",
  "idempotent_replay": true
}
```

This is an excerpt from the allowlisted response, not a cached response body. The old
credential returned 401; changing quantity or replacing the Product with a nonexistent
id on the accepted key returned 409 `idempotency_conflict`. A final HTTP process restart
and disposable Redis flush preserved the same accepted Order and economic state.
The response used `Cache-Control: no-store, private`.

Direct database snapshots before and after replay/rejected requests were equal:

| Durable state | Value |
| --- | --- |
| Orders / Payments / USDT purchase debits / allocated units | 1 / 1 / 1 / 1 |
| IDR purchase debits / stock reservations / remaining stock | 0 / 0 / 0 |
| Customer balance | 3,000,000 micro-USDT |
| Claim | Accepted, original credential id 1, same Order id |
| API Cart | One, `checked_out` |
| Original bot Cart | Same id, `active`, same quantity 1 |

The timeout router and fixture scripts are disposable verification scaffolds, not
application hooks. The HTTP server and Redis container, smoke database and temporary
files are removed after verification; the pre-existing PostgreSQL test container is
not owned by this continuation.

### Limits

- This evidence uses fixture catalog/inventory and synthetic credentials. It does not
  qualify production throughput, TLS/CDN behavior, live Telegram or supplier purchases.
- The host PHP lacks the Redis extension; HTTP used the application image's real Redis
  client instead of bypassing shared admission.
- One Product and quantity 1–100 are supported. Canonical automatic checkout currently
  has no buyer-input fields; catalog `input_requirements` remains empty.
- Own-order history/status/delivery belongs to #624, notifications to #625, public
  documentation publication to #626 and Store Bot management to #627.
- Existing custody, funding, provider, pause and restore qualification gates remain
  fail-closed. This work does not authorize financial production exposure.
