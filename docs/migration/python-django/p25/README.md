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

### Completion-branch audit and continuation (2026-11-09, uncommitted)

The checkpoint was `0d9d0651` on `migration/p25-completion-708`. Before reusing
its evidence, all **102** `source_sha256` entries in the retained P25 manifest
were checked and matched. That did not establish the correctness of the code:
the audit found auth-mail ambiguity retries, successor notices stuck at
`sequence=1`, and a disabled Sales Proof retry route. These are implementation
findings, not historical-data or production findings.

Implemented additions (all still local/opt-in):

- `auth/mail.py` retains uncertain SMTP attempts as terminal
  `mail_delivery_unknown`, clears encrypted work payloads at terminal outcomes,
  fences stale completion by attempt/deadline and permits a retry only after an
  explicit `DefiniteMailFailure`. Imported previously attempted work without that
  definite marker is conservatively uncertain. OTP work additionally checks the
  original browser-binding shape. Grant consumption remains owned by P19; mail
  does not grant authentication. The fixed sink admits only `.invalid` recipients.
- The isolated mail consumer fairly rotates credential, waitlist, auth and guest
  milestone owners. Wakeups for the latter two contain integer work IDs only.
  `milestones.py` uses existing `order_notification_events`, encrypted recipient
  snapshots and the credential-mail claim/finalization seam. Lifecycle, storefront
  contact capture and supplier completion commit their milestone handoffs with
  the source change, without rendering or external I/O. Retained committed state
  and existing pending handoffs are recovered in bounded pages.
- `broadcast_intents.py` adds private-admin `/broadcast` draft/preview,
  optional Product selection, expiry, cancellation and explicit confirmation.
  Confirmation writes the canonical `telegram_broadcasts` source and consumes
  the current draft atomically. Replays cannot publish a second source. It accepts
  text or one photo, preserves authored content, snapshots localized action
  buttons and revalidates authorization/Product eligibility. Session state is
  the existing navigation state, not another outbox. Source recovery rotates
  instead of remaining stuck on its first incomplete source page.
- Price events have bounded admitted-audience recovery and the Store Channel
  family. Admin price edits in the opt-in profile create the immutable event in
  the existing Product transaction. Stock recovery renders locale/price/tier/
  description/channel snapshots into existing recipient rows. Photo reads use
  the guarded disposable public-image reader, outside transactions; HTML becomes
  explicit Telegram entities for the upload relay. Stale sold-out delivery is
  rejected. Channel admission/readiness and the legacy event/chat uniqueness
  remain authoritative; the channel is inserted before a coincident DM target.
- `payment_proof.py` replaces the checkpoint's abbreviated proof caption with
  the PHP item/customer/exact-money/late-evidence presentation and decision
  buttons, rechecking the retained attachment, admin target and payment state.
  `balance.py` adds IDR top-up receipts and paid Owner notices, proving the exact
  immutable posting and Customer/source ownership instead of treating a `paid`
  label as financial evidence. Delivery pause applies before receipt claim.
- Paid outcome successor effects advance their predecessor sequence; projection
  repair serializes on the Order and can replace an old non-null timestamp with
  the latest sent version without transport. The existing admin Sales Proof retry
  POST now requests one shared-protocol manual attempt for a definite failed
  effect, retaining attempts/history. Unknown and terminal rejection cannot use
  this route. The protocol consumes the manual flag at pickup, not an unlimited
  attempt-limit bypass.
- Sales Proof's explicit stopped-publisher counter handoff imports a PHP
  `publishedCount()` floor and exports a monotonic rollback floor, including
  reserved/unknown publication numbers rather than only sent rows. The existing
  PHP cache key must receive that floor before PHP resumes. This seam is not an
  automatic production Redis migration or permission to operate a live cache.
- PHP rollback readers retain candidate auth in-flight markers and milestone
  pending/in-flight/cancelled states; milestone sends use the encrypted recipient
  snapshot when present. Any rollback image must contain these guards, in addition
  to the checkpoint's waitlist guards.

