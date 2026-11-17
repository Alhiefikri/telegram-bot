# Database growth and retention safety

Status: local safety fixes and a proposed design, 12 November 2026. No production access, migration, deployment, CI/CD, purge or restore qualification is authorized by this work. Issue #684 remains open as an operational prerequisite; this report is not restore evidence.

## Verified source behavior

The supplied production observations (11.24 GB database; 5.45 GB outbound effects; 4.91 GB stock recipients; 61,840 failed jobs; mostly expired SQL cache; root filesystem 93% full) are historical input, not measurements repeated here. The 0.2% block sample cannot establish exact purge eligibility or savings.

Current source confirms:

- `TelegramProductNotificationService::stockAddedDeliveryBatch` and `productSoldOutDeliveryBatch` page all Customers with non-null Telegram IDs, filtering blank identifiers before rendering. There is no opt-in or subscription filter. Store Channel delivery is additional when configured.
- `StockNotificationDeliveryPipeline::isNotifiable` accepts positive adjustments, qualifying availability transitions and sold-out negative adjustments/sales, not every sale. Each event can therefore expand into the entire Telegram Customer audience.
- Recipients persist rendered `text`, `reply_markup`, locale, destination and optional photo path. Customer text often repeats within an event/locale; content can differ across materialization batches because product/settings are reloaded.
- `TelegramOutboundEffectService::recordStockNotificationRecipient` writes a separate delivery ledger row per recipient, but **does not duplicate recipient text/keyboard into the effect**. The two large tables represent fan-out payloads plus delivery state/indexes, not two identical payload stores.
- Unique event/movement, event/destination and effect-key constraints make repeat materialization idempotent while identities survive. Retry updates the existing effect. This audit does not establish unbounded retry duplication.
- Recovery selects recipients with missing effects and reconstructs effects from recipient projections. Unknown delivery is not encoded as a dedicated terminal recipient marker. Deleting an unknown effect can therefore reconstruct it as pending. Deleting an event can also reopen source-based materialization; deleting recipient identities can change subsequent effect keys. Independent age-based deletion is unsafe.

Audience, opt-in, suppression and coalescing are unchanged. Any future proposal must specify recipients/event types excluded, customer-visible timing/ordering changes and treatment of already-committed obligations, and receive explicit approval before implementation.

## Local safety changes

### Classifier

Stock `materialization_cursor` is a **Customer ID**, returned from the last Customer in a batch. Price `recipient_effect_cursor` is a **recipient ID**. The classifier now compares each against its own namespace. A channel-only audience with no Customer ID needs no Customer cursor advancement. A null snapshot marker, a cursor behind an observed Customer, missing effects, pending/sending, unknown/manual review and dependency truncation remain protected. A complete snapshot marker does not imply effect materialization is complete.

Every notification root still has `delete_candidates=0` and `archive_review_candidates=0`; fixing intermediate terminal classification grants no deletion authority.

### Operational cleaner

Payment event `processed_at` is not a lifecycle authority: provider-create evidence and pending reconciliation can have null values, while processed callbacks can still support late-money review, cancellation, recovery or canonical replay/dedupe. There is no complete closure/reference proof. All payment events are retained.

Source evidence: `PakasirPaymentEventLogger::{logProviderCreate,logProviderCancel,coalesceCallbackEvent,recordDuplicateDelivery}`, `KlikQrisPaymentEventLogger::logCanonical`, `KlikQrisNativeQrisService::hasProviderCreateEvent`, and the success-indication branches of Sakurupiah/WijayaPay reconciliation queries. These consume event identity or observations after initial receipt; a processing timestamp does not certify archival closure.

Audit rows include financial decisions, Order review and credential-access evidence. An age-only or subject/action-only deletion rule cannot prove those obligations closed. All audit rows are retained. This intentionally trades continued growth of these smaller tables for preservation of evidence; no ad-hoc catalog allowlist is introduced.

Audit readers include `AdminBalanceReportReader::auditQuery`, `AdminDashboardSalesData::credentialOpenTimestamps` and `AdminOrderDetailController::auditEntries`.

Only Telegram updates whose creation **and processing** predate the configured cutoff remain eligible, with no processing claim, scheduled retry or terminal-failure marker. Notification events, recipients, effects, failed jobs and SQL cache are not deleted by this cleaner.

`ops:cleanup-retained-data` defaults to `--chunk=500 --max-rows=5000 --max-seconds=30`. Valid ranges are 1–1000, 1–100000 and 1–300 respectively. The row budget charges selected candidates even if they become ineligible before deletion, so races cannot create an unbounded loop. Eligibility is rechecked at DELETE. The monotonic deadline spans every batch, including with frozen application time. PostgreSQL uses transaction-local statement timeout ≤1000 ms and lock timeout ≤100 ms, each reduced to remaining time; failed batches roll back and inherited timeout settings are restored. These SQL timeouts bound database work, not arbitrary process scheduling/network outages or framework startup.

