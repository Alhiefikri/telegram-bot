# Binance Exact-Amount Order ID Verification Hardening Design

## Goal

Replace Binance Pay amount-only identification with a buyer-submitted Binance Pay Order ID while keeping checkout simple, preventing false delivery, preserving stock integrity under concurrency, and keeping production rollback safe.

The buyer pays the exact catalog USDT amount, taps **I've Paid**, submits the Order ID shown by Binance Pay, and receives the product only after the backend finds one incoming transaction whose identifier, receiver, currency, amount, and transaction time all match the order.

## Product Decisions

- Buyer-facing terminology remains **Order ID**.
- The Telegram button remains **I've Paid**.
- New transaction-mode orders use the exact stored USDT amount without an identifying surcharge.
- Amount alone can never confirm a transaction-mode order.
- A successful match consumes the reserved stock as a sale and delivers the product exactly once. A failed or missing match never delivers stock.
- Buyers can submit at most three syntactically valid Order IDs while the payment is eligible.
- Invalid syntax does not consume an attempt or call Binance.
- A first valid submission made before the original payment deadline receives a fixed three-minute technical lookup grace period. The grace period never permits a payment made after the original deadline.
- Credential-less Binance checkout retains the manual screenshot flow.
- Storefront buyers submit the Order ID directly on their signed invoice. They are never told to find an unrelated Telegram customer record.

## Provider Contract And Identifier Semantics

The Binance Pay trade-history endpoint officially guarantees `transactionId`, but its current published response model does not guarantee `orderId`. The integration therefore separates buyer terminology from provider bookkeeping:

- `binance_order_id` stores exactly the normalized identifier submitted by the buyer.
- `provider_reference` stores the provider's documented `transactionId` after confirmation and remains globally unique.
- A history entry matches the submitted Order ID when either:
  - its scalar `orderId` equals the submitted value; or
  - its documented `transactionId` equals the submitted value.
- Matching is exact and case-sensitive after trimming outer whitespace. The application does not transform, truncate, or numerically cast the identifier.
- A payload with neither identifier is unusable and cannot confirm a payment.
- The compatibility alias does not weaken the remaining checks: receiver, direction, currency, amount, transaction time, and transaction reuse must still match.

Production activation requires a controlled end-to-end Binance Pay canary using the actual buyer-visible Order ID. If the canary cannot match either provider field, transaction-mode checkout remains disabled and the manual screenshot path stays active.

## Safe Rollout

The change ships as separate deployable releases. They must not be collapsed into one first-time production deployment.

### Release A: Compatibility Foundation

- Add verification-mode and Order ID state additively.
- Keep the existing pending-amount unique index intact.
- Continue creating `amount_unique` payments.
- Make the application understand and safely process both `amount_unique` and `order_id` records.
- Add shared Telegram/storefront submission, hardened provider matching, the dedicated queue, bounded history reads, observability, and tests.
- Repair environments that may already have applied the unsafe migration by idempotently restoring the legacy partial unique index.
- Deploy Release A and verify application health, workers, scheduler, legacy reconciliation, and a controlled Order ID canary.

Rolling Release A back to the pre-change application is safe because Release A has not created transaction-mode payments and performs no destructive schema contraction.

### Release B: Exact-Amount Activation

- Change the legacy amount index predicate so it protects only `amount_unique` payments.
- Switch new Binance payments to `order_id` mode.
- Preserve all legacy `amount_unique` payments until they become terminal.
- Expose the Order ID form and prompt for transaction-mode payments.

Rolling Release B back to Release A is safe because Release A already understands both modes and continues verifying transaction-mode records even though it does not create new ones.

### Release C: Later Contract Cleanup

After monitoring proves there are no non-terminal `amount_unique` payments, remove the legacy allocator, legacy reconciler branch, and legacy partial index in a separate maintenance-approved release. Release C is outside the initial activation path.

## Data Model

Each Binance payment gains or retains:

