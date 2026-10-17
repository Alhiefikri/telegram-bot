# Customer API Orders (S06 #623, S07 #624, S09 #625)

Contract: [#617 sections 4.2, 5, 5.1, 6, 7 and 10](https://github.com/Alhiefikri/telegram-bot/issues/617),
implemented by [#623](https://github.com/Alhiefikri/telegram-bot/issues/623) and
[#624](https://github.com/Alhiefikri/telegram-bot/issues/624). Authentication,
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
| Same key, an identical request still running | bounded wait (~2 s), then 409 `in_progress` with `Retry-After` |
| Same key, abandoned pre-order claim | one atomic takeover of the expired lease; loser gets the replay or `in_progress` |

The pre-order lease is short and bounded; a request that never committed is recoverable,
never a stuck lock. Every takeover of an expired lease bounds its own row-lock wait, so a
second request cannot hang behind the first one's transaction and still returns within the
documented wait. A financially rolled-back attempt releases its own lease and never
becomes an accepted claim, so the same key can retry the same local DB work after the cause
is fixed (for example, after funding the wallet). Successful claims never expire, are never
pruned and can never create a second purchase. API key rotation does not reset claims.

To change anything in the body, send a **new** `Idempotency-Key`; a changed payload on an
existing key is always a conflict.

## Errors

Errors keep `error.code`, `error.message` and `error.request_id` matching `X-Request-ID`.
`price_changed` additionally carries `error.quotes`: the fresh IDR total is always present;
USD follows only when the locked snapshot has a complete USD total. IDR-only Products
therefore return one quote, not a null or invented USD total.

The published [Markdown reference](/api-docs/reference.md) and
[OpenAPI document](/api-docs/openapi.json) contain the error/status table generated from
`CustomerApiDocumentation::errors()` and each operation's canonical error list.
Every `503`, including delivery-bound rejection, carries `Retry-After` (five seconds by
default). Invalid UTF-8 paths under `/api/v1` return `400 invalid_path` before admission,
with the same JSON error envelope, UUID `X-Request-ID`, private `no-store` policy and
`Referrer-Policy: no-referrer` as routed errors. No CORS headers are added.

`GET /api/v1/balance` rejects stray query parameters or a body with `422 invalid_input`.
Following S05-fix-A, an unquotable Product on a catalog page stays in the `200` response
with both quotes ineligible and their amounts null; only its detail route returns
`422 quote_unavailable`. The list route must not advertise a page-wide quote failure.

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

S11 (#628) accounts for the Product FK `KEY SHARE` lock taken by the isolated
Cart item insert. PostgreSQL checkout/inventory/supplier lifecycle Product
write locks use `FOR NO KEY UPDATE`, preserving exclusion against price/stock
writers and deletion without conflicting with those FK checks. Upgrading a
nested Product lock to `FOR UPDATE` would recreate the maintenance/Product
cycle between different Customers.

Normal and late last-stock sales commit only the existing
`StockNotificationEvent` handoff, not the entire recipient audience, inside
the financial transaction. The existing stock worker/recovery pipeline owns
bounded recipient materialization. See [S11 integrated acceptance](customer-api-s11-acceptance.md)
for the failing-before/passing-after boundaries, HTTP/worker recovery,
measured query/performance evidence and unchanged production qualification gates.

## Public proof and private Owner notifications (S09)

Order creation snapshots `api_order_claim_id` before the paid lifecycle runs.
The existing Sales Proof and Admin notification pipelines render that immutable
origin, independent of the current key, request retry or polling frequency.

Public API proof requires the canonical paid credential-release decision,
matching inventory sale proof, no pending supplier fulfillment or open Manual
Fulfillment Review, and all ordered units allocated and non-retired. Partial
supplier delivery, ambiguous acquisition and review cannot announce completion.
An all-local balance Order can retain the historical `processing` Order label
while its fulfillment is complete and its units are authorized for API reads.
Proof does not depend on a buyer Telegram receipt. Other channels and historical
Paid Order Proof Events keep their existing timing.

The image's payment-method label and the caption identify API origin. They use
the actual canonical Payment currency and immutable amount/item snapshots:
IDR remains rupiah and internal USDT remains explicitly USDT, while HTTP API
serializers continue exposing the USD denomination. Public fields retain the
existing masked buyer and safe invoice conventions. They omit raw account
identity, payment references, balances, credentials, keys and upstream economics.

The ordinary private paid notice includes the Order, Customer, Product,
actual amount/currency, API origin and useful current fulfillment/review state.
Supplier processing appears there without claiming Telegram delivery. A
canonical Manual Fulfillment Review adds one actionable Owner notice per
review source, not per request, supplier poll or repeated worker execution.
Delayed review notices show the current Order/review state.

`telegram:recover-outbound` remains the recovery command. The original
Order/event keys deduplicate delivery; the new review unit is
`admin.order_review_notification`, keyed `order-review:{review-id}:owner:v1`.
The committed review is its missing-target handoff. Bounded recovery also finds
eligible API Orders without a public proof projection. Existing effect/projection
recovery covers materialized units. Rendering and transport happen outside
financial locks. `unknown` is never blindly resent; an operator uses the existing
Manual Delivery Review protocol. Disabled/missing channels or notification
failure cannot refund, debit again, reallocate or erase an accepted Order.

Architecture clarification: [ADR-0007](adr/0007-telegram-outbound-effects.md#customer-api-public-proof-and-owner-notices-625).
Behavior coverage: `CustomerApiOrderNotificationTest` alongside the existing
Sales Proof, lifecycle/Admin, invoice and supplier tests.

### Disposable outbound verification (2026-10-16)

The local PHP worker used PostgreSQL 16 and Redis 7 on dedicated loopback
ports, the real `TelegramBotClient`, HTTP transport fixtures, the actual
`telegram:recover-outbound` command and `queue:work --queue=notifications
--stop-when-empty`. The non-PHPUnit testing runtime normally binds
`FakeTelegramBotClient`; the smoke explicitly replaced that binding with the
real client before exercising its HTTP boundary.

Four API purchases covered an exact USDT local purchase, an IDR local
purchase with ambiguous public/Owner transport, a successful mixed local/
supplier purchase and an ambiguous mixed acquisition. Redis `FLUSHALL`
removed queue wakeups after commit. Supplier processing retained one purchase
per fulfillment, completed only the successful mixed Order and left the
ambiguous acquisition in review with no public proof.

Recorded public effects were two `sent` and one `unknown`; the review Order
had no public effect. Ordinary Owner effects were three `sent` and one
`unknown`, plus one sent review effect. A second Redis flush, recovery and
worker execution retained all those identities, attempts and outcomes without
another public/Owner transport request. A missing fixture `getMe` identity
initially produced definite proof failures; configuring the fixture identity
recovered the same effects rather than creating new ones.

The inspected 720×720 public images showed API origin, masked buyer and exact
`2 USDT` / `Rp 10.000` totals, including the complete two-unit mixed purchase.
Captured public captions and private paid/review notices carried the expected
origin and current state. Public payloads/images and application logs omitted
raw buyer identity, credentials and upstream source/key/cost data; private
Owner messages retained the intended Customer account details.

A fifth paid Order exercised the real renderer with PHP `imagepng` disabled:
its public effect became `failed` / `telegram_render_failed`, while its Owner
notice sent normally. Restoring PNG support and running recovery sent the same
public effect without changing its identity. Before/after economic snapshots remained identical for
every notification/recovery phase: accepted Orders/Payments and both ledgers
were unchanged, no refunds appeared, and allocation/purchase counts stayed
stable once fulfillment had completed. Fixture delivery is not live Telegram
channel permission proof or supplier qualification. Existing exposure gates
remain fail-closed; no CI/CD, production, activation or deployment was used.

Local regression results:

- SQLite compatibility batch: 137 tests, 1,583 assertions passed, covering
  API checkout/reads/notifications, lifecycle/Admin outbound, supplier
  fulfillment/independent regressions and public invoice/caption behavior.
- PostgreSQL notification behavior: 7 tests, 119 assertions passed.
- PostgreSQL API concurrency/crash/replay regression: 9 tests, 177 assertions passed.
- Final exact-money/caption plus notification run: 15 tests, 161 assertions passed.
  Caption formatting now uses the existing integer-only micro-USDT formatter;
  its boundary regression preserves `9223372036854.775807 USDT`.
- Pint passed. New eligibility and changed public/private pipeline LSP
  diagnostics were clear.

A broader PostgreSQL batch exceeded its 300-second local deadline; the two
focused PostgreSQL runs above are the completed evidence, not a claim that
the full PostgreSQL suite passed. Disposable service containers and smoke
instrumentation were removed; the pre-existing PostgreSQL runtime was untouched.

## Owned history, status and delivery (S07)

All reads authenticate the current key on every HTTP request and scope by its Customer
and immutable `orders.api_order_claim_id IS NOT NULL`, never by the credential that
created the Order. Rotation preserves access to earlier API Orders. A revoked key gets
401. A foreign, nonexistent, malformed or retail Order ID gets the same 404
`not_found` / `Resource not found.` envelope. Browser sessions and Recovery Keys grant
no API authority. All responses, including errors, use `Cache-Control: no-store, private`,
`Referrer-Policy: no-referrer` and `X-Request-ID`.

| GET route | Query / response |
| --- | --- |
| `/api/v1/orders` | `limit` (default 20, 1–100), optional `cursor` and `status`; `data: OrderSummary[]` plus `pagination: {next_cursor: string|null, has_more: boolean}` |
| `/api/v1/orders/{id}` | No query fields; `data: OrderSummary` |
| `/api/v1/orders/{id}/delivery` | No query fields; `data: DeliveryResult`; also consumes the shared 10/minute Customer delivery budget |

Unknown query fields, arrays, invalid filters and nonempty GET bodies are 422
`invalid_input`. `status` filters the canonical **Order** status, not payment or delivery:
`draft`, `pending_payment`, `processing`, `completed`, `cancelled`, `expired`.
IDs/cursors are opaque UUID strings. Omit `cursor` for the first page, then pass
`pagination.next_cursor` unchanged with the same filters. History traverses ascending
Order ID with no OFFSET or total count. Concurrent new Orders may appear later and status
changes can change filter membership; pagination is not a frozen snapshot.

### OrderSummary schema

| Field | Type / meaning |
| --- | --- |
| `id`, `invoice_number`, `origin` | String identity/reference; origin is `api` |
| `status`, `payment_status` | Separate canonical Order/Payment states; `paid` is not proof of delivery |
| `currency`, `amount` | Original selected public currency (`IDR`/`USD`) and exact decimal-string Payment amount; null amount only when no Payment exists |
| `quantity` | Ordered integer units |
| `items` | Array of `{product_id: string, name: string, quantity: integer}` snapshots |
| `fulfillment.state` | `not_ready`, `processing`, `review`, `partial`, `complete`, `refunded` |
| `fulfillment.ordered_units` | Total purchased quantity |
| `fulfillment.allocated_units` | Durable stock-row allocation count, including retired/replaced historical rows; not a release entitlement |
| `fulfillment.released_units` | Current non-retired units authorized by `CredentialDeliveryEligibility`; zero if the lifecycle blocks release |
| `fulfillment.processing`, `fulfillment.review_required` | Independent booleans from durable fulfillment/open manual-review state |
| `delivery` | `{available: boolean, state: not_ready|partial|ready}`; availability means lifecycle-authorized rows exist, not that ciphertext has been decoded by this read |
| `refund` | `{state: none|pending|partial|refunded, currency: IDR|USD, confirmed_amount: string|null, pending_amount: string}` |
| `created_at` | ISO 8601 timestamp with timezone |

`review` takes precedence over partial progress; counts and delivery availability remain
separate so an authorized BuyMail partial result can coexist with manual review.
`complete` means all ordered units are currently releasable, not simply that payment or
Order processing finished. A missing-unit refund can settle the Order while delivery
remains `partial`. A full refund reports the original Payment amount as confirmed,
including earlier partial credits exactly once; otherwise confirmed/pending sums come
from registered original-currency `OrderRefund` entitlements. Warranty refunds/replacements
retain the existing unit retirement rules.

History/status use explicit allowlists and batched Payment/item/fulfillment/review/refund
metadata. They neither select nor decrypt credential columns, call a provider, poll
upstream, enqueue fulfillment nor read a stock pool. The partial index
`orders_api_customer_cursor (customer_id, id) WHERE api_order_claim_id IS NOT NULL`
supports owned keyset traversal without indexing retail history into that access path.

### DeliveryResult schema and release rules

Success, including pending/review, is HTTP 200:

```json
{
  "data": {
    "id": "01a14505-c0a1-7005-aeb6-dbf062934315",
    "state": "not_ready",
    "progress": {
      "state": "review",
      "ordered_units": 2,
      "allocated_units": 1,
      "released_units": 0,
      "processing": true,
      "review_required": true
    },
    "units": [],
    "usage_guides": []
  }
}
```

This is a disposable fixture response. `state` is `not_ready`, `partial` or `ready`;
`progress` has the same schema as `OrderSummary.fulfillment`. A client waits or contacts
support on review; it must not create a new purchase to recover an existing result.

Local non-credential-managed Products (legacy numeric stock without Product Stock Items)
remain purchasable. A paid Order with positive ordered units and no allocated local
credentials creates one pending Manual Fulfillment Review with reason
`local_credentials_missing` and a durable Owner review notice. Bot and API checkout use
the same lifecycle rule. Supplier-backed Orders retain their own fulfillment flow.
Replay reuses the review and notice; a Late Payment retains its existing `late_payment`
review rather than creating a second obligation.

For these Products, `fulfillment.state` is `review`, `review_required` is true, and
`delivery.state` remains `not_ready` until the Owner fulfills manually. Contact support
with the existing invoice, **do not buy again**. The API does not generate credentials
or fabricate delivered units: `allocated_units` and `released_units` stay zero while no
credentials are allocated. A manual handoff outside the API does not itself make API
delivery ready or change those counts.

Each released `units[]` object has exactly:

| Field | Type / meaning |
| --- | --- |
| `id`, `product_id` | Opaque string allocated-unit and Product identities; stable on repeat reads |
| `number` | Logical account number from the shared delivery presentation |
| `format` | `legacy` or `structured_account` |
| `text` | Canonical formatted credential text, including supported legacy MFA fields |
| `email`, `password` | Nullable strings from the shared legacy/structured presentation |
| `access_method`, `access_value` | Nullable structured access enum/value (`none`, `otp_mail_link`, `auth_token`); alternatives are not combined |
| `reader_mail_link` | Nullable supported mailbox-reader link |
| `tutorial` | Nullable item override or snapshotted/default delivery instruction |

`usage_guides` is an array of `{title: string, url: string}` from the canonical
`ProductUsageGuide`. The API does not fetch these URLs. No provider identity, provider
account ID, raw supplier evidence, recovery secret, acquisition cost or pool metadata
appears in any response.

Delivery holds the owned Order shared while deciding entitlement and projecting existing
units. Canonical refund, warranty and fulfillment writers take that Order exclusively;
the read never takes a wallet lock or reverses the writer lock order. It first checks
paid-at/Payment/Order/lifecycle and late-payment review, including the existing committed
BuyMail partial-release exception. Only then does it load at most 101 non-retired
allocated rows (100 is the valid maximum) and the supported credential fields through
`CredentialDeliveryPresentation`. Mixed local/non-partial supplier processing releases
nothing until the canonical gate permits it. Retired units never load/decrypt for this
endpoint. Invalid allocation bounds, malformed ciphertext or a JSON result over 1 MiB
fail closed with safe 503 `temporarily_unavailable`, without returning a partial payload.

GET never buys, allocates, debits, refunds, resends or creates a new fulfillment attempt.
Repeated reads return the same unit identities and values until an authorized existing
warranty/refund operation retires or replaces a unit. This is recovery of the same result,
not exactly-once Telegram transport.

### Durable recovery

No API-specific fulfillment queue/state machine is added. Economic checkout already
commits `SupplierFulfillment` and local allocation atomically. Redis only carries wakeups.
After worker/Redis loss, use the existing bounded recovery and worker:

```sh
php artisan suppliers:recover --limit=25
php artisan queue:work supplier --queue=suppliers --stop-when-empty --tries=1 --timeout=120
```

The scheduler runs supplier recovery every minute. A pending accepted API Order can
progress without a new HTTP purchase. Known supplier identities recover through existing
reads; an expired dispatched non-idempotent purchase without a known outcome remains
`ambiguous`/manual review with holds retained, no new acquisition and no automatic refund.
`telegram:recover-outbound` remains the owner of lost receipt/outbound handoffs; GET is
not a resend path. Existing qualification/activation gates remain unchanged.


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
- Own-order history/status/delivery is implemented in S07 above. Notifications are
  implemented by S09 #625, public documentation publication by S10 #626
  ([Customer API public documentation](customer-api-public-docs.md)) and Store Bot
  management remains #627.
- Existing custody, funding, provider, pause and restore qualification gates remain
  fail-closed. This work does not authorize financial production exposure.

## S07 verification (2026-10-16)

The local PostgreSQL regression batch ran `CustomerApiOrderReadTest`,
`SupplierFulfillmentTest`, `BuyMailFulfillmentTest`,
`CredentialDeliveryPresentationTest` and `UsdtOrderRefundPostgresConcurrencyTest`:
55 tests, 863 assertions passed. The initial SQLite batch of the first four files passed
49 tests, 799 assertions. The API tests exercise canonical checkout and current rotated-key
ownership; supplier/warranty fixtures cover blocked mixed local results, ambiguous purchase
holds, manual replacement, authorized partial BuyMail units, missing-unit refunds and
per-account warranty retirement. Invalid ciphertext is tolerated by metadata history but
delivery fails closed. These are fixture provider contracts, not live supplier qualification.

After the final code/formatting changes, the focused PostgreSQL rerun passed 14 tests,
410 assertions: all order-read cases (including the 1 MiB response bound), mixed unknown
supplier recovery, BuyMail partial/warranty retirement and the six existing original-currency
refund concurrency cases. Pint completed on the changed PHP files; LSP diagnostics for
the new controller/service reported no issues.
The final SQLite compatibility batch passed 125 tests, 1,552 assertions across
`CustomerApiOrderReadTest`, `CustomerApiOrderTest`, `CredentialDeliveryPresentationTest`,
`OrderCredentialMailTest`, `WarrantyClaimTest`, `SupplierFulfillmentTest` and
`BuyMailFulfillmentTest`.

### Disposable HTTP and worker-loss recovery

Runtime: application image PHP 8.4.26, PostgreSQL 16, isolated Redis 7, HTTP bound only
to `127.0.0.1:18624`. It used the real v1 routes, authentication, shared Redis admission,
canonical checkout, durable supplier recovery command and actual queue worker.
The dataset contained 103 accepted API Orders for one funded Customer and 2,001
retail Orders across two Customers. All inventory/credentials/funding were synthetic;
Telegram and live supplier traffic were disabled.

Observed sequence:

1. HTTP POST accepted local, supplier-only and mixed local/supplier Orders with 201/paid.
   Initial delivery GET returned 200/ready for local unit `101`, 200/not-ready with
   zero allocated units for the supplier-only Order, and 200/not-ready with one
   allocated but **zero released** units for the mixed Order.
2. `FLUSHALL` on only the disposable Redis deleted queue wakeups. Actual
   `suppliers:recover --limit=25` reported two due records. Actual
   `queue:work supplier --queue=suppliers --stop-when-empty --tries=1 --timeout=120`
   completed the first obligation, allocating supplier unit `103`.
3. The fixture supplier transport killed the second real worker with SIGKILL after its
   dispatch intent was durable and before a result was returned (worker exit 137).
   Only that killed dispatch's lease/deadline was advanced to expiry for the drill.
   A second recovery reported one due record; the restarted worker completed recovery
   into `review` / `ambiguous`, with the original supplier hold retained.
4. HTTP delivery recovered the completed supplier unit `103`. The unknown mixed Order
   stayed `processing`/`paid`, with one allocated unit, zero released units,
   review required and no refund. Fixture transport logged exactly two calls total,
   both outside DB transactions; recovery/result/history reads made no new acquisition.
5. Account key rotation and HTTP-process restart preserved the exact same local/supplier
   unit IDs and delivery values. The revoked key returned 401. Foreign, nonexistent,
   retail and malformed IDs all returned indistinguishable 404 codes/messages with
   matching response/error request IDs. Private success/error responses used
   `Cache-Control: no-store, private`.

Direct DB snapshots around repeated GETs, key rotation and restart were equal:
103 accepted API Orders, 103 Payments, 103 IDR purchase debits, zero refund credits,
103 allocated rows and IDR balance `480000`. Supplier holds were `0` for the completed
purchase and `1000000` micro-units for the unknown purchase. No API-specific handoff
repair or replacement recovery engine was needed.

### HTTP query-growth evidence

Ten serial actual HTTP requests per page size, with request-local SQL/memory instrumentation
in the disposable router (not application code):

| Summary rows | SQL statements | Statements involving relationship metadata | p50 ms | p95 ms | Peak PHP MiB |
| --- | --- | --- | --- | --- | --- |
| 1 | 11 | 9 | 102.84 | 805.22 | 12 |
| 20 | 11 | 9 | 117.68 | 124.70 | 4 |
| 100 | 11 | 9 | 169.39 | 197.78 | 4 |

The one-row sample includes cold PHP startup. p95 is nearest-rank over ten observations.
Peak in-flight requests was one; these are boundedness diagnostics, not throughput or
production capacity evidence. Credential text was absent from every history response;
upstream fixture calls stayed at two throughout the reads.

`EXPLAIN (ANALYZE, BUFFERS)` after `ANALYZE orders` used
`orders_api_customer_cursor` for the one-row shape (two rows fetched, five shared buffer
hits, 0.183 ms execution). On this small correlated fixture, PostgreSQL chose the existing
nonnull claim index for 20/100, filtering 103 API rows and sorting with 27/38 KiB
(nine buffer hits, 0.159/0.185 ms execution); it did not scan retail history.
`pg_stat_database.deadlocks` was zero. Lock-wait duration was not sampled by this serial
HTTP probe; PostgreSQL refund concurrency coverage is separate from this measurement.

The worker crash and upstream results used deterministic local transport fixtures, not
network/provider qualification. Existing financial exposure gates remain fail-closed.
No CI/CD inspection, production access, activation, deployment, commit or push was performed.
The disposable HTTP/Redis containers, both smoke/regression databases and temporary
router/worker/fixture instrumentation were removed. The pre-existing PostgreSQL container
was left running.
