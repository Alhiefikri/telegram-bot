# Sakurupiah release evidence — issue #415

Evidence date: 2026-09-05 (UTC)

## Production activation status

**NOT ACTIVATED — this evidence document contains no production credentials.**

This document records repository and non-production verification evidence. It
is not a production authorization and it never contains API IDs or keys.
Production `services.sakurupiah.production_enabled` follows the complete
production credential pair at application startup. After readiness passes, an
Owner must select Sakurupiah for new checkout in the Admin Payment Provider
Switch.

## Current blocker resolution update

The earlier sandbox evidence in this document used provider code `QRIS`. The
merchant has since confirmed that the approved channel is `QRISC`; the
application now separates local rail `QRIS` from provider API method `QRISC`.
The earlier invalid-presentation result therefore does not establish that
`QRISC` itself returns an invalid presentation.

The checkout lifecycle now exposes a distinct `provider_presentation_pending`
safe outcome when a provider reference exists but QR/hosted presentation fails
local validation. The Payment stays pending; no raw QR, hosted URL, token,
signature, or provider payload is exposed to customer/Telegram surfaces.

The focused regression command run for this update was:

```bash
cd src && php artisan test tests/Feature/SakurupiahInvoiceLifecycleTest.php \
  tests/Feature/Storefront/SakurupiahStorefrontTest.php \
  tests/Unit/Services/Telegram/TelegramPaymentInstructionRendererTest.php
```

Result: **PASS — 47 tests, 416 assertions**.

Redacted sandbox evidence used the dedicated testing bot and the provider
minimum amount `500`. Readiness reported `enabled=true`, `ready=true`,
`scheduling_enabled=true`, and empty `missing`/`invalid` lists. A direct create
using the app request contract returned `pending`, total `500`, fee `0`,
merchant-fee mode `1`, and both presentation fields. The QR failed the
application EMV/CRC/exact-amount validator; the hosted URL was tokenized and
query-bearing, so the allowlist policy rejected it. The app-linked Payment
therefore remained pending with `provider_qris_unusable` and the new safe
presentation-pending signal. No production request or production bot action
was performed.

The same sandbox reference returned `pending` without amount/total/fee from
status lookup; bounded history returned one matching pending row with no
successful amount evidence. Reusing a sandbox merchant reference was rejected
by the provider with HTTP `400`, and the create path treated that response as
outcome-unknown rather than attempting another create.

The external qualification matrix remains open. The sanitized matrix
`artifacts/sakurupiah-issue-415/p0-gate-status.json` keeps all twelve gates
`qualified=false`: request encoding, signature canonicalization, channel
contract, expiry contract, create response, presentation, amount evidence,
duplicate reference, callback contract, error limits, production network, and
credential operations. This matrix remains unqualified. Because those
observations used the former `QRIS` provider code, a new QRISC-specific run is
required. The provider must supply a valid EMV QRIS payload for channel
`QRISC` or an approved non-tokenized hosted presentation before a new checkout
can become payable. Callback delivery/acknowledgement and Owner production
approval remain unobserved. Production remains **NOT ACTIVATED**.

## Latest QRISC-specific local rerun

On 2026-09-07, the isolated `telegram-bot-testing` Compose project was
rebuilt from the current source with `APP_ENV=local`. Telegram metadata
confirmed `LhieStoreTestBot`; no production bot or endpoint was used.

Sandbox readiness selected `QRISC` with minimum `200`, maximum `20,000,000`,
active status, and `default_expired=24`. The local QRIS expiry contract was
aligned to 24 hours. One fresh amount-`200` app-linked checkout returned
provider method `QRISC`, `pending`, fee `0`, and total `200`; the amount
equation matched exactly.

The persisted provider payload is sanitized by design, so it contains neither
`qr` nor `checkout_url`; this does not prove that the provider portal lacks a
QR. The application persisted no presentation and returned the safe
`provider_qris_unusable` / `provider_presentation_pending` state. The first
durable instruction effect ended `unknown` after a Telegram transport
exception; it was not automatically resent. A separate non-payment test
message through the testing bot succeeded.

A subsequent fresh sandbox create probe at amount `200` received HTTP/application
status `200`. Raw `data[0]` contained `qr` (string length `63`) and
`checkout_url` (HTTPS `sakurupiah.id` with a query). The application parser
recognized the QR, but EMV validation rejected it because the terminal CRC was
missing. The hosted URL failed the non-tokenized presentation policy because
it contained a query. The provider portal Detail page rendering a QR is
therefore a portal/API presentation discrepancy, not evidence that the app
should bypass its guardrail.