- `binance_verification_mode`: `amount_unique` or `order_id`; default `amount_unique` during Release A.
- `binance_order_id`: latest normalized buyer submission.
- `binance_order_id_attempts`: accepted valid submissions, capped at three.
- `binance_order_id_submission_version`: monotonic version used to invalidate stale jobs.
- `binance_order_id_submitted_at`: timestamp of the latest accepted submission.
- `binance_verification_grace_until`: fixed original deadline plus three minutes, established only by the first valid pre-deadline submission.
- `binance_verification_status`: bounded machine state (`awaiting_submission`, `checking`, `not_found`, `provider_error`, `mismatch`, or `confirmed`) used by Telegram notifications and storefront polling.
- `binance_last_verified_at`: timestamp of the latest completed provider check.
- `provider_reference`: documented Binance `transactionId`, written only during confirmation and protected by the existing unique constraint.
- `provider_payload`: sanitized evidence only.

`manual_payment_settings` also gains `binance_order_id_compatibility_ready_at`. Release A records this marker only after its post-cutover health checks have passed. Release B refuses to contract the legacy amount index on a non-empty installation unless that marker exists, which prevents both rollout phases from being applied for the first time in a single production deployment. Fresh empty installations may apply the complete schema directly.

Indexes support:

- pending Binance payments by mode and grace deadline;
- lookup of a submitted Order ID without making it a first-claim ownership lock;
- the legacy unique-amount predicate for `amount_unique` payments only after Release B;
- globally unique confirmed provider references.

The submitted Order ID is intentionally not globally unique before verification. A malicious or mistaken user must not be able to reserve another buyer's identifier merely by submitting it first. The confirmed provider transaction remains protected by the unique `provider_reference` constraint.

## Components And Boundaries

### `BinanceSubmittedOrderId`

A small value object owns trimming and syntax validation. It accepts 10–128 ASCII characters from the documented and observed identifier-safe set: letters, digits, underscore, hyphen, period, and colon. It rejects whitespace inside the value, control characters, Unicode confusables, and oversized input.

### `BinanceOrderIdSubmission`

A channel-neutral application service accepts an order/payment plus the submitted value. Under consistent order-then-payment locks it:

1. verifies ownership was already established by the caller;
2. checks provider, method, mode, pending state, original deadline, and attempt limit;
3. persists the normalized value, attempt count, submission version, and fixed grace deadline;
4. dispatches an after-commit verification job to `binance-verification`;
5. returns a bounded result enum for Telegram or storefront presentation.

It never calls Binance while holding database locks.

### `BinancePayTransaction`

The provider value object parses only scalar fields, preserves identifier strings, converts USDT to integer micro-units, and emits a strict sanitized payload. It exposes:

- `matchesSubmittedOrderId()`;
- `transactionId()`;
- incoming receiver checks using the correct identifier namespace;
- currency, amount, and transaction time.

`receiverInfo.accountId` is compared only with configured Binance Pay ID. `receiverInfo.binanceId` is compared only with configured Binance UID. One must never be substituted for the other. Transaction-mode auto-verification is unavailable unless the application can validate the receiver identifier actually returned for incoming PAY transactions.

### `BinanceTransactionHistoryReader`

This service owns complete, bounded history retrieval. It requests an explicit start and end time with limit 100. A response containing fewer than 100 records is complete for that window. A saturated window is divided into non-overlapping time windows and retried, subject to a strict request budget. Results are deduplicated by `transactionId`.

If completeness cannot be proven within the request budget, the reader returns an explicit incomplete/provider-busy outcome. It never presents a truncated page as complete and never turns absence from a truncated response into a final rejection.

Cache entries are keyed by the exact time window and limit. Single-flight locks coalesce identical requests. A cached earlier start is not assumed to cover a later request when the result was saturated.

### `BinanceAutoVerification`

Verification performs provider I/O before acquiring database locks, then confirms inside a short transaction. It requires all of the following:

1. the payment is still pending and eligible;
2. the stored submission version and Order ID still match the job;
3. one transaction matches the submitted Order ID;
4. the transaction is positive incoming value;
5. the receiver matches configured Pay ID or UID in the correct namespace;
6. currency is exactly `USDT` and equals the payment currency;
7. amount equals the payment's integer micro-USDT amount;
8. transaction time is no earlier than checkout minus clock skew;
9. transaction time is no later than the original payment deadline;
10. the provider `transactionId` has not completed another payment.

