# Runbook: Sakurupiah QRIS Payment Provider

This runbook covers activation, observation, recovery, and rollback of the
Sakurupiah QRIS Payment rail. It does not authorize production checkout by
itself; production activation requires the external contract gates recorded in
the implementation map (#407) to be closed with redacted sandbox evidence and
an explicit Owner activation through the Admin Payment Provider Switch.

Automated release evidence and the current issue #416 gate decision are
recorded in [`docs/sakurupiah-release-evidence.md`](sakurupiah-release-evidence.md).
The current decision is **blocked** while PostgreSQL execution and external
Sakurupiah qualification remain unproven.

## Deployment-time configuration

Only the separate merchant credential pairs belong in the server-side `.env`.
Stable endpoints, callback/return routes, host policy, IP policy, transport
budgets, and contract policy are pinned in application configuration. Never put
credentials in the database, Admin presentation settings, logs, fixtures,
customer copy, or repository files.

```env
SAKURUPIAH_SANDBOX_API_ID=
SAKURUPIAH_SANDBOX_API_KEY=
SAKURUPIAH_PRODUCTION_API_ID=
SAKURUPIAH_PRODUCTION_API_KEY=
```

Sandbox credentials are consumed only when `APP_ENV` is `local` or `testing`.
The sandbox API endpoint is pinned to the provider's documented `api-sanbox`
URL. For an end-to-end sandbox invoice, callback and return URLs are derived
from a secure non-production application URL; no Sakurupiah-specific URL or
allowlist variables are required. The sandbox URL is never derived from the
canonical production host.

Production uses the separate production credential pair and the canonical
application routes pinned by the release. The production activation gate is a
reviewed code/configuration decision and remains disabled until the external
contract policy, provider-approved network policy, and explicit Owner approval
are released. Adding credentials alone never selects Sakurupiah for checkout.

This repository has two environment-file consumers: Docker Compose reads the
root `.env`, while Laravel Artisan uses `src` as its application base and
reads `src/.env`. Use the root file through a Compose command, or configure the
same credential pair in the untracked `src/.env`; never copy credentials into
tracked files or commit either file.

Do not add ENV for channel labels, fees, expiry hours, callback URLs, host/IP
allowlists, retry budgets, or presentation copy. Those values are code/config
policy. The provider spelling `api-sanbox` is intentional and must not be
"corrected".

Changing `src/.env` requires restarting the local Artisan server and workers;
it does not require rebuilding a Docker image. When Compose injects credentials,
recreate only an isolated testing project:

```bash
docker compose --env-file <sandbox-env-file> \
  -p telegram-bot-testing up -d --force-recreate --no-build
```

Use a testing database and a project name distinct from production. Compose
`--build` is needed only when the Dockerfile or build context changes; a
credential-only change needs container recreation, not an image rebuild.

## Telegram testing-bot boundary

Sakurupiah sandbox qualification must use a dedicated, non-production Telegram
bot and a disposable admin chat. Sakurupiah sandbox configuration does not
select the Telegram bot: outbound delivery uses `TELEGRAM_BOT_TOKEN` and
`TELEGRAM_ADMIN_CHAT_ID`. Never run a sandbox checkout with production bot
credentials.

Before creating a sandbox checkout:

1. Keep `APP_ENV` outside production and inject the testing bot token and test
   admin chat ID into the same process that runs Laravel. Do not paste either
   value into chat, tickets, logs, or evidence.
2. Call Telegram `getMe` through an operator-approved metadata check. Record
   only the expected bot username and timestamp; stop if the identity is not
   the dedicated testing bot.
3. Confirm the target chat is disposable and belongs to the testing setup.
4. Only then create the minimum sandbox invoice. The provider remains
   Sakurupiah sandbox; Telegram bot identity is a separate safety gate.

Changing `src/.env` requires restarting the local Artisan server and workers;
it does not require rebuilding a Docker image. When Compose injects the
variables, recreate only an isolated testing project:

```bash
docker compose --env-file <sandbox-env-file> \
  -p telegram-bot-testing up -d --force-recreate --no-build
```

Use a testing database and a project name distinct from production. Compose
`--build` is needed only when the Dockerfile or build context changes; an
environment-only change needs container recreation, not an image rebuild.


## External contract policy record

Production readiness also requires the non-secret
`services.sakurupiah.contract_policy` record. It is versioned in application
configuration and defaults to fail closed. Every one of the twelve external
contract gates must be `true` and have a redacted evidence reference; the
record must also contain a redacted record reference and explicit Owner
approval for automatic fulfillment. Do not set these values from static
provider documentation or from a sandbox response that does not prove the
specific gate.

The policy covers request encoding, signature canonicalization, channel
metadata, expiry, create response, QR/hosted presentation, amount-bearing
status/history, duplicate-reference recovery, callback delivery, error/limit
semantics, production network policy, and credential operations.
A missing or unqualified external-contract policy gate prevents production
readiness before any provider HTTP call.

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

### Latest redacted sandbox observation

On 2026-09-04, the sandbox readiness check loaded the root Compose
environment explicitly and reported `enabled=true`, `ready=true`, and no
missing values. `list-payment.php` returned HTTP `200` / JSON `status: "200"`
with 17 rows; the live `QRIS` row reported minimum `500`, maximum `2000000`,
fee `0.7` with unit marker `Percent`, active status, direct type, and
`default_expired=12`. `check_balance.php` returned a parseable typed balance;
the amount is intentionally not recorded here. One `500` QRIS invoice was
created with a unique redacted reference, recovered through same-day history
as `pending` with amount and expiry, and confirmed by status lookup as
`pending`. A non-mutating invalid-method probe returned HTTP `400` with JSON
`status: "400"` and a message. Repeating the same merchant reference was
rejected with HTTP `400`; no second invoice was accepted. After its configured
expiry, the same redacted invoice was observed as `expired` through both
status and amount-bearing history. No payment or callback was observed.
Rate-limit, timeout, retry, expiry-code mapping, callback, and
production-network behavior remain unqualified. This is sandbox evidence only;
it does not qualify production gates or Owner approval.

After that expiry observation, one Owner-authorized fresh sandbox invoice was
created at approximately 21:28 WIB for amount `500`. Its typed create response
was `pending` with total `500`, merchant-fee mode `1`, and both QR and hosted
presentation fields present; the provider omitted a separate `amount` field.
The Owner later changed the sandbox invoice to successful. Status lookup and
amount-bearing history both reported `berhasil` for QRIS and amount `500`;
status lookup still omitted amount fields. No provider callback was observed
by the application, and the invoice was not linked to a local Payment.
Do not record or share its QR, hosted URL/token, signature, or full identifiers.

After the direct-provider payment observation, a separate Owner-authorized
app-linked sandbox checkout was exercised with the dedicated testing bot. The
provider status lookup returned `berhasil`; because it omitted amount, the
application's reconciliation path fetched same-day history and accepted the
transition only when the history amount `500` matched the local IDR Payment.
The Payment became `paid` with `paid_at`, the Order payment status became
`paid`, and the Order remained `processing` for manual fulfillment. The paid
Telegram admin outbound effect was recorded `sent`, and the signed Web invoice
rendered the paid surface. Three identical signed pending callbacks remained
deduplicated and acknowledged, but no provider paid callback was observed.
The app-linked create response omitted QR/hosted presentation, so the
application correctly held the initial Payment pending and non-payable. A
separate app-linked expiry path is still required; these observations do not
qualify unresolved P0 gates or Owner production approval.

### Evidence record mapping

For each gate, retain a redacted capture or hash in the approved
merchant/provider evidence system and put only its redacted reference or hash
in `services.sakurupiah.contract_policy.evidence_references`. Do not copy API
keys, bearer tokens, full HMAC signatures, raw QR values, raw payloads, or
tokenized URLs into the repository, issue, comments, or artifacts. A reference
is not qualification by itself: the capture must show the observed behavior or
cite a written provider clarification.

| Policy gate | Qualification capture | Issue/configuration record |
| --- | --- | --- |
| `request_encoding` | Endpoint, method, content type, field names, and accepted form encoding for every used request. | Redacted request-capture reference. |
| `signature_canonicalization` | Exact canonical input ordering, amount representation, algorithm, and casing; keep secrets and signature output out of the issue. | Secure evidence hash/reference for the signature vector. |
| `channel_contract` | Active QRIS metadata, IDR unit, min/max, fee payer, and returned fee/total semantics. | Sanitized channel-response reference. |
| `expiry_contract` | Expiry range, `Asia/Jakarta` timezone, boundary behavior, and resolved `2` versus `-2` mapping. | Redacted expiry test or provider-clarification reference. |
| `create_response` | Allowed response field types, `trx_id`, merchant reference, and durable local persistence. | Sanitized create-response reference with identifiers hashed. |
| `presentation` | QR EMV/CRC and encoded amount validation, or HTTPS hosted-URL host/token behavior. | Redacted rendering/allowlist evidence reference. |
| `amount_evidence` | Paid status/history response containing authoritative amount and currency matching the Payment snapshot. | Sanitized status/history reference. |
| `duplicate_reference` | Repeated merchant reference and timeout/ambiguous-create recovery using the same persisted reference. | Redacted duplicate/recovery trace reference. |
| `callback_contract` | Callback headers/body, event/status/code mapping, duplicate delivery, retry, and acknowledgement behavior. | Sanitized callback evidence reference. |
| `error_limits` | HTTP/JSON error shapes, timeout behavior, retryable statuses, rate limits, and bounded retry results. | Redacted error/limits matrix reference. |
| `production_network` | Production IP allowlist, reverse-proxy source identity, HTTPS host policy, and rejection behavior. | Redacted network-policy evidence reference. |
| `credential_operations` | Credential rotation/revocation test, expiry handling, and provider support escalation path. | Provider process or test reference; never credentials. |

The `record_reference` identifies the complete evidence set, while each
per-gate reference identifies the corresponding row. Do not mark a gate true
because documentation, a type declaration, or an unverified sandbox response
looks plausible.

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
2. For an emergency credential incident, revoke or rotate the production pair through the provider and recreate the app/scheduler containers. There is no `SAKURUPIAH_*_ENABLED` ENV switch; production enablement is a reviewed code/configuration gate. Preserve the recovery and reconciliation decision for existing Payments instead of silently switching them to another provider.
3. After the reviewed code/configuration gate is disabled, verify `schedule:list` no longer shows `sakurupiah:reconcile`. An Admin provider switch alone intentionally leaves reconciliation available for existing Payments.

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