The QRISC presentation gate remains unqualified. Production remains **NOT
ACTIVATED**.

## 2026-09-17 — QR image presentation fix

A live sandbox probe of the approved `QRISC` channel clarified the provider
presentation contract: the create response `qr` field is an HTTPS URL to a
provider-hosted QR **image** (fetched `200 image/png`, valid PNG signature,
~4.6 kB), while `checkout_url` is an HTML payment page. The field is not an
EMVCo payload, which is why the EMV-only validator rejected it and the customer
surface depended entirely on the hosted URL.

The application now resolves three presentation modes in order — validated EMV
payload, allowlisted provider QR image URL, allowlisted hosted URL. For the QR
image mode:

- Telegram downloads the image server-side through `SakurupiahQrisImage`
  (HTTPS plus exact host allowlist, no redirects, 2 MB cap, PNG/JPEG signature)
  and sends it as a photo with the scan copy and the usual Check/Cancel actions;
  a policy-compliant `checkout_url` is added as the website button.
- The storefront serves the image through the existing signed `…/qris` route
  and never exposes the provider URL in the page.
- If the image cannot be downloaded and no hosted URL is available, the Payment
  keeps the safe `provider_presentation_pending` retry copy without provider
  data.

Provider application rejections are now diagnosable: `provider_create_last_error`
records the exception plus the provider application status
(`...|application_status:<status>`); messages, payloads, credentials, and
identifiers are still never persisted.

The same investigation found a shared download defect: under
`laravel/framework` v13.24.0, passing `stream => true` together with a `sink`
resource through `withOptions()` leaves the sink empty, so the provider image
download (both the new Sakurupiah fetcher and the pre-existing KlikQRIS
`KlikQrisQrisImage`) failed against real HTTP while fake/stub downloads still
succeeded. Both fetchers now attach the sink through the fluent `sink()` call;
no `stream` option is used, and the existing redirect, content-length, progress,
and PNG/JPEG boundary checks are unchanged. A live provider fetch now writes
the full image (4,604 bytes, PNG signature) into the sink. This removes the
KlikQRIS dependency on its base64 recovery path for the normal QR delivery.

Focused verification after the change: `php artisan test --filter='Sakurupiah'`
— 159 passed, 3 skipped; adjacent KlikQRIS, storefront invoice, and Telegram
renderer suites — 71 passed.

Separately, the 2026-09-16 production checkout on the active `QRISC` rail was
rejected by the provider with a non-200 application status
(`outcome_unknown`, no provider reference, no presentation), so its Telegram
instruction was the safe retry copy with no QR and no payment link. No code
change can substitute for a rejected create; after deployment the recorded
application status identifies the merchant/provider condition to correct.
Selection of Sakurupiah for new checkout remains Owner-controlled and this
evidence record does not change the production activation status.

## Presentation contract correction

> Superseded by the 2026-09-17 QR image presentation entry below. The provider
> `qr` field is a QR image URL for `QRISC`; it is not rendered as opaque content
> and its URL is validated against the environment host allowlist before use.

The rejection above exposed an application contract mismatch. Sakurupiah's
official create documentation defines `qr` only as a QRIS response string; it
does not require consumers to reinterpret that field as an EMV payload or
validate a terminal CRC. Its documented `checkout_url` example also contains
provider query data.

The application now follows that provider contract: it treats non-empty `qr`
as opaque provider-issued QR content, and it permits query-bearing HTTPS hosted
URLs only on the exact environment allowlist. Payment identity, method,
amount/fee, pending status, expiry, HTTPS, host, presentation-mode exclusivity,
encrypted snapshot storage, and payload sanitization remain enforced. Userinfo,
custom ports, fragments, and non-allowlisted hosts remain rejected.

This correction affects new create responses. The existing pending transaction
cannot be reconstructed because its presentation was deliberately discarded
and the provider status/history responses do not return `qr` or `checkout_url`.
No production deployment or request was performed.

One post-correction presentation-and-transport sandbox probe created a single
minimum-amount `QRISC` invoice through the real sandbox API. The create response
was pending, contained both documented presentation fields, and carried a `qr`
string of length 63. The application accepted it as opaque provider content,
rendered a PNG, and the verified `LhieStoreTestBot` Telegram API response
contained a photo.