Confirmation locks the order and payment in the repository-wide order, rechecks every mutable condition, writes the provider reference and sanitized evidence, then invokes the existing order lifecycle. Unique-constraint races become an `already_used` result instead of an exception leak.

### Jobs And Reconciliation

- `VerifyBinanceOrderId` runs only on `binance-verification`.
- Its unique key includes payment ID and submission version.
- Retries use bounded exponential backoff with jitter and respect provider `Retry-After` where available.
- A stale job exits before provider I/O when the stored version, identifier, state, or deadline changed.
- The minute reconciler fetches history once for a bounded batch and matches in memory. It does not make one high-weight API call per payment.
- Provider outages do not block Telegram webhook workers.

## Telegram UX

For an eligible transaction-mode payment, tapping **I've Paid** produces:

> Please send the Binance Pay Order ID for this payment. Open the completed payment in Binance Pay, view Order Details, and copy the Order ID exactly. Do not send your Binance Pay ID or a screenshot here.

After valid input:

> Thanks — we're checking your Binance Pay payment now. This usually takes less than a minute. You don't need to send the Order ID again.

Final outcomes use concise English:

- Confirmed: payment verified and product delivery follows immediately.
- Still indexing/provider unavailable: automatic retries continue until the displayed deadline.
- Found but mismatched/already used: no sensitive comparison details are revealed; the buyer receives the screenshot/support path.
- Invalid input: explain the allowed identifier format without consuming an attempt.
- Attempts exhausted: direct the buyer to the manual evidence path.

Background retries never spam chat. Only start and final state messages are sent.

## Storefront UX

The signed invoice contains the same English instructions and a single Order ID form beneath the exact amount and Pay ID. The buyer does not leave the invoice or switch to Telegram.

- The form posts to a relative temporary-signed route and includes CSRF protection.
- Submission returns immediately after persistence and queue dispatch.
- The invoice displays a checking state and uses the existing signed status channel to poll bounded state, not Binance directly.
- Success reloads the signed invoice and reveals credentials through the existing recovery-key/access boundary.
- Buttons use at least 48px touch targets to avoid fractional rendering failures.
- Terminal invoices cannot submit or retry identifiers.

## Security And Privacy

- Telegram ownership is scoped to the current Telegram customer and order.
- Storefront ownership is scoped to the unguessable temporary signed invoice URL; the submission route independently validates the signature.
- CSRF and per-order plus per-IP rate limits apply to storefront submission and status polling.
- Order IDs and transaction IDs are never written verbatim to application logs, diagnostics, metrics labels, or user-facing error messages. Admin views show a bounded masked form and sanitized evidence.
- API credentials, signatures, raw response bodies, payer contact data, and signed invoice URLs never enter logs or payment events.
- Buyer mismatch messages do not reveal which receiver, amount, timestamp, or identifier check failed.
- Provider exceptions are classified by status/type and redact response bodies.
- The API key remains read-only for required Binance account-history permissions.

The Order ID behaves like bearer evidence. The signed invoice/Telegram ownership checks, checkout-before-transaction constraint, exact amount, receiver validation, attempt limit, and unique confirmed transaction reduce theft risk. The system cannot cryptographically bind a personal Pay-ID transfer to an order unless Binance exposes a merchant reference; this residual limitation is documented and monitored.

## Race-Condition Rules

- Every mutation locks order before payment.
- Provider HTTP calls never occur inside database transactions.
- Submission versioning invalidates delayed work from replaced IDs.
- Confirmation rechecks mode, state, identifier, version, grace, amount, and receiver-derived evidence under lock.
- `provider_reference` uniqueness is the final double-delivery guard.
- Payment events use idempotent identity and are written in the confirmation transaction.
- Delivery and sales-proof jobs dispatch after commit.
- Concurrent expiry, cancellation, submission, reconciliation, and manual admin action must resolve to one valid lifecycle transition without negative stock or duplicate delivery.

