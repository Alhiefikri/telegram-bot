# Runbook: Sakurupiah QRIS Payment Provider

This runbook covers activation, observation, recovery, and rollback of the
Sakurupiah QRIS Payment rail.

Production enablement is intentionally simple: when both
`SAKURUPIAH_PRODUCTION_API_ID` and `SAKURUPIAH_PRODUCTION_API_KEY` are
non-empty, the application marks Sakurupiah production as enabled. No separate
enable flag or contract-policy record is required. The Owner still selects
Sakurupiah for new checkout through the Admin Payment Provider Switch after the
readiness check passes.

Automated release evidence is recorded in
[`docs/sakurupiah-release-evidence.md`](sakurupiah-release-evidence.md). This
runbook and the evidence document never contain production credentials.

## Owner-selected QRIS channel

After the channel-selection migration, the default for new Sakurupiah checkouts
and Store Balance top-ups is `QRIS`. The Owner can change it in **Admin →
Pengaturan → Pembayaran → Channel QRIS Sakurupiah**. Supported choices are
`QRIS`, `QRISC`, `QRISMU`, and `QRIS2`; only unique, active channels with a
supported contract returned by the merchant API can be saved. The Dashboard
shows provider limits, fees, additional fees, and settlement metadata.
The payment settings page shares one bounded channel lookup between its metadata
list and readiness evaluation, so a provider outage cannot double the HTTP retry
budget. Other Dashboard sections do not load this channel selector.

The setting is `manual_payment_settings.sakurupiah_channel`, not ENV. Saving
requires the current `payment_provider_version`, increments it only on change,
and records an audit in the same transaction. Only the Owner can save it.
Channel availability is not a promise that the provider can create an invoice.

Each new Payment/top-up snapshots `sakurupiah_channel` before create. Retries,
status/history verification, callbacks, and presentation use that snapshot,
never the current Dashboard choice. The migration preserves observed Payment
channel codes; previously unobserved Payments and historical top-ups remain
`QRISC`. Old top-ups' generic `provider_payment_method=QRIS` does not mean their
provider channel was QRIS. Changing the selection never retries or reopens
terminal Orders or re-targets an existing invoice.

There is no automatic channel fallback, including after timeout or an ambiguous
create. The same reference must be reconciled instead of creating an invoice on
another channel. `QRISMU` uses 12-hour expiry; the other supported channels use
the qualified 24-hour policy. Readiness requires the exact selected channel and
checks its metadata against that policy. Fee payer remains customer-borne; the
actual fee and total come from the provider invoice, not a static tariff.


## Channel lookup failure with an active QRISC channel

The 2026-10-21 production investigation found HTTP 200 from
`list-payment.php` and an active QRISC channel, while readiness returned
`SAKURUPIAH_QRIS_CHANNEL_LOOKUP`. The DANA channel advertised
`addition.default_expired = "0.5"` hours. Parsing that duration as an integer
rejected the entire channel list, including the valid QRISC channel.

Channel expiry metadata accepts positive fractional hours; whole hours retain
their integer representation for QRISC contract comparison. Monetary amounts
remain whole-IDR values and are not parsed through this duration parser.
Regression coverage: `SakurupiahGatewayTest` exercises the mixed DANA/QRISC list
through readiness, and `SakurupiahDtoTest` covers fractional and invalid durations.

For this failure, deploy the parser correction through the normal release flow;
clearing cache or changing credentials does not repair the old parser. Recheck
`php artisan sakurupiah:check-readiness --json` and the Admin provider availability
after release. Keep the Owner's provider selection unchanged during diagnosis.
A one-off PHP smoke using the corrected parser against the production API
returned `ready=true`; that smoke alone does not update the running application.

## Deployment-time configuration

Only the separate merchant credential pairs belong in the server-side `.env`.
Stable endpoints, callback/return routes, host policy, transport budgets, and
QRIS settlement defaults are pinned in application configuration. Never put
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
application routes pinned by the release. When both production credentials are
present at application startup, `services.sakurupiah.production_enabled` is
`true`; when either credential is missing, it is `false` and a partial pair
fails configuration validation. There is no separate
`SAKURUPIAH_PRODUCTION_ENABLED` environment variable.

