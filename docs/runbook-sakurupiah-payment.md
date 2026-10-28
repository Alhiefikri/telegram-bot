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

## Store Balance partial status evidence (#665)

The [official status example](https://sakurupiah.id/developers/api-dokumentasi)
contains only `data[0].status`; absent merchant reference and amounts are normal
for that documented shape. They are not proof of an identity conflict, nor
authorization to credit. The existing [sanitized sandbox observations](agents/sakurupiah-api-research.md#2026-10-13--fee-payer-and-reported-amount-semantics-live-sandbox-qrisc)
show history `amount` as the final payable total in buyer-fee mode. This is
contract evidence, not a new production qualification.

For an unresolved, nonterminal top-up, reconciliation first rejects every
explicit conflicting status field. Successful but insufficient status triggers
one authenticated history lookup through the existing bounded client, outside
money transactions, with `mechant=1`, the stored `trx_id` and `merchant_ref`.
Exactly one returned row must independently match both references, the original
channel snapshot, successful status and the exact recorded invoice total.
Explicit currency, fee and total conflicts also fail closed. The request-ID
fallback is correlation only; it never counts as a returned transaction ID.
History filters alone are not binding evidence.

Only the original requested top-up amount reaches the existing settlement
boundary and global receipt claim. An invoice with credit 250000, fee 2100 and
payable total 252100 credits 250000, never 252100. This change does not alter
receipt namespaces, source claims, compensation reservations or money locks.

`reconciliation_evidence` events retain the source (`status` or `history`) and
`missing_fields` / `conflicting_fields` names, without copying Customer data,
credentials or QR values into diagnostics. Review reasons distinguish
`provider_evidence_missing`, `provider_evidence_ambiguous`,
`provider_evidence_unusable` and `provider_identity_mismatch`. A provider lookup
failure leaves the existing target unresolved and eligible for a later check;
an empty/ambiguous/conflicting authoritative result enters Owner review.

ADR-0011 policy is unchanged: `invalid_provider_result` and `expired` are not
automatically reopened. Resolve ambiguous money only through the existing
Owner compensation boundary after verifying attribution. Never reset a status,
edit a historical invoice, or replay credit to force recovery. `paid` and
`compensated` remain closed, including when compensation commits while a status
lookup is in flight. Incident top-up #71 is already compensated and must not
receive another credit.

Regression entry points: `SakurupiahTopUpChannelTest`,
`Telegram/StoreBalanceTopUpSakurupiahRailTest`,
`Unit/Services/Sakurupiah/SakurupiahDtoTest`, and the
`sakurupiahResolutionRaces` cases in `StoreBalancePostgresConcurrencyTest`.
Framework reference checked through Context7: Laravel 13
[transactions and retries](https://github.com/laravel/docs/blob/13.x/database.md)
and [pessimistic locking](https://github.com/laravel/docs/blob/13.x/queries.md).

Local verification on 2026-10-28: the six focused SQLite suites passed
103 tests / 546 assertions. On clean disposable PostgreSQL 16 databases,
`StoreBalancePostgresConcurrencyTest` passed 23 / 337 and the two
`ExternalMoneyReceipt*PostgresConcurrencyTest` suites passed 8 / 65. These
include four-worker history/settlement and Owner-compensation races; provider
lookup asserts transaction depth zero. Targeted Pint and PHP LSP diagnostics
passed.

A standalone application bootstrap ran the actual
`sakurupiah:reconcile-balance-top-ups` command against sanitized HTTP fixtures.
The bound-history case produced one 250000 credit/projection from a 252100
invoice; status-only with empty history produced zero entries and
`provider_evidence_missing`. Both full-history ledger inspections were
consistent. A second command pass issued zero provider requests and changed
neither result. This is local fixture evidence, not live provider acceptance,
CI, deployment, or authorization to alter incident #71.

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
another channel. Provider invoices use 12-hour expiry for `QRISMU` and the
qualified 24-hour policy for the other supported channels. Readiness checks
channel metadata against that provider policy. Fee payer remains customer-borne;
the actual fee and total come from the provider invoice, not a static tariff.

Store checkout has a separate **15-minute local deadline**, measured from Order
creation, for every Sakurupiah channel. `Order.expires_at` and `Payment.expired_at`
bound checkout and stock reservation; `Payment.provider_expires_at` preserves the
provider deadline. A shorter provider deadline may tighten the local deadline,
but a longer provider deadline never extends it. Customer instructions use the
effective local deadline. The minute-based `orders:expire-pending` sweep releases
due reservations through `OrderLifecycle`, without cancelling or waiting for the
provider QRIS. Verified money arriving after local expiry enters Late Payment
manual review and never automatically re-reserves stock or fulfills the Order.

The `2026_10_27_000002` migration caps existing unpaid pending Sakurupiah Orders
at `created_at + 15 minutes`, retaining earlier deadlines and provider history.
It also tightens the encrypted presentation's effective deadline. The normal
expiry sweep, not the migration, releases stock. Paid/terminal Orders, other
providers and Store Balance top-ups are untouched. Rollback does not extend
deadlines or re-reserve stock.


## Checkout throttle recovery (#677)

The confirmed defect was a second, independent 60-second negative-readiness
cache window. A provider throttle at t=0 followed by the first readiness read
at t=50 denied checkout at t=60 even though merchant cooldown had ended. The
permanent regression was observed failing before the fix and now requires
recovery exactly at t=60, including the t=59 and t=61 boundaries.

### Recovery and diagnosis

- `rate_limit_cooldown`: no checkout invoice was requested; show the absolute
  merchant retry deadline. `Retry-After` can extend the conservative local
  60-second policy. This policy is not a published provider rate limit.
- `lookup_temporarily_unavailable`: no checkout invoice was requested; retain
  the purchase and retry metadata after five seconds, not the success-cache TTL.
- `observation_refresh_busy`: another process owns the bounded refresh lease;
  return a temporary retry instead of duplicating metadata HTTP.
- `cache_unavailable`: do not dispatch without coordination or bypass payment
  validation. Preserve the durable Cart and ask the Customer to retry from it
  after recovery; do not claim that a retry token was saved.
- `configuration_invalid`, `channel_inactive`, `channel_invalid`: report an
  operator/configuration problem, not a throttle. Malformed supported QRIS rows
  and unidentified rows fail closed. A malformed, identifiable non-QRIS method
  cannot invalidate otherwise valid QRIS metadata.
- `snapshot_changed`: reject changed settings/channel/pricing/selection and
  an observation that expired before the money transaction. Review the purchase
  rather than silently switching its payment channel.

Temporary Telegram recovery retains the original checkout key, Cart quantity
and selection. The retry callback is Customer/chat/message-bound and revalidates
the original price/settings snapshot. Repeated original or retry callbacks do
not add the quantity again. The cache-backed retry expires after 15 minutes;
loss of Redis does not delete the Cart or an existing Order/Payment.

Pre-Order recovery never creates an invoice itself. Post-Order recovery remains
owned by the existing invoice lifecycle: a known pre-dispatch failure may retry,
a definitive provider rejection reuses its merchant reference, and an unknown
create outcome uses status/history without blind second create. Local checkout
expiry, stock/money guards and Late Payment review remain unchanged.

`telegram.checkout.payment_rejected` records an HMAC intent correlation,
allowlisted reason/channel, observation age, retry time and `order_created=false`.
`sakurupiah.request_deferred` records `dispatched=false`; actual request-attempt
events carry `dispatched=true`. Correlate these with operation/caller and merchant
fingerprint, not customer identifiers, QR payloads, full references or credentials.
Do not infer that an invoice exists from an ACK or a sent retry notice.

### Background traffic and capacity decision

The merchant/environment request gate permits one provider HTTP dispatch at a
time, independent of the per-Payment lifecycle lock. Foreground requests wait
at most one second; reconciliation yields immediately on contention and retains
its existing bounded active/released lanes and later retry. The gate is not an
invented requests-per-second quota. Payment verification and late-money discovery
are not disabled. Sustained provider throttling still requires provider support;
no local gate can promise provider acceptance.

Retain the current worker counts, PHP-FPM and Docker limits. The incident
snapshots in #677/#672 show pressure, but do not establish safe extra concurrency.
The disposable single-worker probe below measures application paths, not the
production dataset, real provider latency or the memory of all resident workers.
Increasing workers could consume more memory while every request still shares
the merchant throttle. There is no evidence here authorizing that change or an
OOM-protection rollback.

### Verification entry points and limits

Run the focused `Sakurupiah*`, `ProductDetailPurchaseTest`, `CartCheckoutTest` and
`CheckoutPaymentSnapshotCharacterizationTest` suites. New behavior coverage is in
`SakurupiahRequestBackpressureTest`, `SakurupiahProviderReadinessTest` and
`Telegram/SakurupiahCheckoutRecoveryTest`. Existing lifecycle/reconciliation
coverage retains definitive rejection, unknown create, supported-channel expiry,
amount limits, late settlement, active/released fairness and QR-delivery ambiguity.

`SakurupiahCheckoutRecoveryPostgresConcurrencyTest` requires isolated PostgreSQL
and an explicitly opted-in disposable Redis at `127.0.0.1:56778`
(`REDIS_PORT=56778`, cache/lock DB 7). It uses process races for cold/expired
single-flight refresh, two Customers plus a duplicate checkout, merchant
cooldown monotonicity, and background yielding/resumption. It is included in
`phpunit.postgres.xml`; without the Redis opt-in this lane skips, not silently
falls back to an in-memory cache. Never point this fixture at a shared Redis.

The standalone disposable smoke boots the actual HTTP kernel, submits `/start`,
Product Detail, custom quantity `10` and QRIS callbacks, drains actual Redis
queue work, and exercises the real provider adapter, lifecycle, QR renderer and
Telegram transport with synthetic HTTP responses. It checks temporary notices
before any Order/Payment/create, both Customers' recovery, duplicate delivery,
and unknown-create recovery without another create. PostgreSQL/Redis concurrency
is separately exercised by the process tests; the smoke is a single queue worker,
not a concurrent production benchmark. Telegram-native client rendering is not
visually qualified by these HTTP fixtures.

Local verification on 2026-10-28: the final focused SQLite lane passed
448 tests / 3,483 assertions. The disposable PostgreSQL 16 / Redis 7 recovery
and existing invoice-lifecycle concurrency lanes passed 7 / 62. Targeted Pint
completed; PHP language-server diagnostics for readiness, client and Telegram
checkout were clear.

The final smoke used one actual queue worker inside an application container
limited to 1.5 CPUs / 896 MiB, with fresh PostgreSQL and Redis and no live HTTP.
Before recovery it left zero Orders, Payments and create requests for both
Customers. Recovery created exactly two of each and delivered two QR PNGs,
reserving 11 units. After a Redis cache flush, duplicate callbacks changed
none of those counts. A third Customer's dispatched timeout retained
`outcome_unknown` without another create. A fourth Customer's definitive
rejection recovered the same reference, producing the third QR: four Orders /
Payments and five create attempts total, with no failed jobs or remaining
interaction queue.

| Disposable measurement | Observed value |
| --- | ---: |
| Webhook / queue-wait samples | 20 |
| Webhook ACK p50 (ms) | 5.18 |
| Webhook ACK p95 (ms) | 6.27 |
| Queue wait p95, monotonic enqueue-to-pickup (ms) | 106.83 |
| Queue-drain invocation p95 (ms) | 284.94 |
| PHP allocator peak (MiB) | 40 |
| Process peak RSS, including bootstrap/migrations (KiB) | 136200 |

The provider clock was advanced for cooldown boundaries; latency measurements
used monotonic wall time, not the frozen provider clock. This small synthetic
sample cannot establish a production SLO, safe additional workers, aggregate
resident memory, or a numeric provider quota.

Adjacent-gap disposition:

- Fixed and covered: negative-cache extension, cold/expired refresh stampede,
  stale preflight at transaction entry, retry quantity duplication/ownership,
  changed channel/settings/price/selection, and Redis numeric-string cooldown
  decoding. PostgreSQL/Redis proved the shared-lock and monotonic-deadline lane.
- Fail closed: cache-read/coordination outages, malformed/unidentified or
  supported-QRIS metadata, inactive channel, mismatched requested expiry,
  amount/stock conflicts, and unknown create. Cache outage tests inject failures;
  the runtime smoke separately proves real Redis-cache-loss replay safety.
- Retained and exercised: original 15-minute local expiry versus the qualified
  channel's `default_expired`, provider invoice identity/money checks,
  definitive same-reference recovery and existing late-payment reconciliation.
- Not established: which historical customer attempt hit which guard, the
  provider's global/per-endpoint request quota, sustained-load fairness or
  production latency after deployment. Redis loss can erase a local cooldown;
  bounded request coordination and provider rejection still apply after recovery.
  Escalate with sanitized request evidence rather than increasing retries.

Framework decisions were checked through Context7 against Laravel 13
[atomic cache locks](https://github.com/laravel/docs/blob/13.x/cache.md),
[transaction retries](https://github.com/laravel/docs/blob/13.x/database.md) and
[pessimistic locking](https://github.com/laravel/docs/blob/13.x/queries.md).
The [official Sakurupiah documentation](https://sakurupiah.id/developers/api-dokumentasi)
documents application `400` plus a failure message and channel/expiry metadata;
the inspected page supplies no numeric request-frequency contract. No production,
live transaction, CI/CD, restart, deployment or provider-support outcome is
claimed by this verification.


## Intermittent checkout rejection and delayed QRIS

The 2026-10-28 production audit found Outlook IMAP at Rp600 per unit with
sufficient supplier capacity. Ten units total Rp6,000, within the active QRIS
channel's Rp500–Rp2,000,000 range. A clean Telegram checkout using that snapshot
accepted ten units. A read-only production preflight instead encountered an
application-status `400` while loading channel metadata.

Telegram previously grouped payment readiness, QRIS amount limits, Cart state
and stock conflicts under the same invalid-cart message. `CheckoutPaymentUnavailable`
and `CheckoutPaymentAmountOutsideLimits` remain `InvalidCheckout` subtypes for
service and Web callers. Telegram reports unavailable payment, the observed QRIS
amount range, and insufficient stock separately. Rejections retain the Cart and
create no Order, Payment or supplier hold. A provider setting change during
preflight also rejects checkout rather than using a stale payment snapshot.

Sakurupiah readiness caches only scalar and array data. With
`cache.serializable_classes=false`, old report objects could be read back as
incomplete classes and trigger redundant metadata requests. Cache format v2
also carries typed failure reasons and absolute retry deadlines. Old or malformed
entries require a fresh, single-flight observation; classes remain disallowed.
Successful and authoritative invalid-channel observations retain the configured
TTL. Transient lookup failures use a short recovery deadline; merchant cooldown
reports expire at the actual cooldown boundary, never a new full observation TTL.

The OOM-related deployment preceded the reported failures, but the available
incident evidence does not identify which guard rejected either Outlook attempt.
The Redis defect predates that deployment and can increase metadata traffic; it
does not prove the cause or establish the provider's request-frequency limit.
Git blob comparison from `c5bdfcda` through `bd11ea91`, `4f645b4d` and
`1a46d9e7` found identical Cart, direct Telegram checkout, Order checkout,
supplier sync and Sakurupiah preparation/readiness source files. Scheduler
launcher and registrations changed in that range; identical checkout source
alone cannot rule out an indirect scheduling or provider-pressure effect.
The Product Detail payment action also retains other items in an existing Cart.
An unavailable old item can therefore reject the whole Cart even when the
selected Product has stock.

The prior read-only audit recorded the two QRIS updates created at 08:33:40 and
08:34:57 WIB, and processed at 08:33:41 and 08:34:59 respectively. Those
one-second and two-second end-to-end intervals include queueing and processing;
they do not separately measure queue wait or identify the rejecting guard.
Read-only scheduler logs for 08:32–08:35 WIB showed completed cycles of
33, 38, 32 and 33 seconds. `suppliers:sync` exited zero at 08:32:05, 08:33:13,
08:34:04 and 08:35:04. The aggregate results do not prove the Outlook supplier
snapshot was fresh at either tap. The application log volume was not readable
by the unprivileged audit account, and an authorized `sudo -n` read could not
authenticate.

Inspect both sides of an Order's history before attributing delay to workers:
`PaymentEvent.received_count` and `last_received_at` include repeated identical
provider rejections that share one event. The audited delayed invoice received
ten rejected create responses before a later create succeeded. A checkout effect
marked `sent` can also represent a retry notice; its delivery outcome, not the
effect's declared `sendPhoto` method, establishes whether a QR was sent.

`sakurupiah:reconcile` processes active Payments before released Payments, with
independent `(created_at,id)` cursors and at most `--chunk` candidates per lane.
The active lane gets half the remaining execution window; the released lane
can use the rest, including unused active time. The total execution budget stays
45 seconds by default. A Payment transitioning between lanes is not checked twice
in one sweep. A provider outage in either lane does not consume the other's
entire I/O window.

Ordinary terminal lookback uses `Payment.expired_at`, or `created_at` for legacy
rows without a deadline, rather than mutable `updated_at`. Paid Payments and
definitively rejected terminal creates do not occupy recovery work. Uncertain
`outcome_unknown` and `recovery_unconfirmed` Payments retain discovery beyond
the ordinary lookback; verified late money still enters Manual Fulfillment
Review without fulfillment. Retry intervals, provider limits, stock and
supplier-wallet guards, the local 15-minute deadline and the ban on repeating
ambiguous creates are unchanged.

Regression entry points: the Outlook scenario matrix in
`Telegram/ProductDetailPurchaseTest`, `SakurupiahReconciliationTest`, and the
existing invoice lifecycle and Telegram checkout suites. The standalone local
smoke used the actual checkout, provider adapter, recovery command, QR renderer
and Telegram transport with HTTP fixtures: metadata rejection without a hold,
Outlook ten-unit checkout, duplicate replay, rejected-create recovery, one PNG
delivery and fresh 10/10/25-unit Orders reserving 45 units. It was not a live
payment or supplier purchase.

Additional local verification used disposable Redis and SQLite with the actual
Telegram webhook, synchronous jobs, Sakurupiah adapter and Telegram transport
against HTTP fixtures. A metadata rejection left no Order, Payment or hold; after
recovery, ten supplier units produced a Rp6,000 Order, Payment and QRIS PNG.
Duplicate tap retained one Order/hold, and a new purchase created one more.
The cache probe made two metadata reads before the serialization fix and one
after it; a new PHP process reused the same observation without a lookup. No
live provider invoice or supplier purchase was made.

The [official API documentation](https://sakurupiah.id/developers/api-dokumentasi)
publishes channel limits and application-status `400` failures, but no numeric
request-frequency contract was found during this audit. Do not invent a
merchant-wide request rate or bypass readiness to make a rejected checkout pass.
Escalate repeated “Permintaan terlalu cepat” responses to provider support with
sanitized evidence.

## Issue 661: post-Order recovery evidence

Local reproduction used the original single-cursor command from `HEAD` with
twelve older terminal Payments, an active explicitly rejected create, a
two-candidate page and a one-second I/O budget. Two sweeps produced no recovery
effect. The same scenario with the active/released candidate split recovered
the original merchant reference and delivered one PNG through the actual
Telegram transport with HTTP fixtures. Replay made no second create or QR send.
The initial notice and recovered QR now record `retry_notice_sent` and
`qris_sent` respectively in `outcome_metadata`; declared `telegram_method`
still does not establish the actual delivery.

The independent SQLite smoke bootstrapped the application outside PHPUnit,
explicitly selected the real `TelegramBotClient` instead of the testing runtime
fake, and invoked the actual recovery command twice. It observed one rejected
create, one same-reference successful create, one PNG multipart delivery and an
unchanged local deadline. No live provider or Telegram request was made.

Final local verification: 195 tests / 1,268 assertions passed across the
Sakurupiah reconciliation, gateway, lifecycle, webhook, operations, Admin,
Telegram checkout/top-up, shared KlikQRIS checkout and six scheduler suites.
Targeted Pint completed successfully. These are SQLite/process/HTTP-fixture
results, not production acceptance, PostgreSQL concurrency proof or evidence
that Sakurupiah's external rejection has stopped.

### Request pressure attribution

`SakurupiahClient` emits `sakurupiah.request_attempt` once per attempted HTTP
dispatch, including retries. Group by `environment`, `merchant_fingerprint`,
`caller` and `operation` (`createInvoice`, `listChannels`, `transactionStatus`,
`transactionHistory`; balance reads are also included). `request_id` correlates
retries; `attempt`, `max_attempts`, `started_at`, `finished_at` and `duration_ms`
show attempt count and overlapping intervals. The merchant fingerprint is
HMAC-derived with the application key, not a merchant identifier; key rotation
changes it, and an absent key yields null. Caller labels are a closed,
argument-free bounded stack classification; `unknown` is retained when no known
entry point is visible. `reconciliation_command` does not distinguish a
scheduled invocation from an operator's CLI invocation.

Only the exact observed “Permintaan terlalu cepat silahkan coba lagi nanti.”
failure is classified `application_rate_rejected`. Other application `400`
responses remain `application_rejected_unknown`; HTTP `429`, HTTP/provider
outage statuses, not-found statuses, malformed envelopes, transport ambiguity
and exhausted local budgets have separate outcomes. No provider body, reason
text, credentials, signatures, QR, URL, customer data or full transaction
identifier is logged. `application_accepted` means only an accepted envelope,
not validated QR/payment evidence. A not-found category never authorizes a
second create.

Measure request overlap from these attempt intervals, not elapsed Order age.
Measure Payment lock contention from sweep `busy`, queue wait from existing
worker/scheduler timing, and local I/O exhaustion from `stop_reason`; none is a
provider rejection. These new events cannot reconstruct missing historical
per-caller traffic. The incident's ten deduplicated explicit create rejections
prove repeated provider responses, while the 4,160 status-lookup failures do
not establish their individual causes or merchant-wide request rate.

### Merchant backpressure after an explicit throttle

A read-only production log inspection on 2026-10-28 found 19 accepted
`transactionStatus` attempts and 15 rate-rejected attempts during 11:29 WIB.
Checkout `createInvoice` attempts at 11:29:45 and 11:29:53 were also rate-rejected.
The reconciliation loop continued dispatching after the first explicit throttle.
These observations establish continued request pressure and contemporaneous
checkout rejection, not the provider's numeric quota or an exact per-invoice
correlation. The screenshot's two-product Order had already been created;
the create request sends one aggregate amount, not one request per Product.

`SakurupiahRequestBackpressure` now shares a scalar cooldown through the normal
cache, keyed by environment, API endpoint and hashed merchant API ID. HTTP `429`
or the observed rate-rejection message with application status `400`/`429`
starts a 60-second local recovery pause. A longer valid `Retry-After` extends
the pause. This is application backoff policy, not an advertised provider limit.
Generic application `400`, malformed responses and upstream `5xx` do not start
this cooldown. Their existing create rejection/ambiguity rules remain unchanged.

During the pause every client instance checks before dispatch, including
checkout, readiness, webhook verification and both reconciliation commands.
Lookups report `rate_limit_cooldown`, without claiming a provider response;
an unsent create uses the existing pre-request failure path, retaining its
same-reference recovery. Cache read failures defer before dispatch too.
The original throttled HTTP response keeps its existing ambiguity/rejection
classification. In particular, an ambiguous create is still never repeated.
Cooldown skips emit no `request_attempt` because no HTTP attempt occurred.

Payment reconciliation stops both lanes on throttle, preserves unvisited work
and its rotating cursors, and exits nonzero with `stop_reason=provider_throttled`
and `carry_over=yes`. The top-up sweep also stops provider work during the pause.
Already-issued payable instructions, financial validation, stock holds and
local payment deadlines remain unchanged. Requests already in flight when a
throttle arrives cannot be recalled; this is not a fixed request-frequency cap.

Regression: the gateway cascade test failed before the change with
`Expected merchant cooldown.` Focused local verification passed 180 tests /
1,537 assertions. A standalone application smoke used a disposable SQLite DB,
shared file cache and HTTP fixtures with 8 Gemini and 10 Outlook units totaling
Rp106,000. A second PHP process observed the same cooldown without dispatch;
recovery after the pause used the same reference, sent one PNG, retained one
Order/Payment and 18 reserved units, and did not extend the deadline. These are
local proofs, not Redis concurrency qualification, live-provider acceptance or
a production rollout. The inspected production revision was `839d017d`; this
backpressure correction was not deployed during the investigation.

### Deployment comparison

Local Git diffs, without CI inspection, establish:

| Revision | Relevant change | Consequence |
| --- | --- | --- |
| `c5bdfcda` | Webhook attempts 4 → 6; backoff `[15,60,180]` → `[5,10,15,30,60]`; several maintenance/expiry commands backgrounded | Earlier and potentially more webhook verification attempts, not a changed create retry interval |
| `bd11ea91` | Launcher switched from `schedule:run` to the two-child `ops:schedule-run` pool; expiry gets priority slots | Bounds memory/concurrency; two occupied slots can delay provider polling |
| `4f645b4d` | Unreachable expiry-recipient handling and KlikQRIS diagnostics | No Sakurupiah client/lifecycle/command budget change |
| `1a46d9e7` | Cycle completion token distinguishes child failure from scheduler liveness | Changes heartbeat evidence, not provider frequency policy |

Across those revisions Sakurupiah command cadence remains every minute,
execution budget 45 seconds, lookup attempts three with 250/500 ms retry
delays, and rejected-create minimum interval 60 seconds per Payment. The
default two-minute scheduler overlap TTL is stale-lock protection, not a
mandatory two-minute cadence; normal completion releases it. A status lookup
plus amount-history lookup can attempt six requests before the shared deadline
truncates work. Scheduler cycle duration and occupied slots can stretch cadence.
These source mechanisms support possible pressure/delay effects, not a causal
claim that the OOM deployment triggered provider throttling. The user's
observation that failures began after that deployment remains unresolved by
local fixtures.

Provider support questions: Does the create rejection apply per merchant,
channel, endpoint or concurrent request? Which operations share its limit?
What retry/cooldown contract and safe status/history absence semantics apply?
Supply only incident times, channel, sanitized outcome counts and overlapping
attempt intervals, plus the private correlation hash if requested securely.
Do not invent a numeric throttle or claim the external rejection is fixed.

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

## Paid callback received but Order still pending

Compare the successful callback's `payment_events.received_at` with
`payments.paid_at` and the receipt effect's `sent_at`. Callback
`processed_at` can be later than settlement if a scheduled reconciliation
settled the Payment before the deferred webhook job ran again. Neither the
callback timestamp nor `paid_at` establishes when the buyer's bank debited them.

The 2026-10-27 investigation observed a successful callback at 21:05:03 WIB,
five `status_unavailable` observations, local settlement at 21:07:32, and
credential delivery at 21:07:42–43. A successful status lookup may omit amount,
so the same transaction's authoritative history must also supply matching
identity/channel/currency/amount before settlement. Never bypass that check.

`VerifySakurupiahWebhookPayment` now permits six worker attempts, with retry
delays of 5, 10, 15, 30 and 60 seconds after successive failures. These are
delays after each attempt, not an end-to-end latency guarantee; HTTP retries,
worker availability and payment locks still consume time. Exhausted jobs do
not authorize payment or disable the existing scheduled reconciliation.

Failed status/history calls emit `Sakurupiah verification lookup failed.`
with only `payment_id`, `operation`, `exception_class`, `reason`, `http_status`
and `provider_status`. Inspect `transactionStatus` versus `transactionHistory`
to distinguish the failing lookup. Exception messages/chains, URLs, provider
bodies, credentials and customer data are intentionally excluded. An
`unexpected` reason names an unclassified exception, not proof of provider
fault; HTTP/schema/transport errors must be interpreted using their own evidence.

The scheduler runs `orders:expire-pending`, `store-balance:expire-top-ups`,
`klikqris:backfill-in-flight` and `ops:payment-lifecycle-rollout-report` in
background processes with their existing overlap mutexes. Their execution time
must not serialize the minute-based provider recovery loop. A foreground
heartbeat is not proof that these child commands completed successfully.

Local verification used a disposable SQLite database and the actual database
queue worker/client parsers with synthetic HTTP responses: four failed status
lookups followed by successful status and matching amount-bearing history
settled once at simulated second 30 and queued one receipt. A separate actual
`schedule:run` smoke launched three active maintenance commands as delayed
fixtures and reached the next scheduled task before they finished. These
checks are not live-provider, production-load or deployment qualification.


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
`H+1`. That historical run aligned the local expiry contract to 24 hours;
current checkout instead uses the separate 15-minute local deadline above.
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