## Performance And Availability

- Binance history calls are coalesced and cached per exact bounded window.
- The reconciler performs one bounded query for pending payments and one shared history read, avoiding N+1 HTTP calls.
- Saturated history windows split only when required and stop at a configurable request budget.
- Incomplete history is retryable and fail-closed; it never confirms or permanently rejects based on missing truncated data.
- `429`, `408`, transport failure, and `5xx` use bounded backoff; permanent authentication/configuration errors stop retries and raise an operational health failure.
- The dedicated worker has bounded memory/time/job settings so payment verification cannot starve Telegram updates.
- Database queries use indexed mode/status/grace predicates and fixed chunk sizes.
- Metrics contain counts and timing only, never identifiers: submitted, confirmed, not-found, incomplete-window, mismatch, already-used, provider-error, and queue latency.

## Admin And Late-Payment Handling

- A found mismatch stores sanitized evidence and creates one deduplicated admin alert.
- Admin can review expected versus received amount/currency/time and masked identifiers.
- Automatic approval remains unavailable for mismatches and payments made after the original deadline.
- Late or expired evidence remains a manual support/refund case and never revives the original reservation or auto-delivers. The admin surface and runbook must state this boundary explicitly.

## Migration And Rollback Verification

Automated tests build a pre-change PostgreSQL database containing:

- pending legacy Binance payments with distinct unique amounts;
- paid legacy payments and provider references;
- unrelated manual and Pakasir payments.

Release A migrations must preserve the legacy unique index and allow the pre-change application to read/write during migration. Release B tests start from Release A's schema and data, introduce duplicate exact amounts only in `order_id` mode, and prove Release A code can safely process or ignore them without amount-based attribution.

The deploy harness verifies migration failure, candidate health rollback, external-smoke rollback, and schema compatibility. A destructive down migration is not part of automatic rollback.

## Test Matrix

Tests must prove:

- exact amount creation only after Release B activation;
- legacy unique-amount creation and reconciliation during Release A;
- Telegram prompt, syntax validation, attempt cap, replacement versioning, and quiet retries;
- signed storefront submission, ownership isolation, CSRF, throttling, status polling, and credential reveal after confirmation;
- payload matching through `orderId` and the exact-value `transactionId` compatibility alias;
- rejection when neither provider identifier matches;
- correct Pay ID versus UID receiver validation;
- exact micro-USDT comparison and currency validation;
- lower and upper transaction-time bounds;
- saturated-window splitting, deduplication, request-budget exhaustion, cache isolation, and `Retry-After` behavior;
- stale job no-op before HTTP;
- simultaneous duplicate confirmation, expiry versus confirmation, cancellation versus submission, and two orders claiming one transaction;
- no duplicate sale, credential delivery, notification, or PaymentEvent;
- late-payment evidence remains visible without exposing an unsafe approval transition;
- production configuration validation and safe output redaction;
- upgrade and rollback compatibility using PostgreSQL with existing data;
- Pint, the complete PHP suite, production deploy harness, frontend build, and browser smoke.

## Production Acceptance Gates

Release B cannot activate until all of these are true:

1. Release A is the currently verified production rollback target.
2. Binance API key/secret, Pay ID, and UID pass production configuration validation.
3. Dedicated `binance-verification` workers and the scheduler are healthy.
4. A controlled real Binance payment proves the buyer-visible Order ID matches an accepted provider identifier and all receiver fields behave as expected.
5. No identifiers or credentials appear in logs or diagnostics from the canary.
6. Upgrade/rollback, concurrency, complete test suite, deploy harness, frontend build, and browser smoke are green.
7. Existing pending legacy Binance orders are counted and visible for dual-mode monitoring.

The Release A workflow writes `binance_order_id_compatibility_ready_at` only after public smoke succeeds. The Release B migration checks that marker before changing the legacy index predicate or switching the configured creation mode.

Production deployment continues through the GitHub Actions **Deploy production** workflow from `main`. No ad-hoc production SSH deployment or alternate production branch is introduced.