Exit 0 means the bounded invocation succeeded, **not** that the backlog is empty: hitting a budget warns that eligible rows may remain without an extra scan. Invalid limits or SQL failure exit 1; earlier committed batches stay committed. Re-running is safe for remaining eligible updates, but is not permission for an unbounded operator loop. The schedule stays daily with its existing overlap protection.

## Proposed payload reduction (not implemented)

Use an immutable, event-scoped **content-addressed payload snapshot**, shared only when the actual rendered bytes match. Snapshot fields: rendered text, keyboard, optional photo reference, transport/render schema version and a canonical SHA-256 digest. Destination, Customer identity, locale snapshot, attempts and delivery outcome remain per recipient/effect. Do not regenerate old text from current Product or translations.

1. Add payload storage and nullable recipient reference additively, with a restrictive FK. Key uniqueness by event plus digest/version, not globally across unrelated events. Compare canonical bytes before reuse; a digest collision must fail closed rather than substitute content. Include keyboard/photo differences and rendering version, not locale alone.
2. Keep the current recipient snapshot fallback while readers are introduced. New materialization writes the payload and recipient identity atomically under the existing movement/event lock; the same event/destination dedupe identity remains authoritative. Payload insertion must not add a second recipient or a second ledger protocol.
3. Preserve exact rendered content per batch. No claim that an event has one immutable content variant today; content-addressing must accommodate changes between batches, locales, tier promotions and the Store Channel.
4. Explicit resends retain their original referenced content and version. Review, original send, retry and resend readers must migrate together; missing/corrupt referenced content is a retained error, never live re-rendering.
5. Backfill only bounded keyset batches after capacity/backup approval. Do not rewrite either large table, bulk-update JSON, build large indexes or compact on the nearly-full VPS. Measure WAL, replica/backup space and lock duration in a disposable production-shaped restore first. New-write dedup can precede any historical backfill.
6. Remove legacy payload columns/dual readers only after every row/caller is migrated and rollback no longer needs them. An old reader cannot be rolled back onto reference-only rows; rollback requires rehydrating exact bytes from retained snapshots or a compatible forward fix.

This saves repeated recipient payload, not the per-recipient ledger cardinality. No savings percentage is claimed without a full bounded measurement of distinct actual payloads. Failed-job and expired-cache housekeeping need separate approved, lifecycle-aware bounded work; no queue flush or global retry is proposed.

## Proposed replay-safe retention (not implemented)

The first deployable reduction should retain compact identity/state records and remove only bulky payload after archive qualification, rather than delete dedupe roots. A future closure record must be durable and authoritative before removing anything recovery can recreate.

Required closure identity and evidence:

- Stable movement/event identity and event/destination membership; original recipient IDs and every effect key/version/group/supersedes edge remain reserved.
- Complete audience and effect materialization, terminal delivery outcomes, projection reconciliation, latest activity and a versioned closure fingerprint.
- No active lease/claim, retry, paused obligation, missing child/source, unknown/manual-review, operator hold, untraced financial/credential reference or unresolved supplier episode. The shared ledger policy owns failure classification; exhausted attempts alone do not prove no manual resend/reference remains.
- Qualified immutable encrypted archive manifest, checksums, encryption-key availability and independently demonstrated isolated restore of the exact closure. A copy or archive checksum alone is not restore proof.

Future mutation protocol:

1. Discover closure with bounded scalar/indexed reads. An oversized or unknown graph is protected, not partially purged. No estimate/sample becomes an eligibility list.
2. Serialize against the same source/event locks used by materializers and every operator resend/review writer. Revalidate graph/version, leases, holds, archive evidence and activity inside that transaction. Introduce a durable sealed closure/tombstone consulted by **all** register, snapshot, missing-effect recovery, source discovery, direct job replay and explicit resend entry points before any content deletion.
3. Atomically seal the closure and reserve original identities. A stale worker must fail a version/lease fence; FK and uniqueness claims must survive. Delayed jobs become harmless no-ops through the normal seam, never rebuild pending effects from incomplete projections. Archived unknown/manual-review is not eligible in the initial policy.
4. Remove payload only in bounded transactions under the sealed identity. Keep compact ledger outcomes/dedupe/source anchors. Any later whole-row removal requires an equivalent indexed identity reservation and migration of every reference, not independent table cleanup.
5. Archive restore must restore tombstones/identity reservations before enabling workers. Replay cannot send old deliveries automatically. Operator-requested resend after archive needs a separate explicit version, duplicate-risk acknowledgement, restored immutable payload and the existing ledger protocol; restoration never reruns a financial transaction.