This was deliberately an in-memory probe: it did not create a local Order,
Payment, inventory reservation, presentation snapshot, or durable Telegram
Outbound Effect. The photo was sent without callback buttons because no local
Order existed for those callbacks to address. A later provider status change
therefore cannot be correlated, reconciled, fulfilled, or delivered by the
application. The probe used the configured disposable testing chat, exposed no
identifiers or presentation payload in its evidence, and did not mark any local
Payment paid.

## Full persisted sandbox checkout lifecycle

A separate durable local checkout then exercised the complete application
lifecycle with one disposable credential-backed Product priced at the `QRISC`
minimum. `OrderCheckoutService` created the pending Order, Payment, reservation,
encrypted presentation snapshot, and durable checkout effect. The supervised
worker delivered the QR photo through `LhieStoreTestBot` in one attempt.

After the operator changed only the sandbox provider transaction to success,
the normal `sakurupiah:reconcile` seam reported one paid transition. The local
Payment became paid, inventory recorded reservation and sale movements, the
credential was allocated, and the receipt photo plus action panel were each
delivered in one attempt. An explicit TXT-download effect exported a valid
UTF-8 document containing the product and credential fields and Telegram
accepted it through `sendDocument` in one attempt.

All six outbound effects for this Order finished `sent`; none finished failed,
unknown, or manual review. The disposable Product was deactivated after its
single stock item was sold. No raw credential, QR, hosted URL, provider
identifier, merchant reference, Telegram message identifier, or chat identifier
was printed or stored in this evidence.


## Current verification rerun

The final workspace rerun after the presentation-pending and recovery changes
produced the following redacted evidence:

- `php artisan test --filter='Sakurupiah' --colors=never`: **PASS** — 144
  tests, 141 passed, 3 skipped, 903 assertions.
- `PaymentDocumentationTest`, `ProductionConfigurationValidatorTest`, and
  `SakurupiahProviderReadinessTest`: **PASS** — 44 tests, 408 assertions.
- `composer test -- --parallel`: **PASS** — 2,266 tests, 2,214 passed, 52
  skipped, 16,342 assertions.
- `npx playwright test tests/Browser/admin-dashboard-responsive.spec.js -g
  "customer checkout"`: **PASS** — 7 tests. A local signed invoice page
  rendered the new pending-presentation copy without exposing a provider token
  or authorization marker.
- `composer audit --no-interaction`: **PASS** — no security vulnerability
  advisories. `vendor/bin/pint --test` and `git diff --check`: **PASS**.
- In the sandbox-only readiness run, `enabled=true`, `ready=true`, and
  `missing`/`invalid` were empty. The forced local synchronous queue reported
  `scheduling_enabled=false`, one pending Payment, and no verification backlog.
  `ops:health` reported DB and queue schema healthy and failed only because
  `supervisorctl` is unavailable in this workstation; `schedule:list` showed
  bounded reconciliation jobs.
- The available local PostgreSQL container accepted temporary database
  creation, but the concurrency PHPUnit process exceeded Composer's 300-second
  timeout after partial execution. This is **BLOCKED**, not a concurrency pass.

These checks are workspace evidence only. They do not authorize production,
prove provider callback delivery, or replace Owner approval.

## Automated verification

