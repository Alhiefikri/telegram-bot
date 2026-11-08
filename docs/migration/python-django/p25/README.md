# P25 notification delivery — #707

**Status: partial implementation, not P25 acceptance.** Work is on
`migration/p25-notifications-707`, based on migration integration revision
`68160794824abd9f5d941ff454cf1179ee435f34`. It must not merge directly to `main`.
After reviewing the partial status, the Owner requested commit/push, integration
merge and issue closure. That administrative closure is not P25 acceptance;
the remaining scope below is retained explicitly and is not declared complete.

## Implemented boundary

`candidate/lhie_candidate/notifications/` adds adapters to the **existing**
`telegram_outbound_effects` and `order_notification_events` authorities. There
is no second outbox, new queue dependency, production recipient enrollment,
schema lifecycle, financial writer, or automatic worker activation.

| Flow | Implemented | Limit |
| --- | --- | --- |
| Shared Telegram protocol | Closed per-family attempt limits, scoped claims/sweeps, token fencing, definite failure scheduling, unknown retention | Default receipt families stay unchanged; notification effects cannot be claimed by the receipt worker |
| Admin Broadcast | Bounded test-recipient materialization, legacy state import, locale-snapshotted wrappers/buttons, verbatim authored text/photo caption, aggregate repair | Does not mark a test-only audience as the complete original audience; confirmation/publishing UI and full materialization are not ported |
| Stock | Missing-effect recovery and delivery from existing recipient text/keyboard snapshots | New event-to-audience rendering/fan-out and `photo_path` attachments remain blocked |
| Price | Existing event/recipient snapshots, exact IDR/micro-USDT economics, locale copy, Product action, shared rate budget | New audience materialization and Store Channel price effects remain unported |
| Manual payment proof | Admin-target-bound retained photo forwarding and sent-marker repair | Full PHP caption parity remains unqualified |
| IDR adjustment notice | Deliver the existing transactionally authored message snapshot | Other IDR balance notice/receipt/compensation families remain unported |
| Credential Delivery Email | Authorized complete allocation, encrypted immutable recipient snapshot, HTML/text layout, legacy parts/MFA and structured access, fixed local SMTP sink, attempt-fenced finalization | Live SMTP, visual/differential template qualification and actual historical data remain outstanding |
| Sales Proof | Secret-free eligibility/economics, masked public PNG, legacy/new captions, DB-backed Unicode channel icons, low-stock signal, exact money, Redis numbering and projection repair | Local fake relay only; historical/differential visual qualification, retry UI and rollback counter handoff remain outstanding |
| Admin Order notices | Pending/expired/paid/review adapters, bounded durable discovery, delivery-evidence-bound paid outcome, successor-result recovery and pending cleanup | Pending/expired/paid E2E and unknown-outcome unit evidence exist; review/supplier/outcome-update end-to-end qualification remains outstanding |
| Authentication mail | P19 owner, grant, expiry, and encrypted work remain unchanged | Not folded into receipt/interaction workers; reuse P19 evidence only while relevant source/config are unchanged |
| Waitlist continuation | Native opt-in, signed confirmation/unsubscribe, encrypted email and compatible HMAC, bounded resumable restock fan-out, claim-fenced HTML/text SMTP delivery and unknown retention | Local `.invalid` recipients and loopback origin only; automatic worker integration, visual qualification and actual historical/custom-key coverage remain outstanding |

### Safety contract

- `Notifications()` defaults to an empty admitted Telegram target set. Disabled
  composition is a no-op, including no lease or projection writes. The manual
  command admits only P01's synthetic chat `1`; it has no content, URL, path,
  recipient or credential arguments. The boundary still enforces its own allowlist.
- Mail is sent only to reserved `.invalid` addresses through the P01 loopback
  SMTP sink, using an explicitly constructed Django SMTP backend with a five-second
  timeout. It never consults a console, file, log or configurable default backend.
- Only encrypted recipient snapshots and ID/state metadata are durable. Rendered
  credential bodies stay in memory. SMTP/provider exception text is neither
  persisted nor logged. No fallback to the current Order or Customer email exists.
- An interrupted/ambiguous credential-email attempt is terminal `failed` with
  `mail_delivery_unknown` and its retained in-flight marker. Existing resend UIs
  already refuse that combination. Recovery never clears the marker or resends.
  A definite non-acceptance may retry, still to the original address.
- Telegram success requires a positive integer message ID. Missing ACKs, transport
  exceptions and expired leases become unknown. Only an explicit custom-emoji
  rejection permits a Unicode fallback request; an uncertain send never does.
- Broadcast/stock/price share a rolling 25-per-second Redis admission budget.
  Redis failure defers **before claim**, consuming no effect attempt. A 429
  schedules the affected effect through the shared protocol and ends the batch.
