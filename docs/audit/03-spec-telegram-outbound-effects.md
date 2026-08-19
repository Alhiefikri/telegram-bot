# Spec: Durable Telegram Outbound Effects

## Problem Statement

The application commits real customer Orders, Payments, inventory reservations/sales, credential allocations, and public Sales Proof state before or alongside Telegram work. A process crash can leave a committed checkout without any durable dispatch record. A timeout or connection failure can also happen after Telegram accepted a message, but the current callers treat the exception as a definite failure, clear their claim, and allow a later retry to send a duplicate instruction, receipt, credential, payment proof, notification, or Sales Proof Post.

The current Sales Proof `sending` claim avoids some duplicates but can strand a public post indefinitely because no recovery path distinguishes a stale worker claim from an uncertain Telegram delivery. The same class of problem appears in several customer, admin, broadcast, stock, and price notification paths.

## Solution

Introduce one shared Telegram Outbound Effect Ledger implemented as a transactional outbox for all durable Telegram business deliveries. The source service records an effect in the same database transaction as the business state change. A dispatcher and queue worker claim the effect with a database lease, perform the existing Telegram/provider-specific rendering, classify the result, and finalize the effect with a token-checked state transition.

The ledger uses `pending`, `sending`, `sent`, `failed`, `unknown`, and `manual_review` outcomes. A definite rejection or pre-send validation failure can be retryable `failed`. A timeout, connection failure, HTTP failure with uncertain request delivery, or worker interruption is `unknown`, never an automatic retry. An operator can verify a delivery and mark it sent, or explicitly create a new resend version with duplicate-risk confirmation. Telegram callback acknowledgements and cleanup operations remain outside this ledger.

The design preserves existing checkout mutation idempotency through `orders.checkout_key`, existing provider boundaries, existing queue ownership, and KlikQRIS same-Payment recovery. It is rolled out with expand-contract compatibility so old claim projections are removed only after every effect family is migrated and verified.

## User Stories

1. As a Telegram customer, I want a committed checkout to eventually produce its payment instructions even if the web request, webhook worker, or queue dispatch process stops immediately after the database commit, so that I am not left with a reserved order and no way to pay.
2. As a Telegram customer, I want a payment instruction that may already have been accepted by Telegram not to be sent blindly again after a timeout, so that I do not see conflicting or duplicate payment instructions.
3. As a Telegram customer, I want my paid receipt and digital credential delivery to avoid duplicate sends when the response is lost, so that sensitive credentials are not exposed twice.
4. As an administrator, I want pending, failed, unknown, and manually reviewed outbound effects to be visible with safe metadata, so that I can resolve delivery incidents without seeing secrets in logs or dashboards.
5. As an administrator, I want to verify an unknown customer or admin message in Telegram and mark the effect delivered without sending it again, so that the database reflects reality without creating a duplicate.
6. As an administrator, I want an explicit resend action for an unresolved effect to create a new auditable effect version with a duplicate warning, so that a deliberate resend is distinguishable from an automatic retry.
7. As an owner, I want a committed paid transition to retain its receipt, admin notification, payment-proof forwarding, and Sales Proof intents even if queue dispatch fails, so that financial lifecycle changes do not silently lose their outbound consequences.
8. As an owner, I want a Sales Proof Post that has an uncertain Telegram outcome to require review rather than being automatically published again, so that the Store Channel does not receive duplicate public announcements.
9. As an owner, I want each Admin Broadcast, stock notification, and price notification recipient to have an independent delivery outcome, so that one ambiguous recipient does not block or duplicate an entire batch.
10. As a customer, I want KlikQRIS instructions to continue using the same Payment, final provider total, trusted QR presentation, and existing recovery policy, so that the outbox migration does not create a second payment or change the Telegram-only payment experience.
11. As an owner, I want the existing queue separation for checkout, notifications, and broadcast traffic to remain intact, so that slow Telegram calls do not change unrelated worker behavior.
12. As an operator, I want stale worker leases to become an explicit ambiguous state instead of being silently retried, so that process death is visible and does not create an accidental duplicate.
13. As a developer, I want one effect key and one claim protocol for all durable Telegram deliveries, so that a new notification feature cannot reintroduce the same claim-reset bug with a private state table.
14. As an auditor, I want every outcome transition and manual resolution to be traceable without storing plaintext credentials, QR media, or Telegram bot tokens, so that incident review is possible without expanding secret exposure.
15. As a release engineer, I want the ledger schema and worker behavior to support expand-contract rollout, so that old and new application versions can coexist during deployment without downtime.

## Implementation Decisions

