# Runbook: Sakurupiah QRIS Payment Provider

This runbook covers activation, observation, recovery, and rollback of the
Sakurupiah QRIS Payment rail. It does not authorize production checkout by
itself; production activation requires the external contract gates recorded in
the implementation map (#407) to be closed with redacted sandbox evidence and
an explicit Owner activation through the Admin Payment Provider Switch.

## Deployment-time configuration

Sakurupiah credentials and deployment controls belong in the server-side
`.env`, never in the database, Admin presentation settings, logs, fixtures, or
customer copy. Keep sandbox and production pairs separate:

```env
SAKURUPIAH_SANDBOX_ENABLED=false
SAKURUPIAH_SANDBOX_BASE_URL=https://sakurupiah.id/api-sanbox/
SAKURUPIAH_SANDBOX_API_ID=
SAKURUPIAH_SANDBOX_API_KEY=
SAKURUPIAH_SANDBOX_CALLBACK_URL=
SAKURUPIAH_SANDBOX_RETURN_URL=

SAKURUPIAH_PRODUCTION_ENABLED=false
SAKURUPIAH_PRODUCTION_BASE_URL=https://sakurupiah.id/api/
SAKURUPIAH_PRODUCTION_API_ID=
SAKURUPIAH_PRODUCTION_API_KEY=
SAKURUPIAH_PRODUCTION_CALLBACK_URL=
SAKURUPIAH_PRODUCTION_RETURN_URL=
```

Do not add ENV for channel labels, fees, expiry hours, or presentation copy;
those are database/contract settings or typed provider constants. The provider
spelling `api-sanbox` is intentional and must not be "corrected".

## Readiness

A Sakurupiah selection for new checkout is Owner-controlled and fails closed:
an unready selection never falls back to Manual or KlikQRIS. Run the sanitized
readiness report whenever activation or a provider-related incident is under
investigation:

```bash
cd src
php artisan sakurupiah:check-readiness --json
php artisan ops:health --db --queue-schema --queue-depth --sakurupiah
php artisan schedule:list
```

Readiness separately assesses enabled/configured state, credential pair, HTTPS
URLs and host policy, runtime environment, callback route, QRIS channel
activity/limits, fee/currency/expiry contract, queue/scheduler, and (in
production) the IP policy. A disabled provider is a safe state; an enabled but
unready provider is a release failure. `sakurupiah:check-readiness` reports
the bounded local backlog counts (`pending`, `verification_pending`,
`terminal_recent`) without calling the provider.

The reconciliation schedule (`sakurupiah:reconcile --chunk=100`, every minute)
is registered only when the runtime environment is enabled, operationally
ready, and the verification queue is the supervised `default` queue consumed by
the release worker. Deferred webhook verification jobs also use that queue. Do
not route Sakurupiah work to a `payments` queue that no supervised worker
consumes. Before enabling the schedule, verify live worker ownership with:

```bash
php artisan ops:health --db --queue-schema --queue-depth --supervisor --sakurupiah
```
In production Compose, the scheduler depends on the app health check before
starting its loop. That health check includes `--supervisor`, so live worker
ownership is verified before the scheduler can run; the bootstrap readiness
gate itself remains a non-invasive queue/schema/schedule configuration check.

## Reconciliation

`sakurupiah:reconcile` scans only Sakurupiah QRIS IDR Payments in bounded
eligible states, ordered deterministically by `(created_at, id)`, with a fair
persisted cursor, a hard execution deadline, eager relation loading, and a
per-Payment single-flight lock. It never issues a create request, never holds a
database transaction across HTTP, and reports only aggregate counts and redacted
identifiers:

```bash
php artisan sakurupiah:reconcile --chunk=100
```

Expected summary counters:

- `paid` — authoritative `berhasil` with amount evidence applied through
  `OrderLifecycle`.
- `expired` — authoritative `expired` applied through `OrderLifecycle`.
- `pending` — provider still reports pending; no lifecycle change.
- `verification_pending` — no amount-bearing evidence yet (or provider outage);
  the Order stays unchanged and the next sweep retries.
- `invalid` — identity/amount mismatch recorded; no lifecycle change.
- `busy` — the Payment lock was held by create/callback/refresh work.
- `failed` — an unexpected error; inspect the sanitized log, never raw payloads.
- `budget_exhausted=1` — the execution deadline stopped the page early; the
  persisted cursor resumes the next bounded sweep.

Command restart and duplicate scheduler execution are idempotent: a Payment
that already advanced is not re-queried or re-applied.

## Sandbox validation

Before any production traffic, run a qualified sandbox end-to-end path with a
merchant-owned sandbox credential and the smallest permitted QRIS amount.
Capture only redacted evidence. Key steps:

1. Verify readiness and active QRIS channel metadata.
2. Create one invoice; confirm the exact sanitized snapshot and that only the
   safe instruction is shown to the buyer.
3. Observe pending/paid/expired callbacks; repeat the same callback and confirm
   dedupe (received_count increments once, one lifecycle transition).
4. Confirm status/history responses carry amount-bearing evidence for paid.
5. Confirm the bounded `sakurupiah:reconcile` behaves correctly on the same
   Payment (paid/expired/pending), including after a restart.

Never use a production transaction to discover undocumented provider behavior.

## Outcome-unknown recovery

A create that timed out, disconnected, returned 5xx, or returned invalid JSON
after possible dispatch is **unknown**, not failed. The Payment keeps its
`provider_create_state = outcome_unknown` and is retried by reconciliation
using the persisted provider reference (never a blind second create). If a
Payment has no confirmed `provider_reference`, reconciliation records
`recovery_unconfirmed` and leaves the Order pending; it must not invent an
invoice.

Manual investigation:

```bash
php artisan sakurupiah:check-readiness --json
php artisan sakurupiah:reconcile --chunk=100
```

Then check the admin Order detail's payment observability for the safe state
and the latest reconciliation timestamp. If the provider reference is missing
and the create cannot be correlated, keep the Payment in verification pending
and escalate with the redacted merchant reference hash.

## Verification-pending backlog

`verification_pending` grows when the provider has not yet returned
amount-bearing authoritative evidence or when the provider is unavailable.
Reconciliation retries automatically each minute. The dashboard payment
control shows the bounded count and oldest pending age for Sakurupiah; the
metrics are local-only and never issue per-row provider requests.

If the backlog keeps growing or is stuck, investigate:

- provider status/readiness (`sakurupiah:check-readiness --json`);
- queue health (`ops:health --queue-depth --supervisor`);
- whether the Order/Payment is stuck in an overdue pending state that the
  expiry scheduler should have released;
- the sanitized reconciliation logs for repeated `status_unavailable`.

Do not mark these Payments paid or expired from callback text or from missing
evidence; leave the lifecycle to authoritative observation.

## Callback duplicates

Callbacks are untrusted signals. Duplicate deliveries are coalesced through a
deterministic `PaymentEvent` idempotency key and received-count increments.
Only an authoritative status/history observation with matching reference and
amount evidence may transition a Payment to paid; expired requires the
confirmed status/code pair. If the same callback arrives out of order or after
a terminal transition, the processor acknowledges it without a second
lifecycle mutation.

## Late payment

A provider-confirmed paid observation after the local Order has become
Expired/Cancelled is financially valid but is a **Late Payment**. It is marked
paid through `OrderLifecycle`, the Order stays terminal, a pending
`ManualFulfillmentReview` is created, and no stock/fulfillment/receipt is
auto-issued. Owners resolve the review through the existing Manual Fulfillment
Review flow (fulfill, refund, or reject). Do not auto-fulfill a late payment
and do not re-reserve stock for it.

## Stop-new-checkout

To stop new Sakurupiah checkout without touching existing Payments:

1. In Admin → Mode pembayaran, switch the active provider away from Sakurupiah
   (Owner-only, optimistic version + audit). Existing Sakurupiah Payments keep
   their stored provider, method, merchant/reference identity, and sanitized
   provider snapshots; switching the active provider does not reinterpret them.
2. If the switch is not enough (e.g., credentials must be disabled), disable
   the environment flag (`SAKURUPIAH_*_ENABLED=false`) and restart, or use the
   Owner dashboard readiness state. A disabled provider fails closed; it does
   not resolve to Manual/KlikQRIS and pauses automatic Sakurupiah reconciliation.
3. Verify `schedule:list` no longer shows `sakurupiah:reconcile` when the
   environment is disabled.

With Sakurupiah still enabled and ready, existing pending/verification-pending
Payments continue to reconcile after an active-provider switch because their
stored provider/reference state remains authoritative. If the environment is
disabled, restore a ready configuration before expecting automatic provider
lookups. Do not delete credentials or drop columns while those rows exist.

## Rollback

Rollback disables only new Sakurupiah checkout. It never rewrites or deletes
historical Payment snapshots, references, events, or credentials needed to
reconcile pending rows. Use the normal immutable image/commit rollback flow;
do not run Railway commands or add Railway configuration. After rollback run:

```bash
php artisan migrate:status
php artisan sakurupiah:check-readiness --json
php artisan ops:health --db --queue-schema --queue-depth --supervisor --sakurupiah
php artisan schedule:list
```

Never compensate an uncertain Sakurupiah invoice with a different provider or a
second Payment. A later paid observation on a terminal Order remains a Late
Payment requiring manual review.

## Provider support escalation

When escalating to the provider, share only redacted identifiers: the merchant
reference **hash** (never the full reference), the environment label, and the
sanitized failure code. Never share API IDs/keys, callback signatures, raw
payloads, QR data, or hosted URL tokens in chat, logs, or tickets.
