# IDR/USDT economic sources, receipt claims, and atomic postings

Status: **Proposed**, 2026-10-04. Technical contract for [A0 #537](https://github.com/Alhiefikri/telegram-bot/issues/537), within [#536](https://github.com/Alhiefikri/telegram-bot/issues/536). This is neither an accepted architecture decision nor permission to activate funding. Provider, custody, legal and staffing decisions remain with H0/H1/A1/H2.

## Context

[The source audit and lock graph](../balance-a0-baseline.md) records actual writers, nested callers, gaps, and I1–I12. [ADR-0008](0008-store-balance-ledger.md), [ADR-0010](0010-store-balance-top-up-payment-rails.md), [ADR-0011](0011-store-balance-late-settlement-and-owner-alerting.md), [ADR-0016](0016-private-supplier-fulfillment-and-capacity.md), [ADR-0017](0017-per-account-warranty-and-partial-refunds.md), and [ADR-0018](0018-operator-confirmed-remainder-refunds.md) remain the accepted baseline. ADR-0010 currently excludes crypto funding; this proposal extends that scope only after the separate gates pass. It does not retroactively declare crypto funding supported.

IDR already has one canonical ledger. USDT checkout pricing and external verification exist, but a Customer USDT Balance, cross-target receipt authority, and balance accounting events do not. Redis wakeups and after-commit callbacks alone cannot prove durable delivery.

## Proposed contract

### Typed economic source

The following are proposed value objects/service contracts, not existing PHP symbols. R1/I1/U1 implement them without adding a parallel IDR writer. Each posting accepts a closed discriminated source, exact money, an idempotency identity and a canonical request fingerprint. Controllers cannot select an arbitrary source string to authorize money.

| Source variant | Required binding and authorization | Economic effect |
| --- | --- | --- |
| `VerifiedFunding` | Top-up ID, Customer, immutable currency/rail snapshot, globally claimed receipt and finalized verified attribution | Credit requested amount; fee separately recorded |
| `ApprovedAdjustment` | Immutable request ID/version, Customer, IDR amount, category, maker and distinct approver, reason; verified receipt for funded transfer | IDR credit only after approval |
| `Purchase` | Order/Payment, original Customer, selected currency, frozen pricing/items | Full-payment debit |
| `VerifiedRefund` | OrderRefund or whole-order remainder, original debit, currency, verified unit/missing entitlement and cumulative cap | Credit original units without repricing |
| `ApprovedReversal` | Original entry/event, approved request, outstanding reversible amount, available-funds guard | New linked inverse entry, never editing the original |
| `ResolvedDeposit` | Review ID/version, same top-up resolution identity, receipt or verified compensation evidence, approver | One authorized credit or external-refund resolution; no arbitrary USDT grant |

`ExactMoney` binds currency and positive integer units: IDR rupiah or USDT micro (10^6 per USDT). Public amounts are decimal strings or integer-unit strings with currency. Raw chain amounts remain unsigned integer strings; conversion from token decimals is exact and rejects any nonzero remainder, overflow, excess precision, ambiguous input, or invalid cap. Never pass floats. Do not derive micro-USDT from an IDR total. Binance's signed history parser is not a positive customer-input validator.

Fingerprint input is a versioned canonical encoding of source variant/ID, target Customer/account/currency, amount units, kind, original debit/entry, receipt ID, approval request version and payment snapshot identity. It excludes volatile timestamps, HTTP delivery IDs and secrets. Reusing a source/key with a different fingerprint is a conflict even when the amount matches. Reusing a matching source/key returns the original result. Approval/review records must bind the immutable fields rather than accepting an arbitrary caller-supplied fingerprint.

### Separate ledgers and projections

Keep `customer_balance_accounts` and `store_balance_entries` canonical for IDR; preserve historical source identities, provider snapshots and entries. Add USDT-specific accounts/entries with one account per Customer, immutable USDT currency, nonnegative BIGINT balance, positive amount, account sequence, cursor/version, freeze reason, original-entry/refund linkage and fingerprint. Unique constraints cover account/sequence, account/idempotency and source/kind; financial FKs restrict deletion. Empty-account creation uses conflict-safe insert/savepoint followed by a real row lock; a missing-row SELECT lock is insufficient.

Append entry, update projection/cursor, consume source/receipt, update target, write accounting/audit and persist outbound work in one short PostgreSQL transaction. Guard subtraction, addition, per-account/exposure caps and refund totals under the account lock; a conditional debit checks affected rows. Enforce append-only entries with database privileges/triggers in addition to model guards. Projection mismatch freezes mutations and opens review; repair is an explicit approved posting/reconstruction procedure, never silent balance adjustment. Policy/presets/fees/caps/presentation live in DB/Admin; ENV remains secrets/credentials.

### External receipt authority

Receipt ingestion and consumption are distinct. An observation can be stored before it is eligible; only authoritative, attributable, final evidence becomes spendable. The registry owns immutable physical-money identity and one consumed target globally across checkout, top-up and funded adjustment. A hash, screenshot, successful status alone, sender hot-wallet address or matching amount is insufficient Customer attribution.

| Rail | Canonical receipt key | Evidence required before consumption |
| --- | --- | --- |
| BSC | `(chain_id, normalized_tx_hash, log_index)` | Successful canonical receipt/Transfer log, snapshotted contract/decimals/receiver/value/window and qualified finality; permanent receiver Customer ownership |
| Binance Pay, optional | `(provider, stable_receiver_account_namespace, authoritative_transaction_id)` | Authenticated provider binding of intent/reference or verified payer to Customer; history-only matching remains disabled for funding |
| QRIS | `(provider, stable_merchant_account_namespace, canonical_economic_reference)` | Adapter-qualified authoritative identity/amount/fee/total; transport callback ID is not money identity |

BSC also claims parent `(chain_id, tx_hash)` to one target. All child logs must belong to that target; two targets in one transaction enter review in this release. Contract/receiver/adapter names are evidence fields, not alternate identity namespaces. Verified custody invoice aliases and chain logs map to the same receipt in the same claim transaction. If physical equivalence cannot be proven, enable only one settlement adapter. Alias and parent uniqueness must be database constraints. Conflicting claims roll back the entire posting and return conflict from a fresh transaction/savepoint; do not continue a PostgreSQL transaction after an unhandled uniqueness error.

Receiver registry uniqueness is global `(chain_id, normalized_address)` across checkout and funding pools. Customer receiver assignment is permanent, including retirement/expired intents; no reassignment or shared-address amount matching. Capacity/custody/finality uncertainty fails invoice creation closed. No application private keys or seeds.

R2 backfills settled, reserved, late and compensated legacy money identities and pending targets that can still receive evidence. Preserve the historical provider. Cut every writer over to the registry before activating funding; ambiguous legacy identities/conflicts block the gate rather than inventing claim evidence.

### Accounting and durable work

One append-only Accounting Event per posting commits with the subledger effect and retains source/receipt/original-event identity and exact currency units. A unique posting/event identity prevents duplicate counterpart recognition. Balanced legs record asset and liability for funding; liability and sale for spend; sale reversal and liability for refund; expense and liability for promotional grants; correction/reversal references original counterpart legs. Provider fee and unresolved deposit/treasury movements have separate legs and evidence. Supplier Balance remains the operator's asset, never a Customer liability account. Never sum IDR and USDT or lock one global clearing row on each purchase.

Aggregates consume events asynchronously with a unique consumer/event claim. Reconciliation separately checks projection vs signed entries, receipts/resolutions vs postings, and per-currency liability plus unresolved deposits vs qualified custody/provider assets and treasury movements. Unknown/deficit evidence triggers incident pause and review, never synthetic credit.

Persist workflow/inbox/outbox source work before commit: unique business effect identity, attempt/version, lease/token, next-attempt time, sanitized failure and terminal result. After-commit dispatch is a wakeup optimization. Recovery scans PostgreSQL after process crashes or Redis loss. Telegram work reuses the sole Telegram Outbound Effect ledger and ambiguity/manual-review semantics from [ADR-0007](0007-telegram-outbound-effects.md); a notification failure cannot roll back or repeat credit. Immutable settled sources may be recovered into missing effects; durable evidence of that obligation must exist at commit. No second Telegram outbox implementation.

### Transactions and Admin identity

Use the per-flow graph and remediation rules in the audit. Provider/RPC/custody and Telegram calls occur outside retried money closures. Read evidence outside, then lock and revalidate target/version/snapshot/ownership/finality before consuming it; stale observations cannot settle a modified target. Bounded deadlock retries (at most five at the outer boundary) do not retry auth, insufficient funds or conflicting payloads.

Admin lookup accepts a bounded numeric **exact Telegram ID** as a string, queries an already registered Customer with equality, and never auto-creates one. Username is display-only. Server-generated preview binds Customer ID, Telegram ID, masked identity, amount/currency, source/category, balance-before observation, policy/request version, actor, nonce and expiry. Submit revalidates authorization and immutable binding; balance-before is informational and reread under lock. Never trust client Customer identity or accept a “set balance” form. Request submission does not credit money. Default maker-checker remains distinct actor approval for every amount until H0 explicitly decides otherwise.

Example: pending adjustment R for Customer C, IDR 100000 funded by receipt E → approved/applied entry L and event A atomically. Matching replay returns L/A; altered Customer/amount conflicts. Reversal of L requires a new approved request and available funds; spent funds enter review. Top-up T expires → attributable final receipt E may settle T once; if T was already compensated, late E enters treasury/review without a second credit or reopening T. A reorg after credit freezes exposure and opens an incident; do not delete the ledger or create a negative balance.

## Consequences and unresolved gates

This contract adds typed authorization and global receipt authority without replacing IDR history or widening accepted provider behavior. It introduces migrations, reconciliation and privileges that need disposable PostgreSQL race/crash proofs in downstream tickets. The audit's open-decision register names owner, evidence and gate for every remaining policy/provider decision. No runtime migration, activation, production mutation or deployment is part of A0.

Rejected: one generic dual-written IDR/USDT ledger migration; floats or implicit FX; screenshots as funding authority; adapter-scoped duplicate receipt namespaces; moving HTTP into retried closures; Redis as durable money authority; automatic grant of disputed deposits; treating Proposed as Approved.