This repository has two environment-file consumers: Docker Compose reads the
root `.env`, while Laravel Artisan uses `src` as its application base and
reads `src/.env`. Use the root file through a Compose command, or configure the
same credential pair in the untracked `src/.env`; never copy credentials into
tracked files or commit either file.

Do not add ENV for channel selection, labels, fees, expiry hours, callback URLs,
host allowlists, retry budgets, or presentation copy. Channel selection belongs
in the database/Admin; stable safety policy remains in code. The provider spelling `api-sanbox` is intentional and must not be
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


## Production activation

After adding the complete production credential pair to the root `.env`,
recreate the app and scheduler containers so Laravel loads the new environment.
No image rebuild is needed for a credential-only change. Run the readiness
check, then select **Sakurupiah** in **Admin → Mode pembayaran**. The switch
changes new checkout only; existing Payments keep their stored provider.

The Owner can switch back to **KlikQRIS** from Manual or Sakurupiah in
**Admin → Pengaturan → Pembayaran & toko** when KlikQRIS readiness passes.
Manual, KlikQRIS and Sakurupiah remain visible even when an automatic provider
is unready; its readiness card shows the reason and its activation button is
disabled until configuration is ready again. Temporary provider trouble is not
provider retirement. Switching does not delete credentials or change the provider
of existing Payments.

If the pair is absent, production is disabled automatically. A partial pair is
invalid and must be corrected before startup.

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
activity/limits, fee/currency/expiry contract, and queue/scheduler. A disabled
provider is a safe state; an enabled but unready provider is a release failure.
`sakurupiah:check-readiness` reports the bounded local backlog counts
(`pending`, `verification_pending`, `terminal_recent`) without calling the
provider.

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

## QRIS fee policy

The QRIS channel fee is charged to the buyer. `services.sakurupiah.qris_contract`
pins the policy in code (`fee_payer = customer`, `merchant_fee = 2`); there is no
ENV switch, because a fee policy is a reviewed release decision, not a
deployment secret. The provider documents both modes:

| Contract | Create request | Customer pays | Merchant settles |
| --- | --- | --- | --- |
| `fee_payer = customer` (current) | `merchant_fee = 2` | `amount + fee` | the full order amount |
| `fee_payer = merchant` | `merchant_fee = 1` | the order amount | `amount - fee` |

Live sandbox confirmation of the current contract: order amount `20000` → fee
`240` → payable total `20240`, echoed `merchant_fee = "Pelanggan"`, and the
provider's transaction history reports `amount = 20240` (the final total).

Consequences that the release enforces:

- The configured `fee_payer` and `merchant_fee` are one decision. The readiness
  check, the production configuration validator, and the create request all read
  the same rule, and a mismatched pair fails the create before any provider
  request instead of sending an ambiguous invoice.
- The create response's echoed payer must equal the configured payer. A
  mismatch is an invoice integrity failure, so the Payment stays pending instead
  of presenting a total the store did not authorize.
- Amount evidence accepts the base order amount **and** the recorded final
  total, because the provider reports the base amount in the create response and
  the final total in transaction history. An amount that is neither keeps the
  Payment unverified.
- The payer is snapshotted with the payment presentation
  (`customer_pays_fee`), so the customer copy keeps telling the truth for
  Payments created under an earlier policy.
- Store Balance top-ups follow the same contract: the customer pays credit plus
  fee, and the ledger still credits only the requested amount.

To switch back to merchant-borne fees, change both `fee_payer` and
`merchant_fee` together in `services.sakurupiah.qris_contract`, run the focused
Sakurupiah suite, and redeploy. Existing Payments keep their snapshotted payer;
the switch affects new creates only.

## Create rejection and QR recovery

The provider documents its own failure contract: an application status plus a
reason in `message`. Either shape carries that contract — the HTTP-200 envelope
with a non-200 `status`, or a non-retryable HTTP failure whose body is the same
JSON. When a create is answered that way, the provider decided: no transaction
exists for the merchant reference yet.

- The Payment is persisted as `create_failed` and stays payable/recoverable. It
  is not downgraded to `outcome_unknown`, because that state must never be
  re-created.