Verification remains in `tools/migration_harness/smoke_checkout.py --notifications`
and **one** `.local/migration-p25/verification.json`. The expanded deterministic
fixture is `synthetic-p25-notifications-completion-v4`; PHP/Python comparator
Customers both receive the same additional real manual top-up through the
canonical PHP confirmation/ledger owner. No ledger projection/history is edited
to make an assertion pass. Retained numeric-stock review history is also created
by the PHP owner: the candidate currently refuses a new numeric-only checkout
at its credential inventory guard. That separate upstream parity gap has not
been bypassed or declared fixed by P25.

The runner additionally checks exact price copy for `id/ms/en`, PHP/Django HTML
for guest milestones and a **redacted** structured-credential preview, and offline
Chromium mail layouts at 390/1280px. Browser evidence contains `[REDACTED]`, never
an issued grant, password, Auth Token or Recovery Key. Browser binaries are
already-installed local tools; the run downloads none and records their identity.
Static preview/pixel comparisons do not establish native mail-client rendering,
full waitlist browser behavior, Android/iOS Telegram display or full P27 acceptance.

Failure diagnostics now record only code locations, exception classes and
SQLSTATE, not exception messages/SQL DETAIL/locals that could expose secrets.
The expanded smoke diagnoses concrete fixture/read-boundary failures rather
than relabelling them flaky: a definite-failure fixture incorrectly used HTTP
500 (which the shared protocol correctly treats as ambiguous), a removed legacy
price lease column, colliding synthetic channel/DM recipients, a manual top-up
fixture using `manual` instead of `QR_MANUAL`, a review test requesting ungranted
columns, and a proof-submitted checkout correctly held against ordinary expiry.
The fixtures/read projections were corrected; those guards were not weakened.

#### Acceptance mapping — not yet complete

| Requirement | Implementation/evidence owner | Remaining limit |
| --- | --- | --- |
| Credential retry recipient, expiry/grant, private secret transport | CredentialMail, MailWork, MilestoneMail; SMTP/claim/expiry/unknown and rollback-reader checks | Full auth HTML differential and all retained mail variants still need qualification |
| Confirmed broadcast, authored content, locale/recipient snapshots | BroadcastIntents + existing broadcast recipients/effects; confirmation/replay/cancel and changed HTTP-to-bulk flow | Full picker/premium-icon/photo confirmation parity and complete original-audience handoff are not established by a filtered local audience |
| Stock/price materialization and channel/photo delivery | Existing event/recipient owners, guarded image reader, shared effect protocol; price/stock PHP content comparisons | All stock transitions, aggregate projection repair and historical photo layouts remain to audit/qualify |
| Payment/balance/admin notices | OrderNotices, payment_proof.py, balance.py; existing receipt evidence | IDR instruction/proof/compensation/owner-alert/channel-proof and supplier-health families are not claimed complete; supplier-specific outcome E2E remains outstanding |
| Unknown no-blind-retry and sent projection no-resend | Existing shared effect protocol; no second ledger/outbox | Must cover the remaining families before P25 acceptance |
| Sales Proof retry/counter/rollback | Shared manual retry + guarded admin POST + explicit counter floor transfer + PHP rollback readers | Full retry-browser flow, retained v1/v2 public proof differential and real cache namespace handoff are not qualified |
| Critical-worker isolation/resource budget | Same opt-in eight-process/seven-connection profile; held SMTP versus navigation/receipt | Short disposable resource observations are not final whole-stack/production capacity evidence |

**P25 is not accepted, and its prerequisite for #708 is not fulfilled.** Remaining
implementation/qualification is not an operator-access blocker or an implicit
waiver. #708 has not been started. No commit, push, merge, issue mutation,
production access, live send, provider activation or CI/CD operation is authorized
or implied by this continuation.

Focused regression commands for this continuation:

```sh
cd candidate
.venv/bin/python -m unittest tests.test_notification_completion tests.test_runtime tests.test_notifications tests.test_waitlist tests.test_workers tests.test_receipt_render tests.test_receipt_transport tests.test_jobs tests.test_legacy_crypto tests.test_auth_core tests.test_auth_http tests.test_auth_providers
.venv/bin/python -m unittest tests.test_admin_operations tests.test_admin_pricing tests.test_order_projection tests.test_order_refunds
.venv/bin/python -m compileall -q lhie_candidate tests
cd ../src
php vendor/bin/phpunit tests/Feature/CustomerAuthMailDeliveryTest.php tests/Feature/GuestOrderNotificationTest.php
cd ..
git diff --check
```