| Gate | Exact command | Result |
| --- | --- | --- |
| Focused provider, regression, infrastructure, and documentation checks | `cd src && php artisan test --filter='(CheckoutPaymentSnapshotCharacterizationTest|Sakurupiah(Webhook|Reconciliation|ProviderSwitch|CheckoutPaymentPreparation)Test|FoundationInfrastructureTest|PaymentDocumentationTest|PaymentProviderSwitchTest|PakasirWebhookTest)' --colors=never` | **PASS** — 129 tests, 1,360 assertions. |
| Sakurupiah security boundary and query-budget checks | `cd src && php artisan test --filter='(SakurupiahWebhookTest|SakurupiahReconciliationTest|SakurupiahGatewayTest|SakurupiahSignatureTest|SakurupiahPayloadSanitizerTest|test_sakurupiah_sales_rows_eager_load_relations_without_n_plus_one|test_sakurupiah_delivery_loads_the_checkout_graph_without_relation_n_plus_one)' --colors=never` | **PASS** — 57 tests, 323 assertions. |
| Composer dependency security audit | `cd src && composer audit --no-interaction` | **PASS** — no security vulnerability advisories. |
| Customer browser smoke | `cd src && npx playwright test tests/Browser/admin-dashboard-responsive.spec.js -g "customer checkout"` | **PASS** — 7 tests. |
| Full SQLite suite, current parallel rerun | `cd src && composer test -- --parallel` | **PASS** — 2,266 tests, 2,214 passed, 52 skipped, 16,342 assertions. |
| Full SQLite suite, order-sensitive reruns | `cd src && composer test` | **INTERMITTENT FAILURES RECORDED** — earlier runs failed in unrelated `CatalogSearchTest` because a random username contained `there`, and `BscReadinessTest` with a stale-preflight message. The isolated failing tests and `BscReadinessTest` file passed when rerun. |
| PostgreSQL concurrency deploy gate, isolated PostgreSQL 16 | `CONCURRENCY_GATE_ARTIFACT_DIR=/tmp/telegram-bot-concurrency-gate-416-final-3 CONCURRENCY_DB_HOST=127.0.0.1 CONCURRENCY_DB_PORT=55432 CONCURRENCY_DB_ADMIN_USERNAME=postgres CONCURRENCY_DB_ADMIN_PASSWORD=postgres composer test:concurrency-gate` | **PASS — historical baseline evidence** — PHPUnit output: 95 tests, 766 assertions; gate report: 77 tests, 620 assertions, 0 skips/failures/errors; isolated database dropped. |
| Release configuration guard scripts | `./scripts/test-deploy-production-release.sh && ./scripts/test-redact-release-diagnostics.sh && ./scripts/test-check-migration-prefixes.sh && ./scripts/test-safe-compose-config.sh` | **PASS** — all release, redaction, migration-prefix, and safe-compose checks passed. |
| PostgreSQL concurrency deploy gate, default local port preflight | `cd src && CONCURRENCY_GATE_ARTIFACT_DIR=/tmp/telegram-bot-concurrency-gate composer test:concurrency-gate` | **BLOCKED** — this default-port attempt received connection refused at `127.0.0.1:5432`; it produced no race result. The isolated PostgreSQL 16 rerun above is the passing gate. |
| PostgreSQL gate artifact preflight | `cd src && composer test:concurrency-gate` | **BLOCKED** before execution because the workspace `artifacts/` directory was not writable. The writable `/tmp` rerun above reached the PostgreSQL connection check. |
| PostgreSQL release suite registration | `cd src && ./vendor/bin/phpunit --configuration=phpunit.postgres.xml --testsuite='PostgreSQL release integration' --group=postgres-release --list-tests` | **PASS** — 12 tests are registered, including Sakurupiah create, duplicate callback, and callback/reconciliation/expiry race tests. This is registration evidence only, not an execution pass. |

The CI release path remains the authoritative PostgreSQL evidence path. It runs the `phpunit.postgres.xml` production-critical suite with `--group=postgres-release --fail-on-skipped`, then `scripts/concurrency-deploy-gate.sh` with an isolated PostgreSQL database and `--fail-on-skipped`.

## Regression coverage mapped to issue #415

- `CheckoutPaymentSnapshotCharacterizationTest` characterizes Manual QRIS, KlikQRIS, and Sakurupiah QRIS as shared IDR checkout rails. The same suite covers Binance Pay ID and BSC payment snapshots.
- Existing `PakasirWebhookTest` coverage remains the historical compatibility characterization. New checkout provider selection continues to reject Pakasir; `SakurupiahProviderSwitchTest::test_switching_away_from_sakurupiah_keeps_existing_payment_provenance` verifies that switching the active provider does not rewrite an existing Sakurupiah Payment.
- `SakurupiahWebhookTest::test_paid_callback_duplicate_materializes_each_fulfillment_effect_once` verifies one paid transition produces one sale, one credential allocation, one receipt effect, one Admin paid effect, and one Sales Proof effect under duplicate callbacks.
- `SakurupiahWebhookTest` fixtures distinguish managed from numeric-only stock. `test_out_of_order_callbacks_cause_one_paid_sale_and_stale_callbacks_are_acknowledged` allocates a real Product Stock Item and asserts one paid sale/allocation with no Manual Fulfillment Review; `test_paid_callback_with_numeric_only_stock_records_one_review_without_releasing_credentials` asserts one durable `local_credentials_missing` review, no credential allocation, and distinct paid/review outbound effect identities.
- `SakurupiahReconciliationTest::test_command_bounds_chunk_and_uses_deterministic_ordering_without_n_plus_one`, `SakurupiahCheckoutTest::test_sakurupiah_delivery_loads_the_checkout_graph_without_relation_n_plus_one`, and `AdminDashboardOverviewTest::test_sakurupiah_sales_rows_eager_load_relations_without_n_plus_one` assert bounded reconciliation, eager-loaded checkout delivery relations, and eager-loaded Admin sales relations without per-row queries.
- `ProductionConcurrencyTest::test_concurrent_duplicate_checkout_key_reuses_one_order_payment_reservation_and_effect` adds the PostgreSQL process race for one checkout key.
- `SakurupiahInvoiceLifecyclePostgresConcurrencyTest` registers in both the `concurrency` and `postgres-release` groups and covers concurrent invoice creation, duplicate paid callbacks, and callback/reconciliation/expiry contention. The isolated PostgreSQL 16 result above is historical baseline evidence, not a rerun of this update; the current available-container attempt timed out and is recorded as blocked.
- Existing provider-switch, KlikQRIS callback/reconciliation, stock mutation, and inventory allocation process races remain in the PostgreSQL release/concurrency suites.

