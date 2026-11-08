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
| Credential Delivery Email | Authorized complete allocation, encrypted immutable recipient snapshot, fixed local SMTP sink, attempt-fenced finalization, retry only on proven non-acceptance | Plain-text convenience copy only; full existing HTML/text template parity and live SMTP qualification remain outstanding |
| Sales Proof | Separate secret-free eligibility/economics reader, buyer masking, API complete-allocation/review restrictions | No publication adapter is installed. Counter, caption/icon, scarcity, shopping button, invoice and historical-template parity remain outstanding |
| Authentication mail | P19 owner, grant, expiry, and encrypted work remain unchanged | Not folded into receipt/interaction workers; reuse P19 evidence only while relevant source/config are unchanged |

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
```

They run in distinct processes, each with a 60-second whole-batch timeout. They
are **not** part of `stack.py` or any interaction/payment/receipt handler. A slow
notification/mail command therefore cannot block those queues in-process. There
is no assertion that extra concurrently running processes fit the existing
2-CPU/2-GiB qualification; automatic isolated workers and the revised admitted
whole-stack process/connection budget still require integration and measurement.

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

1. Port pending/paid/expired/review Order notices, remaining payment/balance/admin
   notices and their durable source discovery, safe review/finalization adapters,
   and latest-version projection repair.
2. Complete confirmed Admin Broadcast creation, stock/price event audience
   materialization, historical/photo layouts and channel families, retaining
   exact copy, DB-owned icons and eligible recipient rules.
3. Port waitlist confirmation, opt-in/unsubscribe, bounded restock materialization
   and mail delivery. No waitlist is activated by this implementation.
4. Qualify credential email template parity and finish Sales Proof publication
   using the PHP presentation/numbering contracts; do not substitute a simplified
   message or claim eligibility-only code is publication parity.
5. Integrate automatically isolated notification/mail/bulk consumers without
   putting any of them on interaction/payment/receipt, revise the admitted budget,
   and prove the changed slow-mail/notification isolation boundary.
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