Observed: **121 + 26 focused Python tests**, **21 PHP tests / 189 assertions**,
compilation and whitespace validation pass. These are focused behavior/rollback
checks, not PostgreSQL concurrency or full P25 acceptance by themselves. The
disposable runner supplies changed-flow PostgreSQL/HTTP/Redis/SMTP/browser
evidence separately in the existing manifest, with its source hashes and limits.

#### Current v4 composition evidence (2026-11-09)

The expanded `--notifications` runner now passes through the final browser and
drained counter-handoff checks. The retained single manifest identifies
`synthetic-p25-notifications-completion-v4`; all **122 candidate/harness source
hashes** and **39 PHP owner hashes** matched the working files after the run.
This replaces the stale v3 evidence for these tested slices, not for unimplemented
families or the remaining acceptance rows above.

The expiry failure was an incorrect test assumption about bounded discovery:
two sweeps do not necessarily rotate a keyset when other unnotified, currently
ineligible Orders occupy multiple pages. Random UUID order made that assumption
intermittently pass. The regression now deliberately places the expired Order
behind the cursor, verifies it is initially absent, and requires discovery within
one population-bounded rotation of seven-row pages. It neither resets the
production cursor nor directly creates the expected notification. No lifecycle,
financial or transport guard was changed to make this check pass.

Auth copy is additionally compared against the actual PHP `toMail()` output for
all four purposes: verification, password reset, browser-bound OTP and login
link. Subjects, greeting, paragraph placement, actions, expiry language and exact
route/query/fragment match (only the deliberately different disposable origins
are excluded). The oracle uses `[REDACTED]`, never a persisted/issued capability.
This establishes semantic copy/route parity, **not** full Laravel/Django HTML
layout equality or end-to-end grant/browser acceptance for every purpose.

That earlier smoke included the changed HTTP-confirmation-to-bulk flow, held
SMTP versus navigation/receipt progress, paid-outcome successor recovery, expiry
cleanup, manual proof photo/caption, historical fulfillment review, immutable IDR
posting-backed notices, stock channel photo, and the existing unknown/no-resend
checks. Redacted credential and milestone previews are pixel-identical to PHP
at 390px and 1280px. Counter evidence imports PHP floor **37**, exports candidate
floor **43** after workers drain, and verifies PHP next reservation **44**.

That earlier isolated-worker observation was 422,977,536 peak cgroup bytes,
10 peak PIDs, seven runtime DB connections and 0.433 average CPUs over 23.357s;
these include probe/harness overhead and are not production capacity evidence.
Remaining balance families, supplier-health/outcome qualification, historical
presentation/aggregate cases and full browser flows still prevent P25 acceptance.
**#708 remains unstarted and its P25 prerequisite remains unfulfilled.**

#### Source audit against the retained v4 manifest

The working branch is `migration/p25-completion-708`. The expanded v4 smoke
was rerun after the supplier-health scanner, balance families, historical
Sales Proof, stock projection, waitlist and Admin browser slices. Its 160
`source_sha256` and 71 `legacy_owner_sha256` entries matched the working
files immediately after that run.
Source identity does not prove untested acceptance. The canonical issue and
its comment confirm that administrative closure did not accept unfinished work.