Gate tests before implementing purge: process death before/after sealing and between payload batches; delayed source/recipient jobs; parallel materialization/review/resend; unknown/expired lease; definite retry; same-key replay after retention; all restore orderings; missing archive/key/checksum; dependency-cap exhaustion. Until those contracts and #684 restore gates are satisfied, notification deletion remains NO-GO.

## Rollout and rollback

1. Review the local patch and local PostgreSQL evidence below. No schema migration or audience change accompanies this patch. Do not treat merging code as authorization for production deletion.
2. Before any production deletion, prove encrypted backup **and** an isolated restore with keys, identity reservations and outbound workers disabled; approve RPO/RTO and the #684 gate explicitly. Do not create a dump on the nearly-full root filesystem as an incidental step.
3. With separate authorization, deploy only the safety patch through the existing GitHub Actions/SSH process. Keep existing scheduler cadence; start with low cleanup budgets and one operator-observed invocation. Inspect sanitized deletion counts and timeout/budget status; do not automatically loop partial runs.
4. This patch cannot reclaim the reported 10+ GB notification footprint. Retention of payment/audit evidence can increase their growth versus the old unsafe cleaner. Select payload-normalization design separately, provision disk headroom, qualify restore, then stage new-write changes before historical backfill.
5. On cleaner trouble, disable that scheduled command or retain the new holds while fixing forward. **Do not restore the old age-only payment/audit cleaner.** Classifier rollback may mislabel terminality but must retain zero delete candidates. There is no down migration, data rewrite or Telegram replay needed for this patch.
6. Future payload/retention rollout must specify compatible readers and irreversible points. Stop writers before restoring data, restore identities before workers, and never use `VACUUM FULL`, `REINDEX`, mass DELETE, `TRUNCATE`, `queue:flush` or global retry as emergency space management here. Ordinary small deletes only make reusable database space; they do not guarantee filesystem shrinkage.

## Local verification

All data below is synthetic. PostgreSQL ran in a separate `postgres:16-alpine` container with tmpfs storage, a unique loopback port and database `telegram_retention_test`. No existing local database, production connection, merchant API, Telegram service or Redis instance was used.

| Check | Observed result |
| --- | --- |
| PostgreSQL: `DataRetentionTest`, `ProductInventoryRaceTest`, `TelegramOutboundEffectLedgerProtocolTest` via `phpunit.postgres.xml` | 82 tests, 619 assertions passed |
| SQLite: `NotificationRetentionDryRunTest` | 17 tests, 556 assertions passed |
| PostgreSQL: final row/time-budget warning regressions | 3 tests, 12 assertions passed |
| Focused Pint check on both changed PHP files and both test files | Passed |
| PHP language-server diagnostics on both changed implementation files | No diagnostics |

The PostgreSQL command regressions cover cutoff boundaries, active/retry/terminal processing protection, null and non-null payment processing markers, open manual review, preserved canonical callback replay identity, capped final batch, shared deadline, delete-time predicate recheck, a real five-second trigger cancelled with SQLSTATE `57014`, and actual conflicting table locks cancelled with `55P03`. Rows survive cancelled batches and inherited timeout settings are restored. No full-suite or performance-at-production-scale claim is made.

A disposable harness ran in **separate PHP processes**, retaining PostgreSQL state between invocations:

1. Committed a movement/event without running its worker; observed zero recipients.
2. Restarted, materialized exactly two recipients/effects with Customer IDs 9001/9002, abandoned one claimed lease, and gave the other a definite synthetic HTTP 429 failure.
3. Restarted again: expired lease became `unknown`, claim was rejected, and the failed effect retried to `sent`. Exactly one synthetic HTTP request occurred; unknown was not sent.
4. Ran retention dry-run (`delete_candidates=0`) and the actual cleaner on old synthetic data, deleting one completed Telegram update. Replayed materialization/delivery: the same two effect identities and states survived, recipient count remained two, and no HTTP request occurred.
5. Inside a transaction, removed **one synthetic unknown effect only**, then replayed materialization. A different effect ID appeared as `pending`, demonstrating the independent-purge resurrection hazard. Rolled back the probe and verified the original `unknown` remained. This is evidence against independent deletion, not an enabled purge feature.
6. Ran the actual classifier against committed PostgreSQL fixtures with divergent Customer/recipient ID namespaces, a lagging Customer cursor and a null snapshot marker. The completed covered cursor was not falsely active; lagging/incomplete cases remained active; all had zero delete candidates.

The harness was removed and its disposable container stopped after verification. Snapshot normalization, tombstones, archive restore and destructive notification retention are **designs only**, not exercised implementations. Replay proof here is after the currently supported non-deleting retention scan and bounded Telegram-update cleanup, not after a future notification purge.