- `provider_create_last_error` records the sanitized cause
  (`…|application_status:<status>|provider_message:<reason>`). The reason is
  bounded to 200 characters, whitespace-normalized, and has the merchant
  reference and transaction ID redacted, so it never stores a credential or an
  identifier.
- The same merchant reference is created again when the condition is corrected,
  at most once per `services.sakurupiah.create_retry_min_interval_seconds`
  (default 60 seconds). The customer's **Check Payment**, the storefront invoice
  refresh, and the scheduled sweep all retry it, so a corrected
  merchant/channel condition does not need a new checkout and needs no operator
  step. The admin manual reconcile stays limited to Payments that already have a
  provider reference.
- A sweep that turns an unpresentable Payment payable also queues the QR
  instruction effect for the customer; the delivery effect key is per Payment,
  so repeated sweeps cannot send it twice.
- An ambiguous outcome stays `outcome_unknown`/`recovery_unconfirmed` and is
  never re-created. It only performs status lookups until it becomes observable.

Diagnose a rejected create from the Admin Order detail (`Create diagnostic`) or
from the sanitized payment row, then correct the merchant/channel condition:

```bash
cd src
php artisan sakurupiah:check-readiness --json   # channel/contract/readiness
php artisan sakurupiah:reconcile --chunk=100     # retry + observe
```

Observed on 2026-10-26: `QRISC` creates returned application status `400`,
`Gagal membuat Invoice. hubungi tim dukungan`. An independently signed native
PHP cURL request reproduced the same rejection over HTTP 200. A later
URL-encoded `QRIS` create for the same Rp10,000 base amount succeeded with
fee Rp420 and total Rp10,420. No payment was made. This supports a
channel/merchant-specific incident, not proof of its internal cause: the
attempts occurred at different times. Provider metadata still marked QRISC
active, so list/readiness success alone did not establish create health.

## Reconciliation

`sakurupiah:reconcile` scans only Sakurupiah QRIS IDR Payments in bounded
eligible states, ordered deterministically by `(created_at, id)`, with a fair
persisted cursor, a hard execution deadline, eager relation loading, and a
per-Payment single-flight lock. It holds no database transaction across HTTP and
reports only aggregate counts and redacted identifiers:

```bash
php artisan sakurupiah:reconcile --chunk=100
```

Status lookup is the normal action. The one create-shaped exception is a
**definitively rejected create** (`provider_create_state=create_failed` without a
provider reference): the provider proved that no transaction exists for that
merchant reference, so the sweep re-creates the invoice on the same reference,
at most once per `services.sakurupiah.create_retry_min_interval_seconds`
(default 60). An ambiguous create outcome is never re-created — it is only
re-observed.

Expected summary counters:

- `paid` — authoritative `berhasil` with amount evidence applied through
  `OrderLifecycle`.
- `expired` — authoritative `expired` applied through `OrderLifecycle`.
- `pending` — provider still reports pending; no lifecycle change.
- `verification_pending` — no amount-bearing evidence yet (or provider outage);
  the Order stays unchanged and the next sweep retries.
- `invalid` — identity/amount mismatch recorded; no lifecycle change.
- `busy` — the Payment lock was held by create/callback/refresh work.
- `recovered` — this sweep turned an unpresentable Payment payable and queued its
  QR delivery effect (or reused the persisted presentation).
- `failed` — an unexpected error; inspect the sanitized log, never raw payloads.
- `budget_exhausted=1` — the execution deadline stopped the page early; the
  persisted cursor resumes the next bounded sweep.

Command restart and duplicate scheduler execution are idempotent: a Payment
that already advanced is not re-queried or re-applied.

## Store Balance top-ups on the Sakurupiah rail

Store Balance top-ups follow the same store-wide provider selection as checkout.
Funding confirmation snapshots the selected Sakurupiah channel together with
the payment setting identity and version; switching provider or channel later
never re-targets an existing top-up. Safe EMV payloads or allowlisted provider
QR image URLs are stored encrypted, and images use the existing bounded
downloader rather than encoding a URL as QR text. Settlement always credits only
the exact requested amount through the shared Store Balance ledger
(`balance_top_up:<id>:credit`), never the provider fee. A Sakurupiah top-up that
is not operationally ready fails closed to `provider_unavailable` before any
provider request.

