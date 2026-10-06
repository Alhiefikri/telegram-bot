# External money receipt backfill, reservations, and writer cutover

Status: **Proposed**, 2026-10-05. Implementation contract for R2 ([#542](https://github.com/Alhiefikri/telegram-bot/issues/542)), within [#536](https://github.com/Alhiefikri/telegram-bot/issues/536) and under [ADR-0019](0019-idr-usdt-economic-sources-and-receipt-claims.md). This is neither an accepted architecture decision nor permission to activate funding; provider, custody, legal and staffing decisions remain with H0/H1/A1/H2. USDT funding stays disabled.

## Context

ADR-0019 defines the global receipt registry and requires R2 to backfill legacy money identities and cut every writer over before funding activation. R1 ([#541](https://github.com/Alhiefikri/telegram-bot/issues/541)) added the registry and claim seam without callers. Legacy writers still deduplicate through `payments.provider_reference`, `bsc_payment_transfer_evidence.tx_hash`, top-up event idempotency and ledger source uniqueness, so a transfer observed through two adapters could still fund two targets across lanes.

## Proposed contract

**One writer seam.** `ExternalMoneyReceiptWriter` derives every receipt identity only from persisted columns (BSC `(chain_id, normalized tx hash, log_index)` plus parent claim, provider reference with merchant-reference fallback, owner-attestation namespace). Binance claims prefer the persisted checkout receiver snapshot and fall back to the configured receiver only for a live-verified payment, matching the legacy verifier; the backfill never guesses from live config and reserves a legacy row without a snapshot as `missing_receiver_namespace`. Provider references that do not satisfy the canonical identity alphabet (spaces, separators, legacy lengths) are encoded deterministically as SHA-256, so the runtime claim and the backfill always produce the same canonical key for the same row. A claim runs inside the owner's short transaction without HTTP; `claimed`/`replayed` allows the money effect, `conflict`/`review` never does.

**Legacy reservations are the fail-closed denylist.** Evidence that cannot be normalized or claimed (missing receiver namespace, missing chain identity, registry conflict/review, owner-compensated unresolved money) is recorded in `external_money_receipt_reservations` with a reason, provider and sanitized details. Reservations are never discarded, never rewrite historical amount/provider/status, and block the cutover readiness gate until resolved: backfill-created reservations self-resolve when a later run claims the same legacy target, while runtime conflict/review reservations require an explicit audited operator resolution.

**Compensation reserves unresolved money instead of claiming a receipt.** Owner compensation credits money without independently verified attribution; it records a reservation for the compensated top-up. A later verified settlement receipt for that top-up resolves the reservation without a second credit, and the existing `Compensated` settlement branch keeps the identity identified.

**Owner-attested completions stay attestations.** A manual approval or reviewed BSC fulfillment without persisted chain/provider evidence claims a per-payment `owner_attestation` receipt. That records the operator decision under a dedicated namespace, cannot collide with chain/provider identities, and never fabricates provider evidence; the backfill reserves the same missing evidence on already settled legacy rows instead.

**Backfill is bounded, idempotent, resumable, and additive.** `balance:backfill-money-receipts` scans `binance_payments`, `bsc_payments`, `qris_payments`, `manual_payments`, `qris_top_ups` and `compensations` in chunks with a durable per-source cursor and cumulative counts. Dry runs read only. Re-runs replay existing receipts. Pending targets that cannot yet carry evidence are skipped and settle through the runtime writers when evidence arrives.

**Readiness is reported, not assumed.** `balance:money-receipt-cutover-report` publishes per-source completeness, collisions, open reservations, writer modes, mixed-version compatibility guidance and the disabled USDT state. `--require-ready` fails until every source completed a full scan with zero conflicts and no blocking reservation remains. Readiness is a cutover gate, not funding authorization.

## Consequences

All external money writers (Binance, BSC, KlikQRIS/Sakurupiah/Pakasir checkouts, manual approval, BSC review fulfillment, Store Balance top-ups and compensation) now depend on the registry seam; legacy uniqueness constraints remain as the mixed-version guard and are not removed in this release. Historical rows are untouched, so the registry can be rebuilt by re-running the backfill. Ambiguity stays visible and blocks activation rather than being silently repaired.

Rejected: dual-writing receipts without atomic ownership; fabricating claim evidence for ambiguous legacy identities; deleting or rewriting historical amounts/statuses; auto-resolving conflict reservations; treating a successful backfill as permission to enable USDT funding.