## Served queue and release health evidence

Sakurupiah webhook verification is explicitly queued on `default`. The release update worker serves `payments,default`; the scheduler registers bounded reconciliation only when readiness and schedule checks pass; and `ops:health` checks supervisor status, operational queue depth, Sakurupiah readiness, reconciliation scheduling, and backlog. `FoundationInfrastructureTest::test_sakurupiah_verification_and_reconciliation_use_release_owned_paths` asserts these cross-file contracts.

The release path must use the supervised `default` queue. Do not move Sakurupiah verification to an unserved queue or add a second worker without updating the readiness contract and release evidence.

## 2026-10-12 — production "no QR" incident: rejected creates were treated as ambiguous

Live production checkout `INV-20261012-01M4R9AD07S2EANN49S3VK5X5W` (and an
earlier `INV-20261012-01M4R6BZATN5NY5J1AR5HQZ91C`) produced no QR. Sanitized
production evidence for the first invoice:

- `payments.provider_create_state` ended `recovery_unconfirmed` with
  `provider_create_last_error = missing_provider_reference`; the Payment had no
  provider reference, no total, no fee, and no presentation.
- `payment_events` recorded exactly one create observation:
  `provider_create | outcome_unknown | App\Services\Sakurupiah\SakurupiahApplicationException|application_status:400`.
- A read-only live `create.php` probe against the same production merchant with
  a probe reference answered HTTP `200` with `{"status":"400","message":"Gagal
  membuat Invoice. hubungi tim dukungan"}`. Probes of the same endpoint with
  intentionally invalid parameters answered the documented specific reasons
  (`Signature tidak valid`, `Batas minimal pembayaran adalah IDR 200`,
  `Kode pembayaran tidak aktif…`), and provider history reported no transaction
  for either failing merchant reference.

Root cause (application, not presentation): the create path marked a create
whose response arrived as a transport-ambiguous outcome, so the provider's own
failure contract (`status` + `message`) was discarded and the Payment became
permanently un-creatable; the next observation then relabelled it
`recovery_unconfirmed`. The customer-visible Telegram/storefront copy showed the
`recovery_pending` state ("Restoring QRIS payment"), which is why no QR or
provider instruction ever appeared.

Fix in this repository:

- The client reads the documented failure contract from both the HTTP-200
  envelope with a non-200 `status` and a non-retryable HTTP failure carrying the
  same JSON, and raises it as `SakurupiahApplicationException` with the provider
  status plus a sanitized, bounded reason (merchant reference and transaction ID
  redacted). `SakurupiahProviderException::isProviderRejection()` is the single
  predicate for "the provider decided".
- A rejected create persists `create_failed` (never `outcome_unknown`) together
  with `…|application_status:<status>|provider_message:<reason>` in
  `provider_create_last_error`, so the cause is diagnosable from the Admin Order
  detail and from the sanitized payment row.
- The same merchant reference is created again when the condition is corrected,
  at most once per `services.sakurupiah.create_retry_min_interval_seconds`
  (default 60). The customer's **Check Payment**, the storefront status refresh,
  and the scheduled `sakurupiah:reconcile` sweep all retry it; a throttled retry
  stays `create_failed` instead of being downgraded.
- The reconciliation sweep reports a `recovered` counter and queues the
  presentation-recovery instruction effect when its work turns a Payment
  payable, so the QR reaches the customer without another action.
- Pre-request failures (notably the readiness gate) stamp no dispatch time, so
  they retry immediately, and an ambiguous outcome remains non-retryable exactly
  as before.

Verification for this change:

- `php artisan test --filter='Sakurupiah'`: **PASS** — 179 tests, 176 passed, 3
  skipped, 1,135 assertions.