- Retained sent/in-flight/terminal recipient history is imported conservatively.
  Sent projection repair does not claim or execute transport. Recovery pages and
  delivery batches are bounded; the in-process projection cursor rotates rather
  than permanently inspecting only the first already-repaired page.
- All transport runs outside atomic transactions. No money, stock, Customer
  identity, auth grant or historical credential is edited by these adapters.

## Provisioning and isolation

Apply `candidate/lhie_candidate/notifications/grants.sql` explicitly only in a
disposable database after P06/P07/P09 grants. The runtime privilege verifier
recognizes these exact columns; startup installs no grants. Broadcast authored
content and recipient snapshots are SELECT-only. Grants do not authorize a
production rollout.

Inside the admitted candidate network namespace, the manual commands are:

```sh
python notifications.py --once --family telegram
python notifications.py --once --family credential-mail
python notifications.py --once --family waitlist
```

Manual commands run in distinct processes with a 60-second whole-batch timeout.
The continuation below additionally supplies an **opt-in**, admission-bound
automatic profile; the original stack remains the default. Neither profile
places notification/mail work onto interaction/payment/receipt.

PostgreSQL sweeps discover durable work without Redis wakeups. Redis outage
defers bulk Telegram sends conservatively; it does not lose the source handoff.
No scheduler, CI/CD, production, real email or live Telegram action is added.

## Verification and evidence limits

Changed-flow proof reuses the existing P01/P07 runner instead of adding another
harness:

```sh
python tools/migration_harness/smoke_checkout.py --notifications
cd candidate
.venv/bin/python -m unittest tests.test_notifications tests.test_receipt_render tests.test_receipt_transport tests.test_workers tests.test_jobs tests.test_legacy_crypto
```

The disposable smoke passed on Python 3.13.18, Django 5.2.18 and PostgreSQL 16.15
with dataset `synthetic-p25-notifications-v1`. The single source-hash manifest
is `.local/migration-p25/verification.json`; it records the base revision,
dependency/boundary image IDs, schema source checksum and exercised source files.
No private diagnostic or generated dataset belongs in Git. The final focused
command passed **61 tests**; `compileall` and `git diff --check` also passed.

Observed: real PostgreSQL two-connection notification claim race, losing-token
fencing, default receipt-family exclusion, missing-materialization replay,
sent-marker repair without transport, unknown/expired lease no-resend, actual
local fake relay ACK, actual SMTP sink acceptance, definite mail retry to the
original encrypted recipient after Order email change, unknown SMTP retention,
and unchanged purchase debit/allocation counts. Focused regressions additionally
cover locale/verbatim content, exact money, masking, target admission,
missing-positive-ACK handling, rate admission and definite-only emoji fallback.

The initial smoke exposed a PostgreSQL bigint Customer Telegram ID versus
varchar recipient ID comparison. The query now casts explicitly at that legacy
boundary. Review also found and corrected the column-grant/write-allowlist
contract for importing retained sent/unknown history. These are local findings,
not claims about production data.

This is **not** full message/visual parity, live Testing Bot qualification,
live SMTP readiness, actual-history qualification, a worker resource/slow-mail
load proof, or a complete #707 acceptance run. Later source changes invalidate
only the affected portions of the manifest, not all existing shared evidence.

## Remaining #707 work

1. Finish remaining payment/balance/admin families and guest milestone mail,
   and qualify review/supplier/paid-outcome successor paths end to end. Initial
   lifecycle adapters and bounded discovery are implemented; pending/expired/paid
   smoke does not establish every financial-notice authority.
2. Complete confirmed Admin Broadcast creation, stock/price event audience
   materialization, historical/photo layouts and channel families, retaining
   exact copy, DB-owned icons and eligible recipient rules.
3. Complete waitlist browser/visual qualification. Its automatic mail consumer
   exists in the opt-in profile; no live/historical/custom-key acceptance is implied.
4. Qualify credential-email/Sales Proof differential template and visual parity,
   retry UI and public counter handoff. Local publication and HTML/text mail now
   exist but are not full parity acceptance.
5. Integrate auth-mail and all remaining families into the opt-in consumers,
   retain disjoint notice/bulk/mail budgets, and qualify the final composition.
6. Run changed family flows against test recipients/mail sink, reuse valid P09/
   P10/P19 evidence, and record all remaining parity rows before closing #707.

## Context7 references

Resolved the official Django library and fetched **5.2.18** documentation using
`ctx7` (one library resolution and two focused documentation queries):

