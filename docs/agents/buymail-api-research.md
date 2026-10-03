# BuyMail reseller API research findings

> Documentation research for purchase-on-demand and account refunds. No authenticated requests, purchases, deposits, or production changes were performed.

## Scope and primary source

- **[D1 — BuyMail Reseller API](https://buymail.simuru-store.com/api/reseller/docs)** — public provider documentation, retrieved 2026-10-03 as static HTML using `curl` after the browser research tool could not retrieve it.
- **[R1 — Reseller dashboard](https://buymail.simuru-store.com/reseller)** — linked by D1 as the place to activate keys, configure HTTPS webhooks, and restrict server IPs; the authenticated dashboard was not inspected.

Context7 was searched for BuyMail reseller API documentation. Its results described unrelated reseller services, so no unrelated documentation was used. D1 is the sole source for the provider contract below. Recommendations and unanswered questions are explicitly separated from documented behavior. No OpenAPI/schema, sandbox, SDK, or API version is linked in D1.

## Important conclusions

1. The API supports buying only when a buyer has paid: it purchases available accounts directly from supplier stock and debits a prepaid reseller balance. Requiring a verified paid local order before calling it is an application invariant, not a provider feature.
2. Order creation has unusually useful recovery guarantees: required idempotency keys, an independent unique merchant reference, lookup by reference, and returned order IDs for duplicate references. Persist both keys before sending an order.
3. A successful supplier response can contain fewer accounts than requested. Charge, fulfillment, buyer compensation, and retries must all use the actual delivered count.
4. Refund requests are available but approval is conditional and reviewed. A screenshot is mandatory, the window is two hours from supplier delivery, and some Google account problems are explicitly excluded. An approved refund credits supplier balance; it is not a documented direct refund to the end buyer.
5. Refund approval can later be reversed and deducted from supplier balance. Local buyer refund policy and supplier refund recovery need separate records.
6. Webhooks are advisory: delivery retries may be lost on supplier restart. Polling authoritative GET endpoints is required for recovery.

All six provider-specific conclusions are supported by [D1](https://buymail.simuru-store.com/api/reseller/docs); the local ordering and accounting recommendations are integration design judgments.

## Authentication, configuration, limits

The base URL is `https://buymail.simuru-store.com/api/reseller`. Requests/responses use JSON, except refund evidence upload uses `multipart/form-data`. Money uses whole-number IDR; timestamps use UTC ISO 8601. Requests require `Authorization: Bearer <key>`.

API access unlocks after cumulative deposits reach IDR 100,000. The reseller activates access in the dashboard after logging in through the Telegram bot. Dashboard configuration includes key generation, webhook URL, and an IP allowlist. Provider keys are hashed; a lost key cannot be displayed again. Creating a replacement key immediately invalidates the previous key. Keep keys on the server.

The documented request limit is 120 per minute per key. Exceeded limits return HTTP 429 with `Retry-After`. Purchase quantity is 1–50. A daily sales cap also exists and resets at 00:00 GMT+7; its value and a remaining-daily-cap field are not documented. Example profile limits include purchase maximum, deposit minimum/maximum, and request rate. Treat example price and stock as examples rather than fixed configuration. ([D1](https://buymail.simuru-store.com/api/reseller/docs))

## Complete documented endpoint inventory

Paths below are relative to the base URL. A dash means the docs do not specify a request body; it does not claim a formally validated empty-body schema.

| Method/path | Purpose | Request or query | Documented result/behavior |
| --- | --- | --- | --- |
| `GET /profile` | Current reseller configuration and capacity | — | Identity, balance/currency, `price_per_account`, `stock_available`, `refund_window_minutes`, `refunds_enabled`, limits, webhook URL, IP allowlist, key prefix |
| `POST /deposits` | Create supplier-balance top-up | JSON `amount`; required `Idempotency-Key` | HTTP 201, `{deposit}` with ID, amount/currency, status, payment URL, expiry/creation/payment timestamps |
| `GET /deposits/{id}` | Retrieve top-up | Deposit ID | Deposit lookup; complete response envelope not given |
| `GET /deposits?limit=20` | List top-ups | Example `limit=20` | Complete envelope, maximum limit, pagination and sorting not given |
| `POST /deposits/{id}/cancel` | Cancel unpaid top-up | ID; body not specified | Cancel operation; complete response shape not given |
| `POST /orders` | Buy available Gmail accounts | JSON `quantity`, optional unique `reference`; required `Idempotency-Key` | HTTP 201, `{order, balance}` with credentials and account IDs; full or partial fulfillment |
| `GET /orders/{id}` | Retrieve purchase | Supplier order ID | Purchase lookup; complete envelope not given |
| `GET /orders?limit=20&reference=…` | List/recover purchases | Example `limit=20`; reference filter | Lookup by merchant reference; complete envelope/pagination not given |
| `GET /accounts/{id}` | Read delivered account and refund status | Supplier account ID | Authoritative account/refund state; includes credentials in purchase example |
| `POST /accounts/{id}/confirm` | Confirm account works | ID; body not specified | Closes refund window early; final, no refund afterward |
| `POST /accounts/{id}/refund` | Submit account claim | Multipart `proof` required; `note` optional | HTTP 202; account becomes `refund_pending` |

The API page also supports settings and a test webhook, but D1 documents no public API endpoint for settings, key rotation, webhook testing, dispute messaging, claim withdrawal, or choosing a replacement account. ([D1](https://buymail.simuru-store.com/api/reseller/docs))

## Purchase semantics and safe recovery

Each `POST /orders` and `POST /deposits` requires `Idempotency-Key`: 1–100 characters from letters, digits, underscore, hyphen, period, and colon. Use a new key per new purchase/top-up. Successful results are stored for seven days. Repeating the same request/key returns the original result with `Idempotent-Replayed: true`; concurrent execution returns `409 idempotency_in_progress` and should be retried after several seconds. Reusing a key with a different body returns `422 idempotency_key_reused`. D1 says errors are not cached and do not debit balance.

`reference` is optional, 1–64 characters, and unique per reseller purchase. Duplicate use returns `409 reference_already_used` with `order_id`. `GET /orders?reference=...` recovers the existing purchase. D1 gives no reference-expiry period and no precise charset/normalization rule.

Every account is checked immediately before delivery. Orders normally take 10–30 seconds; client timeout must be at least 90 seconds. A completed order contains `quantity_requested`, `quantity_delivered`, `unit_price`, `amount_charged`, currency, timestamp, reference, and `accounts[]`. Each account includes supplier account ID, supplier order ID, email, password, price, status, `refund_deadline`, refund object, and creation time. `status: partial` means only some requested accounts passed the check; the debit covers delivered accounts only. `out_of_stock` instead means available stock was insufficient, and its error includes `available`. ([D1](https://buymail.simuru-store.com/api/reseller/docs))

**Integration recommendations (inferences):**

- Require a durably recorded successful buyer payment or atomic local-wallet debit before creating a supplier order. Polling profile/stock is safe before payment; calling the purchase endpoint is not merely a stock check.
- Persist an immutable purchase intent with the local paid order, exact payload, stable idempotency key, and unique reference before network I/O. Serialize execution for that paid order.
- Treat connection loss, timeout, process crash, malformed response, and undocumented proxy errors as an uncertain result. Retry with the original key/body and/or recover by reference. The no-debit guarantee for documented provider error responses does not prove that an arbitrary transport failure has no debit.
- Keep recovery separate from new fulfillment attempts. After seven days do not blindly create a new key for an unresolved intent; lookup by reference and escalate unresolved cases.
- Persist returned credentials/account IDs before buyer delivery. A failed Telegram message needs delivery retry of the same stored purchase, not another supplier purchase.
- For partial delivery, explicitly decide whether to deliver/refund the remainder or replenish using a separately tracked remainder intent after reconciling the first order. Never retry the original intent with a changed quantity.
- Enforce balance/price/margin/quantity limits immediately before purchase, while accepting that a profile snapshot cannot reserve price or stock. D1 documents no price ceiling field, quote lock, or reservation endpoint.

## Deposits

Supplier purchases spend prepaid reseller balance. `POST /deposits` opens a payment page supporting QRIS, bank transfer, convenience-store payment, or crypto. Allowed deposit amount is IDR 5,000–5,000,000. At most one unpaid deposit can exist; `409 deposit_pending` includes the open deposit. States are `pending`, `paid`, `expired`, `cancelled`. Payment automatically credits supplier balance and emits `deposit.paid`. No automated debit from the reseller's bank/wallet, recurring funding, balance withdrawal, or deposit-refund endpoint is documented. ([D1](https://buymail.simuru-store.com/api/reseller/docs))

## Refund eligibility, evidence, and states

Account states documented by D1:

| Account state | Meaning |
| --- | --- |
| `active` | Delivered and within the refund window |
| `completed` | Window expired or explicitly confirmed; no later refund possible |
| `refund_pending` | Claim submitted and under review |
| `refunded` | Approved; account price credited to supplier balance |

`refund` is either null or an object with status `pending`, `approved`, `rejected`, or `reversed` and unspecified additional fields. Pending claims may expose `chat_url` for seller dispute chat. The seller can dispute; an admin decides disagreements. Claim withdrawal exists in dispute chat, reflected by `refund.cancelled`, but a matching `cancelled` refund-object status is not listed.

Submission requires a JPEG/PNG screenshot of at most 4 MB as multipart `proof`; optional `note` is at most 500 characters. The deadline is two hours after supplier delivery, not two hours after the reseller eventually sends credentials to the buyer. The profile exposes `refund_window_minutes` and each account exposes `refund_deadline`; use the supplied per-account deadline as the operational deadline. D1's two-hour prose does not establish whether the profile value can change by reseller.

Documented rules:

- Evidence must show a phone sign-in through the Google app with clock, battery and signal in the status bar. Browser screenshots are rejected.
- A Google message reporting a password change more than one hour ago qualifies; one hour ago or less is rejected.
- Family Link supervision qualifies with the Family Link notice as evidence.
- Locks following buyer password changes and Google flags related to a new device, new location, or bulk sending are excluded.
- Fake or mismatched screenshots can lead to rejection and disabled refund access.
- Approved refunds may later be reversed if evidence is invalid; that amount is deducted from supplier balance.

D1 separately advises first login on a computer with a fresh Chrome profile, then password/recovery/2-Step Verification updates on the same connection. This creates an evidence-path distinction: recommended first login uses a computer, while acceptable claim proof requires the phone Google app. The documentation does not explain how to obtain phone proof without risking its new-device exclusion. This must be clarified with the provider rather than solved by inventing a guarantee. ([D1](https://buymail.simuru-store.com/api/reseller/docs))

**Integration recommendations (inferences):** preserve account-to-buyer ownership, deadline, evidence, supplier refund state and local buyer compensation state separately. Do not automatically confirm on purchase/delivery. Permit evidence submission early enough to reach the provider before the deadline. Validate format/size and ownership; preserve the original screenshot. An uncertain submission should be recovered with `GET /accounts/{id}` before resubmission because refund submission has no documented idempotency key. A pending claim must remain pending until the authoritative decision. Buyer refunds/replacements and handling of later supplier reversal require an explicit commercial policy; supplier credits alone do not execute them.

## Webhooks and reconciliation

Configure an HTTPS URL in the dashboard. A `whsec_...` signing secret is shown once. POSTs contain `X-Webhook-Id`, `X-Webhook-Event`, and `X-Webhook-Signature: t=<unix-seconds>,v1=<hex>`. JSON contains `id`, `type`, `created_at`, and `data`.

| Event | Data | Effect |
| --- | --- | --- |
| `deposit.paid` | `{deposit}` | Supplier top-up paid |
| `refund.approved` | `{account}` | Supplier balance credited |
| `refund.rejected` | `{account}` | Claim denied |
| `refund.reversed` | `{account}` | Previous supplier credit deducted |
| `refund.cancelled` | `{account}` | Claim withdrawn in dispute chat |
| `ping` | Unspecified | Test event |

Verify hexadecimal HMAC-SHA256 with the signing secret over the timestamp, period and untouched raw request body. Compare signatures in constant time. Reject timestamps outside five minutes; the examples use absolute clock difference, rejecting overly future timestamps as well. Respond 2xx within ten seconds. Failed delivery retries are scheduled after 30 seconds, two minutes, ten minutes, thirty minutes and two hours, but may be lost across provider restarts. Deduplicate by event `id`; GET endpoints are explicitly the source of truth. ([D1](https://buymail.simuru-store.com/api/reseller/docs))

**Integration recommendations (inferences):** verify before parsing, durably save the accepted event, acknowledge promptly, and process asynchronously. Reconcile pending deposits/refunds and uncertain orders periodically with shared rate budgeting/backoff. Do not assume event ordering or execute a buyer-credit operation directly for every approval webhook. Re-read authoritative state, enforce legal transitions, and use unique ledger keys to avoid duplicate credits. Preserve reversal events and resulting liabilities.

## Error inventory

All use `{error: {code, message, ...}}`; branch on `code`, not wording. ([D1](https://buymail.simuru-store.com/api/reseller/docs))

| HTTP | Codes | Relevant behavior |
| --- | --- | --- |
| 400 | `invalid_json`, `invalid_quantity`, `invalid_reference`, `invalid_amount`, `invalid_request` | Correct request before retry |
| 400 | `idempotency_key_required`, `proof_required` | Required header/evidence missing |
| 401 | `unauthorized` | Missing/invalid key |
| 402 | `insufficient_balance` | Includes `balance`, `required` |
| 403 | `ip_not_allowed`, `account_suspended`, `refunds_disabled` | Correct allowlist or contact support |
| 404 | `not_found` | Missing ID or different owner |
| 409 | `out_of_stock`, `daily_limit_reached` | Available count; cap resets GMT+7 |
| 409 | `reference_already_used` | Includes supplier `order_id`; recover existing order |
| 409 | `idempotency_in_progress`, `order_in_progress` | Wait several seconds and retry |
| 409 | `deposit_pending` | Includes existing deposit |
| 409 | `refund_window_expired`, `refund_already_requested`, `already_refunded`, `invalid_state` | Action is not currently allowed |
| 413 / 415 | `proof_too_large`, `proof_invalid_type` | Correct evidence size/type |
| 422 | `idempotency_key_reused` | Same key used with different body |
| 429 | `rate_limited` | Honor `Retry-After` |
| 502 / 503 | `payment_gateway_error`, `stock_verifying` | Documented temporary failure with no debit |
| 500 | `purchase_failed` | Documented no debit; retry original key |

## Questions that need provider confirmation before production

1. Is there a sandbox/test account? Can partial orders, unknown outcomes, expiry, rejection, reversal, and webhook recovery be exercised without buying live accounts?
2. What are exact GET/list response envelopes, pagination, ordering and retention? Is a reference always discoverable after the seven-day idempotency cache expires?
3. Is order-reference uniqueness permanent and atomic under concurrency, and what normalization/charset rules apply? How does recovery work while a reference's original request is still running?
4. Can `partial` deliver zero accounts? Are `completed` and `partial` the complete order state vocabulary? How does partial live-check failure differ from `stock_verifying`?
5. Is there any acceptable-price ceiling or reservation mechanism? Can reseller unit price change during an in-flight order? What daily cap applies and can remaining capacity be queried?
6. What exactly are the refund object fields and account transitions after rejected, reversed and cancelled claims? Can a rejected/cancelled claim be reopened within its deadline? Is refund POST duplicate-safe, and how do concurrent submissions behave?
7. What is the review SLA? Does a timely accepted submission preserve eligibility until review completes? How should a reseller collect required phone evidence when recommended initial login is on a computer and new-device problems are excluded?
8. Are additional failure reasons covered beyond the two examples? What exact refund amount is credited, can reversals create a negative supplier balance, and is there a finality cutoff for reversals?
9. Is an account replacement ever supported, or only supplier-balance credits? No replacement endpoint is documented.
10. Are webhook retries re-signed with a fresh timestamp? How can signing secrets rotate? Is ordering guaranteed, and can past events be replayed/listed? No event-history endpoint is documented.
11. Does `refund_window_minutes` vary, and can the provider shorten it? Can key rotation interrupt an in-flight order, and do reference/idempotency records survive rotation?

These are missing guarantees in D1, not assertions that the provider lacks the capability. Production decisions must use the documented baseline until the provider supplies a stronger contract.