- Use one shared ledger for durable business Telegram delivery units. Do not create a second outbox or claim table for Sales Proof, checkout, receipts, broadcasts, stock, or price notifications.
- A delivery unit represents one durable Telegram API side effect. Multi-message effects such as chunked receipts use a group key and sequence so successful earlier chunks are not resent when a later chunk is ambiguous.
- Give every unit a deterministic unique logical effect key. The key must distinguish a legitimate new business event or explicit resend version from a retry of the same event.
- Store the source aggregate reference, effect type, target, queue policy, group/sequence, locale/template version, retry metadata, and redacted outcome metadata. Do not store Telegram bot tokens, raw QR bytes, or plaintext credential content.
- If an immutable render context or payload is persisted, encrypt any sensitive field at rest. Logs, exceptions, command output, metrics, audit metadata, and test fixtures must contain only redacted values or correlation IDs.
- Create the pending effect inside the same database transaction as the source state change. The transaction must cover checkout Order/Payment/reservation creation, paid lifecycle and inventory sale, Sales Proof creation, payment-proof state, and recipient materialization where applicable.
- A separate dispatcher may enqueue a worker after commit, but correctness must not depend on that dispatch call completing. A scheduled recovery scan must find committed pending rows even when no claim exists.
- Claim with pessimistic row locking, a unique claim token, and a lease expiration. Finalization must compare the token so an old worker cannot overwrite a later owner’s state.
- Treat a successful Telegram response with the required message identifier as `sent`. Treat a definite negative API response or pre-send validation failure as `failed` according to the effect retry policy. Treat transport ambiguity and worker interruption as `unknown`.
- A stale `sending` lease becomes `unknown`, not `pending`. `unknown` does not automatically re-enter the queue. It transitions to `manual_review` for operator verification.
- Marking an effect delivered after operator verification records the Telegram message identifier and resolution metadata. Explicit resend creates a new effect version and leaves the original unknown record immutable.
- Preserve existing provider-specific rendering and lifecycle ownership. KlikQRIS remains responsible for create/recovery/payability and uses the same Payment. Pakasir remains legacy-compatible. Binance continues to identify the rail by method as well as provider.
- Preserve existing queue names and operational rate limits. Queue uniqueness remains a duplicate-dispatch optimisation, not a delivery guarantee.
- Keep old claim columns as compatibility projections during migration. Stop writing them per effect family only after that family is ledger-authoritative. Drop them in a final contract change after an audit command proves there are no remaining callers or unresolved safe legacy rows.
- Do not automatically backfill a legacy stale claim into `pending`. A row with evidence of a prior attempt becomes `unknown` or manual review. A row with no attempt evidence may be materialized only when the business state and eligibility prove that no send was started.
- Keep `answerCallbackQuery`, `sendChatAction`, delete cleanup, and unclassified edit operations outside this first ledger boundary.
- Use the application’s existing business service owners. Queue jobs adapt to the effect service and must not move payment transitions, inventory mutations, provider selection, or checkout idempotency into a job.

## Testing Decisions

- Tests must verify externally observable customer/admin behavior and durable state transitions. A mock-only Telegram client test is useful for outcome classification but is insufficient as the highest-level proof.
- Exercise the public Telegram webhook callback path through checkout, the database transaction, effect creation, and worker dispatch. Assert that a simulated process death between commit and queue dispatch leaves a pending effect that recovery can find.
- Exercise a real local HTTP boundary for the Telegram adapter that accepts a `sendMessage` or `sendPhoto` request and disconnects before the response. Assert that the effect becomes `unknown` and is not blindly redispatched.
- Exercise successful, definite rejection, retryable failure, ambiguous transport failure, expired lease, mismatched claim token, manual mark-sent, and explicit resend behavior.
- Exercise multi-unit receipt ordering. Once one unit is sent, an ambiguous later unit must not resend the earlier unit. Credential and rendered payload redaction must be asserted in logs, command output, and failure metadata.
- Exercise paid lifecycle through existing payment/provider callback seams. Assert that the paid transition and effect row commit together, while a rollback creates neither a paid transition nor an outbound effect.
- Exercise KlikQRIS through its existing native QRIS test seam. Assert final `total_amount`, same-Payment recovery, 15-minute provider TTL behavior, no fallback Payment, and Telegram-only presentation remain unchanged.
- Exercise Sales Proof through the existing publish/admin dashboard seam. Assert that `sending` lease expiry becomes reviewable, an accepted-then-disconnected send is not automatically duplicated, and operator mark-sent or explicit resend behaves as specified.
- Exercise broadcast, stock, and price notification seams at recipient level. Preserve localized content, literal admin content, batching, rate limits, and independent recipient outcomes.
- Run PostgreSQL concurrency coverage for duplicate checkout callbacks, competing effect claims, lease/finalization races, lifecycle locks, and recovery. The SQLite suite remains the fast behavior gate.
- Extend the existing webhook, checkout, KlikQRIS checkout, payment-proof, broadcast recovery, and Sales Proof feature tests as prior art. Run the broad non-draft PHP suite and record the result.

## Out of Scope

- Changing Telegram Bot API, adding an unsupported Telegram idempotency key, or claiming exactly-once remote delivery.
- Replacing `orders.checkout_key` or changing checkout mutation semantics.
- Changing KlikQRIS provider selection, QR amount rules, TTL, recovery, webhook verification, or Telegram-only UX.
- Automatic fallback between payment providers or creation of a second Payment.
- Tuning RAM, PHP-FPM, PostgreSQL resources, Docker limits, disk cleanup, image cache, or container capacity.
- Ledgering ephemeral callback acknowledgements, typing indicators, deletion cleanup, or unclassified message edits.
- Implementing unrelated storefront features, payment-provider retirement, or admin redesign.
- Automatically resending every historical legacy row during migration.

## Further Notes

This spec implements the accepted decision in ADR-0007 and uses the domain terms `Telegram Outbound Effect`, `Ambiguous Telegram Delivery`, and `Manual Delivery Review` from `CONTEXT.md`.

The recommended approach is based on Laravel queue/transaction/locking documentation, the Telegram Bot API contract, AWS Transactional Outbox guidance, AWS idempotent API guidance, and AWS at-least-once delivery guidance:

- https://laravel.com/docs/13.x/queues
- https://laravel.com/docs/13.x/queries
- https://core.telegram.org/bots/api
- https://docs.aws.amazon.com/prescriptive-guidance/latest/cloud-design-patterns/transactional-outbox.html
- https://aws.amazon.com/builders-library/making-retries-safe-with-idempotent-APIs/
- https://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/standard-queues-at-least-once-delivery.html

The implementation should be released in vertical slices. The first slice must prove manual checkout end-to-end before provider-specific checkout, credential receipt, lifecycle notifications, Sales Proof, broadcast, stock, and price migrations. The final slice removes legacy claim columns only after the ledger is authoritative for every family.
