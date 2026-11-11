# P25 notification delivery — #707

**Status: #707 acceptance is complete on this checkout; production and cutover qualification remain separate.**
GitHub #707 was previously closed as `NOT_PLANNED` without acceptance. This local evidence covers its issue-level criteria against the current checkout; it does not qualify actual history, production, or cutover.
No commit, push, merge, deployment, provider activation, live-recipient delivery, or CI/CD run occurred.

## Ownership and implemented flows

`candidate/lhie_candidate/notifications/` extends the existing
`telegram_outbound_effects` and `order_notification_events` authorities. There is
no second outbox, new broker, financial writer or startup schema/grant installer.
`families.py` is the closed Telegram family registry; SMTP waitlist effects have
a separate method/claim boundary. The receipt worker cannot claim notification
families, and notification workers cannot claim credential receipts.

| Flow | Local implementation | Retained boundary |
| --- | --- | --- |
| Order/admin lifecycle | Pending, paid, expired, fulfillment review and manual proof; durable discovery, immutable snapshots, latest-sent projection repair and instruction cleanup | Paid copy waits for authoritative receipt/supplier outcome; late Binance proof has no approval buttons |
| Balance/admin operations | IDR instructions, receipts, payment proof, paid Owner notice, compensation, adjustment, channel proof, supplier health episodes, receiver-change audit and daily statistics | Existing USDT/refund/balance-operations families remain receipt-owned; no settlement authority is added |
| Admin Broadcast | Authenticated preview/confirm form, server-owned draft and idempotent broadcast key, bounded eligible audience, verbatim text/photo snapshots and aggregate repair | A test-only audience is never labelled the complete original audience |
| Stock and price | Bounded source-to-audience discovery, customer/channel families, historical/photo payloads, snapshotted prices, locale, icons and buttons | Missing media/identity defers delivery; money is formatted exactly, never converted or recomputed from current prices |
| Sales Proof | Existing numbering, masked buyer, safe captions, scarcity, invoice and shopping action; complete API allocation/no-open-review gate | No credential is published; retained Admin-authorized synthetic proof stays separate and creates no Order, Payment or Sale |
| Waitlist | Subscribe, signed single-use/expiring confirmation, unsubscribe, bounded restock materialization, encrypted immutable mail snapshot and multipart sink delivery | No mail before consent except confirmation; unknown SMTP is not automatically resent |
| Credential and guest mail | Existing HTML/text presentation, authorized complete credential allocation, durable guest milestones, immutable encrypted recipient snapshots | Credential bodies are rendered only in memory; no fallback to a changed Order/Customer address |
| Manual delivery review | Telegram receipt resend revalidates its source and allocation before creating a new group; SMTP review records stored evidence or queues an explicit asynchronous retry | Unknown mail is not automatically resent; marking sent records evidence in the audit; auth retry retains expiry, eligibility and recipient snapshot |
| Authentication mail | Dedicated isolated consumer using P19's existing encrypted work, grant expiry and single-use rules | No new authentication authority or alternate grant lifecycle |

## Safety and presentation contracts

- Empty recipient admission disables materialization, claims, lease sweeps and
  projections. Explicit fake-only P01 composition admits synthetic chat `1` and
  reserved `.invalid` mailboxes. Dataset names are not authorization. Genuine
  bot identity uses the existing `getMe` boundary, never a username ENV setting.
- SMTP uses an explicitly constructed fixed loopback backend with a five-second
  timeout, never Django's configurable console/file/log default backend.
  Provider exception text and rendered credentials are not persisted or logged.
- Definite non-acceptance may retry the same frozen snapshot. Missing ACK,
  ambiguous transport, interrupted SMTP or expired effect lease retains an
  unknown outcome. Positive Telegram message IDs, token/attempt fencing and
  the shared protocol remain mandatory. A definite custom-emoji rejection alone
  permits a Unicode fallback; uncertainty never does.
- Broadcast/stock/price share the existing rolling 25-per-second Redis budget.
  Failed rate admission defers before claiming; a 429 ends the current batch.
  PostgreSQL is durable authority; Redis wakeups are disposable hints.
