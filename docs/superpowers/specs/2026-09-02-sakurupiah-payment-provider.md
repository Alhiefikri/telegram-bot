# Sakurupiah Payment Provider Implementation Specification

- **Status:** Proposed — implementation must not enable production checkout until every external contract gate is closed.
- **Date:** 2026-09-02
- **Scope:** Add Sakurupiah as an Owner-selectable Payment Provider for new QRIS checkout in the Web Storefront and Store Bot.
- **Primary sources:** [Sakurupiah API documentation](https://sakurupiah.id/developers/api-dokumentasi), [official Postman collection](https://sakurupiah.id/SAKURUPIAH-DOKUMENTASI-API-v2.1Postman.json), and [official-linked signature example](https://raw.githubusercontent.com/SAKURUPIAH/SIGNATURE_INVOICE/main/PHP_version.php).
- **Companion evidence:** [`docs/agents/sakurupiah-api-research.md`](../../agents/sakurupiah-api-research.md) and [`docs/agents/sakurupiah-payment-provider-guide.md`](../../agents/sakurupiah-payment-provider-guide.md).

## Problem

The checkout core currently supports Manual, KlikQRIS, Binance, BSC, and historical Pakasir Payments. Sakurupiah has different authentication, invoice, callback, status, QR/hosted presentation, and expiry semantics. A branch in a controller or `OrderCheckoutService` would duplicate lifecycle authority, race with checkout/expiry/callback work, and risk duplicate invoices, payments, reservations, or fulfillment.

## Decision

### Product scope

V1 adds exactly one new provider identity and one channel:

```text
Payment.provider = sakurupiah
Payment.method   = QRIS
Payment.currency = IDR
```

`IDR`, fee payer, requested expiry, QR rendering, and hosted-URL policy are production-gated contract decisions, not assumptions. V1 does **not** expose VA, e-wallet, retail, QRISMU, QRIS2, QRISC, or customer channel selection.

The active provider remains Owner-controlled through the existing Payment Provider Switch. Switching affects **only new checkout**. Existing Payments retain their stored provider, method, environment/reference snapshot, and lifecycle interpretation.

### Architectural boundaries

```text
Web Storefront / Store Bot
  → PaymentProviderResolver
  → SakurupiahCheckoutPaymentPreparation
  → CheckoutPaymentPlan
  → OrderCheckoutService
  → pending Order + Payment + reservation + durable checkout effect
  → SakurupiahInvoiceLifecycleService (post-commit)
  → SakurupiahProviderAdapter / HTTP client

POST /api/webhook/sakurupiah
  → raw-body HMAC validation
  → SakurupiahCallbackProcessor
  → authoritative status/history lookup
  → OrderLifecycle

sakurupiah:reconcile
  → bounded pending/outcome-unknown scan
  → authoritative lookup
  → OrderLifecycle
```

- `OrderCheckoutService` retains cart/order/payment creation, canonical locks, checkout key reuse, credential allocation, Inventory Ledger reservation, and durable effect materialization.
- `CheckoutPaymentPreparation::preflight()` may perform bounded readiness/channel I/O before checkout locks. `prepare()` may only use locked local data and its immutable preflight result; it MUST NOT call provider HTTP, queue, Telegram, RPC, or callback code.
- `CheckoutPaymentPlanWriter` writes only the local Payment snapshot. It MUST NOT call the provider or lifecycle.
- `SakurupiahInvoiceLifecycleService` owns one-Payment create/recovery/persistence/safe-outcome classification.
- `SakurupiahCallbackProcessor` owns callback observation and authoritative verification. It MUST NOT directly update an Order or Payment to paid.
- `OrderLifecycle` remains the sole authority for paid, expired, late-payment, inventory, receipt, and Sales Proof transitions.
- `TelegramOutboundEffectLedgerProtocol` remains the sole durable delivery-state seam. Provider recovery and Telegram delivery ambiguity are separate state machines.

No provider-neutral Native QRIS abstraction is introduced. This preserves ADR-0006's provider-local lifecycle rule.

## Official provider contract

### Environment and authentication

| Environment | Base URL |
| --- | --- |
| Production | `https://sakurupiah.id/api/` |
| Sandbox | `https://sakurupiah.id/api-sanbox/` |

The provider spelling is `api-sanbox`; do not correct it. API calls use `POST`, `Authorization: Bearer <API_KEY>`, and body field `api_id`.

Create-invoice signature:

```text
HMAC-SHA256(
  key = API_KEY,
  message = API_ID + METHOD + MERCHANT_REF + AMOUNT_AS_SUBMITTED
)
```

The concatenation has no separator. The client must sign the exact string it submits, use lower-case hexadecimal output consistent with the provider PHP sample, and never calculate or expose a signature outside the server.

Callback signature:

```text
HMAC-SHA256(key = API_KEY, message = raw HTTP request body)
```

Verification must use bytes read before JSON decoding/re-encoding and use constant-time comparison.

### Endpoints

| Operation | Path | Required body | Local purpose |
| --- | --- | --- | --- |
| List channels | `list-payment.php` | `api_id`, `method=list` | readiness/runtime channel metadata |
| Create invoice | `create.php` | `api_id`, QRIS `method`, buyer phone, amount, fee policy, merchant ref, callback/return URL, signature | post-commit invoice creation |
| Status | `status-transaction.php` | `api_id`, `method=status`, `trx_id` | authoritative status observation |
| History | `transaction.php` | `api_id`, `method=transaction`, filters | recovery/amount evidence |
| Callback | merchant URL | JSON plus callback headers | untrusted signal ingress |

History uses the provider field spelling `mechant`. Preserve it in the client.

### Invoice and callback fields

Create response may contain `trx_id`, `merchant_ref`, `payment_kode`, `total`, `fee`, `expired`, `payment_status`, QRIS-only `qr`, `payment_no`, and `checkout_url`. Types are inconsistent across examples; parsers must admit only qualified scalar forms and reject overflow, missing identity, invalid status, invalid date, and mismatched reference/channel.

Callback has `X-Callback-Event: payment_status`, `X-Callback-Signature`, and body `trx_id`, `merchant_ref`, `status`, `status_kode`. Documented strings are `pending`, `berhasil`, `expired`; expiry code is inconsistent (`2` in HTML versus `-2` in Postman) and is a production block.

## Required local snapshot

Use existing Payment columns unless a security review proves a missing protected boundary:

| Provider fact | Local field | Rule |
| --- | --- | --- |
| local generated idempotency key | `merchant_ref` | immutable request/correlation identity |
| `trx_id` | `provider_reference` | required before documented status lookup |
| QRIS code | `method` and `provider_payment_method` | normalized local identity + original provider code |
| base local amount | `amount` | integer only after IDR contract proof |
| provider total/fee | `provider_total_payment`, `provider_fee` | validate against fee policy before display |
| provider timestamps | `provider_created_at`, `provider_expires_at` | parse with frozen timezone policy |
| protected payment identifier | `provider_payment_number` | encrypted; only if needed |
| sanitized provider projection | `provider_payload` | whitelist only; no key/signature/QR base64/token URL/avoidable PII |
| recovery state | `provider_create_state` | lifecycle-private state; UI consumes safe outcome only |

A `checkout_url` with credentials/token query data must not enter logs, audit metadata, fixtures, or a plaintext customer DTO. Persist it only if a reviewed encrypted/allowlisted representation is necessary.

## Checkout and create invariants

1. The documented checkout lock order remains:
   ```text
   cart → checkout_maintenance_settings → cart items/products → ManualPaymentSetting
   ```
2. A `checkout_key` returns the existing Order and never starts another provider create.
3. One successful checkout creates at most one Order, one Payment, one stock reservation, and one checkout effect.
4. Provider HTTP starts only after the local checkout transaction commits.
5. Provider create is serialized with one cache lock per Payment. The same lock serializes create recovery, callback verification, status refresh, and reconciliation.
6. Every create attempt uses the persisted `merchant_ref`. It never generates another reference.
7. If readiness fails before dispatch, creation can retry later. If timeout, disconnect, 5xx, invalid JSON, or schema failure follows possible dispatch, outcome is **unknown**, not failed.
8. Unknown create recovery must query the same Payment's persisted provider reference first; history/reference lookup is used only after qualification proves it safe. No blind second create is allowed.
9. Failed preparation rolls back every local checkout side effect. Failed provider creation after commit preserves one pending Payment for recovery.
10. Provider switch, expiry, callback, reconciliation, and instruction jobs must read stored Payment provider/method; none use the current setting to reinterpret a historical row.

## Financial integrity and lifecycle rules

### Payable presentation

A customer sees a QR or hosted instruction only from a typed safe `Payable` outcome. It requires:

- known `provider=sakurupiah`, QRIS channel, and matching `merchant_ref`;
- a persisted, non-empty `trx_id`;
- provider `pending` status or an explicitly safe equivalent;
- exact validated provider total and fee policy;
- effective expiry later than now;
- either QRIS EMV/CRC + `360` currency + exact encoded amount, or HTTPS hosted URL on a confirmed allowlist;
- no raw provider object crossing into Storefront/Telegram views.

`Creating`, `RecoveryPending`, `ProviderTotalPending`, `InvalidProviderResult`, and `Expired` are safe no-payment states. They display retry/support copy, not QR/URL/raw error data.

### Paid gate

A callback is never sufficient by itself. A paid transition requires:

```text
valid raw-body HMAC
+ exact payment_status event
+ valid typed callback status/code pair
+ known Sakurupiah Payment
+ matching merchant_ref
+ matching persisted trx_id
+ authoritative provider status = berhasil
+ authoritative amount/currency evidence matches Payment snapshot
+ Payment/Order remain lifecycle-eligible
→ OrderLifecycle::applyPaymentPaid(payment)
```

The official status example has no amount. Therefore automatic paid is prohibited until sandbox evidence or written provider contract shows an amount-bearing authoritative status/history result. Before that, retain **Payment Verification Pending**, record a sanitized event, retry reconciliation, and escalate according to operational policy.

### Expiry and late payment

Provider expiry is integer hours in `Asia/Jakarta`, while local QRIS policy is currently minute-based. The implementation must freeze a documented rounding/effective-deadline policy before activation. The effective deadline is never later than the local Order deadline.

`expired` requires an authoritative result and resolved status-code contract. Expiry transitions only through `OrderLifecycle`. A valid paid observation after local Order expiry/cancellation is a **Late Payment**: mark financial evidence via lifecycle/manual review policy, do not auto-fulfill or re-reserve stock.

## Callback and reconciliation safety

### Ingress

The webhook route is `POST /api/webhook/sakurupiah` and must:

1. enforce JSON request type, bounded body size, bounded depth, and request throttle;
2. reject missing/invalid raw-body HMAC before decoding;
3. require exact callback event;
4. validate scalar types, bounded lengths, status token, and resolved code pair;
5. locate a Payment only through `(provider=sakurupiah, merchant_ref)` and require provider reference match once persisted;
6. create/coalesce a sanitized `PaymentEvent` with deterministic idempotency key and received count;
7. invoke authoritative lookup under Payment single-flight lock;
8. acknowledge according to measured provider contract without reporting a payment as processed when verification remains pending.

No callback source IP trust, timestamp, event ID, retry count, or replay window is documented. HMAC plus local correlation and event idempotency are mandatory; source IP policy may add defense in depth only after provider confirmation.

### Reconciliation

`sakurupiah:reconcile --chunk=N` scans only Sakurupiah Payments in bounded eligible states with indexed predicates and deterministic ordering. It loads required relations eagerly; it MUST NOT issue per-row Order/Payment/channel queries. Each item is locked/single-flighted before lookup. The command does not call create for a missing reference, does not hold database transactions across HTTP, and reports only aggregate/redacted identifiers.

The schedule uses `withoutOverlapping()` and runs only after its queue/worker configuration is present and monitored. A queued verification job must use an existing consumed queue or add a supervised worker in the same release; no work may be sent to an unserved `payments` queue.

## Security requirements

- API ID/key, HMAC, callback signature, raw payload, QR base64, and tokenized hosted URL never enter logs, exception messages, test snapshots, audit metadata, docs, Telegram, or browser output.
- Use Laravel encrypted casts or a reviewed encrypted detail boundary for payment identifiers/URLs requiring storage.
- Use HTTPS for production API, callback, and return URL; reject userinfo, unsafe scheme, IP/private host, unexpected port/query policy, and unapproved host where URLs are rendered/redirected.
- Signature comparison uses `hash_equals`; do not accept case/normalization variants until qualified.
- Webhook parsing rejects duplicate critical top-level keys, oversized bodies, and malformed JSON.
- Use typed DTOs and whitelist sanitizers, not raw associative provider field bags.
- Callback failure messages are generic; they must not reveal whether a merchant reference exists.
- Production configuration validates credential pairing, environment matching, HTTPS, callback route, and confirmed host/IP policy.

## Performance and query requirements

- No provider HTTP from the checkout database transaction or from `prepare()`.
- Readiness/channel metadata is bounded and cacheable with environment and configuration fingerprint in the cache key. TTL/invalidation must not let a disabled/stale channel activate checkout.
- Reconciliation uses chunking by stable key, narrow selects, eager relation loading, one lookup per eligible Payment, and no N+1 relation traversal.
- Callback processing performs constant-count local lookups for Payment/Event and does not load Order collections until a candidate Payment is matched.
- Lock granularity is Payment-specific. Do not use a global provider lock that serializes unrelated checkout.
- Retry only transport failures/408/429/5xx with bounded exponential delay; never retry malformed payload/schema or create uncertainty blindly.
- Dashboard reporting, if added, is read-only, paginated, and only queries provider when its tab is active.

## Required configuration/readiness

Environment/config may hold only secrets and deployment controls:

- separate sandbox/production enabled flags, API ID, API key, base URL;
- callback/return URL;
- HTTP connect/request limits, lookup retry budget, lock lease, webhook/status throttle;
- confirmed allowed hosts and production IP controls.

Do not add ENV for channel labels, catalog content, price, fee display, or customer presentation. If Owner needs a mutable policy, use database setting + Admin UI/version/audit.

A readiness report must separately assess enabled/configured state, credential pair, HTTPS URLs, target runtime environment, callback route, QRIS active/limits from runtime channel data, fee/currency/expiry contract, schema, queue/scheduler, and production IP policy. An unready Sakurupiah selection fails closed; it does not resolve to Manual or KlikQRIS.

Production activation additionally requires a versioned, non-secret
`services.sakurupiah.contract_policy` record. The record must explicitly mark
all twelve external gates as qualified, provide one redacted evidence reference
per gate plus a redacted record reference, and contain explicit Owner approval
for automatic fulfillment. The default policy is unqualified and fail-closed.
Changing the policy is a release/configuration decision; it must not be exposed
as an ENV presentation setting or inferred from provider documentation.

## External contract gates

Before production activation, record redacted sandbox evidence for all of these:

1. accepted form encoding for every used endpoint;
2. exact signature canonicalization and casing;
3. QRIS availability, min/max, fee payer, total, and currency unit;
4. expiry range, timezone, and boundary behavior;
5. create response field presence/type and `trx_id` persistence;
6. direct QR EMV validity or hosted URL host/token behavior;
7. status/history amount-bearing authoritative evidence;
8. duplicate `merchant_ref` and ambiguous-create recovery behavior;
9. callback body/header/status-code mapping, duplicate delivery, retry/ack behavior;
10. 200/400 JSON/HTTP semantics, error shape, rate/timeout limits;
11. production IP allowlist and reverse-proxy source identity;
12. credential rotation/revocation and support escalation process.

An unresolved financial integrity gate blocks automatic production fulfillment. An owner may approve a documented manual-review-only mode only if customer copy and operations explicitly state the limitation.

## Test and release acceptance

### Unit and HTTP-contract tests

- invoice/callback HMAC exactness; raw body integrity; constant-time comparison;
- DTO parser scalar variants, overflow, invalid field/status/date/reference rejection;
- sanitizer redaction and URL/QR safety;
- active channel/limit/fee/expiry policy;
- typed plan/snapshot completeness and no external work from `prepare()`;
- endpoint paths, bearer/body, `api-sanbox`, `mechant`, form encoding, status/history/create error separation;
- bounded retries and no second create after ambiguous transport.

### Feature and concurrency tests

- Owner provider switch and readiness; existing Payment interpretation remains unchanged;
- zero local side effects on preflight/prepare failure;
- one checkout key → one Order/Payment/reservation/effect;
- create/recover outcome permutations; no fallback/new Payment;
- direct/hosted instruction only from safe outcome on Storefront and Telegram;
- callback bad signature/body/reference/status, duplicate, out-of-order, unknown Payment, and outage paths;
- paid/expired/late-payment lifecycle behavior; no duplicate sale/receipt/Sales Proof;
- reconciliation bounds, queue dispatch, and restart safety;
- PostgreSQL races: duplicate checkout, concurrent create worker, callback/reconcile/expiry race, provider-switch race, stock race.

Run the focused provider suite, full `cd src && composer test`, and `cd src && composer test:concurrency-gate`. Browser/build checks are required only if surfaces/assets change. Sandbox qualification is mandatory before production provider activation.

## Rollout and rollback

1. Deploy code/config/migrations disabled.
2. Verify readiness, route, schedule, supervisor queue consumption, and health.
3. Run qualified sandbox end-to-end paths on both Store Bot and Web Storefront.
4. Enable controlled non-production traffic and observe pending/recovery/verification backlogs, event dedupe, queue depth, and safe presentation.
5. Owner reviews production contract evidence, then explicitly activates the provider switch.
6. Perform one controlled low-value production transaction; monitor before broader use.

Rollback disables only new Sakurupiah checkout. It never rewrites/deletes historical Payment snapshots, references, events, or credentials needed to reconcile pending rows. Existing Sakurupiah Payments continue reconciliation/manual review. Never compensate an uncertain invoice with a different provider or second Payment.

## Non-goals

- Replacing Payment Provider Switch.
- Changing `OrderLifecycle`, Inventory Ledger, checkout key, or Telegram ledger ownership.
- Pakasir retirement, schema cleanup, or reclassification of historical rows.
- Automatic fallback among providers.
- Multi-channel Sakurupiah selection in V1.
- Production activation from static documentation alone.
