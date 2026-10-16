# Balance operational controls: pause scopes, durable alerts and the post-restore gate

Status: **Proposed**, 2026-10-06. Implementation contract for O2 ([#558](https://github.com/Alhiefikri/telegram-bot/issues/558)), within [#536](https://github.com/Alhiefikri/telegram-bot/issues/536) and under [ADR-0019](0019-idr-usdt-economic-sources-and-receipt-claims.md), [ADR-0021](0021-usdt-exclusive-external-receivers.md) and [ADR-0022](0022-external-money-receipt-backfill-and-reservations.md). Operational controls only: this ADR does not activate funding, does not qualify custody and does not authorize a production restore.

## Context

U3 added Owner pauses for funding, spending and rails; U2 added bounded, sanitized reconciliation runs; U8 added reorg incidents and authorized recovery cases. Three operational gaps remained: a receipt **delivery** pause had no semantics at all, operational signals (drift, receipt collision, coverage, backlog) were only visible by reading commands, and a PostgreSQL restore had no gate that could prove external evidence was replayed before money mutations reopened.

## Proposed contract

**Pauses are scope-specific and never freeze money.** `customer_usdt_funding_pauses` gains the `delivery` scope next to `funding`, `spending`, `rail_binance_pay` and `rail_bsc_usdt`, managed by the same Owner-only audited manager. Funding/rail pauses block new targets; the spending pause blocks new USDT checkout debits; the delivery pause only defers claiming balance receipt families (`usdt.top_up_receipt`, `usdt.refund_receipt`, `balance.top_up_receipt`). A deferred effect keeps its row, `effect_key` and `attempts = 0`, and is delivered unchanged after resume. No pause ever stops reconciliation, settlement, refund, reversal, alerting or the creation of the durable effect row inside the money transaction.

**Metrics are bounded by construction.** `balance_operation_events` stores one append-only row per observed operation with `metric`, `outcome` (both closed `BalanceOperationMetric` enums), an optional fixed duration bucket and a bounded integer value. UPDATE is rejected for every role; DELETE is reserved for the bounded retention prune. Nothing derived from customer identity, Telegram ids, addresses, transaction hashes or credentials can be stored, and metrics are written after the money transaction ends, so a metrics failure can never change money. Backlog, drift, coverage, conflict and control state are read-time queries against durable evidence, not cached counters.

**Alerts are durable and deduplicated by episode.** `ops:balance-alerts` evaluates closed alert keys against durable evidence and reconciles `balance_operations_alert_episodes`. Delivery reuses the Telegram Outbound Effect ledger (`admin.balance_operations_alert`): one effect per episode + fingerprint change, one resolution effect per episode, recovered by `telegram:recover-outbound` after Redis loss. Messages carry counts, run ids and scope names only. A missing admin chat target keeps the episode open (counted as delivery pending) instead of dropping it.

**A restore opens a gate that only evidence can close.** `balance_restore_gates` is a singleton recording the operator's declared restore window (`usdt-balance:restore-open`). While open, `CustomerUsdtFundingGate` refuses new funding and spending with `restore_gate_open`; settlement, refund, reversal and reconciliation keep running. `usdt-balance:restore-replay` re-inspects every non-terminal USDT funding target against authoritative chain evidence, optionally re-runs the receiver scan, and quarantines non-terminal balance deliveries as `unknown` with code `restore_ambiguous_delivery` so a message that may already have been sent is never blindly resent. `usdt-balance:restore-close` refuses until a replay completed after the gate opened **and** a full-scope reconciliation run recorded after that replay is consistent with zero account findings, zero receipt findings and zero unexplained variance.

**Freezes are cleared deliberately, never automatically.** `usdt-balance:unfreeze` locks the account row, re-inspects it and clears only a drift freeze whose drift is gone; a reorg freeze stays until the loss is resolved by the authorized U8 process. The command never writes a ledger entry, projection balance or receipt.

**Rollback stays additive.** Every table in this control plane is additive; rollback is an image rollback plus a pause, never a migration rollback, and never deletes the receipt registry, ledger, claims or resolutions. Production stays on GitHub Actions `main` → VPS.

## Consequences

Operators get auditable pause semantics, durable alerting on the exact evidence U2/U8 already produce, and a restore procedure whose reopening condition is machine-checked. The control plane is deliberately powerless over money: it can only block new exposure, defer messages, quarantine ambiguous deliveries and freeze/unfreeze accounts under proof.

Rejected: a single global "stop everything" switch (it would stall reconciliation of money already received); Redis-based alert dedupe (volatile, no manual review); auto-resending deliveries after a restore (duplicate customer messages); automatically clearing freezes or repairing drift (silent money rewriting); counting or labelling metrics with customer identity or chain hashes (unbounded cardinality and PII exposure); treating the drill's fixture custody observation as coverage proof.

## Limits

The gate covers Customer USDT funding and spending; IDR Store Balance operations are paused through the existing Store Balance controls. The fixture custody adapter used by the disposable drill is test evidence, never a qualification: H1 custody, provider permission, legal review and production activation remain open. SQLite tests do not prove PostgreSQL locking or restore behavior; the disposable PostgreSQL lane does.

Implementation extension (#620, canonical [#617 section 4.2](https://github.com/Alhiefikri/telegram-bot/issues/617)):
all new internal balance checkouts now share the spending-policy and restore
barriers, including explicit IDR and automatic fallback to IDR. This supersedes
the USDT-only new-checkout scope above; it does not widen funding/rail pauses or
block settlement/refund/reversal. Checkout holds policy and restore shared row
locks through commit against their exclusive writers. Missing restore state
fails closed for checkout. Original-currency refunds retain their existing
account-first graph and never consult current preference.