| Requirement | Current boundary and observed evidence | Work required before acceptance |
| --- | --- | --- |
| Balance notices | Receipt and paid Owner notice remain posting-backed. Manual proof delivers the retained photo with a PHP caption comparison and repairs its sent marker without resend; competing recoverers preserve unknown. A PHP-created Owner alert matches the PHP message hash, synthetic configured-token, API-key and Bot-URL redaction, and a DB-backed custom-emoji entity including UTF-16 offsets; it sends through the fake relay. Competing recoverers create one missing handoff and retain an ambiguous outcome. The PHP compensation owner creates two synthetic immutable postings: an existing effect sends, and a suppressed handoff is discovered once and remains unknown. A PHP-created settled top-up supplies the public channel caption differential; its PNG sends through the fake relay. Two PostgreSQL recoverers create a single missing channel effect, which remains unknown after an ambiguous delivery. | Port instructions. Qualify channel-proof photo parity, QRIS and historical/disabled-channel variants; the PNG renderer is not PHP pixel parity. Qualify Owner-alert eligibility/status variants, additional configured-secret forms and width truncation, PHP compensation message/keyboard differential, additional locales and historical proof cases. |
| Supplier health and outcomes | `supplier_health.py` uses the existing scan, state, review and event tables and shared effect ledger; the opt-in notice worker admits the synthetic admin target only. A PHP scanner supplies the low-balance message oracle. Two PostgreSQL scanners serialize on the existing scan row and create one matching low episode; it sends via fake relay. Synthetic observations exercise low recovery/re-entry, transient-error threshold and deduplication, timed error recovery, stale observation and retry grace. A PHP-created ambiguous purchase produces one review and, after 15 minutes, a separate aged-hold alert. Retained PHP effects also send or remain unknown without resend. | Qualify episode closure/reopening and PHP differential for review/hold, no-target behavior, lock races against supplier finalization, bounded work for a fulfillment with many purchases, and real changed-flow outcomes for ambiguous, rejected, handoff and refund. Current review fixture is synthetic and does not prove provider transitions. |
| Audience and projection recovery | Broadcast and price materialization leave the global snapshot marker unset when filtering original recipients. Stock and broadcast aggregate repair require admission of every original recipient; stock also requires one resolved effect per recipient. PHP created zero-, one- and five-recipient stock snapshots and a five-recipient broadcast snapshot with their handoffs. In the multi-recipient repair cases, one effect reaches the fake relay; others receive simulated terminal outcomes or an explicit unknown. A PHP-registered stock event also completes its candidate snapshot and handoff over bounded two-recipient slices when every original recipient is admitted; the check reads at most 101 customers and snapshots and refuses global completion above the admission cap. Restricted admission blocks completion; restricted repair preserves an already completed broadcast. | Qualify all stock transitions, historical/photo variants, partial PHP cursor/channel changes and production-scale recipient counts. Simulated terminal outcomes are not evidence of real delivery to the other recipients. |
| Auth and credential mail | v4 compares semantic auth copy/routes and redacted full PHP/Django HTML preview pixels for magic link, verification, OTP and reset at 390 and 1280 pixels. Credential and milestone previews also match PHP pixels for one redacted variant each. | Qualify remaining credential/milestone variants. The auth comparison normalizes the synthetic action URL and is offline Chromium rendering, not a real email-client, device, or live authentication acceptance. |
| Public Sales Proof | v4 compares both v1 and v2 captions with PHP's formatter for two PHP-created paid Orders, including a long historical Product name. Chromium signs in through the real Admin form, rejects unauthenticated and CSRF-less retry, retries an exhausted definite failure through the actual order route and shared protocol, refuses an unknown effect, and observes one eventual fake-relay send. Admin HTML now uses `same-origin` referrer policy: `no-referrer` caused Chromium to submit `Origin: null` and fail CSRF. Drained counter handoff: PHP floor 37, candidate rollback floor 47, PHP next 48. | Qualify other historical/payment and photo variants. Browser Admin used a separately provisioned disposable role with the existing Admin grants; this is not production authorization evidence. |
| Waitlist | Chromium now submits the real CSRF-protected storefront form, follows the confirmation link delivered through local SMTP, and follows the unsubscribe link after a disposable restock fixture invokes the existing waitlist owner and sends the restock mail. The same manifest retains bounded PostgreSQL fan-out, concurrency and unknown/no-resend checks. | The restock is a test-controlled fixture, not the Admin stock UI; historical/custom-key data and actual email-client rendering remain outside this proof. |
| Worker isolation | v4 holds SMTP while navigation and receipt progress and records seven runtime connections and short cgroup samples. | Qualify the final family composition, relevant payment isolation, concurrency, bounded/fair recovery and resources after the missing families are integrated. |

The original #707 contract also requires disabled-channel readiness, eligible
recipients, locale/fallback/rate handling, masked Sales Proof and API fulfillment
eligibility. Retain those as explicit checks in the same harness and manifest;
the table does not waive them. No production history/key or live-send
qualification follows from synthetic evidence.

The missing-handoff compensation fixture stores the original chat ID with
surrounding spaces, as accepted by the PHP owner. The first recovery smoke
failed because discovery compared it without trimming; the retained smoke
passes with a normalized recipient snapshot and without changing the source.