- [SMTP backend, timeout, EmailMessage and explicit connections](https://github.com/django/django/blob/5.2.18/docs/topics/email.txt).
  Explicit SMTP construction avoids accidentally routing credentials into a
  configured console/file backend; a successful send count is checked.
- [Atomic transactions and on-commit semantics](https://github.com/django/django/blob/5.2.18/docs/topics/db/transactions.md).
  Committed database sources remain authoritative; callbacks/wakeups are not
  delivery guarantees and external sends do not run under source locks.
- [Row locking and skip_locked](https://github.com/django/django/blob/5.2.18/docs/ref/models/querysets.md).
  The existing SQL protocol retains row-locked claims and bounded expired-lease
  sweeps; SQLite/mocks are not offered as locking evidence.

## P25 prerequisite continuation for #708 (2026-11-08)

The Owner requested finishing the incomplete P25 prerequisites before P26. This
continuation is on `migration/p25-completion-708`, based on integration revision
`3b26e50d214ef41a37155253f28c42105825a31f`. **P25 as a whole and #708 are still
incomplete.** The waitlist slice and later continuation below are implemented
locally; remaining parity rows are not waived. No production scheduler or
deployment is added.

`notifications/waitlist.py` owns the existing `product_waitlist_*` tables.
`storefront/waitlist.py` adapts the original `/produk/{id}/waitlist`,
`/waitlist/confirm/{id}` and `/waitlist/unsubscribe/{id}` routes. The unavailable
Product page supplies a native CSRF-protected form. Generic submission feedback
does not disclose deduplication, eligibility or recipient admission. Shared Redis
admission preserves the source defaults: 5/email, 30/IP and 100/Product per hour;
Redis failure fails closed. Inputs are normalized before validation and HMAC.

Apply `notifications/waitlist_grants.sql` explicitly in the disposable database,
after the existing navigation/checkout grants. HTTP additionally needs the
existing auth metadata/grants and storefront catalog read grants. Startup never
installs these. `Waitlist()` is disabled by default; the HTTP factory requires
the explicit column grant. Enabled mail still accepts only `.invalid` addresses
and uses `127.0.0.1:1025`, never a configured logging backend. Signed links bind
the local origin, purpose, subscription ID and expiry; duplicate/extra query
parameters are rejected. Canonical key/HMAC primitives reuse P03. A nondefault
historical waitlist hash key must be supplied explicitly to the owner constructor
after HD qualification; the local composition uses only the synthetic APP_KEY.

Materialization uses a transaction-scoped advisory mutex over the read-only
inventory source and the Product lock to serialize distinct restocks. Persisted
event cursors and per-event unique recipients make pages replay-safe. No UPDATE
grant on immutable inventory history is added. Unconfirmed, unsubscribed,
unavailable and nonpublic candidates do not send. Delivery locks subscription
before notification consistently with unsubscribe; SMTP is outside those locks.
An unsubscribe stops future pickup, but cannot recall mail already in flight.

Attempt and timestamp predicates fence finalization. Only proven non-acceptance
can retry; a lost ACK/expired in-flight marker is retained as
`mail_delivery_unknown` and is never resent automatically or enrolled by a later
restock. There is no new operator authority to clear it. The PHP compatibility
guards retain these rows on rollback, including confirmation rediscovery and
new restock materialization. **Any qualified rollback image must include these
guards**; an old unpatched image is not certified safe by this local proof.

Verification reuses the existing runner and its single source-bound manifest:

```sh
python tools/migration_harness/smoke_checkout.py --notifications
cd candidate
.venv/bin/python -m unittest tests.test_waitlist tests.test_notifications tests.test_receipt_render tests.test_receipt_transport tests.test_workers tests.test_jobs tests.test_legacy_crypto
cd ../src
php vendor/bin/phpunit tests/Feature/ProductWaitlistTest.php
```

Observed: 66 focused Python regressions and 12 PHP tests (77 assertions) pass.
The disposable PostgreSQL 16.15/Django 5.2.18/Python 3.13.18 smoke passes the
native-form CSRF path, actual local confirmation/restock SMTP, signed link
purpose/replay/privacy, four one-recipient cursor pages, concurrent one-winner
SMTP claim, unsubscribe, definite retry and unknown/expired-marker no-resend.
The unchanged P25 financial-history and Telegram protocol checks also pass.
The PHP regression proves the rollback guards without asserting SQLite locking.
Evidence remains `.local/migration-p25/verification.json`, dataset
`synthetic-p25-notifications-waitlist-v2`; private/generated evidence is not Git
content. Initial fixture SQL/required-column errors and the older P09 image's
missing current QR dependency were corrected, not retried as flaky successes.
The runner now uses the existing P13 dependency image for this HTTP path and
records the same selected image identity that it actually executes.

This establishes local lifecycle behavior, not full P25/P26 acceptance, visual
parity, live mail readiness, production history/key compatibility, measured
automatic-worker capacity or approval to run production/CI/CD. The HTML/text
mail layout/copy is ported from the PHP waitlist templates; browser/device
qualification remains outstanding.

Context7 was resolved to `/django/django/5.2.18` and checked for
[management-command/system-check database behavior](https://github.com/django/django/blob/5.2.18/docs/howto/custom-management-commands.txt)
and [transactional row locking](https://github.com/django/django/blob/5.2.18/docs/ref/models/querysets.txt).
The former informs the pending P26 design, not an implementation claim; the
latter supports transaction-scoped claims and keeping external sends outside
locks. Existing shared SMTP references above remain applicable.

### Mail, public proof, lifecycle notices and isolated workers continuation

`credential_render.py` projects the already-authorized ReceiptSource allocation
into the PHP mail fields: legacy formatted parts/MFA, structured token/OTP/reader
access, account overrides versus Product instruction, unique guides and invoice
access. HTML autoescaping is retained. Rendering/credential plaintext exists only
in memory, and no Recovery Key value is read. `CredentialMail` sends a real
multipart message through the fixed sink and rotates its source cursor so an
ineligible first page cannot permanently starve later Orders.

`proof_publication.py` now publishes through the existing shared effect protocol.
It reads no secret columns, renders the existing paid-invoice PNG and a bounded
public caption, supports retained v1 text and v2 photo effects, uses DB-owned
Unicode channel icon slots, preserves bulk/low-stock/money/API restrictions and
never reclaims an unknown. Redis counter failure defers before transport. The
counter uses the isolated candidate namespace; **historical PHP counter transfer
and rollback continuity are not yet qualified**. Shop links use only the Testing
Bot. No live Store Channel is enrolled.

`order_notices.py` ports pending/expired/paid/review presentation and bounded
discovery over Orders/reviews. The paid outcome waits for shared receipt evidence
or escalation/intervention; paid/completed alone does not claim buyer delivery.
Supplier uncertainty stays explicit. Outcome updates are separate effects linked
by `supersedes_effect_id`, not a resend of an unknown original. Projection repair
and best-effort pending-message cleanup do not move money or authorize fulfillment.
Dashboard links are HTTPS localhost-only; a qualified real destination remains
separate configuration work. Long text truncation preserves complete custom-emoji
tags and escaped Product/Customer values.

The optional runtime document requires **both** `workers: true` and
`notifications: true`, with exact limits `{processes: 8, threads: 8,
db_connections: 7}`. It adds `notice`, `bulk`, `mail` OS processes to the unchanged
three critical workers, HTTP and supervisor. Each process owns one connection;
the supervisor owns none. The explicit disposable role limit is 7; the old
profile remains at its original limits/role ceiling. This is a local runtime
document, not a new presentation ENV setting. Startup does not grant privileges.
Notice/bulk policies are disjoint, ID-only wakeups cannot switch families, mail
sweeps alternate their first owner, and all processes retain the existing bounded
timeout/drain/restart protocol. Auth-mail remains on its P19 manual owner and is
not claimed integrated by this profile.

The existing `--notifications` smoke exercises the opt-in supervisor, real HTTP
ingress/navigation/receipt workers, fake Telegram relay and SMTP sink. A
harness-only mail transport holds at an explicit gate; navigation and receipt
finish **before** the mail gate is released, and mail finishes after release.
Observed runtime connections: **7**. Cgroup memory/CPU/PID samples (including the
probe/harness overhead) are included in the same verification manifest, not a
second acceptance report. This establishes process isolation, not a production
capacity or final whole-stack qualification.

Focused regressions:

```sh
cd candidate
.venv/bin/python -m unittest tests.test_notification_completion tests.test_runtime tests.test_notifications tests.test_waitlist tests.test_workers tests.test_receipt_render tests.test_receipt_transport
```

The final focused command including `tests.test_jobs` and
`tests.test_legacy_crypto` passed **86 tests**; `compileall` and `git diff --check`
also passed. The changed-flow disposable
smoke additionally passed pending -> expired notices/cleanup, paid notice after
receipt ACK evidence, sent projection repair without resend, actual public PNG
publication and actual credential HTML/text SMTP, retaining the prior waitlist
and shared protocol checks. Initial notice delivery exposed a local HTTP
Dashboard URL rejected by the relay: the URL was corrected to the existing
HTTPS-local policy; the relay was not weakened. Public PNG delivery similarly
uses the upload relay's literal Unicode text contract instead of adding an
unsupported parse-mode option. No failed run was labelled flaky or accepted.

Current dataset: `synthetic-p25-notifications-isolation-v3`. Source-hash manifest:
`.local/migration-p25/verification.json`. Full P25 acceptance remains blocked by
the explicit remaining rows above; this continuation does not establish #708.
