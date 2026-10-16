# Customer API balance and catalog reads (S05, #622)

Contract: [#617 sections 4.2, 5, 7 and 8](https://github.com/Alhiefikri/telegram-bot/issues/617).
Authentication, shared admission, rate-limit headers and revocation follow
[Customer API key operations](customer-api-key-operations.md). These routes are
server-to-server reads on the existing website origin. No Order, stock
reservation, price lock, funding, debit or delivery action occurs.

## Requests

Every request requires `Authorization: Bearer <secret>`. Keep the secret in the
backend; never put it in a URL, browser storage or frontend bundle. All three
routes return `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer`,
`X-Request-ID` and the existing authenticated read-limit headers. There is no
private financial response cache. A read admitted before revocation may finish.

| GET route | Accepted query fields |
| --- | --- |
| `/api/v1/balance` | None; Customer identity comes only from the admitted key |
| `/api/v1/products` | `limit`, `cursor`, `quantity`, `category_id`, `family_id`, `available` |
| `/api/v1/products/{id}` | `quantity`, optional `currency` eligibility assertion |

Unknown query fields, query arrays and nonempty GET bodies fail with 422.
Numeric query values are unsigned canonical decimal integers, not exponents,
signs, fractions or zero-padded numbers. Product/category/family IDs must be
positive and fit a signed 64-bit integer. Treat IDs and cursors as opaque strings.

- `limit`: default 20, range 1–100. There is no total-count query.
- `cursor`: omit for the first page; pass `pagination.next_cursor` unchanged for
  the next page. Internally this is the last Product ID, ordered ascending.
  `0` starts at the beginning. Use the same filters on subsequent pages.
  Concurrent inserts can appear later; deleted/hidden Products disappear. This
  is stable keyset traversal, not a frozen catalog snapshot.
- `quantity`: default 1, range 1–100 on list and detail. Stock shortage does not
  suppress a price quote; `stock.quantity_available` reports the shortage.
  Checkout still owns stricter inventory/provider/business limits and rechecks
  current pricing and availability; a quote is not purchase authorization.
- `category_id` / `family_id`: exact optional positive IDs, combined with AND.
- `available`: omit for all visible Products; `true` restricts to canonical
  available-for-purchase Products; `false` restricts to unavailable Products.
- `currency`: detail only, `IDR` or `USD`. Both quote objects are always returned.
  Explicit `USD` rejects a missing USD price with 422 `currency_unavailable`;
  without that assertion the USD object is returned with `eligible: false`.

## Balance schema

Success is `{"data": {"payment_preference": ..., "wallets": [...]}}`.
`payment_preference` is the saved Customer enum: `idr_only`, `usd_only`,
`idr_first` or `usd_first`. No preference write endpoint is added here.

Each wallet has exactly `currency` (`IDR` or `USD`), `available` (boolean),
`frozen` (boolean) and `amount` (decimal string or null). Missing accounts are
healthy zero. Untrusted ledger projections are unavailable, with null amount,
not zero. Frozen USD accounts are also unavailable with null amount, including
empty accounts. Raw freeze reasons, account/entry IDs and ledger cursors remain
private. IDR has no account-freeze flag in its canonical ledger, so its `frozen`
field is false; a projection mismatch still makes it unavailable. Availability
here describes wallet snapshot health, not spending-policy authorization.

Public USD is the store denomination funded 1:1 by USDT. It is not a fiat
custody claim. One internal micro-USDT maps exactly to `"0.000001"` USD. IDR is
whole rupiah; USD uses up to six fractional digits without trailing zeros.
Clients must use exact decimal arithmetic, never binary floating-point money.
Internal USDT currency and historical financial records are unchanged.

Synthetic healthy response:

```json
{
  "data": {
    "payment_preference": "idr_first",
    "wallets": [
      {"currency": "IDR", "available": true, "frozen": false, "amount": "12345"},
      {"currency": "USD", "available": true, "frozen": false, "amount": "1.234567"}
    ]
  }
}
```

## Product schema

List success is `{"data": [Product, ...], "pagination": {"next_cursor": string|null,
"has_more": boolean}}`. Detail success is `{"data": Product}`.

| Product field | Type / meaning |
| --- | --- |
| `id`, `name` | String sellable Product identity and store-owned name |
| `plan_label` | Nullable string Product option label; a Plan is not a separate sellable entity |
| `description`, `description_ms`, `description_en` | Store-authored base/Malay/English copy, nullable localized values |
| `icon`, `custom_emoji_id` | Nullable strings; canonical Product override or inherited family pair |
| `category` | Null or exactly string `id`, `name`, `slug`, nullable `description` |
| `family` | Null or exactly string `id`, `name`, `slug`, nullable `description`, `icon`, `custom_emoji_id` |
| `stock` | Exactly integer `available`, boolean `is_available`, boolean `quantity_available` |
| `quantity` | Integer quantity used for both quotes |
| `quotes` | IDR and USD quote objects, including explicit unavailable USD |
| `input_requirements` | Empty array: current canonical automatic Product checkout accepts no buyer-supplied Product input fields |
| `metadata` | Exactly the nullable store-authored fields listed below |
| `informational` | Always true; no stock reservation or frozen price |

`metadata` fields are `product_type`, `access_duration`, `region`, `account_type`,
`device_limit`, `included_items`, `limitations`, `fulfillment_expectation`,
`warranty_policy`, `warranty_policy_ms`, `warranty_policy_en`, `support_policy`,
`delivery_note`, `usage_guide_title`, and `usage_guide_url`. Values retain their
current Product text representation; the API does not invent structured input
schemas, warranty entitlements or guaranteed delivery times from this copy.
Supplier SKUs requiring buyer input are already ineligible for canonical
automatic supplier acquisition. No supported Product is hidden by a new API
source-type filter.

Each quote contains exactly `currency`, boolean `eligible`, and nullable decimal
strings `regular_unit`, `effective_unit`, `regular_total`, `total`.
`regular_*` is the base Product price before any quantity tier or reseller
override. `effective_unit` and `total` come from `ProductQuantityPricing` with
trusted API context: a configured reseller override wins independently per
currency, otherwise normal quantity-tier pricing applies. Overrides never stack
with tiers and never manufacture USD eligibility. Supplier identity, pricing
source diagnostics, costs, profit, purchase evidence and credentials are absent.

Synthetic quote for quantity 2, base IDR 15000 with an IDR reseller override of
8000, and no USD price:

```json
[
  {"currency": "IDR", "eligible": true, "regular_unit": "15000", "effective_unit": "8000", "regular_total": "30000", "total": "16000"},
  {"currency": "USD", "eligible": false, "regular_unit": null, "effective_unit": null, "regular_total": null, "total": null}
]
```

Visibility and stock reuse `Product::publicVisible`, `availableForPurchase`,
`withAvailability` and `availableStock`. Active uncategorized, local,
supplier-backed and zero-stock visible Products remain discoverable. Inactive,
soft-deleted and private-category Products return the same 404 as missing IDs.
Expired supplier capacity contributes zero. Stock contains aggregate availability,
never local/upstream source breakdowns or supplier wallet/status data.

## Errors and backend example

Errors always have `error.code`, `error.message` and `error.request_id` matching
`X-Request-ID`. Never parse the human message as a machine contract.

| Status | Code | Read behavior |
| --- | --- | --- |
| 401 | `unauthenticated` | Missing, invalid or revoked key |
| 403 | `backend_only` | Browser Origin with a Customer key |
| 404 | `not_found` | Missing or nonpublic Product, indistinguishable response |
| 422 | `invalid_input` | Unsupported/malformed fields, IDs, quantity or pagination |
| 422 | `currency_unavailable` | Explicit USD assertion without a base USD price |
| 422 | `quote_unavailable` | Canonical schedule or representational overflow prevents a safe quote; no partial quote |
| 429 | `rate_limited` | Shared read/IP quota exhausted; observe `Retry-After` |
| 503 | `temporarily_unavailable` | Shared admission or local read readiness failure; never fake a zero wallet |

The inherited body-size and method boundaries also return 413 `payload_too_large`
and 405 `method_not_allowed`. No error mutates financial state.

Run from a trusted backend shell with the real website origin and a key supplied
by its secret store. This read makes no purchase:

```sh
curl --fail-with-body --connect-timeout 5 --max-time 15 \
  --header "Authorization: Bearer ${CUSTOMER_API_KEY}" \
  "${STORE_ORIGIN}/api/v1/products?limit=20&quantity=2"
```

`CustomerApiReadController` owns request bounds and wallet boundary mapping;
`CustomerApiCatalog` owns the bounded credential-free read projection and
allowlisted response. It delegates all economic and visibility rules to existing
owners. It loads at most limit + 1 Products, category/family/icon/tier relations
in bulk, then quotes at most limit Products. It never loads Product Stock Item
models, performs provider HTTP or adds catalog caching/indexes.

## Disposable verification, 2026-10-16

Focused local checks: 160 PHPUnit tests, 2143 assertions, no failures or skips;
Pint passed on the six changed PHP files. `CustomerApiReadTest` compares quotes
and eligibility with canonical owners, checks cursor traversal, malformed input,
missing USD/overflow, two-Customer isolation, exact money, drift and freeze.
The empty frozen wallet regression failed before preserving `frozen_reason` in
`CustomerUsdtBalanceLedger::snapshot()`'s empty-account branch, then passed.
Unavailable snapshots also preserve the freeze flag when drift and freeze
coexist; raw reasons never enter the public response.

Actual HTTP ran through the unchanged v1 authentication/admission boundary using
two synthetic Customer keys, the application PHP 8.4 image, PostgreSQL 16 and
shared Redis. Dataset: 10,100 Products, 20,050 Product Stock Items, 10,034 tiers,
20 families and 20 categories. The first 100 Products mix local credentials and
supplier-capacity projections, nullable USD prices, reseller overrides and tiers.
A retrieval guard rejected any Product Stock Item model hydration; the background
stock contained invalid ciphertext to avoid mistaking successful decryption for
proof of a credential-free query. Provider HTTP was prohibited.

Ten sequential HTTP samples per page size after fixture augmentation:

| Products/page | SQL statements including auth | Catalog/relation statements | Application p50 | Application p95 | Upstream calls |
| --- | --- | --- | --- | --- | --- |
| 1 | 5 | 4 | 47.00 ms | 51.66 ms | 0 |
| 20 | 5 | 4 | 59.08 ms | 85.74 ms | 0 |
| 100 | 5 | 4 | 90.43 ms | 100.20 ms | 0 |

Times include instrumented application dispatch, not network latency. Nearest-rank
p95 of ten samples is the maximum. Peak in-flight HTTP work was one, deliberately
sequential; this is bounded-query evidence, not throughput or concurrency proof.
The disposable database reported zero deadlocks, zero sampled lock waiters and
zero logged lock waits above the configured 100 ms threshold. Shorter waits and
concurrent financial load were not measured.

Representative `EXPLAIN (ANALYZE, BUFFERS)` for the 100-row page selected
`products_pkey` with LIMIT 101 and the existing
`product_local_credential_capacity` index-only count subplan. PostgreSQL chose a
one-time hashed existence scan of 20,050 stock keys; it did not select or decrypt
credential payloads. Main query execution was 12.910 ms. Bulk category/family
queries scanned their small 20-row tables (0.031 ms each); the tier query chose a
single sequential scan of 10,034 rows and returned 35 matches (1.624 ms). These
are observed planner choices, not reasons to force indexes or add speculative
ones. There is no per-result ledger/provider/status query or total count.

HTTP checks observed exact `"1.234567"` / `"1.234568"` wallet amounts for the two
Customers, `"3.703701"` for three units at `"1.234567"`, private headers, 401,
404, malformed-input 422, explicit missing-USD 422, subtotal-overflow 422,
nonoverlapping cursors and bounded filters. Drifted IDR, frozen USD and
simultaneously frozen/drifted USD wallets returned null amounts, not zeros or
private reasons. Revocation returned 401, hidden/missing Products shared one 404
shape, and expired supplier capacity returned zero stock. Financial entries
remained the two fixture credits per currency; Orders, Payments, allocated
credentials, supplier purchases and synchronous usage DB rows remained zero.
The image disables opcode timestamp validation; after the final snapshot change
the disposable HTTP server was restarted and current wallet/quote/error paths
and 1/20/100 page query counts passed again.
Disposable containers, synthetic keys and temporary instrumentation/fixture
scripts were removed after verification.

No production/TLS, real supplier request, live wallet funding, checkout,
concurrent-money qualification, CI/CD, deployment, commit or push forms part of
this evidence. Existing financial exposure gates remain unchanged. Public
website/OpenAPI publishing is implemented by S10 #626 and documented in
[Customer API public documentation](customer-api-public-docs.md); this file remains
the implemented read contract.