Top-up settlement currently converges through the scheduled reconciliation
command:

```bash
php artisan sakurupiah:reconcile-balance-top-ups --chunk=100 --max-top-ups=10
```

It runs every minute only when the Sakurupiah readiness scheduling gate is open —
the same `SakurupiahProviderReadiness::scheduleRegistrationEnabledFor()` gate that
registers `sakurupiah:reconcile`. It performs authoritative status lookups (never a
create request) under a per-top-up single-flight lock and reports only aggregate
counts and redacted identifiers.

There is currently no Sakurupiah **top-up webhook fast path**: unlike an Order
Payment, a paid Sakurupiah top-up is not advanced by a dedicated top-up callback,
so it settles on the next scheduled reconciliation sweep. Adding that fast path is
a follow-up; until then, a top-up that looks paid in the provider portal but is
still pending locally is expected to settle on the next sweep. Verify
`sakurupiah:check-readiness --json` and `schedule:list` before expecting top-up
reconciliation to run.

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

### Channel correction

The historical observations below exercised provider code `QRIS`. In September
the merchant qualified `QRISC` (`QRIS Custom Merchant`, minimum `200`, maximum
`20,000,000`, `0.7%`, additional fee `100`, settlement `H+1`). Those earlier QRIS
observations were not QRISC qualification evidence. The October channel
selection cutover supersedes the fixed-channel rule for new transactions only.
### QRISC-specific local rerun

On 2026-09-07, the isolated Compose project `telegram-bot-testing` was rebuilt
from the current source with `APP_ENV=local`. Telegram metadata confirmed the
dedicated testing bot `LhieStoreTestBot`; no production bot or production
endpoint was used.

The live sandbox observation selected `QRISC` and reported `minimum=200`,
`maximum=20,000,000`, active status, `default_expired=24`, and settlement
`H+1`. The local expiry contract was aligned to 24 hours before checkout.
A fresh amount-`200` app-linked checkout then returned provider method `QRISC`,
status `pending`, fee `0`, and total `200`; the total equation matched
`amount + fee`.

The persisted create row is sanitized by design, so it contains neither
`qr` nor `checkout_url`; those values are not stored in the audit payload. The
application therefore persisted no presentation and safely recorded
`provider_qris_unusable` / `provider_presentation_pending`. The app did not
send a QR to the testing bot. A separate non-payment test message through
`LhieStoreTestBot` succeeded; the first durable checkout-instruction attempt
ended `unknown` on a Telegram transport exception and was not blindly resent.

This established an API presentation-validation failure, not proof that the
provider portal cannot render a QR. Keep the Payment pending and request a
valid redacted QRISC sandbox fixture or an approved non-tokenized hosted
presentation from Sakurupiah.

### Provider QR presentation (current contract)

For the approved `QRISC` channel the provider returns `qr` as an HTTPS URL to a
provider-hosted QR image (verified `image/png`), not as an EMVCo payload. A
successful create is presented to the customer through exactly one of these
modes:

- **Direct QR** — `qr` is a valid EMV payload (terminal CRC and exact total
  verified) and is rendered locally.
- **QR image** — `qr` is an HTTPS URL on the configured environment allowlist;
  Telegram fetches it server-side (no redirects, bounded size, image signature
  required) and sends it as a photo, and the storefront serves it through the
  signed `…/qris` route. A policy-compliant `checkout_url` is kept alongside it
  and appears as the website button.
- **Hosted** — no usable QR; the allowlisted `checkout_url` is shown as the
  website button.

An unvalidated `qr` string is never rendered as a QR image. When no mode yields
a usable presentation the Payment stays pending with
`provider_create_state=provider_qris_unusable`, `provider_create_last_error=provider_presentation_unusable`,
and the safe `provider_presentation_pending` outcome; the customer receives
retry copy without QR, total, or provider data.

A provider create rejected at the application level is recorded as
`outcome_unknown` with `provider_create_last_error` naming the exception and the
provider application status (for example
`App\Services\Sakurupiah\SakurupiahApplicationException|application_status:400`).
The create is never repeated blindly; investigate the recorded status and the
merchant/provider configuration before retrying a new checkout.

### Raw sandbox create diagnostic