Balance receipt and compensation buttons now use the existing DB-backed
navigation presentation. The focused regression verifies the Unicode fallback
after an explicit custom-emoji rejection and that the internal fallback field
is not sent to the relay. Unknown delivery remains outside that retry path.

For the retained supplier-health slice, `python
tools/migration_harness/smoke_checkout.py --notifications` passed with the
single manifest above. The focused candidate command for notification,
runtime, worker, waitlist and receipt-transport regressions passed 63 tests. The prior
expected red run stopped at the missing family import; no assertion or
delivery guard was weakened.

The latest synthetic resource sample was 440,664,064 peak cgroup bytes, 10 PIDs
and 0.447 average CPUs over 23.476s; it is not a production capacity measurement.

The later v4 run also covers the instruction handoff and the seven-day channel
proof discovery boundary:

| Requirement | Implementation | Evidence | Limit |
| --- | --- | --- | --- |
| Lost balance instruction handoff | `TopUpInstructions.recover` discovers admitted eligible top-ups with a bounded rotating keyset, locks Customer then TopUp, rechecks eligibility and uses the existing funding effect writer and canonical key/queue. | A PHP-created manual top-up without an effect is recovered concurrently by two PostgreSQL workers; exactly one effect is created. An ambiguous send remains unknown and is not rediscovered or resent. Retained manual JPEG instructions match PHP caption hashes in `id`, `ms`, and `en` and reach the fake relay. | Provider-image fetch and KlikQRIS/Sakurupiah end-to-end presentation, instruction cleanup and additional retry cases remain unqualified. Unknown provider values are not admitted by recovery. |
| Lost balance channel proof | Existing channel-proof discovery and its locked eligibility recheck enforce the PHP seven-day settlement window. | A PHP owner creates a synthetic settlement under an eight-day-old clock without rewriting financial history; recovery does not rediscover it. A recent lost handoff is recovered once under concurrency and an unknown send remains unknown. | The synthetic PNG is not full historical invoice pixel parity; retained older effects are not invalidated solely by the discovery window. |
| Supplier review message | The existing scanner/review state and effect ledger remain authoritative. | A separately PHP-created ambiguous review supplies a normalized message hash; the candidate matches it. A blocked supplier-row race rechecks current supplier observations after acquiring the lock. | This does not qualify every supplier-specific purchase, handoff and refund outcome or all scanner fan-out bounds. |
| Mail layouts | Django auth and credential templates use the existing presentation paths. | Four redacted auth purposes and two redacted credential variants match PHP preview pixels at 390 and 1280 pixels. | Offline Chromium previews normalize synthetic action URLs; they do not establish mail-client, device or every live auth flow acceptance. |

This run recorded seven runtime database connections, 452,710,400 peak cgroup
bytes, 10 peak PIDs and 0.461 average CPUs over 24.237 seconds. It is a short
disposable observation including harness overhead, not capacity evidence. The
synthetic boundary image is source-hash checked before the smoke and accepts
both JPEG and PNG photo fixtures; it remains fake-only.

Supplier outcome recovery now has a changed-flow PostgreSQL test using the
existing checkout, supplier-acquisition, Owner attestation, refund and manual
handoff owners with a separate disposable supplier role. It proves that an
ambiguous purchase is not repeated or refunded without debit evidence; a real
synthetic refund posts once, and a successor notice reaches the fake relay.
Another Order proves a manual handoff alone does not resolve the independent
supplier debit: only the subsequent Owner no-debit attestation allows a result
notice. No financial rows are edited to manufacture those outcomes.

The PHP differential exposes an intentional mismatch for the full-refund case:
after the lifecycle cancels the purchase and Order, PHP
`AdminPaidOrderOutcome` still reports the purchase uncertain and cannot deliver
the paid-outcome successor. The candidate reads the existing immutable Owner
no-debit evidence, explicitly attributes it to the Owner, and admits a resolved
successor only when it references the original unresolved notice. It reports
the actual refunded payment status. This is **not PHP parity** and must remain
visible in acceptance review; it is not permission to modify financial history
or retry an unknown original send. Other supplier outcomes and edge cases remain
to be qualified.