- Recovery and projection scans are bounded and rotate their cursors. Sent
  projection repair never performs transport. No notification adapter changes
  money, allocated stock, authentication grants or credential identity.
- New Admin surfaces reuse the existing layout and authentication/CSRF policy.
  Broadcast progress has a keyboard-focusable scroll region. P55 browser measured
  a 390px viewport/document, a 324px progress region and a 576px table. Waitlist
  keyboard navigation focused the email field with a visible 3px outline; its
  mobile form remained within a 390px document.
- The P55 browser run used the existing light theme; it did not requalify dark
  theme contrast. At 390px the inherited Admin navigation labels wrap, while
  the document remains 390px wide. That separate mobile parity gate remains
  open in P27 (#709).
- Admin native forms retain `Referrer-Policy: same-origin` so Chromium supplies
  a CSRF-valid origin without disabling CSRF protection.

## Explicit provisioning and isolated composition

Apply the checked-in grants only to an admitted disposable database after the
existing navigation/checkout/receipt/auth grants. Runtime verifies exact column
privileges; startup installs none. Broadcast creation, waitlist and notification
projection writes are explicitly scoped. These grants do not authorize rollout.

[ADR-0032](../../../adr/0032-python-bounded-worker-supervision.md) now defines
seven isolated consumers: `interaction`, `payment`, `receipt`, `notifications`,
`bulk`, `mail` and `auth-mail`. Together with HTTP and the connection-free
supervisor, that is **nine runtime processes and eight runtime DB connections**.
The role limit is ten, leaving two diagnostic connections. All remain within
the existing application allocation **1.25 CPU / 1216 MiB** and whole-stack
ceiling **2 CPU / 2 GiB**; these are limits, not extra per-worker allocations.
Starting HTTP alone or importing a module does not activate consumers.

Each consumer retains bounded execution/restart/drain and durable discovery.
SMTP, notification rendering and bulk delivery never run inside interaction,
payment or receipt consumers. Slow-delivery qualification starts the actual
supervisor and holds real transport for eight seconds, then seeds independent
durable work after that hold begins without Redis wakeups. Other eligible
queues must finish before the held transport completes. Authentication mail
continues to enforce its own grant eligibility.

## GitHub #707 acceptance mapping

GitHub #707 has four issue-level acceptance criteria. This section maps each
criterion to the implementation and the current P55 evidence. The comparator
uses output generated from the checked-out Laravel source. These deterministic
fixtures establish source-level parity for their cases, not actual-history
coverage.

| Criterion | Implementation | P55 evidence |
| --- | --- | --- |
| AC1. Credential-mail retries use the recipient snapshot; authentication grant expiry and single-use rules remain; transport secrets do not enter logs. | `notifications/mail.py`, `auth/mail.py`, `admin/reviews.py`, and `templates/admin/mail_delivery_review.html`. | PostgreSQL checks `snapshot_mail_sink_retry`, `auth_mail_presentation`, and `mail_recovery` passed. The auth retry retained its encrypted recipient/payload snapshot and grant eligibility, then reached the local SMTP sink; terminal plaintext was erased. The focused `test_auth_mail_transport_diagnostics_never_enter_logs` injected a synthetic private SMTP diagnostic under `assertNoLogs`. |
| AC2. Admin-authored Broadcast content is preserved; Sales Proof keeps API fulfillment eligibility and masked buyer data. | `notifications/broadcast.py`, `notifications/publishing.py`, `notifications/proof.py`, and `notifications/proof_publication.py`. | Browser preview exactly retained authored markup and Unicode; a product button and a photo-only empty-caption broadcast were confirmed. The PHP comparator checked Sales Proof API gates, economic snapshot, buyer mask, caption/photo button wire, and rendered image dimensions/difference. PostgreSQL publication and no-economic-write checks passed. |
| AC3. Unknown sends require review rather than blind resend; sent projections repair without another transport across families. | Existing effect/event ledgers, bounded recovery in `notifications/service.py`, receipt protocol, and the mail review operations. | P55 assertions `unknown_no_resend`, `sent_projection_no_resend`, and `manual_late_proof_unknown_no_resend` passed. The SMTP-sink acceptance became `sent/operator_marked_sent` only after the operator supplied its stored evidence reference and attestation; the audit retained that reference and the flow did not resend. Auth retry required its duplicate-risk checkbox and confirmation. |
| AC4. Slow notification/mail work does not hold interaction, payment, or receipt work; full-audience delivery is test-only locally. | Seven isolated consumers in the existing worker supervisor; recipient admission remains `TestRecipients`. | The P55 isolation run held SMTP and notification transports for eight seconds while independent work completed. Only synthetic chat `1` and reserved `.invalid` mailboxes were admitted. The local source audience was not represented as fully processed. |

## PHP-Python parity

`candidate/tests/test_notifications_php_parity.py` compares candidate payloads
with fixtures produced by the PHP comparator pinned in the P55 manifest. P55
passed these source-derived categories:

| Surface | Compared behavior |
| --- | --- |
| Lifecycle, admin, and balance notices | Exact Telegram message/photo request fields, authored text, localized receipts (`id`, `en`, `ms`), owner/proof eligibility, buttons, icon slots/fallbacks, QR bytes, and sent outcomes. |
| Broadcast, stock, and price | Exact text/photo/caption/reply-markup wire for customer and channel recipients; supported locales; product purchase buttons; stock/price snapshots; photo-only empty-caption behavior. |
| Sales Proof | PostgreSQL-backed API and non-API eligibility, latest-paid/payment chronology, complete allocation and no open review, unchanged economics, buyer masking, caption/button wire and invoice image. PHP/Python images have equal dimensions, mean channel difference below 6, and fewer than 5% of pixels with grayscale difference above 48. |
| Guest, credential, and waitlist email | Subject, normalized visible text, HTML visible text/link targets, credential instructions, guest milestones, waitlist confirmation and restock mail. This compares visible HTML signatures, not byte-identical markup serialization. |
| Recovery and delivery outcome | Immutable recipient/content snapshots, durable discovery, expired/unknown handling, explicit retry, single-use auth grants, SMTP-sink acceptance, terminal payload erasure, and projection repair without transport. |

The PHP source comparator does not provide production history, live inbox or
real Telegram rendering evidence. `NotificationTransport` permits Unicode
fallback only after a definite custom-emoji rejection; the focused tests cover
ambiguous/missing acknowledgements without fallback. Storefront/admin icon
presentation continues to use the existing database-backed slots and Unicode
fallbacks.

## Verification run and evidence

The final disposable integration/browser run used the current application source:

```sh
python3 tools/migration_harness/smoke_checkout.py --notifications-browser
```

`.local/migration-p25/verification.json` records `status: passed`, dataset
`synthetic-p25-notifications-v1`, Python 3.13.18, Django 5.2.18, and PostgreSQL
16.15. PostgreSQL, Redis, fake Telegram relay, fixed loopback SMTP sink, and the
actual WSGI application were used. The manifest pins `base_revision`
`3b26e50d214ef41a37155253f28c42105825a31f`,
`migration_sources_sha256`
`9ab094a89703f2fa3a076440a14c83d00b9933b71d7f5d04250c594ddbdedf18`,
the checked-out PHP comparator image/source/vendor/lock hashes, dependency
image, and schema/boundary identities. The corrected mail-review source hashes
are `operations_views.py`:
`1fe657b7a6db0f2e5152033f7e84876a984553004f7b5e829789dd2c2f0339ad` and
`mail_delivery_review.html`:
`2ae41b940c773930681fc4a52a1fe4130a7894f25fa6a205b75d6537b0d0fe75`.

The P55 manifest records notification claim fencing, immutable lifecycle and
mail snapshots, paid-outcome truth, double opt-in, unsubscribe, bounded
materialization, unknown-without-resend, sent-projection repair, fake-relay
outcomes, Sales Proof publication, unchanged financial history, and the
mail-review SMTP-sink/auth-retry scenario as passed. The browser-specific
observations are in `.local/migration-p25/browser-evidence.json`.
The final criterion map, post-manifest regression results and current source
hashes are recorded in `.local/migration-p25/issue-707-acceptance.json`.

Post-integration regressions were rerun against this checkout:

```sh
cd candidate
.venv/bin/python -m unittest tests.test_notifications tests.test_notifications_balance tests.test_notifications_lifecycle tests.test_waitlist tests.test_proof_publication tests.test_notification_workers tests.test_workers tests.test_runtime tests.test_receipt_render tests.test_receipt_transport tests.test_jobs tests.test_admin_operations tests.test_order_projection tests.test_order_refunds
cd ..
python3 -m unittest discover -s tests/migration_harness -p test_navigation_boundary.py
```

The focused candidate command passed **138 tests**. The relay-boundary command
passed **13 tests**. The focused suite includes parity-aligned photo-only and
Sales Proof eligibility fixtures, the success-redirect regression for both
mail-review actions, and the no-transport-secret-log assertion.

P55 isolation observations, from an eight-second injected transport hold:

| Held transport | Interaction | Payment | Receipt | Other independent consumers | App cgroup observation |
| --- | ---: | ---: | ---: | --- | --- |
| SMTP | 2.000 s | 2.280 s | 3.286 s | Notifications 3.286 s; bulk 6.639 s; auth-mail 6.640 s | 472,064,000-byte peak; 0.553 average CPU over 23.786 s |
| Notifications | 1.033 s | 6.000 s | 6.000 s | Bulk 6.001 s; mail 6.002 s; auth-mail 6.002 s | 465,997,824-byte peak; 0.693 average CPU over 16.980 s |

The manifest reports nine runtime processes, nine threads, and eight database
connections. These short application-cgroup observations are not whole-stack
capacity limits or production percentiles.

The browser exercised sold-out waitlist submission, exact authored Broadcast
preview/confirm, photo-only confirmation, mobile progress scrolling, and both
manual mail review actions. At 390px the document remained 390px wide; the
Broadcast progress region was 324px wide with a 576px scrollable table. The
keyboard-focused waitlist email input had a visible 3px outline. The
inherited Admin navigation labels wrap at this width; that full visual-parity
gate belongs to open P27 (#709), not #707. P55 used the existing light theme
and did not requalify dark-theme contrast.

## Qualification limits and separate gates

All P55 evidence is local and synthetic. The SMTP server discards messages;
the Telegram relay is fake. No live recipient, provider, production database,
Testing Bot, device, actual-history dataset, production p95, or restore
qualification was used. No production scheduler, deployment, CI/CD, or
provider activation was performed. The corrected files and local artifacts
remain uncommitted and were not pushed.

These limits do not block #707's local issue-level acceptance. They remain
separate from the migration/cutover gates:

| Issue | Current state | Boundary |
| --- | --- | --- |
| #706, #695, #696, #698 | Closed as completed | #707 native blockers; their closure is not evidence for P25. |
| #693 H1 | Closed as completed by the owner | Decision to continue the experiment, not a performance pass or production approval. |
| #679 | Open | Parent migration acceptance and outcome. |
| #684 HD | Open, human gate | Actual data/schema/key coverage and backup restoration before #710. |
| #708 P26 | Open | Scheduler, retention, metrics, health, and Python-only operations. |
| #709 P27 | Open | Full visual/browser parity and public URL/API documentation, including the observed inherited mobile navigation wrapping. |
| #710 P28 | Open | Full correctness/security/recovery/resource gate; requires HD. |
| #712 H2, #713 H3 | Open, human gates | Readiness decision, then separately authorized cutover/observation/retirement. |

No parent, human gate, or separate migration gate is closed by P25 evidence.
The final GitHub comment for #707 maps the four issue criteria to the P55
manifest, browser evidence, focused regressions, and explicit limits above.

## Framework references

The implementation follows Django 5.2.18's existing application patterns:

- [Explicit SMTP connections and MIME mail](https://github.com/django/django/blob/5.2.18/docs/topics/email.txt).
- [Atomic transactions and on-commit semantics](https://github.com/django/django/blob/5.2.18/docs/topics/db/transactions.md).
- [Row locking and bounded skip-locked consumption](https://github.com/django/django/blob/5.2.18/docs/ref/models/querysets.md).

External sends run outside transactions; committed source/ledger rows, not
callbacks or wakeups, remain the delivery authority.