A subsequent fresh sandbox create probe at amount `200` captured only response
shapes before sanitization. The provider returned HTTP/application status `200`;
`data[0].qr` was a string of length `63` and `data[0].checkout_url` was an HTTPS
`sakurupiah.id` URL containing a query. `SakurupiahInvoice` parsed both fields,
so the application parser did not lose the QR. `QrisEmvPayload` rejected the QR
because it was missing the terminal CRC. The hosted URL was also rejected by
the safety policy because query-bearing provider URLs are tokenized
presentations. The provider portal Detail page may still render an image, but
that does not make the API QR payload valid for customer checkout.

The QRISC presentation gate remains unqualified. Production remains **NOT
ACTIVATED**.

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
Rate-limit, timeout, retry, expiry-code mapping, and callback behavior remain
operational observations to monitor. They do not create extra ENV activation
settings, and sandbox credentials remain separate from production credentials.

After that expiry observation, one Owner-authorized fresh sandbox invoice was
created at approximately 21:28 WIB for amount `500`. Its typed create response
was `pending` with total `500`, merchant-fee mode `1`, and both QR and hosted
presentation fields present; the provider omitted a separate `amount` field.
The Owner later changed the sandbox invoice to successful. Status lookup and
amount-bearing history both reported `berhasil` for QRIS and amount `500`;
status lookup still omitted amount fields. No provider callback was observed
by the application, and the invoice was not linked to a local Payment.
Do not record or share its QR, hosted URL/token, signature, or full identifiers.

After the direct-provider observation, a separate Owner-authorized app-linked
sandbox checkout was exercised with the dedicated testing bot and the minimum
amount. The first attempt stopped before provider HTTP because the disposable
test Customer had no phone number; after adding a test-only phone number, the
same local Payment was retried without creating a second Order or Payment.

The retried create persisted a provider reference, total `500`, fee `0`, and
`pending`, but no payable presentation. A matching direct sandbox create using
the app's request contract returned both QR and hosted fields; the QR failed
the application's EMV/CRC/exact-amount validation, while the hosted URL was
tokenized (query-bearing) and therefore rejected by the HTTPS/host allowlist
policy. The Payment remains pending with
`provider_create_state=provider_qris_unusable`,
`provider_create_last_error=provider_presentation_unusable`, and the safe
outcome `provider_presentation_pending`. No raw QR, hosted URL, or token was
persisted in the sanitized provider payload or sent to Telegram.

Status lookup for that same provider reference returned `pending` without
amount/total/fee fields; bounded history returned one matching pending row and
no successful amount evidence. This confirms the recovery path must keep the
Payment pending until a safe presentation or authoritative paid evidence is
available. A separate app-linked expiry path remains covered by the existing
historical sandbox evidence and lifecycle tests.

The storefront status action and Telegram `/order` detail path may retry the
same persisted provider reference when this presentation-pending state is
encountered. They never create another Order, Payment, or provider invoice.
When a later status response yields a validated presentation, Telegram
instructions are recorded through the durable outbound-effect ledger. An
ambiguous Telegram delivery remains manual-review/unknown; it is never blindly
resent.


### Evidence and secret handling

Keep provider evidence redacted and outside the repository when it contains
merchant-specific data. Do not copy API keys, bearer tokens, full HMAC
signatures, raw QR values, raw payloads, or tokenized URLs into chat, logs,
tickets, or artifacts. Use the sanitized readiness, health, and reconciliation
reports for operational evidence.

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
2. For an emergency credential incident, revoke or rotate the production pair
   through the provider. To disable the provider through configuration, remove
   both production credential variables and recreate the app/scheduler
   containers. A complete pair enables production again at the next startup.
3. Verify `sakurupiah:check-readiness --json` reports the disabled state and
   `schedule:list` no longer shows `sakurupiah:reconcile`. An Admin provider
   switch alone intentionally leaves reconciliation available for existing
   Payments.

With Sakurupiah still enabled and ready, existing pending/verification-pending
Payments continue to reconcile after an active-provider switch because their
stored provider/reference state remains authoritative. If the credentials are
removed, restore a complete pair before expecting automatic provider lookups.
Do not delete credentials or drop columns while those rows exist.

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