- `SakurupiahStorefrontTest`: new case proves a rejected create is reported as a
  retryable state and recovers to a payable QR on status refresh — 8 tests.
- Live sandbox end-to-end through the real lifecycle and the real provider:
  a below-minimum create was rejected and persisted
  `application_status:400|provider_message:Batas minimal pembayaran adalah IDR 200`;
  after the cause was corrected the retry on the same merchant reference returned
  `payable` with a provider QR image presentation and total `200`; the persisted
  presentation was reused on the next call with no second provider invoice.
- Pint (`--test`) passed for every changed file after one formatting pass.

Not covered by this change: the two historical Payments above stay stuck,
because an ambiguous historical outcome is never re-created. The paid-out
invoice `…01M4R9AD…` was cancelled; `…01M4R6BZ…` is still `pending_payment` and
needs a fresh checkout (or an operator decision) after deployment.

## 2026-10-13 — buyer-borne QRIS fee policy

The Owner changed the QRIS fee policy so the buyer bears the channel fee. The
contract is pinned in `services.sakurupiah.qris_contract` as
`fee_payer = customer` / `merchant_fee = 2`; the readiness check, the production
configuration validator, and the create request now read one shared rule
(`SakurupiahQrisContract::feeRuleIsQualified()`), so a mismatched pair fails the
create locally instead of sending an ambiguous invoice.

Live sandbox evidence (real provider, `QRISC`, unique merchant references):

| `merchant_fee` | amount | total | fee | echoed payer | history `amount` |
| --- | --- | --- | --- | --- | --- |
| `1` | `10000` | `10000` | `0` | `"Merchant"` | `10000` |
| `2` | `10000` | `10170` | `170` | `"Pelanggan"` | — |
| `2` | `20000` (app lifecycle) | `20240` | `240` | `"Pelanggan"` | `20240` |

Two latent production defects were found and fixed by that evidence:

1. `SakurupiahInvoice`/`SakurupiahStatus` parsed the echoed payer only as
   `1`/`2`/`merchant`/`buyer`/`customer`, so the provider's documented
   `"Pelanggan"` word made every buyer-fee create response fail schema
   validation and produce no QR. Both parsers now accept `pelanggan`.
2. `transaction.php` history reports the **final total** in buyer-fee mode while
   the create response reports the base amount, so the previous single-value
   amount evidence would have left every buyer-fee payment permanently
   unverified — the customer would pay and the Order would never fulfill.
   `SakurupiahInvoiceLifecycleService::expectedProviderAmounts()` now defines
   the owned amounts (base amount and recorded final total) and the callback
   processor, refresh identity check, and Store Balance top-up evidence all use
   it. An amount that is neither still stays unverified.

Presentation consequences: the payer is snapshotted with the payment
presentation (`customer_pays_fee`), the Telegram caption and the storefront
invoice label the fee as paid by the customer, and the final payable total is
what the customer is told to pay. The store still settles the full order amount
(`amount_merchant = amount`).

Verification: focused Sakurupiah suites **PASS** (218 tests including the new
buyer-fee settlement, parser, and snapshot-round-trip cases), and a live
sandbox lifecycle run through the app reported fee `240`, total `20240`,
`customerPaysFee=true` with the payer persisted and rehydrated, and provider
history `amount = 20240` matching the Payment's owned amounts.

## External behavior notes

Sandbox observations and regression evidence are recorded here for operators.
Production credentials remain deployment-time inputs in the server-side
environment file. Callback, expiry, timeout, rate-limit, and reconciliation
behavior remain operational concerns covered by the readiness and health
commands; they are not separate ENV activation gates.

## Reproduction commands

Run from the repository root unless noted:

```bash
cd src
php artisan test --filter='(CheckoutPaymentSnapshotCharacterizationTest|SakurupiahWebhookTest|SakurupiahReconciliationTest|SakurupiahProviderSwitchTest|SakurupiahCheckoutPaymentPreparationTest|FoundationInfrastructureTest|PaymentDocumentationTest|PaymentProviderSwitchTest|PakasirWebhookTest)' --colors=never
npx playwright test tests/Browser/admin-dashboard-responsive.spec.js -g "customer checkout"
composer test
CONCURRENCY_GATE_ARTIFACT_DIR=/tmp/telegram-bot-concurrency-gate composer test:concurrency-gate
```

A PostgreSQL gate is only **passed** when the isolated database is created, the process-race suite completes without skips, and the command exits successfully. An unavailable PostgreSQL service is a blocked gate, never a pass.
