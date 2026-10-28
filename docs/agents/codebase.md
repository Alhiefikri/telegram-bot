# Codebase guide untuk agent dan AI

Dokumen ini adalah peta implementasi aktual repository. Gunakan bersama [CONTEXT.md](../../CONTEXT.md) dan [ADR](../adr/). `CONTEXT.md` hanya berisi bahasa domain; detail modul, flow, dan invariant berada di sini.

## Cara memakai dokumen ini

Sebelum mengubah behavior:

1. Baca istilah yang relevan di [`CONTEXT.md`](../../CONTEXT.md).
2. Baca ADR yang menyentuh area perubahan. Untuk pembayaran KlikQRIS/Pakasir, mulai dari [ADR-0006](../adr/0006-klikqris-native-qris-and-pakasir-retirement.md); untuk checkout Telegram, [ADR-0002](../adr/0002-telegram-direct-checkout-idempotency.md). Untuk assembly payment checkout, gunakan [accepted Checkout Payment Assembly Architecture](../superpowers/specs/2026-08-25-checkout-payment-assembly-architecture.md) sebagai spesifikasi seam dan invariant.
3. Cari entry point dari tabel file di bagian akhir, lalu ikuti service yang memiliki ownership atas aturan tersebut.
4. Ubah boundary service dan test behavior-nya; jangan menyalin aturan bisnis ke controller, view, job, atau adapter channel.

Dokumen ini menjelaskan _mengapa_ file-file utama saling berhubungan. Source code tetap menjadi sumber kebenaran untuk detail method dan schema.
## Proposed IDR/USDT balance extension (A0 #537)

The [source audit and lock graph](../balance-a0-baseline.md) maps actual money writers, nested checkout/lifecycle/supplier/warranty/refund/compensation callers and I1–I12 gaps. Its [lock-site appendix](../balance-a0-lock-sites.md) names each explicit acquisition by file/symbol. [ADR-0019](../adr/0019-idr-usdt-economic-sources-and-receipt-claims.md) is **Proposed**, not accepted: it defines typed economic sources, additive USDT ledger, global receipt aliases/parent transaction claims, counterpart accounting and durable work. R1 (#541) implements the additive receipt registry and claim seam; R2 (#542) routes external payment writers through it and backfills legacy identities; I1 (#543) adds the IDR adjustment request boundary; I3 (#545) adds the linked IDR reversal; U1 (#546) adds the separate Customer USDT account/entry/accounting schema, typed economic sources and exact micro-USDT contract; U2 (#547) adds bounded USDT reconciliation, scoped drift freeze, receipt/settlement comparison, custody/treasury coverage and idempotent accounting aggregates. USDT funding stays gated. Runtime ownership below remains current for every other writer.

Start downstream work from the audit's per-flow lock graph and remediation register, including event-first lifecycle entry and historical Supplier/SKU resource unions. Preserve the IDR ledger/provider history, prepare without network inside locks, and reuse Telegram Outbound Effect recovery. H0/H1/A1/H2 own operational policy, custody/provider qualification and production sign-off. A1's [capability report](../balance-a1-bsc-capability.md) records the BSC chain/token/decimals, `finalized`/canonical-receipt/log capability classification (`verified`/`unsupported`/`unknown`, fail closed), Binance C2C funding decision, and immutable fee/network/custody snapshot contract; a qualified RPC probe is not funding, custody, or legal authorization. Admin IDR adjustment lookup is exact numeric Telegram ID for an already registered Customer, with identity/amount preview and server revalidation; username does not identify the recipient and unknown IDs never create Customers.

## IDR adjustment request seam (I1 #543)

`BalanceAdjustmentService` owns exact Telegram-ID preview and single-Owner request-bound password confirmation. `BalanceAdjustmentPasswordConfirmation` owns durable failure/lockout/KDF leases; `ApprovedBalanceAdjustment` supplies typed authority to `StoreBalanceLedger`. Request resolution, ledger/projection, accounting, audit and Telegram Outbound Effect obligations commit together. Original Top-up and registered refund recovery retain their existing economic resolution. See [I1 contract and activation boundaries](../balance-i1-adjustments.md). I2 #544 adds the Owner-only Dashboard adapter at `admin.store-balance.adjustments.*`: exact Telegram-ID search, immutable preview, per-action password, replay feedback, and paginated IDR request/positive-balance views. Displayed IDR balances use `StoreBalanceLedger::snapshot()` and fail closed on projection drift. U1 #546 has since installed the real USDT account/entry/accounting schema, so the readiness gate opens on migrated databases; the positive USDT directory integration is still missing, so multi-currency tracking is incomplete and production activation is not authorized. These seams do not authorize production activation.

### IDR adjustment reversal seam (I3 #545)

`BalanceAdjustmentService::requestReversal()` server-loads one applied original adjustment for the exact Customer and creates a five-minute `category = 'reversal'` preview whose amount is the full original credit; the Owner never supplies the reversed amount. It reuses the same per-action Owner password boundary, lockout and session/credential binding as I1, and rejects foreign, non-IDR, non-applied or non-adjustment sources plus a second reversal of the same entitlement. `StoreBalanceLedger::reverseApprovedAdjustment()` posts one linked `admin_adjustment_reversal` debit under the account lock with full-history projection verification; the unique `(source_type, source_id, kind)` claim (`balance_adjustment`, original request id) allows exactly one reversal entry, and a held loser is rejected without a partial write. Insufficient available funds reject as a safe review with no debit and no negative balance. Reversals of `top_up_recovery`/`refund_recovery` credits leave the original compensation/refund resolution and any external receipt claim resolved, so later callbacks cannot recredit. Reversal entries are immutable through the same PostgreSQL adjustment-history trigger. See [I3 contract and evidence](../balance-i3-reversals.md).

### Customer USDT balance ledger (U1 #546)

`CustomerUsdtBalanceLedger` owns every Customer USDT Balance mutation. `snapshot()` reads projection, newest balance and cursor version in one SQL statement and fails closed on drift; `lockForTransaction()` creates the empty account with a conflict-safe insert followed by a row lock; the only mutation entry points are `creditVerifiedTopUp`, `debitPurchase`, `refundEntitlement` and `reverseApprovedEntry`, each accepting exactly one closed typed source in `App\Services\UsdtBalance\` (`VerifiedUsdtFunding`, `UsdtPurchase`, `UsdtRefund`, `ApprovedUsdtReversal`) that binds kind, currency, Customer, exact `UsdtMicroAmount` money and a versioned request fingerprint. There is no arbitrary public credit API. `UsdtMicroAmount` parses positive micro-USDT strictly (unsigned canonical decimal, no float/exponent/zero/negative/excess-precision/overflow) and converts raw uint256 token units exactly with Brick `BigInteger`, rejecting any non-zero remainder or integer overflow; the installed **brick/math 0.18.0** signatures are pinned by `UsdtMicroAmountTest`. Each posting locks the account, rejects frozen accounts or a drifted cursor, enforces source linkage (refund cap against the original purchase debit, one full linked reversal per credit), appends one entry with the next `account_sequence`, updates the projection with a conditional balance guard and writes one balanced accounting event in the same transaction; a matching replay resolves the recorded entry even after a freeze or projection drift, new postings and `lockForTransaction()` refuse a frozen account or a drifted cursor, and a changed payload conflicts.

The additive tables are `customer_usdt_balance_accounts` (one account per Customer, `USDT` CHECK, nonnegative `balance_micro`, `version`/`last_entry_id` cursor, `frozen_reason`), `customer_usdt_balance_entries` (RESTRICT account/Order FKs, positive amount and nonnegative balance checks, unique sequence/idempotency/source claims, linked `original_entry_id`/`refund_order_id`) and `customer_usdt_balance_accounting_events` (one unique append per entry). PostgreSQL triggers reject every entry/accounting UPDATE/DELETE; the runtime-role split is in [runbook-usdt-ledger-privileges.md](../runbook-usdt-ledger-privileges.md). IDR tables, writers and readers are untouched. Funding, rails, intents, settlement, reconciliation, policy, checkout and refund remain #547–#556; USDT funding stays disabled. See [U1 contract and evidence](../balance-u1-usdt-ledger.md).

### USDT reconciliation, receipts and treasury (U2 #547)

`App\Services\UsdtReconciliation\UsdtReconciliation` coordinates one bounded run: `UsdtBalanceReconciliation` compares each `customer_usdt_balance_accounts` projection, cursor and entry sequence range against the signed sum of `customer_usdt_balance_entries` in one SQL statement per page; `UsdtReceiptReconciliation` compares `external_money_receipts` targeted at `customer_usdt_top_up` (target id = top-up id = the ledger's `customer_usdt_top_up` source id) against their funding entries and sums unresolved deposits exactly; `UsdtAccountingAggregator` reports claim/aggregate divergence; `UsdtTreasuryReconciliation` compares the signed ledger liability plus unresolved deposits against approved custody observations and audited `usdt_treasury_movements`. All component reads run inside one read-only `REPEATABLE READ` PostgreSQL snapshot, and custody observations are resolved before it opens so no provider I/O extends a transaction.

Custody assets enter only through the `UsdtTreasuryAssetProvider` contract (`available`/`unavailable`/`unsupported` observations; unavailable is never zero) registered under the container tag `usdt-treasury-asset-providers`, which is intentionally empty until H1 #539 approves an adapter. `UsdtTreasuryMovements` records append-only audited bookkeeping movements (unique direction/reference identity, replay/conflict semantics); it never signs or moves funds. `UsdtAccountingAggregator` consumes accounting events into per-leg aggregates through a unique consumer/event claim committed with the aggregate delta, so no global clearing row is locked and replays never double count.

`usdt-balance:reconcile` freezes exactly the drifted accounts by re-proving the drift under the account row lock (no money write, no repair), appends sanitized `usdt_balance_reconciliation_runs` evidence for #558 alerting, and exits non-zero on any finding; `usdt-balance:aggregate-accounting` and `usdt-balance:record-treasury-movement` are the bounded maintenance seams. Details and limitations: [balance-u2-usdt-reconciliation.md](../balance-u2-usdt-reconciliation.md).

### Global USDT receiver ownership (U4 #549)

`App\Services\UsdtReceivers\UsdtReceiverAddressClaims` owns the permanent, channel-exclusive claim of one normalized `(chain_id, address)` in `usdt_receiver_address_claims`. `claim()` inserts through a savepoint so a unique conflict never leaves a caller transaction aborted, returns a conflicting channel/previous binding as `UsdtReceiverAddressConflict`, re-uses a `checkout` claim only when no checkout receiver references it, and never re-uses a `customer_deposit` claim.

`UsdtDepositReceiverRegistry` owns Customer-exclusive receivers in `customer_usdt_deposit_receivers`: Owner-only `register`/`verify`/`manualVerify`/`retire` (audited, with masked address and fingerprint) and `allocateFor`, which returns the assignable `UsdtDepositReceiverSnapshot` or provisions a new exclusive address. Verification reuses `BscWalletOwnershipVerifierInterface::verifyMessage()` with a domain-separated `UsdtReceiverOwnershipMessage`; `manualVerify` is the explicitly acknowledged Owner attestation. Provisioning adapters implement `UsdtDepositReceiverProvisioner` and are resolved through the intentionally empty container tag `usdt-deposit-receiver-provisioners`, so allocation fails closed (`UsdtReceiverCapacityUnavailable`) instead of falling back to a shared address; no key material is ever created. Read seams for the future rail: `snapshotFor`, `activeFor`, `findByAddress`, `channelOf`, and the keyset `monitoredReceivers` scan that keeps retired addresses monitored.

`bsc_payment_receivers.address_claim_id` is a NOT NULL unique FK (PostgreSQL): `BscReceiverManager::add()` claims the address as `checkout` before insert, `activate()` re-checks the channel, and `BscPaymentReceiverPool` selects only checkout claims, so the checkout pool and Customer deposit receivers can never share an address. PostgreSQL triggers enforce claim immutability, per-channel claim identity on both receiver tables, deposit-receiver identity/verification/retirement immutability and pending-only deletion; model guards and a partial unique index (`usdt_deposit_receivers_active_customer_unique`) mirror them for the SQLite lane. Operator commands: `usdt-receivers:register` (exact `--customer-id`/`--telegram-id`, `--actor` Owner), `usdt-receivers:verify`, `usdt-receivers:retire`. Details, races and limitations: [balance-u4-receiver-ownership.md](../balance-u4-receiver-ownership.md).

### Watch-only BSC USDT rail (U5 #550)

`App\Services\UsdtTopUps\UsdtTopUpRail` is bound to `BscUsdtTopUpRail`, a watch-only RPC adapter using registered and verified Customer-exclusive public receivers. It never provisions keys, signs transfers, falls back to another receiver, claims receipts or credits a ledger. The October 8 top-up rework removes environment-only creation restrictions; real receiver, network, finality, policy and pause checks remain required.

`createOrRecover(Customer, reference, UsdtMicroAmount, deadline)` persists one immutable `usdt_bsc_rail_invoices` snapshot and scanner enrollment. Matching reference replay returns the same snapshot without RPC, including after deadline, receiver retirement or settings changes; changed Customer/payload fails. Creation prepares RPC facts outside locks, then locks Customer → receiver → checkpoint, rechecks binding/retirement/review and the deadline, and rejects overlapping inclusive payment windows. Monetary units are exact micro-USDT with zero application fee. Chain 56, approved contract, decimals 18, custody namespace, receiver, minimum confirmations from the existing active DB payment setting, mandatory finalized policy and epoch-second window are immutable. Epoch seconds avoid Eloquent/app-timezone reinterpretation of chain timestamps. PostgreSQL/SQLite guards reject evidence rewrites and foreign receiver snapshots.

`inspect()` scopes the invoice to the authenticated Customer supplied by the trusted caller; a submitted hash is lookup only. `BscFinalReceiptInspector` is pure, reuses checkout's `BscTokenTransferReader`, and requires one fully decoded Transfer, exact representable amount, successful receipt, matching token/receiver/log identity, inclusive invoice window, canonical block hash, confirmations and canonical `finalized` facts behind head. Missing RPC/finality remains pending; malformed, wrong, multi-log or reorg evidence enters review. Sender hot-wallet identity never assigns ownership. Inspection is not the receipt claim or typed funding authority: U6 owns confirmed Top-up targets and U7 must revalidate/claim/settle atomically.

`BscUsdtTransferIngestion` scans a Customer receiver once, not separately for each invoice. `usdt_bsc_scan_checkpoints` commits a fully scanned block/hash/version with append-only `usdt_bsc_transfer_observations` containing transaction/log/block identity. Each call bounds block pages, logs and RPC operations; incomplete or malformed pages do not advance, and stale concurrent versions cannot commit. Scans include retired enrolled receivers and only inspect finalized blocks. Anchor, log or page-boundary reorgs preserve old evidence and latch review instead of silently rewinding finalized history or changing money. Receiver review blocks new creation and positive inspection. Incident resolution/freeze after settlement belongs to U8.

Operator discovery: `php artisan usdt-bsc:scan --json` reads enrolled work by keyset (`--after`, `--limit`), sharing `--requests` across receivers. Invoice creation enrolls work durably; explicit history enrollment uses `--receiver=<id> --from-block=<first>`. Restart must retain the original first block. No scheduler, customer UI, second outbox or production rollout is added here. The RPC transport aborts oversized downloads and rejects bodies over 1 MiB before JSON decoding.

### USDT funding policy, active-target reservation and pauses (U3 #548)

`CustomerUsdtFundingPolicyManager` owns the versioned DB/Admin policy singleton (`customer_usdt_funding_policies`) plus append-only terms versions (`customer_usdt_funding_terms`) and scoped Owner pauses (`customer_usdt_funding_pauses`). H0-v1 disables business min/max/per-Customer/aggregate caps and the beta allowlist, so the policy carries no cap column: the Owner activation flag, a bounded confirmation TTL (default 600 s), a nonnegative exact micro-USDT application fee (default 0), the terms version and the Owner-editable USD top-up preset list (`preset_amounts_micro`, at most 8 positive micro-USDT values). Presets are presentation only: they never cap, reserve or change money, and the Admin form accepts USD decimals that are stored as exact micro units. Validation stays representational through `CustomerUsdtFundingPolicyDefinition`. `change()` is optimistic-versioned (stale updates conflict without touching money), no-op submissions write no audit, and pause/resume (`funding`, `spending`, `rail_binance_pay`, `rail_bsc_usdt`) require an Owner, a bounded single-line reason and an audit row. Policy changes never create a ledger entry, reservation or provider target, and no ENV is added for any of it.

`CustomerUsdtFundingReservationService` owns the durable active-target reservation (`customer_usdt_funding_reservations`): one open target per Customer across rails, enforced by a partial unique index on `customer_id WHERE resolved_at IS NULL` instead of a check-then-insert. `reserve()` locks the policy row, replays a recorded source by fingerprint, returns the existing open target for a new source, re-checks the fail-closed gate and inserts with savepoint recovery for uniqueness races. Reservations snapshot rail/amount/fee/policy version/terms version; identity columns are immutable (model guard + PostgreSQL trigger, DELETE denied). Expiry only moves a row to `expired_unresolved` and keeps holding the slot — ambiguous money stays reserved; `markSettled()` resolves a reservation only together with a matching `funding_credit` ledger entry (same Customer, exact amount, `customer_usdt_top_up` source claim) inside the posting transaction, and `release()` requires a typed `CustomerUsdtFundingRelease` resolution. `usdt-funding:hold-expired-reservations` is the bounded hold sweep; no scheduler is wired here (O2 #558 owns cadence/alerts).

A Customer can close an unpaid target on demand: a private `/start` cancels the open target and the instruction surface carries an explicit cancel button, both writing the immutable `cancelled_by_customer` resolution through the same transactional close (skipped while a settlement/preparation lease is active). `usdt-funding:release-expired` (every ten minutes) frees the slot of a confirmed target whose payment deadline plus grace elapsed without any settlement: it writes one immutable `expired_unpaid` resolution and the typed reservation release in the same transaction, skips targets with an existing resolution, an active settlement lease or terminal work, and never moves money. A late transfer is routed by the existing settlement paths to manual review instead of crediting a released reservation. The Customer-facing status is the terminal `expired_unpaid` state.

`CustomerUsdtFundingGate` checks policy activation, funding/spending/rail pauses, restore state and registered rail readiness. Built-in `LocalBscFundingReadiness` and `BinancePayFundingReadiness` apply in production too: BSC checks network/token/RPC configuration, Binance checks credentials, receiving account and Admin enablement. Buttons remain visible; missing configuration is reported when creating the funding intent. The seeded funding policy stays disabled until the Owner enables it through the existing Dashboard policy controller. Details: [balance-u3-usdt-policy.md](../balance-u3-usdt-policy.md).

### USDT confirmation and durable target preparation (U6 #551)

`UsdtFundingIntentService` owns local intents, opaque confirm/cancel claims and bounded expiry. `TelegramUsdtFundingActions` accepts trusted update actor/chat/type/message facts, resolves an already registered exact numeric Telegram identity and canonical root, and rejects unknown/guest/group/cross-actor requests without registration. `openConfirmedIntent()` opens the intent and claims `confirm` in the same action: on the wallet the Customer's choice of an amount (a preset button or a typed amount) is the confirmation, so there is no separate preview screen. `usdt:confirm|cancel` callbacks no longer exist for this flow.

Intent preflight captures exact credit/fee/payable micro-USDT, policy/terms versions, verified exclusive receiver, network/token/custody/finality and the Dashboard BSC payment TTL. Root/message identity, nonce and ten-minute-default policy confirmation expiry bind the action; the root render generation is persisted as a snapshot only (U9 corrected the earlier equality check, which every canonical render invalidated), while a replaced canonical message still fails closed. A partial unique index allows one pending intent per Customer; changed input replaces the old intent. Policy, settings or receiver changes before confirm require re-choosing the amount; confirmed-target replay bypasses changed policy but still checks authenticated actor/current root context.

The wallet amount chooser reads presets from the current policy and encodes each button as a signed, versioned identity (`balance:usd:<rail>:p:<version>:<token>`) that carries no amount: a stale identity re-renders the chooser instead of funding a changed amount. When the Customer already has an open target, every new amount request renders that target's instructions with an explicit notice — the previously requested amount is never reused and no second target is created.

Confirm commits intent state/version, the U3 reservation, one immutable `customer_usdt_top_ups` UUID/reference/snapshot, one `customer_usdt_top_up_work` preparation obligation and one U7 `customer_usdt_top_up_settlement_work` obligation atomically. It performs no RPC/HTTP/provider/Payment/receipt/ledger/outbound effect. The U3 reservation accepts the snapshotted payable deadline at creation, rather than extending immutable reservation history afterward. H0-v1 means one open target across rails, not business exposure caps or quota buckets; unresolved expiry continues holding that slot.

`UsdtTopUpPreparation` claims short version/token-fenced DB leases, invokes the actual U5 rail outside transactions and records `ready`, `creation_unknown` or `expired_unresolved` on the same target. BSC has no remote create: unknown is an unobserved preparation outcome, not a fictional custody invoice. Same-reference recovery reads a committed invoice before readiness/deadline/receiver checks; a rotated receiver before initial creation never becomes a fallback. App fee stays separate from U5's zero rail fee; the invoice verifies the target's exact payable amount. PostgreSQL guards reject intent identity/terminal-state changes and every target UPDATE/DELETE; model guards cover the SQLite lane.

Run `php artisan usdt-funding:recover --limit=25 --json` to expire previews, prepare BSC targets, scan receivers, hold expired reservations, settle each rail's due work and dispatch durable receipts. Scheduled every minute; durable work survives lost Redis wakeups. BSC and Binance recovery select only their own targets. `BinanceUsdtTopUpSettlement` reads authoritative Pay history outside DB transactions, matches Order ID, incoming receiver, exact USDT amount and payment window, claims the global transaction/Order-ID receipt identity, and credits the existing USDT ledger once. Mismatch or receipt conflict enters manual review without credit. `BinanceUsdtTopUpTest` covers scheduled settlement, cross-Customer receipt reuse and amount mismatch.

Binance top-up settlement and Owner review accept the submitted `transactionId` compatibility alias through the same `BinancePayTransaction::matchesSubmittedOrderId()` contract as Product checkout. The canonical receipt remains the physical transaction identity; a distinct nonempty Order ID is also claimed globally, so alternating identifiers cannot fund twice or fund both a Product and a Customer balance. A transaction-only provider record creates no empty Order-ID alias. The shared parser rejects non-string explicit `status`/`transactionStatus` values instead of treating malformed evidence as the legacy status-absent success shape. Top-up retains its successful-status, exact amount, snapshotted receiver and inclusive payment-window checks; Owner credit still requires explicit successful status and fresh evidence.

`BinanceUsdtTopUpTest` additionally covers one-micro precision/underpayment/overpayment, Pay ID and UID receiver shapes, pending/failed/refunded/malformed evidence, exact creation/deadline boundaries, delayed verification of an on-time transfer, foreign Customers, cancellation, expired worker fencing and receipt-obligation rollback/recovery. Provider-failure cases include HTTP 429/408/503/401, invalid signatures, HTTP-200 API rejection, malformed responses/history and later recovery; a transient 429 followed by valid evidence must credit exactly once within the existing bounded HTTP retry. `UsdtTopUpSettlementPostgresConcurrencyTest` exercises three workers on one Binance target, worker death during history lookup, cross-Customer/Product receipt-alias races and credit versus spending. Runtime smoke must use provider fixtures and disposable data; these tests do not prove live Binance availability or authorize production transfers.

Post-resolution Binance transfers (#630) still pass through history validation, evidence insertion and the global receipt claim within the existing cooldown, lease and inspection budget. A distinct valid transfer adds a receipt but never another credit: its inbox row becomes `review`, and work becomes `manual_review` with `last_error=receipt_after_resolution`. The original resolution, ledger entry and outbound receipt remain unchanged. Missing hints stay pending; mismatches and global alias conflicts retain their existing review/backoff behavior. `BinanceUsdtTopUpTest` covers the second transfer, replay, post-settlement mismatches and alias collision. Provider fixtures do not qualify Binance live funding.

Binance hint recovery (#629) advances the inspected inbox row on both missing Order IDs and receipt-claim conflicts: increment attempts, then defer by `min(300, 5 * 2 ** min(6, attempts))` seconds. Later due hints can therefore overtake a wrong hint. The existing one-minute work cooldown and two-minute fenced lease remain independent of this per-hint backoff. Each target permits at most 100 durable history inspections, including provider failures and abandoned leases; new hints, Check, recovery and expiry cannot reset that count. Each history inspection retains the reader's existing bounded page/transport retry limits. Once exhausted, the next eligible pending hint records `binance_inspection_budget_exhausted` and retains `manual_review`, or records `expired_unresolved` for an expired non-review target; neither path credits money or releases the reservation. Work without a due pending hint performs no provider reads, including after settlement. `BinanceUsdtTopUpTest` covers wrong-then-correct hints, both backoff branches, request counts, exhaustion during missing/unavailable history and replay, alongside the existing 100-hint budget and lease checks.

Verification entry points: `UsdtFundingIntentTest` (Telegram/service identity, stale/expired/cancel, exact money/overflow, atomic work failure, lost lease/reference and snapshot rotation) and `UsdtFundingIntentPostgresConcurrencyTest` (three-process double confirm, competing amounts and concurrent recovery/DB immutability). PostgreSQL 16 proves the locking lane; SQLite does not. Timestamp storage follows the repository's application-timezone `timestamp` convention so PostgreSQL leases/deadlines do not shift to a different instant on serialization.

Framework decisions were checked against Context7's Laravel 13 [transactions](https://github.com/laravel/docs/blob/13.x/database.md)/[pessimistic locking](https://github.com/laravel/docs/blob/13.x/queries.md) and PostgreSQL 16 [partial unique indexes](https://www.postgresql.org/docs/16/indexes-partial.html)/[row locking](https://www.postgresql.org/docs/16/explicit-locking.html): retry only local DB work, keep network outside lock closures and let DB uniqueness own the active slot.

Observed U6 evidence (2026-10-06): focused SQLite U6/U3/U5 suites passed 93 tests / 574 assertions; disposable PostgreSQL 16 U6/U3/U5 lanes passed 14 / 151 (U6 alone 3 / 38). Pint and the actual Vite build passed. An actual bootstrap/`usdt-funding:recover` smoke recovered one expired worker lease, returned the same confirmed target/invoice, made zero RPC calls on replay and left Payment/ledgers/receipts/outbound effects empty. Provider scenarios use the actual watch-only adapter with synthetic RPC facts, not live custody or independent staging qualification. No CI workflow, deploy, production activation, live deposit or full-suite claim is implied by these results.

### USDT settlement, late money and durable recovery (U7 #552)

`UsdtTopUpSettlement::submit(Customer, topUpId, transactionHash)` accepts an authenticated Customer-scoped lookup hint, never a receipt/payment authority; malformed/foreign hints are rejected and Customer hints have a persistent 100-row budget. U5 receiver observations bypass that hint budget and are discovered by a monotonic per-target cursor. `check()`/`recover()` claim short PostgreSQL `SKIP LOCKED` work leases with token/version/expiry fencing, inspect the actual U5 rail outside every transaction, and reject a result if the worker lease or receiver checkpoint version changed. Preparation also rejects expired leases even without a replacement worker.

Money finalization follows Customer → USDT account → immutable target/receiver → work → reservation/resolution → receiver checkpoint/receipt. Hint/lease transactions take target before work so FK insertion cannot introduce the reverse work → target graph. Revalidation binds the immutable invoice's Customer, receiver, amount, chain/token/decimals/custody/finality to the target snapshot; receiver retirement does not retarget existing money, while receiver review blocks positive consumption. `allowLate` removes only the invoice's upper payment deadline, retaining start-time, exact amount, ownership and canonical finality checks. A newer target on the same receiver at the transfer time holds attribution for review instead of letting the old target claim new money.

For an exactly representable single deposit whose actual amount differs from the invoice, `BscFinalReceiptInspector` returns `review / canonical_final_amount_mismatch` with evidence only after all ownership/token/window/canonical-finality checks pass. Settlement claims that physical receipt and appends its actual amount evidence under the same fences, without a balance entry, fee event, reservation settlement or economic resolution. U2 therefore includes the full actual deposit as unresolved treasury money; a receipt claim alone is not spending authority. U8 revised settlement or external return reuses the same receipt identity and removes the unresolved amount only through the linked authorized resolution. Replays cannot multiply the receipt or credit; wrong receiver/token, nonfinal, malformed or multi-transfer evidence cannot use this path. Existing nonrepresentable-amount review remains fail closed without rounding.

One `customer_usdt_top_up_resolutions.top_up_id` owns the economic resolution. The global BSC receipt/parent claim, funding entry/projection/accounting, reservation settlement, append-only authoritative evidence, audit and `usdt.top_up_receipt` Telegram effect commit together, with at most five local DB transaction attempts and no external side effects in their closures. U8 routes real-target compensation/external-return through `UsdtTopUpReview`; generic reservation release cannot bypass its verified evidence and Owner authorization. Expiry remains `expired_unresolved`, not a release. Terminal settlement/compensation/external-return/rejection never reopen; additional receipts remain unresolved and cannot be matched to the original ledger credit by U2.

`customer_usdt_top_up_settlement_work` owns lifecycle/version/lease/next-check/error and discovery cursor; `customer_usdt_top_up_inbox` owns deduplicated lookup work; `customer_usdt_top_up_evidence` retains authoritative inspection versions; `customer_usdt_top_up_resolutions` retains one immutable result. PostgreSQL guards reject evidence/resolution/fee-event UPDATE/DELETE and work identity/version/terminal-state rewrites. Application fees are separate append-only balanced `customer_usdt_top_up_fee_events` (settlement asset → funding-fee revenue), never Customer credit or rounding; receipt reconciliation subtracts only the recorded fee and matches a resolution's specific receipt.

Resolved invoice windows no longer block a new confirmed target on the same permanent receiver: U5 excludes only invoices with a committed U7 resolution from its overlap guard, leaving unbound/unresolved invoices protected. Monotonic intent identity distinguishes superseding targets even when both were created in the same timestamp second. Accounted application fees enter U2 expected custody separately (`accounted_funding_fee_micro`), so legitimate earned fees are neither Customer liability nor an unexplained asset surplus.

The scheduled recovery uses attempted-at fairness across enrolled receivers and a shared 32-operation scanner budget; each target processes at most 25 new observations and one due hint per tick. Pending hints use bounded exponential delay (10–300 seconds), empty targets defer a minute and dead workers are reclaimable after the two-minute lease. Funding/rail pauses do not stop old-money reconciliation. `UsdtTopUpOutbound` materializes through `TelegramOutboundEffectService`, dispatches `SendUsdtTopUpReceipt` through the existing dispatcher, and exclusively delegates claim/finalization/ambiguity to `TelegramOutboundEffectLedgerProtocol`. Definite failure preserves money; interrupted send/ACK becomes `unknown` and follows existing Manual Delivery Review, never blind resend or a second outbox.

Framework choices were checked through Context7 against Laravel 13 [transaction retries](https://github.com/laravel/docs/blob/13.x/database.md)/[locking](https://github.com/laravel/docs/blob/13.x/queries.md) and PostgreSQL 16 [SKIP LOCKED queue consumption](https://www.postgresql.org/docs/16/sql-select.html)/[savepoints](https://www.postgresql.org/docs/16/sql-update.html). Verification entry points: `UsdtTopUpSettlementTest` and `UsdtTopUpSettlementPostgresConcurrencyTest`. U8 operator workflows are implemented below; production funding, live custody/finality qualification and U9 public menus remain separate gates. No CI, deployment or real deposit is authorized here.

Observed local U7 verification: eight focused SQLite suites passed 155 tests / 964 assertions; the U7/U6/U3 PostgreSQL concurrency lane passed 13 tests / 143 assertions; targeted Pint checks passed. An actual recovery command and Redis queue worker against disposable PostgreSQL 16.15 / Redis exercised abandoned-worker recovery, two Redis flushes (including lost dispatch after monetary commit), one successful Telegram API-fixture delivery, one resolution and exactly 1,000,000 micro-USDT credit with no Payment/IDR entries. The fee variant retained 100,000 micro-USDT as accounted funding fees with consistent treasury coverage. A next target prepared before the old invoice deadline, and a stale original-target poll could not claim its newer receipt; the regression also covers same-second creation and UTC-to-application-timezone SQL attribution. RPC/Telegram calls were observed outside DB transactions. Provider and Telegram responses were fixtures, not live custody/finality or a real deposit; disposable infrastructure and the runtime smoke script were removed. No CI or deployment ran.

### USDT exception review and spent-reorg recovery (U8 #553)

`UsdtTopUpReview::request()` performs authoritative RPC inspection outside money locks and creates an immutable, expiring Owner/session/credential-bound preview. `confirm()` requires the same Owner's per-action password through the shared I1 authorization/rate-limit protocol, refreshes evidence, and revalidates work/checkpoint versions before committing money. H0 single-Owner authorization supersedes maker/checker: no second approver, implicit super-admin exemption or screenshot authority.

`AdminUsdtTopUpReviewController` exposes the Owner-only `/admin/store-balance/usdt-review` surface from the Stats tab. Operators see immutable original Customer/Telegram/amount/fee, authoritative review history, exact raw receiver observations (including deposits without invoices), and revised net credit before password confirmation. Revised settlement and compensation must equal the verified, exactly representable incoming amount minus the original fee; invoice identity and original amount never change. Creation-unknown/unbound, ambiguous/multiple-log and sub-micro observations remain unresolved until admissible evidence exists; no rounding, unbacked grant or arbitrary attribution. One resolution and global physical receipt claims serialize operator actions against settlement/replay.

Binance exception resolution (#632) uses the same `UsdtTopUpReview` Owner/session/credential preview and per-action password authority, without a BSC invoice. Only unresolved `manual_review`/`expired_unresolved` targets are eligible. The rail-specific Admin form accepts a Binance Order ID and `settled` or `rejected`; BSC compensation/external-return controls remain BSC-only. Both preview and confirmation bypass Binance history caching and require one matching Order ID from a complete provider read. Credit requires explicit successful status, USDT, the snapshotted receiver, exact actual amount covering the original fee, and a payment time between target creation and now; late transfers are allowed, but not transfers belonging to a later Customer target. Persistent asset/receiver/status or early-window mismatches cannot authorize credit. A rejection also requires fresh provider evidence and closes the reservation without a ledger entry. Received, successful USDT is globally claimed even when rejected; foreign-asset/foreign-receiver/failed evidence does not manufacture a USDT receipt. Credit uses `creditVerifiedTopUp` with the immutable review request and typed password authority, for verified actual amount minus snapshotted fee. Resolution, receipt/Order-ID alias claim, entry/accounting, fee, reservation, audit and deduplicated Owner notification commit atomically; replay returns the existing result. `BinanceUsdtTopUpTest` covers revised/late credit, mismatch rejection, password failure, cache bypass, provider outage, alias conflicts and replay, and is included in `phpunit.postgres.xml`. Fixtures do not qualify live Binance funding.

External returns require two finalized physical claims: the incoming deposit and a distinct outgoing transfer from its custody receiver to the exact Customer-authorized destination for the full incoming amount. `TelegramUsdtFundingActions::authorizeExternalReturn()` binds registered numeric actor, private chat and current navigation root before recording the immutable Customer destination authorization. Neither a public hash nor an exchange hot-wallet sender proves Customer ownership. U9 still owns public menu wiring; this change adds no public wallet mutation endpoint.

`UsdtReorgIncidentService` rotates a durable bounded monitor over credited resolutions, including revised/compensated deposits. Stable canonical block mismatch or durable scanner checkpoint reorg evidence creates one immutable incident and freezes the account without rewriting receipts/history, debiting the Customer or producing negative balances. Available versus spent exposure is a conservative allocation of fungible funds, not transaction-level tracing. Recovery remains available during funding pauses; the scheduled funding recovery examines at most ten credited targets per tick with a separate RPC budget.

`requestRecovery()`/`confirmRecovery()` require fresh Owner/session/credential binding and per-action password. The resulting immutable case is `authorized_unresolved`: authorization alone never invents treasury assets, closes loss, releases the freeze or charges a Customer. Treasury reconciliation reports total/spent reorg exposure and authorized cases separately, with `unresolved_reorg_loss` even when remaining Customer liability is zero. `usdt-balance:reconcile` prints the `REORG_LOSS` finding.

Verification entry points: `UsdtTopUpReviewTest`, `AdminUsdtTopUpReviewTest`, `UsdtReorgIncidentTest`, `UsdtTopUpReviewPostgresConcurrencyTest`, and `UsdtReorgIncidentPostgresConcurrencyTest`. Context7 guidance used Laravel 13 [DB transaction retries](https://laravel.com/docs/13.x/database#database-transactions)/[pessimistic locking](https://laravel.com/docs/13.x/queries#pessimistic-locking) and PostgreSQL 16 [consistent lock ordering](https://www.postgresql.org/docs/16/explicit-locking.html)/[savepoints](https://www.postgresql.org/docs/16/sql-update.html): keep RPC/password work outside money locks, use a consistent lock order and database uniqueness for economic claims.

Observed U8 runtime proof (2026-10-06): actual authenticated Admin browser on disposable PostgreSQL revised an immutable 1-USDT invoice to a verified 2-USDT credit through Owner password confirmation. A subsequent 1.5-USDT purchase and canonical reorg froze the remaining 0.5 USDT, recorded 2-USDT exposure / 1.5-USDT spent exposure, preserved ledger history and printed unresolved treasury loss. The actual Admin recovery preview/password confirmation created `authorized_unresolved`; desktop and 390px mobile rendering were inspected without page overflow. The six-suite PostgreSQL concurrency lane passed 31 tests / 332 assertions; the Vite production build passed. RPC responses were synthetic fixtures, not live finality/custody qualification. No CI, deploy or production activation ran.

Observed focused U8 regression lane passed 98 tests / 569 assertions across settlement, operator review, reorg, reservation, treasury/receipt reconciliation and shared authorization filters. The separate corrected scanner/trusted external-return scenarios passed 3 tests / 16 assertions. Targeted Pint formatting passed.

### Telegram balance, USDT top-up and personal history (U9 #554)

`TelegramMenuIntents::usdtBalanceSection()` owns the USDT part of the private Saldo screen: the IDR rows keep their existing callbacks and semantics, and the USDT section shows an exact `UsdtMicroAmount::formatUnits()` balance with `balance:usdt` (Top-up) and `usdt:history` rows. A drifted projection (`CustomerUsdtBalanceLedger::snapshot()` unavailable) or a frozen account renders a localized unavailable/frozen line instead of a fabricated zero and hides the funding action while history stays readable. The top-up row only appears when `CustomerUsdtFundingGate::availability()` reports the rail available, so Owner pauses/activation are honoured without duplicating policy logic.

`TelegramUsdtBalanceIntents` renders the chooser, the exact confirmation preview (U6 intent snapshot: credit, fee, payable total, network, token contract, exclusive receiver, deadline, wrong-network warning), the honest status screen and the keyset history. `UsdtTopUpStatusReader` derives target status from the immutable snapshot plus preparation/settlement work and resolution rows, and only reports `settled` when the linked ledger entry row exists — receipt/status text is never money authority. `CustomerUsdtActivityService` merges entries and unresolved targets into one keyset stream with `DEFAULT_PAGE_SIZE = 25` / `MAX_PAGE_SIZE = 100`; `TelegramUsdtActivityToken` signs and Customer-scopes the cursor with a version distinct from the IDR activity token, so the two cursors can never be exchanged.

`TelegramUsdtFundingCallbackHandler` owns `balance:usdt`, `usdt:history`, `usdt:h:{o,n}:<token>`, `usdt:status|check|hash:<topUpId>` and `usdt:confirm|cancel:<nonce>`, delegates confirm/cancel to U6 `TelegramUsdtFundingActions`, persists hash prompts through `TelegramUsdtFundingSession`, and passes the next private message to U7 `UsdtTopUpSettlement::submit()` as a bounded lookup hint. `usdt:check` refreshes durable state only; the scheduled `usdt-funding:recover` remains the sole inspection driver. All USDT screens are private-chat only (`allowsNonPrivateChat() === false`, `/usdt` is a private-only command), read fresh projection/ledger data (never the shared metrics cache), render no IDR conversion, call no rail/RPC and record no outbound effect — settled receipts stay owned by `UsdtTopUpOutbound`. Unknown `usdt:` callbacks resolve to the localized stale surface, and foreign target ids or cursor tokens fail closed to the same notice.

`TelegramUsdtFundingCallbackHandler::verifyBinanceOrderId()` sends a separate loading reply and edits that message with `TelegramUsdtBalanceIntents::binanceVerificationSurface()`. The original invoice/root is preserved. Status comes from `UsdtTopUpStatusReader`: only linked-ledger credit can show success or already-paid totals; pending/provider-outage results retain the hint and input session, invalid format consumes no provider lookup, and repeat submissions show the existing credit without re-running settlement. Review and closed states do not invite another transfer. These replies have no navigation callbacks and are localized in id/en/ms. BSC keeps its canonical checking flow.

Plain-text balance receipts pass `parse_mode => null` to `TelegramBotClient`; the shared payload normalizer must omit that field on the wire. Telegram rejects a JSON `null` parse mode with HTTP 400 `unsupported parse_mode`. `TelegramBotClientTest::test_plain_text_receipt_omits_null_parse_mode_instead_of_sending_an_unsupported_mode` covers the transport rejection and literal text preservation. Live test-bot smoke confirmed one fake 3-USD funding receipt, a 1-USD Telegram purchase with credentials and Owner notice, then a 1-USD API purchase with an Owner notice; identical API idempotency replay left two total Orders and 1 USD remaining. This is isolated synthetic money with real test-bot delivery, not production funding qualification.

USD payment instructions use the `UsdtPayment` navigation screen: entering it deletes the amount menu and sends a new text-only canonical message, while leaving it deletes the invoice and sends the destination menu. A successful `usdt:close:<id>` cancels through the existing funding boundary, clears the lookup session and opens Home. Binance instructions show the immutable receiver snapshot as inline code and a native `copy_text` button; a pasted Order ID needs no separate prompt button. Verification failures keep the invoice open and re-arm lookup for another paste without crediting money. `UsdtBalanceMenuTest` covers the fresh message, receiver copy payload, deletion, home transition and unchanged ledger; `BinanceFundingPresentationTest` covers provider-confirmed credit versus unverified hints without requiring a self-transfer.

S08-fix-A aligned the human boundary and the spending barrier. `TelegramUsdtFundingActions::preview()` now owns comma-decimal normalization, so `/usdt 0,3`, the prompted `0,3` reply and `0.3` reach one identical preview while mixed separators, grouping, exponents, zero, negatives, more than six decimals and overflow stay rejected by the strict parser; `UsdtBalanceMenuTest` covers the command and rejection set, `BinanceFundingPresentationTest` covers the boundary itself. The `usdt:check` button catches every provider/history failure so no exception escapes the webhook update: the durable target state is still rendered, no money moves and recovery keeps owning the leased work. The IDR store-balance button in the private cart chooser and the Product detail keyboard is gated on the same `spendingAvailability()` as the USDT rail, so an active spending pause or open restore gate hides it while the checkout service keeps rejecting a stale callback (fail closed, no money effect).

Integration corrected two defects found while wiring this feature. First, the canonical render lease advances `telegram_navigation_roots.generation` on every edit, so `UsdtFundingIntentService` no longer treats the stored render counter as an identity version — intent identity is the root row plus canonical message id, while a replaced message still fails closed; `UsdtFundingIntentTest` covers both directions. Second, `StoreBalanceActivityService::applyCursor` compared only the timestamp when walking IDR history, so an IDR top-up row sharing the page-boundary second with ledger entries was dropped from the older page entirely; the walk now compares `(created_at, rank, id)` lexicographically and the replacement regression tests (`test_equal_timestamps_keep_same_second_rows_reachable_in_both_directions`, `test_same_second_top_up_row_at_a_page_boundary_is_never_lost`) replace the earlier test that pinned the row-dropping behaviour. `CustomerUsdtActivityService` uses the same rank-aware predicates.

Verification entry points: `UsdtBalanceMenuTest` (private/group presentation, drift/frozen honesty, preview exactness and pre-confirm side effects, confirm replay, cancel/stale callbacks and foreign cursors, hash hints, keyset history bounds and paging, id/en/ms), `StoreBalanceActivityTest` (IDR regression) and the existing U6 `UsdtFundingIntentTest`. Details and limitations: [balance-u9-telegram-balance.md](../balance-u9-telegram-balance.md).

Observed local U9 evidence (2026-10-06): the full parallel SQLite suite passed (3,742 tests, 3,582 passed, 160 skipped, 27,264 assertions, no failures); focused lanes `UsdtBalanceMenuTest` 17 tests / 234 assertions, `UsdtBalanceSettledPresentationTest` 1 test / 16 assertions (real U7 settle against the watch-only rail fixture), corrected U6 `UsdtFundingIntentTest` 15 tests / 109 assertions, `StoreBalanceActivityTest` 9 tests / 94 assertions and `TelegramTranslationAuditTest` 8 tests / 15 assertions. The PostgreSQL concurrency deploy gate passed on disposable PostgreSQL 16 (port 55432): 187 tests / 1,764 assertions, 0 skipped, exit 0 — this includes the U6 confirmation lane that covers the intent-identity correction. Pint is clean on the changed files. RPC/Telegram responses are fixtures, not live custody or finality evidence; production funding authorization, custody/legal qualification and deploy remain separate gates.

### Internal full-payment USDT checkout (U10 #555)

`UsdtBalanceCheckoutIntent` and `UsdtBalanceCheckoutPaymentPreparation` assemble `provider = usdt_balance`, `method = USDT_BALANCE`, `currency = USDT`, with integer micro-USDT Payment amounts. Preflight and preparation are local: no Binance invoice, BSC receiver assignment, external receipt claim, provider fee, HTTP or RPC. `CheckoutPricingSnapshot` supplies the actual selected quantity-tier USDT total; any item without a positive eligible USDT price makes the whole rail unavailable. No IDR conversion or partial tender.

`OrderCheckoutService` follows Cart → selected USDT account → shared funding-policy barrier → maintenance → Cart Items/Products → canonical Supplier/SKU resource union → payment setting → newly created Order/Payment/lifecycle. The shared policy lock conflicts with the Owner pause manager's exclusive lock without serializing unrelated Customer reads. The existing spending gate is intentionally independent of new-funding activation/funding/rail pauses; spending pauses, missing policy, frozen account and projection drift fail closed. Matching checkout-key replay resolves the same Order/debit before new-money gates or repricing, including after a lost response; replay ownership is rechecked against the persisted Cart Customer.

One typed `UsdtPurchase` debit, immutable accounting event, Order Items, credentials, local/supplier reservations, paid lifecycle and Admin outbound effect commit together. Supplier acquisition and Telegram delivery stay outside retried transactions; existing Paid Order receipt discovery recovers lost Redis wakeups. Both private Telegram Product Detail and cart checkout now expose one **Pay with Balance** action, followed by an explicit **IDR / USD** wallet chooser (`checkout:wallet:idr|usd|cancel:<nonce>`). USD spends the existing micro-USDT wallet at 1:1 without an external Binance invoice or external-rail readiness requirement; a missing USD price hides that option. The ten-minute Customer-scoped selector binds the private chat, selector message, original checkout key, quantity/cart identity and quoted totals. A `BalanceCheckoutRequest` pins the selected wallet and expected total again inside canonical checkout; no conversion, split payment or fallback to the other wallet occurs. Both wallet choices share the original checkout key, so the first successful payment wins and repeat/cross-wallet taps cannot debit twice. Cancel and stale/changed/expired selections spend nothing. Group/cross-actor callbacks and guest spending are denied; localized insufficient/unavailable feedback never creates a paid Order.

Order `currency` is USDT for this internal rail; the additive `2026_10_06_170000` migration widens `orders.currency` to four characters and refuses rollback while four-character values exist. `Order.total_amount`, `OrderItem.unit_price/subtotal` remain **legacy IDR commercial display snapshots**, not micro-USDT; monetary authority is the USDT Payment and `usdt_unit_price/usdt_subtotal`. Paid invoice, receipt, customer totals and order detail/status use explicit USDT units; IDR-only GMV/sales/monthly sums exclude USDT Orders. Shared USDT presentation uses the zero-safe exact formatter rather than float division. IDR and historical external payment preparation/verification paths remain unchanged; historical USDT receipts also use the authoritative Payment amount rather than the legacy Order display total. Full/partial entitlement refunds belong to #556; full lifecycle refund cannot falsely mark an internal USDT Payment refunded without the original-currency credit.

Context7 references checked against Laravel 13 [transaction retries](https://github.com/laravel/docs/blob/13.x/database.md)/[pessimistic locking](https://github.com/laravel/docs/blob/13.x/queries.md) and PostgreSQL 16 [shared/exclusive row locks and lock ordering](https://www.postgresql.org/docs/16/explicit-locking.html). Verification entry points: `UsdtBalanceCheckoutTest`, `Telegram/UsdtBalanceCheckoutTest`, `UsdtBalanceCheckoutPostgresConcurrencyTest` (last Customer funds, three-worker replay, last local stock, last Supplier wallet capacity, PostgreSQL accounting failure rollback). Funding/custody qualification, actual staging/production activation and U11 refunds are not claimed by U10.

Observed local verification: full SQLite suite **3,590 passed / 165 skipped / 27,356 assertions** (3,755 total); 10 focused SQLite/Telegram tests / 111 assertions; 5 U10 PostgreSQL tests / 54 assertions and 31 existing IDR/maintenance/Supplier PostgreSQL tests / 357 assertions, with no PostgreSQL skips. A disposable PostgreSQL 16.15 bootstrap smoke exercised the real checkout and Telegram transport seam with HTTP fixtures: quantity-two tier total `2.469134 USDT`, balance `5.000000 → 2.530866 USDT`, same-Order replay, zero network calls during checkout/replay, committed inventory and durable receipt effect. The generated invoice PNG was inspected visually; rendered receipt and monthly Order summary preserved exact USDT amounts separately from IDR. This is application/fixture evidence, not a live Telegram, provider, custody, funding or production qualification. Disposable smoke files and database container were removed.

### Atomic balance preference (S03 #620)

`OrderCheckoutService::checkout(..., balanceRequest: new BalanceCheckoutRequest(...))` is the trusted service seam for account preference or a per-order `BalancePaymentPreference` override. It cannot be combined with an explicit payment intent. Customer `balance_payment_preference` persists `idr_only`, `usd_only`, `idr_first` (default), or `usd_first`; adapters must authenticate preference changes and use the guarded enum attribute. Existing Telegram payment buttons still pass explicit IDR/USDT intents.

The request takes exact whole-IDR `expectedIdrTotal` and micro-USDT `expectedUsdTotalMicro`, not public decimal strings. Downstream API adapters own decimal parsing and the public `USD` mapping. Checkout re-quotes locked Products with the existing retail/reseller context. A priced primary requires consent; an omitted secondary is not authorized for spending. Every supplied candidate total must match before either wallet can be selected, even if the primary is affordable. `CheckoutPriceChanged` carries safe current totals; `InsufficientCheckoutBalance` means no authorized full wallet. Missing USD price permits an authorized IDR fallback, never conversion.

Automatic checkout locks both healthy wallets in IDR then USDT order, independent of preference, before inventory. IDR health belongs to `StoreBalanceLedger::lockForPurchase`; USDT health remains in `CustomerUsdtBalanceLedger::lockForTransaction`. All new balance debits, including explicit IDR, share the spending/restore barriers, so changing rails cannot bypass a spending pause or open restore gate. A missing restore singleton fails closed. Refunds remain ungated recovery of original funds through the existing Payment/debit authority.

`orders.balance_checkout_snapshot` records resolved preference, account/override/explicit-rail source, internal selected currency, amount and supplied expected totals. Model and PostgreSQL guards reject snapshot updates. Historical Orders retain null snapshots and their original records. Replay returns the accepted Order before current wallet, price, preference and pause checks; it never reselects. Full and warranty remainder refunds continue using the original Payment and ledger caps, not this descriptive snapshot.

The [S03 lock graph and verification record](../balance-a0-baseline.md#s03-atomic-dual-balance-checkout-620) documents the Customer-before-Cart shared prefix, both wallets, pause/restore writers, top-ups and original-currency refunds. Service-only scope: HTTP/API idempotency claims and private preference UI belong to #623 and #627; no endpoint, production activation or new ledger is introduced here.

### Original-currency USDT refunds (U11 #556)

The additive `2026_10_06_180000` migration widens `manual_fulfillment_reviews.currency` from three to four characters so actual supplier/late-payment reviews retain `USDT` and the original Payment micro amount. Rollback refuses to truncate any retained four-character currency; it never drops review or money history.

`2026_10_06_180001` indexes `(original_entry_id, kind)` so cumulative refund-cap checks and linked-credit discovery do not scan unrelated Customers' ledger history while holding the account lock.

`UsdtOrderRefundService` owns the original debit/Customer/Order/Payment binding and refund receipt orchestration. `OrderLifecycle::applyPaymentRefunded()` locks the selected currency account before Order → ordered Payments → canonical Supplier/SKU resources, then credits only the original micro-USDT debit remainder. `WarrantySettlementService` and `MissingFulfillmentRefundService` select that same account before their existing resource locks and register immutable `OrderRefund` entitlements; only `CustomerUsdtBalanceLedger::refundEntitlement()` appends money and accounting. The ledger revalidates the registered verified warranty/unit or definitively missing fulfillment authority under the account lock, including the frozen Order Item USDT allocation, original `UsdtPurchase` fingerprint and Payment identity. No live Product price, exchange rate or legacy IDR display amount authorizes a refund.

Full and partial credits share the original debit cap, append `original_entry_id`/`refund_order_id`, and claim one source/key/fingerprint. Full refunds have `source_type = order`; verified partial refunds have `source_type = order_refund`. Every registered partial must already have its exact linked internal credit before a full remainder can proceed; requested, uncertain, unverified, foreign-currency or externally confirmed entitlements require review rather than another credit. Internal USDT Payments cannot register/confirm external transfers or external remainders. Supplier acquisition/replacement ambiguity remains blocked by the canonical fulfillment owner. All-unit partial refunds close the lifecycle without a zero-value or third credit. Healthy delivered credentials survive partial refunds; full refunds retire exposed credentials without restocking.

The immutable accounting event, ledger/projection, lifecycle changes and one `usdt.refund_receipt` Telegram Outbound Effect per credit commit together. Receipt text snapshots the exact credited decimal and invoice reference; Customer USDT history already reads these Order-linked entries. `SendUsdtTopUpReceipt`/`UsdtTopUpOutbound` reuse their existing snapshot-delivery protocol for both USDT receipt families; `telegram:recover-outbound --chunk=25` discovers refund effects after lost Redis wakeups. Definite failures retry within the shared three-attempt policy; ambiguous delivery follows Manual Delivery Review, not blind resend. An explicit resend retains the original receipt text and cannot change money. IDR and external-payment refund rails remain separate.

Context7 references: Laravel 13 [transaction retries](https://github.com/laravel/docs/blob/13.x/database.md)/[pessimistic locking](https://github.com/laravel/docs/blob/13.x/queries.md), PostgreSQL 16 [consistent lock ordering](https://www.postgresql.org/docs/16/explicit-locking.html). Verification entry points: `UsdtOrderRefundTest`, `UsdtOrderRefundPostgresConcurrencyTest`, `UsdtBalanceCheckoutTest` and `CustomerUsdtBalancePostgresConcurrencyTest`; `HistoricalOrderRefundTest`, `HistoricalOrderRefundPostgresConcurrencyTest`, `ManualFulfillmentReviewTest` and IDR ledger suites own regression coverage. No CI, deployment, live funds, custody qualification or production activation is authorized by U11.

Observed disposable PostgreSQL runtime smoke: original debit `2.000001 USDT`, warranty credit `1.000001`, full remainder `1.000000`, replayed balance `5.000000`. Dropped in-memory wakeups were recovered by the actual outbound command; two actual HTTP requests to a local Telegram API fixture delivered the immutable exact receipts once despite repeated job execution. Both effects became `sent`; no IDR ledger entry was created. The fixture is not live Telegram or supplier/custody qualification.

Observed final focused verification: 81 SQLite/service/Telegram regression tests / 597 assertions; 27 disposable PostgreSQL refund/ledger tests / 257 assertions, including full/partial/actual-purchase races, duplicate warranty/missing entitlements, foreign Order/currency/Payment/debit checks, supplier ambiguity, receipt-failure rollback, retained-review currency rollback protection and ledger fixture isolation. The four-character review schema and original-entry index were exercised against PostgreSQL; a session-scoped diagnostic with sequential scans disabled confirmed the cap query can use the new composite index. This is not a production performance benchmark.

The final separate PostgreSQL checkout, warranty, fulfillment, IDR ledger and manual-review regression run passed 105 tests / 1,045 assertions. Combined with the focused run, all 132 PostgreSQL tests / 1,302 assertions passed. All verification used disposable local data; no CI workflow or deployment was invoked.

## Balance operational controls, alerts and restore gate (O2 #558)

`CustomerUsdtFundingPauseScope` now also carries `delivery`, stored in the same Owner-only audited `customer_usdt_funding_pauses` table (policy row lock, Admin Stats card). A delivery pause defers claiming/dispatching the balance receipt families (`balance.top_up_receipt`, `usdt.top_up_receipt`, `usdt.refund_receipt`): `TelegramBalanceReceiptDeliveryPause` is read on every `TelegramOutboundEffectPolicy::canClaim()`/`applyRetryableConstraints()` evaluation, so a pause activated while a worker runs applies to its next claim. Effects keep their row, key and `attempts = 0` and are delivered unchanged after `Lanjutkan`; settlement, refund, reconciliation and the creation of the durable effect row inside the money transaction are never gated. `UsdtTopUpOutbound` records a bounded `outbound_delivery_outcome` metric (`skipped_paused`, `claimed`, `sent`, `failed`, `unknown`).

Operational metrics are append-only and bounded by construction: `balance_operation_events` stores only closed `BalanceOperationMetric` outcomes, a fixed duration bucket and a bounded integer value (PostgreSQL rejects UPDATE for every role; DELETE is reserved for the retention prune). `BalanceOperationEventRecorder` never throws and writes only after a money transaction ended; `BalanceOperationRetry` mirrors `DB::transaction($cb, $attempts)` semantics but records one `lock_retry` event per retried attempt (deadlock vs serialization + duration bucket) in `CustomerUsdtBalanceLedger::apply()`, `ExternalMoneyReceiptClaims::claim()` and `CustomerUsdtFundingReservationService::reserve()`. `usdt-balance:metrics` renders the bounded report (counters window, backlog ages, drift/frozen accounts, coverage, receipt conflicts, pause/gate controls) and prunes aged events.

`BalanceOperationsAlertService` reconciles closed alert keys into `balance_operations_alert_episodes` and materializes one durable `admin.balance_operations_alert` Telegram Outbound Effect per newly opened/reopened/fingerprint-changed episode plus one resolution effect; the effect carries the rendered sanitized message, so a later episode change never rewrites a pending delivery. The evaluator tolerates a concurrent insert of the same episode/effect (savepoint + bounded retry), which the PostgreSQL lane proves. `ops:balance-alerts` runs every five minutes; `telegram:recover-outbound` rediscovers the family. Messages and metrics never contain customer ids, Telegram ids, addresses, hashes or secrets.

`BalanceRestoreGateService`/`BalanceRestoreGateReader` own the singleton `balance_restore_gates` row. `usdt-balance:restore-open` records the declared restore window and blocks new USDT funding/spending through `CustomerUsdtFundingGate` (`restore_gate_open`); `usdt-balance:restore-replay` re-inspects every non-terminal funding target, optionally re-runs `usdt-bsc:scan`, and quarantines non-terminal balance deliveries as `unknown` (`restore_ambiguous_delivery`) so nothing is blindly resent; `usdt-balance:restore-close` refuses until a replay completed after the gate opened **and** a full-scope reconciliation run recorded after that replay is consistent with zero account/receipt findings and `unexplained_micro = 0`. `usdt-balance:unfreeze` clears only a recognized drift freeze whose fresh re-proof finds no drift. Receiver reorg markers, durable receiver evidence (including before incident materialization or under an existing drift freeze) and incident rows keep the hold; unknown freeze reasons return `unsupported_freeze`. Exact procedure, roles, windows and limitations: [balance operations](../runbook-balance-operations.md) and [restore/rollback](../runbook-balance-restore-rollback.md) runbooks; decision record [ADR-0023](../adr/0023-balance-operational-controls.md).

Verification entry points: `BalanceReceiptDeliveryPauseTest` (defer/resume, unrelated families, no money gating), `BalanceOperationsMetricsTest` (fixed shape, counters, prune, bounded-cardinality column allowlists), `BalanceOperationsAlertTest` + `AdminBalanceOperationsPanelTest` (episode lifecycle, single sanitized effect, read-only Stats card), `BalanceRestoreGateTest` (gate blocks funding/spending only, close refusals, quarantine, history preservation), `UsdtBalanceUnfreezeTest`, and `BalanceOperationsPostgresConcurrencyTest` (gate vs concurrent reservations, pause vs concurrent claims, concurrent alert evaluation producing one episode/effect, unfreeze race, metric UPDATE denial + retention DELETE). `scripts/balance-restore-drill.sh` executes the disposable PostgreSQL restore drill; observed evidence is recorded in [balance-o2-operations.md](../balance-o2-operations.md). No CI workflow, deployment, production mutation, live funds or custody qualification is part of this package.

The [approved H0-v1 decision register](../balance-h0-operational-decisions.md) records #538 field values, Owner/date and written sign-off. The Owner approved single-operator manual IDR/full reversal with per-action password, immutable history and durable notifications; [ADR-0020](../adr/0020-single-owner-balance-adjustments.md) defines compensating controls plus additional acceptance before activation. H0-v1 selects no business amount/exposure cap, all existing bot Customers, manual USDT input and application fee 0. It supersedes proposed dual-approval/capped-beta policy only. The [implementation plan](../superpowers/plans/2026-10-04-balance-h0-implementation-plan.md) preserves receipt/accounting/provider/race/crash/performance gates; #541 is still a prerequisite for #543. Runtime features remain unimplemented; no global Owner bypass or source-less automatic USDT credit is authorized.

## Balance performance evidence (V2 #560)

`scripts/balance-v2-benchmark.sh` owns the disposable PostgreSQL/Redis benchmark. The guarded PHP harness measures actual IDR/USDT ledger owners with independent processes and scheduled arrivals; auxiliary probes measure paginated history, funding reservations, provider outage/budget/fairness and Redis-loss outbound recovery. Source/hardware/dataset metadata, raw percentiles, query plans and post-load correctness checks are retained per run. See [V2 benchmark profiles and evidence](../balance-v2-benchmark.md). Short profiles are diagnostics only; full performance evidence and operator capacity acceptance remain separate gates. No production endpoint is accepted by the wrapper.

## Configuration boundary (current runtime)

The [H1 custody decision](../balance-h1-custody-decision.md) and [ADR-0021](../adr/0021-usdt-exclusive-external-receivers.md) select permanently Customer-exclusive public receivers with external signing for the BSC funding design. Receiver capacity/control, token/bridge provenance, legal/provider qualification, treasury/gas and external returns remain unproven; activation remains no-go. GitHub closure of #539 does not qualify these obligations or authorize activation. Binance Pay funding is separately unqualified. These decisions do not activate or change existing checkout.

`ENV` hanya untuk credential/secret dan nilai deployment yang memang tidak boleh berada di database. Jangan menambah ENV untuk data katalog atau presentation customer. Icon Product wajib nullable di database dan diedit dari Admin Dashboard; perubahan tidak boleh membutuhkan rebuild image atau clear config.

## 1. Topology repository dan runtime

```text
repository root
├── src/                    Laravel application
├── docs/                   ADR, runbook, specs, agent guidance
├── scripts/                release gate, smoke test, benchmark, backup
├── docker-compose.yml      app + Nginx + PostgreSQL + Redis + workers + scheduler
├── docker-entrypoint.sh    migration/optimization/cutover guards saat container start
└── supervisord.conf        PHP-FPM dan queue worker pools
```

`src/` memakai PHP 8.3 dan Laravel 13. Aset frontend dibangun dengan Vite, Tailwind, dan Alpine. Runtime production-like terdiri dari:

- PHP-FPM/Laravel app di belakang Nginx;
- PostgreSQL sebagai database utama dan penyimpanan durable untuk domain serta failed jobs;
- Redis untuk queue, cache, session, dan lock; `maxmemory=96mb`, container `128m`, tanpa persistence atau persistent volume (`/data` memakai tmpfs 1 MiB);
- service scheduler yang menjalankan Laravel scheduler tiap menit;
- Telegram Bot API, KlikQRIS, Binance, and (until the retirement gate passes) Pakasir legacy settlement as external dependencies.

Queue Redis bersifat volatile: restart Redis dapat menghilangkan queued jobs dan session/cache/lock state. Queue operasional dipisah per beban kerja: `default` untuk update/interaksi bot, `payments` untuk verifikasi pembayaran, `telegram-receipts` khusus unit receipt kredensial beserta successor/fallback-nya, `notifications` untuk email/admin notice/proof/cleanup, serta `telegram-outbound`, `telegram-broadcast`, `telegram-stock`, `binance-verification`, dan `suppliers`. `QueueTopology` adalah satu-satunya daftar nama queue yang dipakai health summary, readiness, cutover, dan test consumer. Jangan menggabungkan `payments` dengan `default`, atau receipt dengan `notifications`, karena worker priority tidak mem-preempt job yang sedang berjalan. Detail batas worker, timeout, dan cutover ada di [topologi queue Telegram](../telegram-queue-topology.md).

### Isolasi receipt dan verifikasi pembayaran (#651)

`TelegramOutboundEffectService::RECEIPT_QUEUE` adalah `telegram-receipts`;
family `receipt.credentials`, `receipt.order_actions` dan
`receipt.txt_download` memakai queue itu untuk unit pertama, successor,
text fallback, TXT download, action panel dan resend. `TelegramOutboundEffectDispatcher::queueFor()`
menurunkan queue dari tipe effect, sehingga recovery atau baris lama yang masih
menyimpan `notifications` tidak dapat mengembalikan receipt ke worker bersama.
`NOTIFICATION_QUEUE` tetap `notifications` untuk email, admin notice, proof,
Sales Proof, Store Balance notice dan cleanup receipt.

`telegram:receipt-queue-cutover` memindahkan metadata effect receipt dan
wakeup pending/delayed dari queue lama ke queue baru; reserved Redis job tidak
dicuri dari owner-nya. Urutan drain, rollback dan batas timeout ada di
[runbook](../runbook-telegram-outbound-effect-contract.md#dedicated-receipt-queue-cutover-651).

Verification entry points: `QueueConsumerTopologyTest` (consumer/isolation/
timeout dari `supervisord.conf`), `TelegramReceiptQueueCutoverTest` (metadata,
database jobs, dry-run), `TelegramReceiptPostgresConcurrencyTest` (dua worker
pada satu receipt multi-chunk, exactly-once dan fencing), serta
`TelegramReceiptOutboundEffectTest` untuk ordering/ambiguity. Bukti lokal:
[telegram-latency-baseline-evidence.md](../telegram-latency-baseline-evidence.md#issue-651-queue-isolation).

### Status provider di luar lock interaksi chat (#653)

`TelegramOrderIntents` hanya membaca snapshot milik Customer, menerapkan rate
limit KlikQRIS/Pakasir existing, mengantrekan `VerifyProviderPaymentStatus`
dan menampilkan Payment Verification Pending. Lookup status tidak lagi berada
di lock `TelegramChatInteractionLock`. Fast callback ACK tetap hanya dimiliki
`TelegramWebhookIngress`; ACK bukan completion verifikasi.

Job memakai `QueueTopology::PAYMENTS` pada koneksi queue runtime yang sama
dengan worker #651, bukan koneksi alternatif yang tidak dilayani. Runtime
memerlukan koneksi asynchronous Redis/database, bukan `sync`. `ShouldBeUnique`
mengikat satu Payment selama antrean, eksekusi dan retry (lease uniqueness
600 detik); rate limit tetap membatasi callback setelah job selesai. Timeout
job 60 detik berada di bawah default Redis `retry_after` 90 detik dan database
180 detik; tiga percobaan dengan backoff 15/60 detik tidak mengganti retry HTTP
finansial existing. Profile provider worker adalah `Reconciliation`, bukan
budget `Interactive` milik handler.

Payload hanya membawa Payment ID, Customer ID, provider dan timestamp enqueue.
Worker membaca ulang binding Customer/provider dan eligibility sebelum HTTP.
Hasil authoritative tetap masuk `KlikQrisCallbackProcessor` atau
`PakasirCallbackProcessor`; Pakasir tidak memperoleh jalur checkout baru.
Exception tetap dilaporkan oleh worker, retry tidak mengubah Order menjadi
unpaid/cancelled, dan eskalasi KlikQRIS tetap melalui
`TelegramKlikQrisRecoveryEscalator`. Rekonsiliasi terjadwal existing memulihkan
status jika wakeup Redis hilang atau retry habis, tanpa outbox baru.

Completion job tidak memanggil ulang renderer detail dan tidak mengedit root
atau menu. Karena tidak membawa target render, hasil terlambat tidak dapat
menimpa root yang sudah diganti. Receipt dan pemulihan QR sequence 2 tetap
memakai lifecycle/outbound effect existing yang Customer-scoped dan idempotent.
Customer dapat membuka detail lagi untuk snapshot status terbaru.

Verification entry points: `ProviderStatusVerificationTest`,
`KlikQrisCheckoutTest`, `WebhookIngressTest`, `KlikQrisWebhookTest`,
`PakasirWebhookTest` dan kedua reconciliation suite. Kontrak uniqueness
dicocokkan melalui Context7 dengan
[Laravel 13 queues](https://github.com/laravel/docs/blob/13.x/queues.md):
gunakan `ShouldBeUnique`, bukan `ShouldBeUniqueUntilProcessing`, agar lock
uniqueness tidak dilepas sebelum HTTP selesai. Bukti runtime terisolasi:
[status refresh smoke](../telegram-latency-baseline-evidence.md#issue-653-status-refresh).

### Tanpa pin receipt kredensial otomatis (#668)

Keputusan produk #668 (S03, parent #666) menggantikan fitur pin #652.
`ReceiptDeliveryPipeline` dan `telegram:recover-outbound` tidak meng-enqueue
`PinTelegramCredentialReceipt`, tidak menjalankan service pin dan tidak
memanggil `pinChatMessage`. Job/service, queue operasional `telegram-pins`,
worker khusus dan persyaratan health-nya dihapus tanpa compatibility stub.

Transisi ledger `sent`, successor berurutan, fallback, cleanup dan admin
wakeup tetap memakai owner yang sama. Delivery `unknown`/ambiguous tetap
memerlukan review, bukan blind resend. List Order dan detail Order yang
terotorisasi tetap menjadi akses kredensial. Pin Telegram yang sudah ada tidak
di-unpin; kolom pointer/claim historis Order beserta migrasinya dipertahankan
tanpa pembacaan atau penulisan runtime.

[ADR 0031](../adr/0031-credential-pin-outside-receipt-critical-path.md) hanya
superseded untuk fitur pin; prinsip optional work tidak boleh menghambat
receipt, successor atau admin wakeup tetap berlaku. Konfigurasi lokal
Supervisor berkurang satu proses (`numprocs=1`), bukan bukti penghematan resource
production. Bukti #652 dan skenario pin tetap merupakan sejarah, bukan kontrak
runtime aktif.

Sebelum deploy kode tanpa class job lama, ikuti
[rencana cutover terarah](../runbook-telegram-outbound-effect-contract.md#automatic-credential-pin-retirement-668):
hentikan producer/recovery/worker lama secara graceful termasuk in-flight
jobs, lalu periksa dan hapus hanya payload class pin yang retired dari ready,
delayed, reserved dan failed storage. Jangan purge queue/Redis global atau
mengubah receipt ledger dan job lain.

Verifikasi lokal 2026-10-28: sembilan suite receipt/no-pin/action-panel,
List Order, topology/health, baseline command/report dan supplier fulfillment
lulus 265 test / 2.765 assertion. Race receipt PostgreSQL lulus 1 test /
18 assertion; Pint dan diagnostik pipeline/baseline lulus. Smoke disposable
PostgreSQL 16 / Redis 7 dengan `TelegramBotClient` asli dan HTTP fixture
(stray request diblokir) menjalankan worker Redis, menghilangkan wakeup Redis,
memulihkan receipt satu akun serta foto+TXT tiga akun, mengirim notifikasi admin,
dan mengulang recovery tanpa resend. Delivery ambiguous tetap `unknown` tanpa
successor; seluruh skenario menghasilkan nol panggilan pin dan nol job pin.
Processor update Telegram asli membuka callback List Order milik Customer
hingga kredensial dan menolak callback Customer lain.

Smoke cutover lokal menghapus hanya enam payload dengan `data.commandName`
dan identitas class serialized pin lama yang cocok dari ready/delayed/reserved
di queue pin serta queue campuran `notifications`, ditambah satu failed pin.
Receipt sentinel di setiap penyimpanan dan failed receipt lain tetap utuh;
tidak ada class lama yang di-unserialize atau dijalankan. Worker sudah berhenti
sebelum pembersihan reserved. Ini membuktikan prosedur fixture disposable,
bukan drain production atau seluruh variasi payload historis production.

Runner benchmark aktif lulus `idle`, `redis-loss`, `ambiguous-telegram`
(masing-masing satu warm sample; tiga worker lokal tanpa pin, final audit
semuanya benar). Artefak lokal:
`artifacts/telegram-latency-baseline/20261028T072925Z-163610/`.
Parser Supervisor menghitung sembilan program queue worker, satu lebih sedikit
dari topologi #652. Angka ini bukan pengukuran CPU/RAM production. Rujukan
Context7: Laravel 13 `/laravel/docs/__branch__13.x`,
[queue lifecycle](https://github.com/laravel/docs/blob/13.x/queues.md).
Tidak ada CI/CD, deploy, akses production, pembayaran nyata atau pesan pelanggan.

Startup menolak cutover selama tabel database queue berisi job; rollback ke driver database menolak berpindah selama queue Redis tidak kosong atau tidak dapat diperiksa. Lihat [ADR 0014](../adr/0014-redis-runtime-state.md) dan [runbook cutover](../runbook-redis-runtime-cutover.md).

Suite PHPUnit biasa dipaksa menggunakan SQLite in-memory, cache array, queue sync, dan session array lewat [`src/phpunit.xml`](../../src/phpunit.xml). Itu cepat tetapi tidak membuktikan seluruh locking PostgreSQL; gunakan `composer test:concurrency-gate` untuk race yang menyentuh stok, checkout, payment, atau admin mutation.

Bootstrap utama ada di [`src/bootstrap/app.php`](../../src/bootstrap/app.php): route web/API/console, health endpoint `/up`, middleware alias admin/storefront, trusted proxy/host, dan JSON exception behavior untuk `/api/*`. Binding dependency penting ada di [`AppServiceProvider`](../../src/app/Providers/AppServiceProvider.php).

## 2. Bahasa domain dan aggregate utama

Istilah harus mengikuti [glossary](../../CONTEXT.md). Aggregate dan tabel utama berhubungan seperti ini:

```text
Customer
  ├── Cart ── CartItem ── Product ── ProductStockItem
  └── Order ── OrderItem ── Product
               ├── Payment ── PaymentEvent
               ├── InventoryMovement
               ├── SalesProofPost
               └── AuditLog
```

### Customer

`Customer` mewakili pembeli. Customer Telegram diidentifikasi oleh `telegram_id`; customer Web Storefront dapat dibuat tanpa Telegram ID dan memakai Recovery Key/order access. Jangan menganggap customer selalu punya chat Telegram.

Customer authentication foundation (#608) extends the existing account, not admin `User`. The additive migration `2026_10_11_100000_add_customer_authentication_contract.php` adds nullable hashed `password`, hidden/guarded `auth_version` and `enrollment_version`, `customer_identities` and `customer_auth_tokens`. `first_name` remains the account-name profile owner. Existing nullable email/Telegram identity, magic-link routes and financial relationships remain unchanged; `auth.php` already isolates `customer`/`customers` from admin `web`/`users` and needs no configuration cutover.

`CustomerIdentityProvider` closes providers to Google/Telegram; unique provider/subject and Customer/provider constraints reject competing ownership. `CustomerIdentityLinkOutcome` defines downstream linked/replay/provider-slot/foreign-subject/canonical-Telegram-conflict outcomes. PostgreSQL triggers enforce immutable identity binding and exact same-Customer `customers.telegram_id` ownership in both mutation directions. No provider access/refresh token column exists. Trusted linking owners must recover uniqueness races inside a savepoint, require fresh proof and never merge Customer, Order, receiver or balance rows.

Google authentication (#613) is owned by `CustomerGoogleAuthenticationService` and the narrow authorization-code `GoogleOAuthProvider`. `customer_google_auth_claims` commits one-use hashed browser/configuration/operation state before provider IO; Google userinfo must supply a valid subject and strictly verified email. Only explicit registration creates a Customer; email collision requires fresh authentication and explicit linking. Customer-first locks and uniqueness savepoints prevent subject/slot races without merges. Link/unlink require five-minute fresh version-bound proof, preserve its timestamp and rotate the session only after commit; unlink also requires verified email or exact canonical Telegram as another login method. Nullable-email Google sessions bind the exact persisted identity ID. Account controls depend on credential/HTTPS-origin readiness; callback query capabilities are excluded from Laravel previous-URL history and Nginx callback logs. Evidence: `CustomerGoogleAuthenticationTest`, `CustomerGoogleAuthenticationPostgresTest` and [Google operations](../customer-web-account-operations.md#google-authentication-and-explicit-linking-613). Live credentials/consent/redirect qualification remains operator-gated.

`CustomerAuthTokenPurpose` sets 30-minute verification/reset, 10-minute OTP and 5-minute Telegram deadlines. `CustomerAuthToken` guards all fields, hides private bindings/digests, and exposes only purpose/expiry/consumption/attempt eligibility through `isActiveFor()`, not atomic consume. PostgreSQL enforces valid digest/binding/TTL/attempt bounds plus immutable bindings, monotonic attempts and irreversible consumption. OTP digest issuance must use a server-secret keyed hash; five durable failures exhaust it. Email challenges bind normalized email or Customer; anonymous Telegram challenges bind their browser/session without inventing an email or Customer. Customer/enrollment versions and proposed password hash support later enrollment/reset fencing. #609/#610/#611 own issuance, locked POST consumption, session revocation, encrypted durable mail and private Telegram proof.

`CustomerAuthenticationService` still issues/consumes the existing 15-minute `CustomerLoginLink`; the link model now hides email/hash/IP from serialization. `CustomerOrderClaimService` and `StorefrontOrderAccess` retain invoice-plus-Recovery-Key authority. Focused evidence: `CustomerAuthContractTest`, `CustomerAuthContractPostgresTest` (repository PostgreSQL lane, provider uniqueness races and canonical/token constraints), `CustomerAccountTest`, `CustomerBalanceWebTest`, `StorefrontOrderAccessTest`, `StorefrontShopCheckoutTest`, and `AdminDashboardAccessTest`. See [ADR-0001's authentication extension](../adr/0001-web-storefront-order-access.md) and the [canonical spec section 12](../superpowers/specs/2026-10-11-customer-web-account-authentication-design.md#12-agent-implementation-clarifications).

Password authentication (#609) is owned by `CustomerPasswordAuthenticationService`: new pending registration, locked purpose/email/auth-version/enrollment-version verification and reset consumption, verified password login, and fresh first-password enrollment for legacy Customers. Duplicate registration never mutates an existing Customer. `CustomerPasswordHasher` uses the configured Laravel hasher over a versioned domain-separated SHA-384/base64 input so 128-character and Unicode passwords retain their suffix beyond bcrypt's 72-byte boundary; the additive `password_scheme` is hidden/guarded and raw legacy hashes migrate only after successful proof.

`CustomerSessionAuthentication` rotates session/CSRF state and destroys the prior session ID while preserving admin guard data. `ValidateCustomerSession` fences every `/akun` route by verification and `auth_version`, allowing missing-version legacy sessions only at version zero; rejection stops protected-route dispatch even when Laravel runs `auth:customer` earlier in middleware priority. Reset increments authentication/enrollment versions and revokes all outstanding Customer challenges and legacy links without editing Orders, Recovery Keys, balances or Telegram identity. Legacy magic-link consumption now shares Customer-first locking and current session/version ownership.

`CustomerAuthMailDeliveryService` atomically persists encrypted verification/reset/OTP or retained magic-link mail work beside its grant. `CustomerAuthMailWork` binds exactly one shared token or legacy link through restrictive FKs. Encrypted ID-only `SendCustomerAuthMail` jobs use the existing `notifications` queue; bounded `customer-auth:recover-mail` rediscovery runs each minute. Attempt-fenced 120-second leases keep SMTP outside locks, retry the same grant after worker/Redis loss, reject stale work, and erase terminal payloads. Secret-bearing notifications are encrypted if queued directly; auth delivery refuses logging transports, including log fallbacks. `CustomerAuthenticationRateLimiter` owns HMAC-keyed shared mail bounds, password failures and OTP consume-IP limits and fails closed when storage is unavailable.

Verification: `CustomerPasswordAuthenticationTest` plus `CustomerPasswordAuthenticationPostgresTest` (competing registrations and three-process verification/reset races), existing Customer account/balance/guest/Recovery Key/admin suites, production asset build and disposable browser form flows. See [password authentication and mail operations](../customer-web-account-operations.md#password-authentication-609). Actual Resend inbox delivery remains an operator-gated release check.

Email OTP (#610) is owned by `CustomerEmailOtpService` and the CSRF-protected `customer.login-code.request` / `customer.login-code.consume` routes. Six-digit codes preserve leading zeros and use an application-keyed, purpose/email/challenge-bound digest. The challenge also binds the originating browser, verified Customer and auth/enrollment versions. Customer-first locks serialize one-use consumption, five durable failures and a 60-second resend cooldown; resending atomically revokes the prior challenge. Unknown-email requests return the same guidance without creating a Customer. Successful OTP grants a fresh Customer session, not password-reset authority. #612 owns form integration; the controller provides normalized email and opaque challenge session state.

`customer-auth:cleanup --limit=500` runs hourly, deleting one bounded consumed/expired page per token table only after associated mail-work retention ends; the legacy cleanup command delegates to that owner. `customer-auth:mail-health` reports bounded safe counts without decrypting work. Resend uses the current SMTP/config/Compose owners with required STARTTLS on port 587 and sender `no-reply@notify.lhiestore.web.id`. The token-free non-production `customer-auth:smoke-mail` command requires explicit inbox/tracking approval and distinguishes SMTP acceptance from inbox delivery. Details: [email code and mail operations](../customer-web-account-operations.md#email-code-contract-610).

Verification entry points: `CustomerEmailOtpTest`, `CustomerEmailOtpPostgresTest` (competing issuance, one-winner consumption and six concurrent failures capped at five), `CustomerAuthMailDeliveryTest`, `CustomerAuthMaintenanceTest` and `CustomerAuthMailOperationsTest`, alongside the existing password/account/guest/Recovery Key/admin suites. Disposable runtime smoke uses the actual sync worker and rendered array mail, rotates a Customer session, rejects OTP replay and exercises cleanup after mail retention. Real Resend inbox evidence still requires the operator's sending-only key and approved inbox; no production deployment or CI operation is authorized.

Telegram authentication (#611) is owned by `CustomerTelegramAuthenticationService` and `customer_telegram_auth_claims`: immutable five-minute browser/operation/Customer/version binding, private actor preparation, explicit one-use Bot confirmation, then separate atomic browser consumption. `TelegramUpdateProcessor` intercepts reserved `wa_` / `wa_confirm:` payloads before Customer resolution; unused actors cannot be auto-registered during linking. Authentication update envelopes use encryption in the existing update column. Link rejects occupied/foreign canonical or provider ownership; legacy login materializes only the identity matching that exact existing Customer. Customer-first locks, a savepoint and PostgreSQL constraints serialize ownership races.

`CustomerAccountController` exposes session-only Telegram login/link/status/consume routes. Fresh linking and keyed operation limits fail closed. `ValidateCustomerSession` admits nullable-email Telegram Customers only with a proof-session marker and exact persisted canonical identity; generic authentication/logout clears the marker. Fresh Telegram email enrollment reuses `CustomerPasswordAuthenticationService` and encrypted verification mail on the same Customer without a password or account merge. Cleanup includes bounded failed/consumed/expired Telegram claims. Evidence: `CustomerTelegramAuthenticationTest`, `Telegram/WebAuthenticationWebhookTest`, `CustomerTelegramAuthenticationPostgresTest` and the isolated ingress/processor/Bot-adapter runtime. See [Telegram operations](../customer-web-account-operations.md#telegram-two-sided-proof-611); live Bot/browser rollout remains operator-gated.

Compatibility (#614) keeps account authorization on the canonical `customer` guard, including IDR/USDT overview, activity and signed cursors. `CustomerOrderClaimService` locks claimant and discovered owner in ascending Customer ID order before the Order and rechecks ownership; nullable email does not classify a persisted Google identity, password or verified-history owner as a guest. Invoice plus Recovery Key and paid status remain mandatory, competing claims produce one owner, and the original key remains usable after claim. `AuditLogger` resolves implicit admin actors only through `web`, so a Customer session cannot become an admin audit FK during signed invoice or checkout events. Evidence: `CustomerAuthenticationCompatibilityTest`, `CustomerOrderClaimCompatibilityTest` and `CustomerOrderClaimPostgresTest`; migration/support/rollback policy is in [account operations](../customer-web-account-operations.md#legacy-rollout-and-ownership-compatibility-614). No Customer backfill, forced enrollment, merge, balance move or Recovery Key regeneration occurs.

Customer API credentials/admission (#621) use `CustomerApiCredentials`, `TelegramCustomerApiActions` and the global v1 `CustomerApiBoundary`, isolated from all browser/Admin/supplier guards. A unique selector and digest authenticate without a positive cache; Customer-serialized lifecycle and a partial unique index enforce one active credential. `CustomerApiPrincipal::revalidateForMutation()` is the terminal shared-row-lock fence inside API checkout transactions. `CustomerApiAdmission` uses atomic shared Redis budgets and owner-fenced 90-second permits; unsupported/unavailable shared state fails closed. `customer-api:flush-usage` drains bounded lossy account aggregates, never per-request DB counters. Contract, lock order, logging boundaries and verification: [Customer API key operations](../customer-api-key-operations.md).

Customer API runtime availability (#647) is owned by `CustomerApiAvailability` and the singleton `customer_api_settings`, seeded OFF. The boundary reads it once per matched `/api/v1` request after IP admission and before body/auth/financial work; OFF returns `503 api_maintenance` (`Retry-After: 60`) for every path and method, while an unreadable row uses the existing `temporarily_unavailable` path. The Owner-only Admin action at `tab=settings&section=pembayaran` is optimistic-versioned, audited and no-op-safe. The Store Bot hides the fresh `menu.api` row and renders localized ID/EN/MS maintenance for stale `api:*` callbacks without calling key/preference actions. OFF preserves all credentials, claims, Orders, ledgers and delivery results. Tests: `CustomerApiAvailabilityTest`, `CustomerApiAvailabilityBoundaryTest`, and the OFF branches in `TelegramCustomerApiManagementTest`.

Private Store Bot API management (#627) uses `TelegramCustomerApiCallbackHandler` and `TelegramCustomerApiIntents` on the canonical navigation root. The DB-backed `menu.api` icon follows existing Admin/capture/Unicode fallback paths. Generate sends a memory-only secret once outside transactions; reopening shows only the mask, creation time and eventual account admission metadata. Rotation and disable require purpose/key/message-bound opaque confirmations; stale callbacks cannot revoke a newer key. Four payment-preference choices update the S03 Customer enum without altering explicit checkout rails. Documentation links to the existing `/api-docs` route. Tests: `TelegramCustomerApiManagementTest`, `MenuIconCaptureTest`, `CustomerApiCredentialsTest`, and the PostgreSQL credential/admission suites. See the S08 walkthrough in [Customer API key operations](../customer-api-key-operations.md#store-bot-screens-627).


Customer API reads (#622) use `CustomerApiReadController` for bounded input and own-wallet mapping, and `CustomerApiCatalog` for ascending-ID keyset Product pages with explicit response allowlists. Visibility/availability use canonical Product scopes, family icons use `CatalogFamilyIconResolver`, and batched quotes use `ProductQuantityPricing::quoteMany()` with the admitted Customer's reseller context. IDR/USDT ledger snapshots remain the money owners; public USD is exact boundary mapping, and frozen/drifted wallets expose null amounts rather than fake zeros. No stock credential model, provider HTTP, supplier economic relation, financial cache or new index is introduced. Schemas, errors, examples and disposable PostgreSQL/Redis HTTP evidence: [Customer API reads](../customer-api-reads.md).

Customer API orders (#623) add `POST /api/v1/orders` through `CustomerApiOrderController`,
`CustomerApiOrderCheckout` and the durable claim owner `CustomerApiOrderClaims`. A claim is
uniquely guarded by Customer + `create_order` + `Idempotency-Key`, carries the canonical
payload fingerprint, the resolved preference snapshot, the admitted credential id, a fenced
lease and (once accepted) exactly one Order. The namespaced `api-` checkout key is derived
only from the durable claim, so the shared `OrderCheckoutService` still owns pricing, stock,
credential allocation, the single currency debit and the paid lifecycle, and a committed
Order is replayable without current stock or price. The API Cart and item are created inside
the checkout transaction with `carts.origin = api`, which is immutable and filtered by every
customer-facing cart read and write; a rolled-back request leaves zero cart rows. The API
transaction locks the Customer shared and calls `CustomerApiPrincipal::revalidateForMutation()`
before any write. Contract, error table, lock order and evidence: [Customer API orders](../customer-api-orders.md);
decision: [ADR-0025](../adr/0025-api-order-idempotency-claims.md).

Issue #637 hardens that claim boundary: takeover lock waits use at most 500 ms and
never more than the monotonic two-second wait's remaining time. Model saves and the
PostgreSQL shape constraint require `accepted` if and only if `order_id` is present.
The upgrade migration locks and checks existing claims before replacing the constraint
atomically; inconsistent legacy bindings stop migration without changing claim history
or dropping the old constraint. Reconcile those bindings before retrying migration.
`CustomerApiOrderTest` covers application rejection; `CustomerApiOrderPostgresConcurrencyTest`
covers direct writes, valid/invalid legacy upgrades, commit-during-wait replay and
timeout without takeover, alongside the existing crash and recovery cases.

Customer API order recovery (#624) uses `CustomerApiOrderReadController` and
`CustomerApiOrders` for Customer + immutable API-origin history/status/results, independent
of the creating key version. Ascending UUID keysets are bounded to 100 summaries;
`CredentialDeliveryEligibility::rejectionReasons()` batches the shared paid/lifecycle,
supplier partial-release and late-review gate without credential hydration. Delivery
holds the owned Order shared, rechecks that gate on every request, loads one bounded
page of non-retired allocated units in ascending identity order after it, and reuses
`CredentialDeliveryPresentation`. Pages are byte-sized from the exact unit JSON under
a 1 MiB response bound; an authenticated opaque `pagination.next_cursor` continues the
same traversal without changing whole-Order progress or logical account numbers. GET
never purchases, reallocates, debits, refunds or resends. `suppliers:recover` and the existing `suppliers` worker recover
the committed obligation after volatile Redis loss; unknown dispatched purchases retain
review/holds. Schemas, partial/refund semantics, query plans and disposable worker-loss
HTTP evidence: [Customer API orders](../customer-api-orders.md#owned-history-status-and-delivery-s07).

Customer API notifications (#625) snapshot `api_order_claim_id` before paid
lifecycle work. `SalesProofEligibility` preserves other-channel proof timing
and requires paid release authority, complete non-retired allocations, no
pending supplier fulfillment and no open review for API proof. The existing
public invoice/caption and private paid Owner notice identify API origin.
Supplier review reuses the Admin pipeline and paid worker with an independent
`admin.order_review_notification` effect per `ManualFulfillmentReview`.
Bounded `telegram:recover-outbound` source discovery recovers missing API
proof/review handoffs; the shared protocol retains sent/unknown semantics.
Local numeric-stock checkout (#635) also commits one `local_credentials_missing`
review per paid Payment when ordered units are positive, no local credentials are
allocated and no supplier fulfillment exists. Its Owner review effect and missing-target
recovery cover bot and API Orders; Late Payments reuse `late_payment`. API delivery
counts remain allocation-derived, never fabricated for an external manual handoff.
Lifecycle fixtures keep the distinction explicit: managed-stock cases allocate a real
Product Stock Item before checkout, while numeric-stock cases opt out and assert the
durable review with no credential release. Paid and review notices are separate
`admin.order_paid_notification` / `admin.order_review_notification` effects, so
queue assertions match `effectId`, not the shared PHP job class.
Contract and evidence: [API notifications](../customer-api-orders.md#public-proof-and-private-owner-notifications-s09).

Customer API public documentation (#626) is published from one canonical description in
`App\ApiDocs\DocumentationSet`: `CustomerApiDocumentation` owns the operations, schemas,
enums, bounds, budgets and machine error codes, `CustomerApiGuide` the bilingual
explanations, `CustomerApiExamples` the runnable cURL/Python/JavaScript/PHP examples and
`CustomerApiSamples` the JSON examples, while `DocumentationTables`, `OpenApiDocument`,
`ReferenceMarkdown` and `LlmsIndex` render them. `StorefrontApiDocsController` serves
`/api-docs`, `/api-docs/openapi.json` and `/api-docs/reference.md`; `StorefrontLlmsController`
serves the root `/llms.txt`; `StorefrontSeo::apiDocs()` supplies metadata and the sitemap
lists the page. The page is a standalone storefront document whose explanations, tables and
code samples are readable without JavaScript; `storefront/api-docs.js` only reveals the
optional language, sample, filter and copy controls. All artifacts render from the same
classes, so a contract change lands in one place. Contract, accessibility decisions and
evidence: [Customer API public documentation](../customer-api-public-docs.md).

S11 integrated acceptance (#628) is recorded in
[Customer API qualification](../customer-api-s11-acceptance.md). Independent
PostgreSQL API/Bot/price/refund/receipt/revocation races are combined with
actual disposable HTTP, worker/Redis-loss, Telegram-adapter, browser and
1/20/100-row measurements. Qualification found the API Cart FK/Product lock
cycle: checkout and nested inventory/supplier lifecycle Product writes use
PostgreSQL `FOR NO KEY UPDATE`, not an FK-conflicting `FOR UPDATE`. Sold-out
normal/late sales now register the existing durable Stock Notification Event
and defer audience fan-out to the stock worker, matching other stock writers.
`Product::withAvailability()` uses an indexed scalar `SELECT 1 ... LIMIT 1`
for local credential existence, avoiding PostgreSQL's whole-stock-key hashed
projection; availability counts and legacy/retired-stock semantics remain
unchanged. The qualification retains actual plans, not just query counts.
No new financial architecture, queue or production activation gate is introduced.



### Product dan stock

`Product` adalah katalog yang dapat aktif/nonaktif, dapat berada dalam `Category`, dan punya `stock` serta `reserved_stock` untuk inventori lokal. `localAvailableStock()` membatasi numeric stock dengan credential lokal yang belum dialokasikan; credential hasil pembelian supplier yang sudah order-owned tidak ikut pool lokal. `availableStock()` menambahkan proyeksi `supplier_available_stock` hanya sampai `supplier_available_until`.

Gunakan `Product::publicVisible()`, `availableForPurchase()`, `withAvailability()`, dan `availableStock()` daripada mengulang query visibility/stock di caller. Perubahan stok lokal direkam lewat `InventoryLedger`/`EloquentInventoryLedger` dan menghasilkan `InventoryMovement` bertipe `reservation`, `sale`, `release`, atau `adjustment`. Tipe `availability` adalah sinyal transisi kapasitas eksternal untuk pipeline restock, bukan Stock Intake atau mutasi stok lokal.

`Product` juga memiliki override harga reseller opsional per mata uang (`reseller_price_amount` untuk IDR dan `reseller_usdt_price_amount` untuk micro-USDT yang di-backing harga dolar). Override hanya berlaku di channel API/reseller: `ProductQuantityPricing::quote($product, $quantity, ?ResellerPricingContext $context)` menerapkan override sebagai harga final per unit tanpa digabung dengan Quantity Price Tier, per mata uang secara independen, sedangkan `null` memakai quote quantity normal. Context hanya membawa aktor/asal terautentikasi dan tidak pernah mengubah harga retail; bot, Web Storefront, Admin preview, dan paid-invoice presentation selalu mengirim tanpa context. Override USDT tidak berlaku (dan tidak boleh dibuat) tanpa harga USDT dasar, sehingga override tidak pernah memunculkan kelayakan dolar. `ProductQuantityPricing::quoteMany()` adalah seam batch untuk halaman catalog API dan menolak halaman yang belum eager-load `quantityPriceTiers`, sehingga quote 1/20/100 Product tidak menambah query relasi per Product. Detail kontrak dan verifikasi: [reseller price overrides](../reseller-pricing-overrides.md).

`ProductStockIntake` adalah pemilik canonical untuk penambahan positif melalui **Input Cepat** dan **Input Akun**. Ia menormalkan seluruh batch sebelum transaksi, mengunci Product, mengklaim idempotency key dan `CredentialIdentity`, membuat `ProductStockItem`, menaikkan numeric stock, membuat satu adjustment movement/audit, lalu menyerahkan restock notification setelah commit. `StockNotificationDeliveryPipeline` mematerialisasi teks recipient sebelum bounded delivery batches; untuk adjustment ia menyimpan tier terendah yang baru reachable pada `StockNotificationEvent`, sehingga retry/recovery tidak membaca ulang Product/tier yang sudah berubah. Controller `AdminProductStockController` dan `AdminProductAccountStockController` hanya adapter; mereka tidak boleh mengubah stock atau membuat identity secara langsung.

Pesan privat notifikasi Produk baru/restock menampilkan harga retail IDR bersama USD dari `Product.usdt_price_amount` bila tersedia, termasuk harga tier yang dipromosikan. Tidak ada konversi kurs atau harga USD pengganti untuk nilai `null`. Jalur broadcast langsung dan materialisasi recipient memakai formatter yang sama; teks recipient yang sudah tersimpan tetap mempertahankan nominal awal saat harga katalog berubah sebelum retry.

`ProductStockItemManager::deleteAvailableBatch` owns manual credential deletion (single deletion delegates to it). Bulk deletion accepts 1–100 distinct IDs, locks Product then Available items in ID order, and rejects the whole selection if any item is stale, allocated, retired, missing, or belongs to another Product. It reads only IDs, records one adjustment/audit with IDs and counts (never credentials), retains credential identity history, and aggregates the full deleted quantity after commit. The Admin checklist selects only the bounded currently displayed Available items and reloads the open modal after deletion. It locks Product and the Available item, creates a negative `adjustment` movement plus audit row, records the `available > 0 → 0` transition, and only after commit records the deletion in the 20-second per-Product Fake Sales Proof aggregation window. The aggregation job runs on `telegram-stock`; it never sends Telegram while the stock transaction is open. Sold-out and restock fan-out both use `StockNotificationDeliveryPipeline`, which sends the Store Channel copy plus every eligible Telegram Customer without a channel-membership gate. A sold-out customer delivery is localized and has no checkout button; a stale sold-out event is finalized without sending after restock.

Kelola stok menyediakan **Salin kredensial** untuk setiap Product Stock Item yang payload-nya dapat dibaca, termasuk paid. Akun terstruktur menampilkan email, password, metode akses, Reader Mail Link, dan tutorial item dalam textarea read-only; credential legacy tetap dapat dipilih sebagai teks. Jika Clipboard API tidak tersedia atau ditolak, tombol memilih teks dan menampilkan petunjuk salin manual. Copy hanya bekerja di browser Admin yang sudah menerima payload privat, tanpa endpoint baru, mutasi stok, atau pengiriman otomatis ke Customer. Paid/retired tetap tidak dapat diedit atau dihapus. Regresi: `admin-stock-credentials.test.js` dan skenario browser `paid stock credentials can be copied and selected without becoming editable`.

Salin memakai guard per-item untuk mencegah klik ganda; hasil Clipboard API yang terlambat tidak memperbarui feedback atau memilih teks setelah modal ditutup/dimuat ulang. Penutupan modal mengosongkan daftar credential; fitur tidak menambah timer, listener, cache credential, query, atau request jaringan.

Pencarian dan delapan urutan katalog Admin memakai `productCatalog` (Alpine) dengan indeks seluruh family/product tanpa credential. `CatalogBrowse::adminClientIndex()` menghitung rank memakai comparator katalog yang sama; browser melakukan filter dan pagination lokal (20 family per halaman). Hanya satu family dibuka, dengan maksimal 12 kartu per request ke `admin.products.cards`; kartu memakai partial dan aksi produk yang sama. Credential stok tetap dimuat lewat modal kelola stok, bukan indeks/kartu. Request detail dibatalkan dan dijaga generation agar hasil lama tidak muncul setelah pencarian berubah. Query URL, hash product, empty/loading/error, dan fallback GET tetap tersedia. Tampilan, warna, tipografi, dan focus mengikuti dashboard yang ada (ENERGY/RHYTHM/MOTION 1/1/1); batas DOM dan lazy detail dipilih untuk menjaga daftar tetap ringan. Regresi: `admin-product-catalog.test.js`, `ProductFamilyCatalogTest`, dan `AdminDashboardOverviewTest`.

Stock Notification delivery claims up to 25 due recipient effects per job, reserves the same `TelegramBroadcastRateLimiter` budget as Admin Broadcast, and sends requests concurrently with a 25-request ceiling. A limiter miss leaves effects unclaimed and delays the continuation; `telegram:recover-outbound` routes stock source discovery through `StockNotificationDeliveryPipeline::reconcile()` and excludes stock recipient effects from its generic due-effect sweep. Family-specific discovery passes `effectType` to the ledger policy so non-receipt scans never evaluate receipt predecessor subqueries. PostgreSQL partial dispatch and recipient cursor indexes keep terminal HTTP 400/403 history out of dispatch scans; they are built concurrently outside migration transactions. The dedicated stock reconciliation runs in the background with a 45-minute overlap mutex.
The generic due-effect page unions family-scoped ledger queries before applying its single keyset cursor and item limit. This lets PostgreSQL use the existing dispatch-family index instead of scanning terminal history in primary-key order; non-receipt branches omit receipt predecessor checks. Legacy product details wait for a pending invoice, but a failed/unknown invoice photo does not block them.

`CredentialIdentity` menyimpan fingerprint HMAC keyed untuk identity yang memang tidak boleh dipakai ulang, saat ini hanya Auth Token eksplisit; email tetap menjadi data akun biasa dan tidak lagi diklaim sebagai identity registry. Fingerprint bukan plaintext. `CredentialIdentityFingerprint` memakai override ENV opsional atau kunci acak persisten di `storage/app/private`; `BackfillCredentialIdentityRegistry` memproses retained Product Stock Items secara bounded dan hanya melaporkan row IDs/counts, lalu menyimpan readiness singleton yang dikunci ke digest fingerprint key hanya setelah full commit tanpa unresolved row. `CredentialIdentityBackfillReadinessService` adalah runtime gate untuk Input Akun; deployment otomatis menjalankan backfill setelah migration, dan `credentials:check-identity-readiness` adalah deployment check. Tidak ada environment feature switch untuk menyalakan atau mematikan Input Akun.

### Supplier acquisition

`SupplierAccountUnit` retains historical private account identity, deadlines and immutable per-unit retail entitlements. `OrderRefund` registers immutable partial entitlements; only the selected Customer ledger moves money, capped against the original debit. Full-order refunds credit only the remaining amount. A full external refund with registered partial refunds records the unclaimed remainder as one confirmed `OrderRefund` from the same operator transfer evidence before the full-refund transition; pending registered refunds must be confirmed or recovered first. Provider-specific account claims, replacements, confirmation, deposits, webhooks and deadline notifications are retired. Historical claims, purchases, holds and retired credentials remain protected. See [ADR-0017](../adr/0017-per-account-warranty-and-partial-refunds.md), [ADR-0018](../adr/0018-operator-confirmed-remainder-refunds.md) and the [supplier runbook](../runbook-supplier-api-reseller.md#retired-supplier-connections-2026-10-19).

Slow acquisition uses the `supplier` connection, `suppliers` queue and dedicated worker. `MissingFulfillmentRefundService` registers only definitively missing quantity; it never treats uncertain supplier outcomes as refundable missing units.

[`SupplierManager`](../../src/app/Services/Suppliers/SupplierManager.php) owns private connections, encrypted API keys, optimistic versions and account-change guards. [`ProductSupplierManager`](../../src/app/Services/Suppliers/ProductSupplierManager.php) owns equivalent-SKU mapping, ranked moves and safe detach (Product-first serialization, optimistic identities/versions, bounded decryption of Product-scoped retained snapshots, all purchase history guarded, atomic audit, after-commit availability refresh), inactive store-owned draft import and Product pricing policies. These do not replace Product identity or Customer Store Balance.

`SupplierManager::delete()` removes only an unused connection and its private catalog through `DELETE /admin/suppliers/{supplier}`. Owner/Admin authorization, explicit UI confirmation and optimistic version checks protect the action. Supplier → Catalog locks serialize deletion with sync/mapping/acquisition; live sync leases, wallet/SKU holds, any Product mapping and retained financial/fulfillment history reject deletion. Encrypted fallback snapshots are checked in bounded batches before deleting catalog rows; unreadable history fails closed. The atomic `supplier.deleted` audit contains identity/version only, never credentials. Connections with history must be disabled instead. See [operator deletion flow](../runbook-supplier-api-reseller.md#menghapus-koneksi-duplikat-atau-tidak-terpakai) and `SupplierAdminTest`.


Canboso uses the shared `SupplierClient` transport with provider-specific catalog/purchase normalization: fixed USD wallet evidence, account-only automatic delivery, exact charged amount and mandatory same-reference idempotency. Its GET order history omits credentials, so completed history cannot authorize fulfillment; quantity bonuses and incomplete delivery retain private evidence for review. See [Canboso operations](../runbook-supplier-api-reseller.md#canboso) and `CanbosoSupplierTest`.

`CanbosoPostgresConcurrencyTest` is included in `src/phpunit.postgres.xml` under the `postgres-release` group so CI exercises cross-process purchase dispatch, shared rate limits, wallet/stock contention, catalog fencing and lost-lease recovery before publishing the production image.

[`SupplierClient`](../../src/app/Services/Suppliers/SupplierClient.php) normalizes ten fixed HTTPS provider contracts (including Nastele, MiraiAPI, Canboso, NyStore Official and ToolOraX/ShopBot), decimal economic tokens, bounded response bodies, rate budgets and safe rejection versus ambiguity. Nastele uses fixed VND wallet evidence, two catalog/balance GETs, quantity-range wholesale prices, non-idempotent purchase and exact-ID GET recovery; MiraiAPI uses a fixed VND wallet, Bearer authentication, `GET /balance` + `GET /packages`, idempotent `POST /orders` with `package_code`/`quantity`/`external_order_id`, and one `redeem_codes` entry per unit. `NasteleSupplierTest`, `MiraiSupplierTest` and their fulfillment-matrix cases cover these boundaries. Supplier and Purchase exchange rates retain six-place decimals so sub-IDR currencies do not truncate before profit checks. [`SupplierCurrencyRateManager`](../../src/app/Services/Suppliers/SupplierCurrencyRateManager.php) owns one global `supplier_currency_rates` row for USD/USDT and one for VND, propagates it to every connection's `exchange_rate_idr` cache under the Product → Supplier lock order, audits each change, and refreshes mapped product capacity. Checkout and purchase rows keep immutable rate snapshots, so a later global change cannot reprice an open order. Connection creation/update copies the global rate; per-connection rate input was removed. [`SupplierExchangeRate`](../../src/app/Services/Suppliers/SupplierExchangeRate.php) owns the plausible band of `exchange_rate_idr` for each currency (VND: `0.1`–`0.999999`) because the stored value is IDR for **one** supplier currency unit: a rupiah-per-USDT figure is not a conversion and must never authorize capacity, pricing or a purchase. `SupplierAdminRequest`, `SupplierPricing::isEligible()` and `SupplierAvailability::refreshLocked()` refuse an out-of-band rate, `AdminProductWorkspaceData::cost()` marks the derived cost unusable, and `suppliers:rate-audit` reports or explicitly corrects stored rows through `SupplierManager::correctRate()` without touching purchase snapshots. No supplier HTTP runs inside financial transactions. [`SupplierCatalogSync`](../../src/app/Services/Suppliers/SupplierCatalogSync.php) claims a durable read lease, fetches outside locks, then validates generation/mapping and applies observations under ordered Product → Supplier → Catalog locks. Failed reads retain prior observations; expired observations cannot authorize checkout.

Supplier purchase POSTs allow 90 seconds for synchronous bulk fulfillment. GETs retain the 12-second response limit and four-second connection limit. A ToolOraX create response requiring exact-order hydration can use up to 102 seconds of HTTP time inside the existing 120-second supplier job and two-minute purchase lease, below the 180-second queue visibility timeout. Known, identity-trusted `pending` results remain `awaiting_delivery` even with a partial intermediate credential list: scheduled exact-order GETs continue until complete recognized delivery, including after transient read failures. No elapsed-order-age cutoff is added. Conflicting credentials, quantity/economic mismatches and terminal incomplete delivery still require review; incomplete processing data is not released to the buyer. Transport loss uses only documented same-reference recovery or operator review, never a speculative new purchase or timezone guess. `SupplierFulfillmentTest` covers ten-unit slow acquisition across all ten adapters, multi-hour processing/read outages, receipt completion and conflicting intermediate evidence.

[`SupplierAvailability`](../../src/app/Services/Suppliers/SupplierAvailability.php) projects stock/balance/commitment/profit-bounded capacity into Product for constant-query customer catalog reads. It hands genuine unavailable-to-available transitions to existing Telegram/Store Channel and Web waitlist pipelines. [`SupplierPricing`](../../src/app/Services/Suppliers/SupplierPricing.php) owns integer manual/percent/fixed/hybrid rules; retail tiers and immutable Order Item commercial snapshots remain authoritative. `ProductSupplierManager::preview()` accepts a simulation quantity, prices the supplier's wholesale tiers/discounts, and returns total and per-unit cost, retail and net profit without saving or buying.

Issue #657 hardens Mirai economics: catalog cost never substitutes for a charged total; identity correlation precedes redeem-code alias validation; undocumented 402/409 rejections retain holds and cannot select fallback. Packages with minimum quantity above one fail closed in the persisted catalog because safe capacity starts at one. `MiraiFulfillmentIntegrityTest` covers ordinary finalization, exact-order recovery, mixed checkout and fallback. Automatic preview derives base retail from quantity-one cost before Product tiers, retaining quantity discounts only in purchase cost/profit.

`SupplierCurrencyRateManager` initializes missing rows conflict-safely, then locks/rechecks versions. It rechecks mapped Product IDs under Supplier locks and restarts the transaction on expansion, at most five times. `SupplierCurrencyRateCutover` reconciles projections transactionally and installs a persistent cache-equality trigger during backfill/merge; the additional fence migration repairs already-upgraded installs. The trigger rejects baseline writers even after expand commits without taking a reverse-order global row lock. `SupplierCurrencyRatePostgresConcurrencyTest` and `SupplierCurrencyRateMigrationTest` cover setup/mapping barriers, historical snapshots and a blocked baseline writer. Rate-audit corrections execute once per global key. See [cutover and recovery](../runbook-supplier-api-reseller.md#cutover-fence-and-recovery-657).

`SupplierAvailability::explainZeroCapacity()` supplies private Admin Product stock management with allowlisted reasons for a zero safe external capacity (mapping/activity, freshness, wallet after holds, SKU stock/reservations, input requirements, an out-of-band conversion rate, or profit/tier guards). It is diagnostic only and never relaxes `SupplierPricing` authorization. `AdminSupplierDashboardData` presents the last actual `balance_observed_at` separately from catalog sync and flags stale/unknown wallet observations.

[`SupplierFulfillmentService`](../../src/app/Services/Suppliers/SupplierFulfillmentService.php) reserves external wallet/SKU commitments during checkout, buys only after verified payment, and finalizes under the same Order/resource locks as refund. All candidate wallets/catalog rows are prelocked in ascending ID order across the whole cart, not per-item supplier order. A persisted attempt/token/reference survives queue loss. Trusted known IDs recover by GET; non-idempotent ambiguous POSTs never buy again or switch supplier, and local replay rejection never resolves original ambiguity. Correlated exact identity and recognized status gate economic evidence independently from distinct complete delivery. Encrypted `trusted_delivery_payload` cannot be populated from untrusted raw observations; metadata-only/subset recovery retains stronger acquired evidence, while conflicting sets require review. Manual replacement preserves an unknown original debit, but immediately settles one already proven and committed.

Issue #571 makes fulfillment recovery dispatch-only for every provider on the supervised `supplier` connection / `suppliers` queue (`tries=1`, `timeout=120`, fail-on-timeout; `retry_after=180`). `supplier_purchase_dispatches` records a token-fenced transport-intent episode before financial HTTP, separately from the claim; unfinished episodes become unknown without discarding their identity. A fresh claim with neither intent nor `dispatched_at` can resume, while legacy timestamps remain conservative. `SupplierOrderHistoryReconciliationService` automatically reads unknown-identity ToolOraX review work at most three times, five minutes apart, using durable scan coordination. Offset-free provider timestamps remain unqualified/inconclusive until an operator attests the timezone and uses the existing evidence action; automatic reads never buy again or release a hold. The PostgreSQL SIGKILL regression runs an actual queue worker and verifies durable episode recovery plus exactly one evidence-backed no-debit decision.

Issue #587 adds operator-only CSxStore first-page history discovery through the same service: one bounded GET, encrypted allowlisted `candidate_facts`, raw offset-free time with unknown timezone, and explicit incomplete/truncated coverage. No CSxStore automatic scans or history-derived no-debit authority are introduced. Private Admin candidate selection binds latest scan/Purchase/version/claim and calls `confirmDebit`; checks are repeated inside its recovery claim transaction before exact-order HTTP. The existing finalizer/evidence/receipt owners remain unchanged. Verification: `CsxStoreHistoryRecoveryTest` and the CSx history cases in `SupplierPostgresConcurrencyTest`; the dated [runbook change record](../runbook-supplier-api-reseller.md#csxstore-candidate-history-recovery-change--2026-10-08-587) separates synthetic evidence from provider qualification.


`OrderItem.local_quantity`/`supplier_quantity` separate reservation ownership. Local first; mixed Orders stay Paid/Processing until every external unit is delivered. Completion then uses existing receipt, guest notification and Sales Proof Post seams. Supplier credentials are order-owned `ProductStockItem` rows with unique purchase/unit identity; refund permanently retires them, never restocks them. Public Product/Order Item/credential serialization hides eager-loaded source relationships and economic metadata.

`ManualFulfillmentResolutionService::recordExternalHandoff()` records outside-bot customer delivery in idempotent `SupplierManualHandoff` rows. Full handoff completes the customer-facing Order without allocating a `ProductStockItem`, sending a credential receipt, clearing the supplier hold, or resolving original purchase economics. `SupplierFulfillmentService::confirmDebit()` requires an exact-order GET whose order ID, requested quantity and actual micro-cost match the operator evidence before it delegates to the existing recovery finalizer; `confirmNoDebit()` requires a provider case/statement reference or the latest durable complete `SupplierOrderHistoryReconciliation` for that exact Purchase. Both decisions persist `SupplierPurchaseEvidence` and audit data with the financial state change. ToolOraX history reads are one GET capped at 100 rows; a saturated/incomplete list, out-of-window dispatch, unknown provider timestamp timezone, or later candidate order remains inconclusive. None of these actions dispatches a replacement purchase automatically.

`AdminSupplierInvoiceRecoveryController` adapts the invoice-filtered supplier monitor to three explicit operator decisions: submit all remaining manual credentials through `provideManualCredentials()`, record remaining outside-bot handoff through `recordExternalHandoff()`, or let an Owner attest no supplier debit through `confirmOwnerNoDebit()`. Manual credential submission always retains any supplier reservation; the original receipt ledger owns eventual Telegram acknowledgement independently from Completed. Owner no-debit is a distinct `owner_attestation`, not provider-qualified evidence: it cannot override known supplier order identity, actual cost, committed debit, live leases or changed purchase identity. A never-dispatched reservation without a Purchase can also be released, with `not_dispatched` audit evidence. Wallet sync runs outside financial locks through `SupplierCatalogSync`; its failure/blocked-wallet warning does not undo a committed hold release. The [invoice operator flow](../runbook-supplier-api-reseller.md#penanganan-sederhana-berdasarkan-invoice) describes required confirmations and boundaries. `SupplierFulfillmentTest` covers actual receipt transport, separate handoff/economics, stale/known/in-flight rejection and secret-safe password expiry.

`AdminOrderDetailController` and `AdminDashboardSalesData` keep payment, customer delivery, and supplier purchase as separate interim statuses. For Telegram Orders, `AdminPaidOrderOutcome` gates the normal paid Admin notice on exact committed purchase evidence and acknowledgement of every relevant receipt effect with valid message IDs and the shared group-completion policy. `AdminOrderNotificationDeliveryPipeline` leaves the existing durable paid effect pending without consuming attempts until outcomes are actionable, or 120 seconds from authoritative Payment `paid_at` have elapsed. Review/unknown/terminal failure can escalate earlier. The effect freezes its safe outcome snapshot before claiming; unresolved notices get one deterministic `:result` effect after resolution, not a reset/resend of the original notice. Supplier processing and receipt finalization wake the existing dispatcher after commit; `telegram:recover-outbound` also discovers deferred effects and missing result updates after queue loss. Payment settlement, holds, supplier leases, allocation/refunds, and receipt delivery remain independently owned. API-origin, guest/email, pending manual approval, expired and independent review alerts retain their existing paths. Customer-facing copy remains store-branded. See the [supplier runbook](../runbook-supplier-api-reseller.md) for evidence and operator actions.

Verification: `SupplierIndependentRegressionTest` covers supplier acquisition versus receipt acknowledgement, the 120-second boundary, one recovery update, rejection/unknown/definite delivery failure, and mixed outside-bot handoff. `SupplierPostgresConcurrencyTest::test_admin_escalation_and_recovered_result_are_each_sent_once_by_competing_workers` races three delivery/recovery workers through each notice; the existing lifecycle and receipt suites retain ambiguous-response and retry-policy coverage.

The scheduler launches `suppliers:sync --limit=5` and `suppliers:recover --limit=25` before payment/notification sweeps, each minute in the background with a two-minute overlap mutex. Sync skips accounts read within the last minute, discovers external balance/stock changes on subsequent ticks, retains the five-minute checkout freshness limit, and uses attempted-at fairness and durable per-account leases. Commands discover durable PostgreSQL work; Redis jobs are wakeups only. Setup, qualification and reviewed recovery are in the [supplier runbook](../runbook-supplier-api-reseller.md) and [ADR-0016](../adr/0016-private-supplier-fulfillment-and-capacity.md).

Sync failures now persist `sync_failures` / `sync_next_attempt_at` with 2–30 minute exponential backoff and known rate-limit cooldowns; manual reads cannot bypass failure backoff. Healthy ProdSeller polling is conservatively paced by catalog size, with the five-minute checkout freshness unchanged. General supplier HTTP honors shared provider 429 cooldowns, including pooled details; Redis loss does not erase the account's durable sync retry deadline.

[`SupplierHealthService`](../../src/app/Services/Suppliers/SupplierHealthService.php) owns durable low-balance/error/review, aged-hold and stale-wallet episodes and safe event snapshots, never transport or purchasing. `suppliers:notify-health --limit=25` runs every minute in background with a two-minute overlap mutex. Review dedupe uses the persisted episode rather than purchase state; wallet observations aged fifteen minutes alert separately from transactions once the next scheduled sync retry is also overdue, unless an unresolved purchase deliberately withholds the wallet, while aged holds join the per-connection review conditions. Fresh wallet evidence/resolved holds rearm their episodes. PostgreSQL cursors fairly rotate bounded connection/obligation discovery; the event, dedupe state, `low_balance_alerted_at` materialization marker and unique outbound effect commit atomically. `SupplierHealthDeliveryPipeline` claims/finalizes through the existing Telegram outbound ledger; unknown delivery is not blindly resent, and generic outbound recovery rediscovers pending work after Redis loss. `balance_observed_at` distinguishes actual wallet observations from catalog-only freshness while unsettled debit blocks wallet refresh. Null/stale balance does not imply zero or recovery and is never presented as a current amount. Alerts contain allowlisted invoice/purchase/SKU/quantity/hold/time context, never Customer identity or supplier payloads. See the supplier runbook for cadence, real Telegram smoke evidence, private target, redaction and operator recovery.

Connection-error dedupe rearms only after fifteen minutes of healthy observations. A new failure or freshness gap resets the recovery clock; one successful sync cannot rearm flapping transport/rate-limit alerts. This does not suppress independent new financial review/hold or stale-wallet alerts.

`SupplierPriceChangeNotificationService` compares mapped catalog price baselines captured under the existing sync locks, then snapshots both upward/downward base-price changes and effective tier/discount changes after `refreshLocked`. It records batched private `SupplierHealthEvent`/supplier-health effects in the same transaction, with after-commit queue wakeups and existing health recovery/delivery. First observations, unmapped SKUs and unchanged prices remain quiet. Messages distinguish quantity-one economics from combined local/all-source availability; existing automatic pricing is preserved. One batched baseline query, no extra supplier HTTP/scheduler/migration, and HTML-safe 3,800-byte message splitting bound the added work. `SupplierPriceChangeNotificationTest` covers quiet syncs, recurring reversals, private-target safety, margin versus local/fallback capacity, delivery recovery, rollback, effective tier changes, automatic pricing and message splitting. See [operator behavior and local evidence](../runbook-supplier-api-reseller.md#notifikasi-perubahan-harga-supplier).

### Cart

`Cart` punya status `active`, `checked_out`, `abandoned`, atau `expired`. `CartService` memiliki aturan satu cart aktif/customer dan perubahan quantity. Checkout mengunci cart dan cart items; jangan membuat Order langsung dari UI/controller.

### Order

`Order` adalah unit komersial dengan UUID, invoice number unik, snapshot `OrderItem`, total, currency, status order, status payment, deadline, dan relasi ke Payment/inventory. Status order dan status payment adalah dua state machine berbeda; jangan memakai salah satunya sebagai pengganti yang lain.

Status order canonical: `draft`, `pending_payment`, `processing`, `completed`, `cancelled`, `expired`.

### Payment

`Payment` adalah instruksi pembayaran yang terikat ke satu Order. Field yang penting untuk semua provider adalah `provider`, `merchant_ref`, `method`, `amount`, `currency`, `status`, `expired_at`, `paid_at`. Field `provider_*` menyimpan snapshot terseleksi yang sudah disanitasi; beberapa nilai sensitif seperti payment number dan payment proof dienkripsi.

Status payment canonical: `pending`, `paid`, `failed`, `expired`, `refunded`.

### PaymentEvent dan audit

`PaymentEvent` menyimpan jejak callback, provider create, reconciliation, manual update, cancel, atau owner alert. Event bukan otorisasi pembayaran; ia adalah bukti observasi/proses yang harus terhubung ke Payment bila dapat dicocokkan. `AuditLog` menyimpan aksi operator/admin dan perubahan nilai penting.

### Store Balance

**Store Balance** adalah saldo IDR milik Customer yang direpresentasikan oleh satu row proyeksi `CustomerBalanceAccount` dan ledger append-only `StoreBalanceEntry`. [`StoreBalanceLedger`](../../src/app/Services/StoreBalance/StoreBalanceLedger.php) adalah satu-satunya seam yang boleh memindahkan uang: dalam satu transaksi ia mengunci row account, melakukan dedupe berdasarkan klaim idempotency dan source identity, menulis satu entry immutable (`amount` positif, `kind`, `balance_after`, `source_type`/`source_id`, `idempotency_key`, `created_at`), lalu memperbarui proyeksi. Reapply dengan source atau key yang sama mengembalikan hasil ekonomi yang sudah ada tanpa entry kedua; dana tidak cukup melempar `InsufficientStoreBalance` sehingga tidak ada account, entry, atau proyeksi parsial yang tersisa.

Unique index `(customer_balance_account_id, idempotency_key)` dan `(source_type, source_id, kind)` adalah guard database terakhir untuk duplicate economic effect, dan check constraint PostgreSQL menjaga `amount > 0`, `balance >= 0`, serta `balance_after >= 0`. Kolom `balance` account sengaja tidak mass assignable; jangan menulis proyeksi langsung dari controller, job, checkout, atau refund caller.

`StoreBalanceLedger::snapshot()` adalah bacaan untuk presentasi: point read proyeksi yang diverifikasi terhadap entry terbaru. Proyeksi yang drift dikembalikan sebagai unavailable, sehingga Telegram home menampilkan state recovery lokal alih-alih angka. `store-balance:check` ([`StoreBalanceReconciliation`](../../src/app/Services/StoreBalance/StoreBalanceReconciliation.php)) membandingkan proyeksi dengan signed sum ledger dan melaporkan mismatch tanpa mengubah uang; repair tidak pernah otomatis. Detail keputusan ada di [ADR-0008](../adr/0008-store-balance-ledger.md).

Metric global Store Activity (jumlah Customer Telegram dan canonical successful Order) boleh memakai cache singkat; Store Balance Customer selalu dibaca fresh dan tidak boleh masuk cache global.

`StoreBalanceFundingPolicyManager` memiliki konfigurasi funding yang terpisah dari
ledger: satu row singleton `store_balance_funding_policies` menyimpan minimum,
preset berurutan, dan versi optimistik. Manager ini memiliki validasi nominal
whole-IDR berbatas provider, lock/update, audit atomik, serta identitas callback
Telegram yang opaque dan terikat versi. Admin Dashboard adalah satu-satunya
caller mutasi; chooser Add Balance hanya membaca policy terbaru dan tidak pernah
memindahkan saldo. Funding policy yang berubah tidak boleh dianggap sebagai
ledger entry atau kredit saldo.

`StoreBalanceFundingIntentService` adalah owner confirmation sebelum provider:
ia memakai `StoreBalanceFundingAmountValidator` yang sama untuk preset dan
`/deposit`, mengunci Customer/policy/current intent dalam transaksi, menyimpan
intent Customer-owned yang terikat chat dan canonical navigation root, serta
menandai replacement, expiry, stale-policy, cancel, dan confirm secara
idempotent. Intent ini tidak membuat Payment, invoice, provider request, atau
`StoreBalanceEntry`; sebelum Confirm seluruh recovery tetap lokal ke chooser.
Setelah Confirm, `StoreBalanceTopUpService` mengunci Customer, intent, current
root, dan top-up identity untuk membuat/reuse satu `StoreBalanceTopUp`. Top-up
mengikuti provider yang dipilih store, sama seperti checkout: provider
(`manual`, `klikqris`, atau `sakurupiah`) diresolusi sekali saat Confirm dari
`ManualPaymentSetting::current()` dan disnapshot pada top-up bersama
`payment_setting_id`/`payment_provider_version`, sementara `pakasir` tidak
dipilih dan provider tak dikenal fail closed ke `manual`. Seluruh lifecycle,
presentasi, recovery, settlement, dan expiry kemudian dibaca dari snapshot
top-up itu, sehingga mengganti Payment Provider Switch hanya memengaruhi
top-up yang di-confirm setelah switch. Perilaku provider berada di balik satu
seam `StoreBalanceTopUpLifecycle`, yang dipilih
`StoreBalanceTopUpLifecycleResolver` dari provider top-up:
`KlikQrisStoreBalanceTopUpLifecycle` mendelegasikan ke
`KlikQrisNativeQrisService`, `SakurupiahStoreBalanceTopUpLifecycle` membuat/
refresh invoice pada snapshot `sakurupiah_channel` lewat adapter Sakurupiah dan
menyimpan payload QR atau URL gambar provider yang aman secara terenkripsi,
dan `ManualStoreBalanceTopUpLifecycle` membuat top-up
`awaiting_confirmation` untuk dikonfirmasi operator. Rail mengembalikan DTO
provider-neutral dan tidak pernah memindahkan saldo: `StoreBalanceTopUpService`
tetap pemilik transaksi top-up, urutan lock, validasi settlement evidence,
transisi status, dan satu identity kredit ledger `balance_top_up:<id>:credit`.

Store Balance checkout memakai `StoreBalanceCheckoutIntent` dan
`StoreBalanceCheckoutPaymentPreparation` sebagai rail internal IDR penuh.
`OrderCheckoutService` menjaga satu transaksi bersama: callback Telegram tidak
memilih nominal; untuk Store Balance, transaksi mengunci account Customer tepat
setelah Cart dan sebelum snapshot CartItem/Product, lalu melakukan debit melalui
`StoreBalanceLedger` dan menyerahkan state paid serta efek fulfillment canonical
ke `OrderLifecycle`.

Refund Order yang didanai Store Balance hanya boleh melalui `OrderLifecycle`.
`StoreBalanceLedger::refund()` mengunci account sebelum lock Order/Payment dan
resource inventory, memverifikasi debit Order asli serta nominal Payment yang
tepat, dan memakai identity source/idempotency Order yang sama agar retry tidak
membuat credit kedua. Urutan lock bersama adalah `Cart ->
CustomerBalanceAccount -> CartItem/Product` untuk checkout dan
`CustomerBalanceAccount -> Order -> Payment ->
OrderItem/InventoryMovement/Product` untuk refund.

### Customer balance web and Admin reports (#557)

`CustomerBalanceOverviewReader` reads both IDR and USDT account projections and their latest immutable ledger cursors in one SQL statement. Missing accounts are zero; a projection/cursor mismatch is unavailable, and a valid frozen USDT projection remains visibly frozen. `customer.balance.show` is read-only behind `auth:customer`; only the existing verified-email magic-link flow establishes that session. `CustomerBalanceActivityCursorToken` encrypts and binds each history keyset cursor to the Customer and currency. Invoice/recovery keys and Telegram IDs are not session authority, and the page adds no wallet write route.

`StoreBalanceActivityService::pageForCustomer()` and `CustomerUsdtActivityService` own the bounded customer history pages. `AdminBalanceReportReader` owns the role-checked IDR/USDT accounts, entries, top-ups, reviews, treasury and audit reports, exact numeric Telegram-ID filters, state filters, masked references and actor/filter-scoped keyset cursor. Per-currency totals aggregate account projections rather than full ledger history; the treasury panel reads the latest persisted reconciliation run and never presents missing/unavailable custody as zero.

IDR payment-proof links use Laravel temporary signed URLs ([Laravel URL documentation](https://laravel.com/docs/13.x/urls#signed-urls)), expire after five minutes, and are additionally bound to the current report-authorized admin. The download fetches only the proof file ID stored on the selected top-up, through the fixed Telegram Bot API file host; it disables redirects and rejects oversized, non-JPEG/PNG, mismatched, or undecodable image bytes. No user-supplied URL is fetched. `2026_10_06_190000_add_balance_report_keyset_indexes` supports report and customer-history ordering. Behavior coverage is in `CustomerBalanceWebTest` and `AdminBalanceReportTest`; responsive customer rendering is exercised by the customer browser suite.

### External money receipts (R1 #541)

[`ExternalMoneyReceiptClaims`](../../src/app/Services/MoneyReceipts/ExternalMoneyReceiptClaims.php)
adalah seam claim provider-neutral untuk satu transfer eksternal yang teramati,
ditambahkan secara additive oleh R1 tanpa mengubah caller lama.
`VerifiedMoneyReceipt` bersifat immutable dan mengikat identity canonical
(`MoneyReceiptIdentity`: namespace penerima Binance yang stabil atau BSC
`(chain_id, tx_hash, log_index)` yang memakai normalisasi `BscTransactionHash`
legacy), beneficiary, currency, amount integer positif exact, satu
`MoneyReceiptTarget` (`order_payment`, `store_balance_top_up`,
`balance_adjustment`) dan fingerprint berversi; alias terverifikasi memetakan
dana fisik yang sama yang terlihat lewat adapter lain, dan log BSC juga
mengklaim parent `(chain_id, tx_hash)`-nya. `claim()` dipanggil di dalam
transaksi money caller yang pendek tanpa HTTP dan mengembalikan `claimed`,
`replayed`, `conflict`, atau `review`: receipt yang belum ada di-insert dengan
`INSERT ... ON CONFLICT DO NOTHING`, replay fingerprint yang sama mengembalikan
`replayed`, payload uang yang berubah atau target kedua menjadi `conflict`, dan
beberapa log BSC dalam satu transaksi yang menunjuk dua target ditahan sebagai
`review` dengan owner pertama dipertahankan; selama parent masih `review`,
setiap klaim/replay berikutnya pada transaksi itu tetap `review` dan receipt
yang bersangkutan ikut ditahan. Unique constraint database tetap
otoritatif; race SQL unique dipulihkan lewat savepoint atau transaksi baru,
bukan melanjutkan transaksi PostgreSQL yang sudah aborted. Status baris hanya
menyelesaikan kepemilikan klaim (`claimed`/`review`), bukan finalitas bukti atau
kredit uang: adapter tetap memvalidasi finalitas/attribution sebelum dana
dianggap spendable, dan backfill reservasi legacy (R2) dapat mengklaim identitas
lebih awal tanpa memindahkan uang. Tabel
`external_money_receipts`, `external_money_receipt_aliases`, dan
`external_money_receipt_parent_claims` bersifat append-only pada model
(`ExternalMoneyReceipt` menolak perubahan identity/uang dan penghapusan).

R2 (#542) memindahkan seluruh writer eksternal ke seam ini: Binance
(`BinancePaymentVerification::confirm`), BSC (`claim` dan late payment),
QRIS checkout (KlikQRIS/Sakurupiah/Pakasir callback dan cancellation
re-check), approval manual, fulfillment review BSC, settlement top-up Store
Balance (termasuk rail manual) dan compensation. `ExternalMoneyReceiptWriter`
membangun identity dari kolom persisten; Binance memakai snapshot receiver
checkout (fallback config hanya untuk pembayaran yang tervalidasi live oleh
verifier lama), dan backfill tidak menebak dari config sehingga baris legacy
tanpa snapshot di-reserve fail-closed. Reference provider yang tidak
memenuhi alfabet canonical (spasi/karakter khusus, misalnya merchant ref
legacy) di-encode deterministik sebagai SHA-256 agar runtime dan backfill
menghasilkan canonical key yang sama. Outcome Conflict/Review tidak pernah
memindahkan uang dan dicatat sebagai reservation. Keputusan implementasi ada
di [ADR-0022](../../docs/adr/0022-external-money-receipt-backfill-and-reservations.md).

Backfill (`balance:backfill-money-receipts`) memproses sumber
`binance_payments`, `bsc_payments`, `qris_payments`, `manual_payments`,
`qris_top_ups`, dan `compensations` secara bounded/chunked, idempotent, dan
resumable lewat `external_money_receipt_backfill_states`; dry-run tidak
menulis receipt, reservation, atau checkpoint. Bukti yang tidak bisa
dinormalisasi (receiver namespace Binance hilang, log index BSC hilang,
conflict/review registry) dan compensated funding sources dicatat di
`external_money_receipt_reservations` (denylist blocking) alih-alih dibuang,
tanpa mengubah amount/provider/status historis.
`balance:money-receipt-cutover-report` merangkum kelengkapan per sumber,
collision, reservation terbuka, daftar writer, kompatibilitas mixed-version
dan status USDT (`disabled`); `--require-ready` gagal selama gate belum
bersih. `balance:resolve-money-receipt-reservation` menyelesaikan satu
reservation setelah review operator dengan audit note.

Bukti race PostgreSQL: [`ExternalMoneyReceiptPostgresConcurrencyTest`](../../src/tests/Feature/ExternalMoneyReceiptPostgresConcurrencyTest.php)
(receipt sama/target berbeda, replay, alias collision dengan pemulihan
savepoint, dan review multi-log lintas target) dan
[`ExternalMoneyReceiptWriterPostgresConcurrencyTest`](../../src/tests/Feature/ExternalMoneyReceiptWriterPostgresConcurrencyTest.php)
(checkout vs top-up writer contention dan replay konkuren melalui
`ExternalMoneyReceiptWriter`).

### Store Channel dan Sales Proof Post

`StoreChannelSetting` menyimpan target Store Channel dan konfigurasi Sales Proof Post. Home `/start` menampilkan CTA channel opsional ketika username/link tersedia; membership channel tidak menjadi syarat akses Store Bot. `SalesProofPost` dipublish setelah payment paid dan inventory sale tercatat; publikasi dilakukan job sehingga OrderLifecycle tidak mengirim pesan Telegram langsung.
The shared public-safe invoice image is owned by
[`PaidInvoiceRenderer`](../../src/app/Services/PaidInvoice/PaidInvoiceRenderer.php)
and accepts only the immutable
[`PaidInvoicePresentation`](../../src/app/Services/PaidInvoice/PaidInvoicePresentation.php).
`PaidInvoiceOrderPresentationAdapter` reads paid Order snapshots and the
canonical paid Payment; its immutable item values include the nullable
`quantity_price_tier_minimum` captured on each Order Item. `PaidInvoiceSyntheticPresentationAdapter`
uses the same `ProductQuantityPricing` quote for manual and deleted-stock
Fake Sales Proofs, including the aggregated quantity and the
one-percent provider fee; `SalesProofPostFormatter` renders subtotal, fee when
non-zero, and total after fee. `PaidInvoiceTopUpPresentationAdapter` remains
separate. Buyer receipts and real/fake Store Channel posts use the same
renderer; fake publication never creates an Order, Payment, Sale movement, or
durable Sales Proof effect. Manual stock deletion remains an inventory
adjustment/audit, while its cache-locked 20-second aggregation only controls
the public fake proof.

Issue #667 removes scheduled automatic fake publication entirely: the
`telegram:fake-sales-proof:auto` command, scheduler registration, automatic
publisher entry point, random-product selection and interval cache helpers no
longer exist. The Store Channel card offers manual publication and its last-sent
time only. Existing automatic interval cache entries are ignored and can expire
normally; no migration, Telegram post deletion or historical-data cleanup is
part of this cutover. Keep the shared Sales Proof toggle, publication lock,
numbering, invoice renderer, manual route and deleted-stock aggregation. Real
paid-order proof delivery and its durable recovery/ambiguity rules are unchanged.

Local verification (2026-10-28): the seven focused suites
`AdminStoreChannelTest`, `ProductStockDeletionFakeSalesProofTest`,
`SchedulerIsolationTest`, `TelegramLifecycleOutboundEffectTest`,
`SalesProofStockSignalTest`, `SalesProofPostFormatterTest` and
`PaidInvoiceRendererTest` passed 114 tests / 804 assertions; targeted Pint passed.
A disposable SQLite runtime with the real Telegram client and intercepted HTTP
ran `ops:schedule-run` with unrelated tasks skipped and a local callback as a
positive control: zero automatic entries/commands and zero scheduler sends.
Manual publication, deleted-stock publication and an actual stock-deletion
aggregation job sent invoice photos. A synthetic local checkout followed by
`OrderStateMachine::applyPaymentPaid()` and `PublishSalesProofPost` finalized the
real proof as `sent`; replay sent nothing. Fake publication left Order, Payment
and Sales Proof records empty and stock unchanged before the separate deletion
and checkout scenarios. Authenticated browser checks exercised the manual form
by keyboard, channel validation and disabled controls; desktop and 390px mobile
views retained the existing light/dark styles without overflow or automatic
indicators. These are local transport fixtures, not live payments or messages.

Framework reference: Context7's Laravel 13
[scheduling snippets](https://context7.com/laravel/docs/llms.txt?tokens=3000&topic=scheduling)
separate schedule definitions from Artisan commands. Related scope and baseline:
[#666](https://github.com/Alhiefikri/telegram-bot/issues/666) and
[#672](https://github.com/Alhiefikri/telegram-bot/issues/672#issuecomment-6462928894).
No production access, deploy, CI/CD run or CPU/RAM savings claim accompanies
this local cutover.

`SalesProofStockSignal` owns the optional low-stock line of the public caption:
it reads mutable Product availability and passes a plain number into the
formatter, so `PaidInvoicePresentation` keeps only immutable order/payment data
and the caption never publishes a number that the sold-out post will contradict.

## 3. Ownership aturan bisnis

| Aturan                                                        | Pemilik canonical                                                                                                                                                                                                 | Adapter/caller yang tidak boleh mengambil alih                         |
| ------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- |
| Store Balance funding confirmation intent dan claim | [`StoreBalanceFundingIntentService`](../../src/app/Services/StoreBalance/StoreBalanceFundingIntentService.php) | Telegram router/callback membuat Payment, invoice, provider request, atau ledger entry langsung |
| shared checkout transaction, idempotency, dan side-effect orchestration | [`OrderCheckoutService`](../../src/app/Services/OrderCheckoutService.php)                                                                                                                                        | controller, Telegram router, storefront view, payment rail adapter     |
| rail-specific Payment assembly dan preflight/prepare           | adapter [`CheckoutPaymentPreparation`](../../src/app/Services/Checkout/CheckoutPaymentPreparation.php) yang dipilih oleh [`CheckoutPaymentPreparationResolver`](../../src/app/Services/Checkout/CheckoutPaymentPreparationResolver.php) | `OrderCheckoutService`, provider resolver, channel adapter              |
| generic Payment plan persistence                                | [`CheckoutPaymentPlanWriter`](../../src/app/Services/Checkout/CheckoutPaymentPlanWriter.php)                                                                                                                      | rail adapter menulis Payment langsung                                 |
| positive stock intake + identity claim                          | [`ProductStockIntake`](../../src/app/Services/ProductStockIntake.php), [`CredentialIdentity`](../../src/app/Models/CredentialIdentity.php)                                                                       | admin controller, model caller, browser adapter                         |
| manual stock deletion + Fake Sales Proof handoff                 | [`ProductStockItemManager`](../../src/app/Services/ProductStockItemManager.php), [`ProductStockDeletionFakeSalesProofAggregator`](../../src/app/Services/ProductStockDeletionFakeSalesProofAggregator.php) | admin controller creates neither movement nor Telegram delivery directly |
| transisi paid/cancel/expiry/refund                              | [`OrderLifecycle`](../../src/app/Services/OrderLifecycle.php) dan [`OrderStateMachine`](../../src/app/Services/OrderStateMachine.php)                                                                            | callback controller, provider client                                   |
| registrasi remainder refund eksternal                           | [`RemainderRefundService`](../../src/app/Services/Warranty/RemainderRefundService.php)                                                                                                                             | review resolution, controller, atau caller membuat baris `OrderRefund` remainder langsung |
| provider baru untuk checkout                                    | [`PaymentProviderSwitcher`](../../src/app/Services/Admin/PaymentProviderSwitcher.php) dan [`PaymentProviderResolver`](../../src/app/Services/Checkout/PaymentProviderResolver.php)                              | channel-specific provider selection                                    |
| reservation/sale/release stock                                  | [`InventoryLedger`](../../src/app/Services/InventoryLedger.php)                                                                                                                                                    | model/controller langsung mengubah stock                               |
| Store Balance credit/debit dan projection                        | [`StoreBalanceLedger`](../../src/app/Services/StoreBalance/StoreBalanceLedger.php)                                                                                                                                 | checkout, refund, top-up, atau Telegram adapter menulis balance langsung |
| Customer USDT Balance credit/debit/refund/reversal, projection dan accounting | [`CustomerUsdtBalanceLedger`](../../src/app/Services/UsdtBalance/CustomerUsdtBalanceLedger.php)                                                                                                        | settlement, checkout, refund, atau adapter Telegram/web mengubah saldo, cursor, atau baris accounting langsung |
| USDT reconciliation, drift freeze, treasury coverage dan run evidence | [`UsdtReconciliation`](../../src/app/Services/UsdtReconciliation/UsdtReconciliation.php), [`UsdtBalanceDriftFreezer`](../../src/app/Services/UsdtBalance/UsdtBalanceDriftFreezer.php) | adapter Telegram/web/admin menulis balance, cursor, accounting, receipt, atau `frozen_reason` langsung |
| approved USDT custody asset observation | [`UsdtTreasuryAssetProvider`](../../src/app/Services/UsdtTreasury/UsdtTreasuryAssetProvider.php) via [`UsdtTreasuryAssetRegistry`](../../src/app/Services/UsdtTreasury/UsdtTreasuryAssetRegistry.php) | reconciliation membuat angka backing sendiri, fixture adapter dianggap bukti aset nyata |
| audited treasury movement bookkeeping | [`UsdtTreasuryMovements`](../../src/app/Services/UsdtTreasury/UsdtTreasuryMovements.php) | command/CLI menandai movement sebagai top-up, settlement, atau refund Customer |
| idempotent accounting aggregates | [`UsdtAccountingAggregator`](../../src/app/Services/UsdtBalance/UsdtAccountingAggregator.php) | caller menulis aggregate/claim langsung atau memakai event sebagai authority saldo |
| global external money receipt identity dan consumption           | [`ExternalMoneyReceiptClaims`](../../src/app/Services/MoneyReceipts/ExternalMoneyReceiptClaims.php)                                                                                                               | adapter provider, top-up, checkout, atau controller menulis baris receipt/alias/parent claim langsung |
| Store Balance funding policy admin/customer chooser                 | [`StoreBalanceFundingPolicyManager`](../../src/app/Services/StoreBalance/StoreBalanceFundingPolicyManager.php) | controller admin, form, atau callback mempercayai nominal langsung |
| Store Balance top-up provider rail lifecycle                    | [`StoreBalanceTopUpLifecycle`](../../src/app/Services/StoreBalance/TopUp/StoreBalanceTopUpLifecycle.php) yang dipilih [`StoreBalanceTopUpLifecycleResolver`](../../src/app/Services/StoreBalance/TopUp/StoreBalanceTopUpLifecycleResolver.php) | `StoreBalanceTopUpService`, provider resolver, atau adapter Telegram memilih perilaku rail |
| manual Store Balance top-up operator confirmation               | [`ManualStoreBalanceTopUpConfirmationService`](../../src/app/Services/StoreBalance/TopUp/ManualStoreBalanceTopUpConfirmationService.php) | callback Telegram menandai paid/expired atau mengkredit saldo langsung |
| KlikQRIS create/recovery/payability                             | [`KlikQrisNativeQrisService`](../../src/app/Services/KlikQris/KlikQrisNativeQrisService.php)                                                                                                                     | Store Bot/Storefront mengklasifikasi persistence state                  |
| provider status verification                                    | callback processor + provider adapter masing-masing                                                                                                                                                               | callback payload langsung menandai paid                                |
| Telegram outbound effect state dan delivery ledger              | [`TelegramOutboundEffectLedgerProtocol`](../../src/app/Services/Telegram/TelegramOutboundEffectLedgerProtocol.php) dan service materialization-nya                                                                  | checkout caller, queue job, channel adapter                              |
| customer order access                                           | [`StorefrontOrderAccess`](../../src/app/Storefront/StorefrontOrderAccess.php)                                                                                                                                      | controller menyimpan plaintext Recovery Key                             |
| customer-facing copy                                            | Telegram/Storefront presentation adapter                                                                                                                                                                            | domain service mengirim pesan channel                                   |
| paid credential projection                                      | [`CredentialDeliveryPresentation`](../../src/app/Services/Credentials/CredentialDeliveryPresentation.php)                                                                                                         | Telegram/Web adapter parsing encrypted payloads                         |
| locale kolom dan fallback Product Details / Replacement & Refund Policy | [`LocalizedProductCopy`](../../src/app/Catalog/LocalizedProductCopy.php) + [`StorefrontLocale`](../../src/app/Storefront/StorefrontLocale.php)                                                                     | presenter, controller, view, atau adapter menulis/membaca nama kolom `{field}_{locale}` sendiri |
| premium emoji metadata Product copy                              | [`TelegramProductContent`](../../src/app/Services/Telegram/TelegramProductContent.php)                                                                                                                             | Dashboard atau adapter menyimpan offset emoji untuk teks yang berbeda   |

`CheckoutPaymentPreparation` adalah seam dua fase: `preflight()` berjalan sebelum
checkout memperoleh lock dan boleh melakukan bounded readiness I/O; `prepare()`
berjalan setelah lock checkout lengkap dan hanya boleh melakukan allocation atau
snapshot work yang transaction-local. `prepare()` tidak boleh memanggil RPC,
provider HTTP, Telegram, atau queue. Hasilnya adalah immutable
`CheckoutPaymentPlan` yang dikonsumsi oleh `OrderCheckoutService`. Payment
Verification, `OrderLifecycle`, `InventoryLedger`, dan Telegram Outbound Effect
Ledger mempertahankan ownership yang sudah ada.

Aturan praktis: service domain mengembalikan outcome/DTO; adapter menerjemahkan outcome menjadi Telegram message, HTTP response, view, atau admin alert.

## 4. Flow request utama

### 4.1 Store Bot / Telegram

```text
POST /api/webhook/telegram
  → TelegramWebhookController
  → TelegramWebhookIngress
  → telegram_updates (dedupe by update_id)
  → ProcessTelegramUpdate
  → TelegramUpdateProcessor
  → TelegramMessageRouter atau TelegramCallbackRouter
  → intent/service/job
```

`TelegramWebhookIngress` memeriksa secret header, `update_id`, dan bentuk update yang didukung. Service message `pinned_message` di-acknowledge tanpa persist/routing sebelum minimisasi, sehingga pin receipt tidak menjadi command kosong atau mengubah session/customer. Payload input customer diperkecil sebelum disimpan. Duplicate update tidak membuat pekerjaan bisnis kedua; update yang belum diproses diklaim oleh processor job.
`TelegramUpdateProcessor` serializes message and callback routing per Telegram chat with a distributed cache lock. The critical section includes selection/query-session transitions and inline message edits, so parallel workers cannot apply out-of-order UI state for one chat; transport ambiguity is never converted into a blind send fallback.
`TelegramChatInteractionLock` uses a 55-second lease, below the update worker's 60-second timeout, and acquires non-blockingly. Contention clears the update claim and requeues it after five seconds without incrementing `processing_attempts`; the lock is released in `finally` when a handler exits normally or exceptionally.

`TelegramMessageRouter` menangani command/text/menu. Customer Telegram baru langsung memakai locale Indonesia dan membuka home `/start`; picker bahasa tetap tersedia dari tombol Bahasa. `TelegramCallbackRouter` menjalankan urutan:

1. callback handler stage `BeforePrivacy`;
2. `TelegramChatPrivacyGuard` — sebagian besar aksi customer harus private chat;
3. stage `Privileged` untuk aksi admin/operator;
4. stage `Customer` untuk navigasi dan aksi pembeli.

Handler callback didaftarkan terpusat di [`AppServiceProvider`](../../src/app/Providers/AppServiceProvider.php). Tambah handler baru ke pipeline dan tentukan stage-nya; jangan membuat router kedua.

Private `/start` milik customer Telegram memakai **canonical navigation root** `TelegramNavigationRoot`: satu pesan banner yang dimiliki Customer/chat, dikelola [`TelegramNavigationRootService`](../../src/app/Services/Telegram/TelegramNavigationRootService.php). Service me-lease `generation` sebelum menyentuh Telegram dan baru menulis `screen`/`locale` setelah edit terbukti berhasil, sehingga lease yang kalah, hasil ambigu, dan `message is not modified` tidak pernah memajukan state secara optimistis; kegagalan edit definitif dan layar yang melebihi caption media 1024 karakter mengganti root tepat sekali (banner reference tetap disimpan untuk `/start` berikutnya), sedangkan hasil ambigu tidak pernah memicu penggantian buta. [`TelegramNavigationPresenter`](../../src/app/Services/Telegram/TelegramNavigationPresenter.php) adalah seam presentasi: callback hanya boleh commit ke root yang benar-benar menjadi targetnya (selain itu stale → recovery lokal tanpa side effect), sedangkan command seperti `/menu`, `/produk`, `/riwayat`, `/support`, dan `/language` mengedit root aktif dan hanya memakai jalur kirim lama bila chat belum punya root. Caption disusun [`TelegramMenuIntents`](../../src/app/Services/Telegram/TelegramMenuIntents.php) dalam urutan greeting → Profile (Telegram ID, username atau missing value lokal, Store Balance fresh) → Store Activity (jumlah Customer Telegram dan canonical successful Order) → action prompt, di-escape [`TelegramHtmlCaption`](../../src/app/Services/Telegram/TelegramHtmlCaption.php) dan dibatasi 1024 karakter. Layar routine lain adalah Balance (saldo fresh + Add Balance), Orders (`TelegramOrderIntents::orderHistorySurface()`), Support, dan Language picker; semuanya memakai tombol Back/Home yang kembali ke home terbaru. Reply keyboard persisten dan path menu lamanya sudah dihapus, bukan dipertahankan sebagai alias. Group `/start` tetap public-safe: tanpa identitas, Store Balance, atau callback Balance/deposit, dan hanya mengekspos tombol katalog.

`TelegramBalanceFundingCallbackHandler` mengikat `/deposit <amount>` dan
callback preset ke intent funding yang sama. Callback confirm/cancel/back hanya
membawa nonce opaque; handler mencocokkan Customer, chat, message root, policy
version, dan expiry sebelum mengedit canonical root. Confirmation menyatakan
credit amount, fee/final payable provider yang belum authoritative, serta bahwa
invoice belum ada. Sebelum Confirm tidak ada Payment, provider HTTP, QRIS
effect, atau ledger entry; sesudah Confirm, top-up/provider recovery berjalan
pada top-up dan merchant reference yang sama.

`TelegramCatalogIntents` consumes `CatalogBrowse::browsePublic()` for the
Product Family and standalone Product discovery list. On the default public
sort, family entries rank by descending total `sold_quantity` across their
Product options before pagination. `sold_quantity` counts quantities in paid
Processing or Completed Orders; ties keep the existing availability and family
admin-order rules. Other sort modes and Admin ordering are unchanged.

The discovery list shows up to fifteen entries per page, with entries pressed
against their divider rule instead of padded by blank lines, while category
filtering and search keep up to ten. Family option callbacks use the signed
compact `TelegramFamilyCallbackToken` and revalidate the current public family
projection and page before resolving a Product ID. Multi-option family entries
show their customer-facing Plan count (`▸ N Plan »`); single-option families
use their Product's name and icon, with Telegram discovery submitting
`product:<id>` plus the rendering list's origin token to skip the Plan list. The Web Storefront family-card list also
displays the Product name. `/start family_<id>` still opens the Plan list.
Committed Order and OrderItem changes invalidate the cached catalog projection.
Product Detail, quantity, payment, Cart, Order, and outbound-effect ownership
remains unchanged.

Product Detail copy is locale-aware: `TelegramCatalogIntents::renderProductDetail` and
`TelegramProductNotificationService` resolve Product Details and Replacement / Refund
Policy through [`LocalizedProductCopy`](../../src/app/Catalog/LocalizedProductCopy.php) for
the recipient's `Customer.locale`, and `TelegramProductContent::html()` receives the
resolved variant's column name, so premium-emoji metadata stays bound to the exact text
it was captured from. The `/admin` editor ([`TelegramContentIntents`](../../src/app/Services/Telegram/TelegramContentIntents.php))
picks a language before editing and stores one `telegram_content` entry per edited column,
which the write-time optimistic-concurrency check and the `TelegramProductContent::MAX_PRODUCT_DETAIL_UNITS`
UTF-16 budget both evaluate per locale; `/clear` on a variant drops that variant's metadata
and returns the Product Detail to the base copy. The Admin Dashboard enforces the same budget
for `ms`/`en` variants in `AdminProductFormRequest`.

Routine catalog navigation from inline controls renders onto the canonical
message (`editMessageCaption` for a photo root, `editMessageText` for a text
root) through the same `sendCatalogMessage` seam. Text product-list commands
(`/product`, `/products`, `/produk`, `/lihat_produk`, `/lihat produk`,
`/katalog`) create a new catalog root at the chat tail when one already exists;
callbacks from that list continue against the new root, leaving an older `/start`
message untouched. With no existing root, the command keeps its send path.
Legacy persistent-keyboard labels also establish a fresh root and remove the
stale keyboard. A screen that cannot fit a media caption replaces the photo root
once with a text root instead of truncating content or uploading media again.
Product quote cards use Telegram HTML `<blockquote>` formatting; other
command/deep-link entry points without a root use their send path, while
deterministic edit rejection may use a safe new-message fallback and transport
ambiguity never triggers one.

The public `/stock` command is handled by
[`TelegramStockIntents`](../../src/app/Services/Telegram/TelegramStockIntents.php).
It reads the uncached public catalog availability projection, groups available
Products by Product Family, renders family-owned heading icons and each Product's
effective row icon (including a captured Telegram custom emoji), and sends masked HTML links to
`/start product_<id>`. It is safe for every customer chat, including groups;
the legacy admin-only `/stok` command and `📦 Cek Stok` button keep their
operator stock report.

Telegram public list and product-in-category read models are cached only at
the bot adapter seam by
[`TelegramCatalogProjectionCache`](../../src/app/Services/Telegram/TelegramCatalogProjectionCache.php),
with model-event invalidation for Product, Product Family, Category, and
quantity-price-tier changes. The cache contains credential-free projections,
not Telegram markup or customer/session state. Nonempty searches bypass it to
avoid unbounded search-key cardinality; general `CatalogBrowse` and storefront
projections remain uncached. Family/product callbacks re-read current public
eligibility before selection.


Catalog icons resolve through [`CatalogIconResolver`](../../src/app/Services/Telegram/CatalogIconResolver.php). Each Product owns an optional Unicode `icon` fallback and an optional captured `custom_emoji_id`, and each Product Family owns the same pair for its whole line; nothing is inferred from a Product name. [`CatalogFamilyIconResolver`](../../src/app/Services/Catalog/CatalogFamilyIconResolver.php) is the only place that decides the effective icon: a Product's own pair when it stores either value, otherwise the icon of the family it belongs to (`forProduct`/`forProducts`), with `forFamily` returning the family-owned pair for headings and discovery entries. It prefers an already-loaded `family` relation, so `CatalogBrowse` read models resolve without extra queries. Capture stores Telegram's associated Unicode `emoji` as the fallback, while plain emoji capture stores that glyph directly. The Admin Dashboard may edit the fallback.

Telegram message text and captions render available custom emoji identifiers with `<tg-emoji emoji-id="...">glyph</tg-emoji>`; plain text requests consume every such wrapper into custom emoji entities, including registry wrappers that carry no `data-omp-icon` marker, so literal markup never reaches a customer. Converting wrappers must rebase every caller-supplied entity offset that follows a removed wrapper, otherwise tap-to-copy values point at the wrong text. Inline keyboard labels use Unicode fallback text, while navigation buttons may carry `icon_custom_emoji_id`. Product and family icons remain catalog-owned data with the Admin Dashboard editing the Unicode fallback, and every live-Product Telegram copy resolves through [`TelegramProductNameRenderer`](../../src/app/Services/Telegram/TelegramProductNameRenderer.php) (`renderProduct`/`renderSelection`) so a variant inherits the family-effective icon. Rejected custom emoji requests are retried once without wrappers or button identifiers. Rejection capability is scoped separately for bot messages and Store Channel posts, so an unsupported channel post cannot disable custom emoji in private bot chats; the Store Channel probe is channel-scoped too, and Telegram silently strips channel custom emoji rather than rejecting them when the bot lacks the Fragment-purchased username entitlement. Every button on the Home inline keyboard sets Telegram Bot API `style=primary` to request blue styling.

A Product Family presents the icon it owns in its heading; each Plan row, public `/stock` member row, and Product detail/quantity prompt uses the Product's effective pair. A member without an icon of its own inherits the family pair, while a member that stores its own glyph or captured identifier overrides the whole pair. `🛍️` stays the final default when neither the Product nor its family stores an icon, and a member override never changes the family heading. Order Items snapshot this same effective icon at checkout so receipts and Sales Proof Posts retain the selected Product presentation. Multi-Plan discovery entries use the compact Unicode marker `▸` in their count label; Plan-selection buttons show only the option number, without a separate arrow. Each button carries its Product's effective custom emoji identifier when present; a glyph-only override omits the family identifier. Product Detail returns to the exact list its entry was pressed in when that entry carried an origin token (`product:<id>:o<token>`, restored by `TelegramCartCallbackHandler::handleBack()` through `TelegramCatalogIntents::openCatalogOrigin()`), and a sold-out detail offers the same return through `family:list:1:o<token>`. Without one — deep link, notification, or broadcast call to action — it returns to the family Plan list whenever the Product belongs to a public family with more than one Plan, which `TelegramCatalogIntents::familyNavigationFromProduct()` resolves from the Product itself; a token-less standalone Product keeps its category list.

Shared static-copy icons use `message.*` rows alongside navigation `menu.*` rows in `telegram_ui_icons`. `TelegramTranslationCatalog::translate()` resolves source glyphs in trusted translation templates before interpolation. Hardcoded static prefixes call `TelegramUiIcons::renderLegacyIcons()` only for static copy. Telegram Product-name copy uses `TelegramProductNameRenderer` with Product-owned or immutable family-effective icon data, except catalog Product and Product Family presentation, which stays in `TelegramCatalogIntents`/`CatalogIconResolver` to preserve family icon rules. Other customer, payment, credential, and broadcast values remain separate.
Admins open Product icons and then choose Menu icons or Message text icons; `menuicon:` and `messageicon:` handle capture. `TelegramBotClient` renders plain text/caption wrappers as custom emoji entities, and inline keyboard labels use Unicode fallbacks. Store Channel posts attempt custom emoji when Telegram permits the bot; an unsupported channel falls back to Unicode and its rejection is cached separately from bot-message capability. `catalog:icons-check` audits Product, family, menu, and message-copy IDs.

Every Telegram copy that names an individual Product uses its effective icon — its own override, otherwise the family icon it inherits, or the immutable selection snapshotted at checkout; it never borrows a `menu.*` or `message.*` slot. Shared notification/order copies use `TelegramProductNameRenderer`; catalog Product-name and Plan rows use `CatalogIconResolver` on their effective read-model pair, while Product Family headings use the family-owned pair.
Checkout snapshots the effective Product/Family icon fields on `OrderItem`, while price-change events snapshot the Product-owned icon alongside Product name for delayed delivery. The Store Bot icon screen keeps one row per family and adds a `producticon:family:<id>` list where one variant at a time can capture or clear its own icon.

`TelegramCheckoutIntents` adalah adapter checkout Telegram. Ia membaca Payment provider yang sudah tersimpan, memanggil lifecycle provider bila perlu, lalu menerjemahkan hasil menjadi payment instruction. Payment instruction dan outbound send dikerjakan oleh job unique di queue yang sesuai.
`CheckoutPaymentUnavailable` separates Sakurupiah readiness or stale payment-preflight rejection from invalid Cart state; `CheckoutPaymentAmountOutsideLimits` carries the observed QRIS minimum and maximum. Both remain `InvalidCheckout` subtypes for service/Web callers. The Telegram checkout callback also reports `InsufficientStock` separately. Each rejection retains the Cart and transaction rollback prevents Order, Payment or supplier-hold creation.

`SakurupiahProviderReadiness` stores a versioned scalar/array observation, not a PHP object, so Redis can reuse it with `cache.serializable_classes=false`. An old or malformed value is replaced by one fresh observation; an unavailable cache still uses the fresh provider result. Configuration fingerprints, channel selection, financial guards and the existing observation TTL remain unchanged.

Cancellation yang berhasil melalui `OrderLifecycle` mengantrekan `DeleteBuyerCheckoutMessages` setelah commit, bukan menunggu sweep recovery. Cleaner menghapus instruksi checkout yang dilacak untuk semua rail dan pesan detail order terakhir; completion menunggu receipt terkirim sebelum cleanup. Callback hanya menghapus langsung pesan klik yang tidak dilacak, untuk menghindari dua pemilik penghapusan pesan yang sama. Recovery memakai tracking pesan tanpa whitelist provider. Kegagalan verifikasi provider, termasuk HTTP 404 KlikQRIS, tetap fail closed: tidak dianggap bukti unpaid atau cancellation berhasil.
Instruksi hasil resend/recovery juga dihapus melalui ID pada checkout effect berstatus `Sent`, dengan claim penghapusan durable; receipt dan effect non-checkout tidak disentuh. Response checkout yang tiba setelah cancellation mengantrekan cleanup lagi setelah ID tersimpan. Recovery juga menemukan instruksi ledger-only saat projection Order tidak memiliki ID pesan.
Pemeriksaan state setelah pengiriman hanya membaca ID, status Order, dan marker receipt; tidak memuat ulang graph Payment.

Telegram checkout instructions use Telegram `code` entities for tap-to-copy values: the Binance Pay receiver ID and exact USDT amount in both photo captions and text fallbacks, and the exact USDT amount plus snapshotted BSC receiving address in BSC messages. Those entities are best-effort decoration: a value that cannot be located on its own line is skipped so the instruction still reaches the customer as plain text, and `CustomEmojiCapability` rebases the offsets when custom-emoji wrappers are converted.

Untuk delivery durable, [`TelegramOutboundEffectLedgerProtocol`](../../src/app/Services/Telegram/TelegramOutboundEffectLedgerProtocol.php) adalah satu-satunya seam state: claim/lease, token-checked finalization, ambiguity, retry scheduling, terminal failure, dan receipt ordering. [`TelegramOutboundEffectPolicy`](../../src/app/Services/Telegram/TelegramOutboundEffectPolicy.php) memasok limit dan policy family tanpa memindahkan state machine ke adapter. `TelegramOutboundEffectService` hanya mematerialisasi effect, melakukan lookup, dan menyimpan resend history; queue mapping berada di `TelegramOutboundEffectDispatcher`. Delivery/projection adapter per family adalah `CheckoutInstructionDeliveryPipeline`, `ReceiptDeliveryPipeline`, `AdminOrderNotificationDeliveryPipeline`, `ManualPaymentProofDeliveryPipeline`, `SalesProofDeliveryPipeline`, `AdminBroadcastDeliveryPipeline`, `StockNotificationDeliveryPipeline`, `ProductPriceChangeNotificationService`, `BalanceTopUpPaymentProofDeliveryPipeline`, `BalanceTopUpOwnerAlertDeliveryPipeline`, dan `BalanceTopUpChannelProofDeliveryPipeline`. Jangan menambahkan claim/finalization pass-through baru ke service materializer atau job. [`TelegramOutboundResponseValidator`](../../src/app/Services/Telegram/TelegramOutboundResponseValidator.php) adalah satu-satunya klasifikasi respons Telegram: respons sukses tanpa message identifier yang valid berarti ambiguous (`unknown`, Manual Delivery Review) untuk `sendMessage`, `sendPhoto`, maupun `sendDocument`, sedangkan `ok:false` dan kegagalan pre-send tetap kegagalan definitif; caller yang memang butuh identifier tetap gagal-tertutup. Boundary lengkap, scope #286, recovery, dan Manual Delivery Review ada di [`telegram-outbound-effect-architecture.md`](../../docs/telegram-outbound-effect-architecture.md); migration/incident runbook ada di [`runbook-telegram-outbound-effect-contract.md`](../../docs/runbook-telegram-outbound-effect-contract.md).

Paid-order receipt materialization has a deliberate after-commit handoff:
`OrderStateMachine` queues `SendTelegramReceipt` with only the Order ID after
the outermost payment/checkout transaction commits. The job then asks
`TelegramOutboundEffectService` to prepare the transient receipt plan and
invoice image before acquiring the short Order lock, rechecks eligibility,
and materializes the durable effect group. Explicit and admin resends follow
the same prepare-before-lock boundary. Pending BSC manual-review completion
uses `OrderLifecycle` and its receipt handoff exactly once; already-paid late
fulfillment uses the manual service's fallback handoff. The
`telegram:recover-outbound` source scan materializes a missing receipt effect
from the committed paid Order if the after-commit dispatch was lost.

Issue #655 reuses an automatic TXT export only inside the current
`TelegramReceiptPlan`/`sendEffect()` attempt; a continuation or retry still builds
a new authorized plan. The payload stays memory-only, bounded to 1 MiB and
excluded from serialization/debug content. No PNG or plan crosses a job/cache
boundary. `AdminPaidOrderOutcome` refreshes stock allocation counts with one
collection `loadCount('stockItems')`, rather than one query per Order Item;
it does not hydrate secrets or trust stale loaded stock collections/counts.
Completion/intervention readiness and the 120-second threshold are unchanged.
See [measured query/render work and limits](../telegram-receipt-work-evidence.md).


`ReceiptDeliveryPipeline` marks only the first successful receipt unit whose transient presentation contains credentials, then enqueues `PinTelegramCredentialReceipt` on `telegram-pins` after the `sent` transition; `TelegramCredentialPinService` re-checks pin eligibility and writes one canonical pointer under an atomic claim. The pin never gates the successor or admin wakeup. Pin state is stored on `Order` for idempotency; the outbound-effect recovery command retries only the safe pin side effect and never re-sends credentials. `TelegramOrderIntents` presents up to ten customer-owned successful paid orders (`processing`/`completed`) at a time with deterministic newest-first keyset pagination and compact invoice/status buttons; each selected invoice still resolves through the customer-owned detail query. `/status` adds a bounded latest-item preview, payment/order guidance and deadline, a signed invoice payment link for an unexpired pending order, and current/previous-calendar-month paid spending (Order creation month in the application timezone; refunded Orders excluded). Its month buttons open customer-owned lists of all Order states with ten rows per page; compact `om:YYYYMM` cursors retain the month across adjacent older/newer pages and stay within Telegram’s 64-byte callback limit.
Untuk KlikQRIS, `delivery_outcome=retry_notice_sent` tidak dianggap selesai: ketika customer menekan Check Payment atau reconciliation menemukan Payment yang sama sudah kembali payable, service mematerialisasi effect sequence 2 dan dispatcher mengantrekan ulang QR melalui Payment yang sama. Effect ini hanya dibuat untuk order/payment yang masih pending dan tidak menduplikasi effect awal `qris_sent`.

Balance top-ups follow the store's selected provider rail (`manual`, `klikqris`,
`sakurupiah`) but never create an Order or Payment. The provider is resolved once
at funding confirmation and snapshotted on the top-up, and
`StoreBalanceTopUpService` selects the rail through
`StoreBalanceTopUpLifecycleResolver`; the service then settles the requested
credit through `StoreBalanceLedger` and emits `StoreBalanceTopUpSettled` as an
after-commit application event. `StoreBalanceTopUpEventLogger` stores only
sanitized provider evidence. `MaterializeStoreBalanceTopUpReceipt`
reloads the committed top-up and delegates the idempotent receipt handoff to
`StoreBalanceTopUpOutbound`; the family module owns both top-up effect
eligibility/identity and safe callback matching, and provider-scoped effect keys
and templates keep each rail's instructions and receipt distinct (KlikQRIS keeps
its historical key). Manual top-ups reuse the manual product-purchase proof
habit: the customer sends the payment photo with the `/payment` caption, the
effect `admin.balance_top_up_payment_proof` forwards it to the admin on the
`notifications`
queue and carries approve/reject callbacks
(`admin:topup-approve:{id}` / `admin:topup-reject:{id}`) handled by
`TelegramAdminPaymentCallbackHandler` (stage `BeforePrivacy`, guarded by
`TelegramAdminAccess::isAdmin`) and settled through
`ManualStoreBalanceTopUpConfirmationService`, audited as
`store_balance.top_up.manually_confirmed` / `.manually_rejected`.
A verified provider success that arrives after the top-up deadline still settles
the same single credit (audited as `store_balance.top_up.late_settled`), while
top-ups that may carry money but cannot be settled automatically materialize at
most one `admin.balance_top_up_owner_alert` effect per top-up through
`StoreBalanceTopUpOutbound::recoverMissingOwnerAlerts`; plain expired top-ups
without money evidence stay silent.
Successful IDR top-ups also hand off one private
`admin.balance_top_up_paid_notification` from
`MaterializeStoreBalanceTopUpReceipt`, independently of the Store Channel toggle.
`SendStoreBalanceTopUpOwnerAlert` and `BalanceTopUpOwnerAlertDeliveryPipeline`
deliver the success family separately from unresolved-money alerts. The admin
message includes merchant reference, buyer username/Telegram ID, credited balance,
payable total, method/provider and paid time; top-ups are not reported as earnings.
`StoreBalanceTopUpOutbound::recoverMissingPaidNotifications` repairs missing
handoffs for settlements within seven days without resending existing effects.
Verification: `StoreBalanceOwnerAlertTest` covers private-only delivery, replay
deduplication and recovery excluding unsettled/older top-ups.
Settled top-ups also publish one public Store Channel proof
(`balance.top_up_channel_proof`) through
`MaterializeStoreBalanceTopUpChannelProof`, reusing the paid-invoice renderer and
the masked-buyer caption seam; it follows the channel `sales_proof_enabled`
toggle and never increments the order proof counter.
`TelegramBalanceFundingCallbackHandler`
and routine navigation callbacks consume that seam without inspecting
Telegram Outbound Effect state. No Telegram work runs inside financial locks.

Receipt successor/readiness is exposed by
`TelegramOutboundEffectLedgerProtocol::nextReceiptEffect($groupKey, $afterSequence)`.
Pass `0` for initial materialization; pass the completed sequence for a
continuation. Materialization, delivery, recovery, and Manual Delivery Review
must consume this seam rather than repeat sequence/state selection.
`isReceiptGroupComplete($groupKey)` is the matching completion seam: it uses the same family policy to distinguish a completed group from a blocked group when no successor is dispatchable.

Receipt legacy invoice-photo effects memiliki satu pengecualian ordering:
unit `invoice` pertama memakai `sendPhoto` dan bukan unit credential. Jika
hasilnya `failed` (termasuk render gagal atau penolakan Telegram definitif)
atau `unknown` (Ambiguous Telegram Delivery), unit Product Detail/text
berikutnya boleh berjalan; effect foto tetap dapat direview dan `unknown`
tidak pernah di-resend otomatis. `manual_review` pada invoice tetap menahan
urutan sampai operator menandainya `sent` atau membuat explicit resend.

Group baru `receipt-photo-caption-v1` memakai `invoice_caption` yang dapat
memuat credential, sehingga seluruh child strict ordered sejak sequence
pertama. `failed`, `unknown`, atau `manual_review` pada photo maupun
continuation memblokir child berikutnya. Only a definite render/send failure
may materialize a text fallback; an unknown photo result containing credentials
blocks both fallback and successors until operator review. Explicit
text-fallback memakai aturan
strict yang sama. Buyer completion untuk group baru terjadi setelah semua
child `sent`; group legacy tetap memakai aturan non-invoice lama. Pipeline lalu
mengisi `orders.telegram_receipt_sent_at` dan mengantrekan
`DeleteBuyerCheckoutMessages`.

Scheduled recovery rechecks deterministic receipt groups whose children are
already `sent` when the Order marker is null. It repairs the marker; the
command's cleanup scan then applies normal eligibility and queues the cleanup
without redispatching the receipt.

Scheduled recovery also scans sent Admin Broadcast recipient effects after
ledger finalization. It conditionally repairs the recipient `sent_at` and
clears stale failure fields, then recomputes the affected broadcast aggregate
projection without redispatching the sent effect; other recipient outcomes
remain independent.

### 4.2 Web Storefront

Route utama ada di [`src/routes/web.php`](../../src/routes/web.php):

- public catalog: `/`, `/shop`, `/pilihan/{productFamily:slug}`, and `/produk/{product}`;
- family discovery is built by `StorefrontFamilyPageBuilder` from the shared credential-free `CatalogBrowse` projection. `/pilihan/{slug}` is canonical/indexable discovery; `/produk-keluarga/{slug}` is a 301 legacy redirect backed by durable slug aliases; `/produk/{product}` remains the product detail and waitlist contract;
- guest cart: `GET /shop/cart`, `POST /shop/cart/items`, `PATCH /shop/cart/items/{product}`, and `DELETE /shop/cart/items/{product}`;
- canonical direct Product checkout review: `GET /shop/checkout/products/{product}` (`storefront.shop.checkout.product`), with bounded `quantity` and safe same-origin return context;
- shared Cart checkout review: `GET /shop/checkout` (`storefront.shop.checkout.start`);
- one checkout transaction: `POST /shop/checkout` (`storefront.shop.checkout`) accepts exactly one direct Product source or Cart snapshot;
- signed invoice/order: `/shop/orders/{order}`;
- signed status refresh/cancel and Binance Order ID submission;
- Recovery Key order access: `/akses-pesanan`;
- customer account/login link: `/akun/*`;
- admin: `/admin/*` behind `admin.access`.
Cart checkout replay identity is based on the Cart and its Product/quantity
composition; the submitted Cart snapshot separately validates mutable pricing
and availability before a new Order is created. This keeps duplicate submits
resolvable after reservations update Product timestamps without accepting a
stale snapshot for a new checkout.

`StorefrontShopCheckoutReviewController` and `StorefrontCartPageBuilder` own the page-based checkout review module. The Blade page
[`storefront/shop-checkout.blade.php`](../../src/resources/views/storefront/shop-checkout.blade.php)
renders direct Product and guest Cart sources through one hierarchy: source-aware return link,
summary, quantity or read-only Cart items, buyer name, selected payment method, optional
disclosures, consent, and one in-flow submit action. It has one document scroll, no checkout
dialog, no nested checkout scroll container, and no modal transport/runtime state.

The #477 Storefront presentation keeps catalog data and checkout ownership unchanged while the `storefront-shell` scope supplies soft paper/surface/ink roles, a shared search header, direct mobile Cart access, compact DB-backed featured entries, and reduced-motion-safe motion. Available Product detail and checkout pages may expose mobile sticky actions that reuse the canonical checkout form; JavaScript-disabled users retain the in-flow controls.
Storefront hero is an image-only banner carousel: it shares the floating header card's width (`min(100vw - 2rem, 80rem)`), the slide box takes the uploaded art's own aspect ratio (clamped to 16:9–4:1), and the slide stops one peek token short of that width on each side, so the neighbouring banner stays a hint instead of a competing second banner. The track padding derives from the slide width, so both peeks stay symmetric and the first and last banner always reach their snap position. Its controls ride on the banner instead of reserving a row below it: arrows sit just inside the active slide's edges — the peek is narrower than the button — and reveal on hover or keyboard focus (always visible for touch-only input), and a status pill holding the dots and the autoplay toggle sits on the banner's bottom edge. The best-seller rail keeps its own header controls inside `carousel-controls`, where the pause toggle mirrors the same `aria-pressed` contract. Up to five ordered banners, alt text, and optional store-local links are managed in Admin → Produk and stored in `StorefrontSetting.homepage_banners`. `StorefrontBanners` owns atomic edits, optimistic version checks, audit, and file cleanup; the existing after-commit settings observer invalidates the homepage cache. Empty slots are omitted, and a neutral empty banner is shown until the owner uploads images. The best-seller rail uses the same native-scroll carousel in `src/resources/js/storefront/carousels.js`. Rotation pauses on hover, focus, touch, hidden tabs, off-screen sections, and reduced motion. Images in cards, banners, and detail use `object-fit: contain`, except the hero slide, which fills its box with `cover` when art outside the accepted band is uploaded; detail pages share a native dialog preview with Escape/focus restoration. Shared presentation styles live in `storefront-enhancements.css`, imported by both public and customer bundles. Images remain DB/Admin-owned; there are no additional catalog/presentation ENV settings. Verified purchase activity uses the existing verified feed in a dismissible, in-flow bar immediately below the hero banner on home and shop pages, and below breadcrumbs on Product and Product Family pages without banners. Mobile stacks the label and wrapping message; wider screens align the message to the right. Rotation preserves the bar's space, so it never overlays Plan options or sticky checkout actions, and reduced motion disables automatic rotation.
Storefront Product cards and detail covers read the optional Product `icon` and custom image from the database/Admin product record. Runtime presentation uses a neutral generic fallback when both are absent; it does not map product names to brand-specific cover content. The separate ChatGPT login guide remains informational content, not catalog data.

Per-locale Product Details and Replacement / Refund Policy copy resolves through [`LocalizedProductCopy`](../../src/app/Catalog/LocalizedProductCopy.php), the single owner of the locale columns: `description`/`warranty_policy` hold the Indonesian variant, `description_ms`/`description_en` and `warranty_policy_ms`/`warranty_policy_en` hold the other supported locales, and an empty variant falls back to the base column. `StorefrontLocale::code()` is the one validated reader of `config('storefront.locale')` (allowlist `id`, `ms`, `en`) shared by `StorefrontPaymentTerminalCopy`, `StorefrontShopSignedStatusRefreshResult`, `StorefrontProductPresenter`, `StorefrontCatalogProductPresenter`, `StorefrontProductMerchandising`, and the `StorefrontResponseCache` key, so the storefront locale signal exists in exactly one place. `CatalogBrowse` selects the locale columns, keeps the raw values on `CatalogProductReadModel::$copy` (packed by `CatalogProjectionPayload`, with `TelegramCatalogProjectionCache::DESIGN_VERSION` bumped for the changed shape), and matches search terms against every variant, not only the base copy. Adding a locale means adding its column plus its code to `LocalizedProductCopy::LOCALES`; nothing else may read a `{field}_{locale}` column name directly.

The final Storefront release matrix covers `320x568`, `390x844`, `768x900`,
`1280x900`, `1440x900`, and `1920x1080`. Public browser evidence keeps
`/pilihan/{slug}` canonical, treats `/produk-keluarga/{slug}` as redirect-only
(including durable aliases), rejects invalid canonical and legacy slugs with
404, preserves only safe same-origin return context, and verifies sitemap,
JSON-LD, Open Graph, breadcrumb, and placeholder-asset URLs without the
retired public family path or internal catalog labels. Product detail evidence
covers available direct review, unavailable waitlist, no-image fallback,
keyboard/Escape navigation, reduced motion, 200% zoom, and JavaScript-disabled
links/forms without horizontal overflow or sticky-action overlap.

Every public purchase entry point uses this contract: homepage and `/shop` Product cards
link `Beli sekarang` to the canonical direct review; Product detail uses
`#product-checkout-cta`; Product Family options use `Beli sekarang` directly to the same review; Cart
uses `Lanjut checkout` to the shared Cart review. No entry point uses
`/produk/{product}?checkout=1`, `data-modal-url`, or the retired Product modal endpoint.

`StorefrontShopCheckout` composes direct-buy and Cart review submissions through the same Cart and
`OrderCheckoutService` transaction. Cart checkout keys include the complete ordered item snapshot
and selected payment rail. The signed review snapshot detects Product, price, visibility, stock,
or Cart-quantity changes; authoritative Product locks still revalidate all items before Order
creation.

Validation, provider readiness, maintenance, stale state, or stock failure redirects back to the
same canonical direct or Cart review URL with safe `old()` values, an error summary, and the
first-invalid focus target. GET review performs no Customer, Cart, Order, Payment, reservation,
provider, queue, or outbound side effect. Checkout Web Storefront requires explicit terms/refund
consent before any checkout side effect; the accepted policy version and timestamp are snapshotted
on the Order.

Invoice web uses signed URLs and status refresh. `StorefrontShopPaymentStatusRefresh` may recover
native QRIS, perform provider status lookup, or verify Binance, but paid transition remains owned
by `OrderLifecycle`. `StorefrontOrderAccess` only emits plaintext Recovery Key at issue time;
the database stores its hash and session grants are time-limited.

Paid account data is also emailed to the checkout address when the guest filled it. `OrderCredentialMailService` owns that delivery and extends the existing `order_notification_events` row with `event_type = credentials_ready`, an encrypted `recipient_email` snapshot, and `next_attempt_at`; the milestone mailer (`GuestOrderNotificationService`) refuses to create or claim that type. The row is the delivery authority: the `notifications` queue only wakes it, the ID-only `SendOrderCredentialMail` job carries no credential plaintext, an attempt-fenced 120-second lease with five attempts survives a worker or Redis loss, and the recipient can never be re-pointed because every retry and every resend reads the stored snapshot rather than `orders.notification_email`. `OrderStateMachine` (paid transition and manual completion), `ManualFulfillmentResolutionService`, and `SupplierFulfillmentService` record the handoff only when `CredentialDeliveryEligibility` allows it and allocated credentials exist, so supplier fulfillment is deferred until it completes; `GuestOrderNotificationService::capture()` additionally refuses to retarget a paid order's address. Delivery fails closed on a logging mail transport and defers, without burning an attempt, when the configured mailer is not a delivering SMTP/SES-style transport. The customer invoice page shows the masked recipient, status, and a throttled resend (`POST /shop/orders/{order}/credential-email`) gated by the same reveal grant as the credentials; Admin order detail exposes an audited resend for the same recorded address. `orders:recover-credential-mails` runs each minute to redispatch retained rows and to materialize handoffs a lifecycle hook missed.

### 4.3 Admin dashboard

Admin request melewati `AuthorizeAdminAccess`, yang menempelkan `adminUser` ke request. Role canonical ada di [`UserRole`](../../src/app/Enums/UserRole.php): `owner`, `admin`, `finance`, `support`.

- Owner/Admin: boleh masuk `/admin` (satu-satunya role yang lolos `User::canAccessAdmin()`), katalog dan sebagian setting.
- Owner: mengganti Payment Provider Switch.
- Owner/Admin: rekonsiliasi dan complete manual order sesuai policy.
- Owner/Admin: melihat order/payment dan resend receipt sesuai policy.
- Finance/Support: tidak bisa masuk `/admin` sama sekali; capability predicate-nya (`canReconcilePayments`, `canResendReceipts`, …) tetap ada untuk role semantics dan dipakai bila akun dipromosikan ke `admin`. Konfirmasi manual payment/top-up manual tetap berjalan dari notifikasi admin Telegram, yang gerbangnya ID Telegram owner (`TelegramAdminAccess`), bukan role web.
- Supplier connections, private catalog/pricing and purchase recovery: Owner/Admin only; API key/provider changes and operational recovery require password confirmation within 15 minutes. Finance/Support cannot read this surface.

`AdminPaymentModeController` hanya mengorkestrasi request. Validasi readiness dan optimistic version conflict berada di `UpdatePaymentModeRequest`/`PaymentProviderSwitcher`; switch dan audit log ditulis atomik dalam transaksi. Switch provider lama tidak mengubah Payment yang sudah ada.

`AdminDashboardOverviewData` menyusun Ringkasan melalui `AdminDashboardData::forActiveTab()`: pendapatan hari ini, count pesanan lunas lintas mata uang, antrean proses, grafik 7/30 hari, perhatian yang memerlukan tindakan, dan lima pesanan terbaru. `PaymentReceiptReport::dailySalesReceipts()` mengagregasi satu pembayaran paid canonical per Order berdasarkan `paid_at`, membatasi Order ke paid + processing/completed, serta memakai perhitungan net provider yang sama dengan laporan pembayaran. Hari tanpa transaksi bernilai nol; IDR dan USDT tidak dijumlahkan. Top-up tidak masuk penjualan, sedangkan refund parsial dan modal supplier belum dikurangkan.

`AdminDashboardStatsData::forSettings()` memuat hanya bagian yang aktif pada `/admin?tab=settings&section=pembayaran|saldo|diagnostik`. Form kebijakan IDR/USDT dan jeda layanan berada di disclosure; error validasi membuka form terkait. `AdminDashboardRedirector` dan request validation mengembalikan mutasi ke bagian Pengaturan yang sesuai, dengan anchor target tetap. Role, password confirmation, optimistic version, audit, dan aturan uang tetap milik service masing-masing.

The product create/edit modal edits each localizable Product copy section — Product
Details and Replacement / Refund Policy — through language tabs that share one
`productContentLocale` state. `AdminProductDashboardProductsData` exposes `descriptionMs`/
`descriptionEn`/`warrantyPolicyMs`/`warrantyPolicyEn` on `edit_payload`,
`AdminProductDashboardState` rebuilds them from `old()` input (and reopens the tab that owns a
rejected variant), `AdminProductFormRequest` trims the variant columns and enforces the shared
Telegram Product Detail unit budget for `ms`/`en`, and the create/update controllers persist and
audit the variant columns. Locale variants render the plain Unicode text on the Web Storefront:
the Dashboard never stores Telegram emoji offsets, so saving from the Dashboard intentionally
leaves existing `telegram_content` bindings stale for the variant it changed.

Untuk stok, `AdminProductAccountStockController` menerima JSON Account Input (1–20 kartu) dan mengembalikan hanya metadata aman. `AdminProductStockController` mempertahankan redirect/flash contract Input Cepat. `ProductStockItemManager` hanya mengedit atau menghapus Available rows; Reserved, Paid, retired, dan repair-required rows tetap read-only. Invalid `credential_format` atau encrypted structured payload diproyeksikan sebagai `REPAIR` tanpa secret.

`CredentialDeliveryPresentation` adalah seam bersama Store Bot dan Web Storefront. Ia memeriksa ulang paid eligibility, customer ownership, ordering, format legacy/structured, dan effective tutorial; DTO-nya hanya hidup di memory dan sengaja menolak serialization/debug secret output. Telegram menggunakan [`TelegramReceiptNotifier`](../../src/app/Services/Telegram/TelegramReceiptNotifier.php) + receipt ledger; Web menggunakan [`StorefrontShopSignedInvoice`](../../src/app/Storefront/StorefrontShopSignedInvoice.php) + Recovery Key/access policy. Halaman Warranty memakai proyeksi sempit `forStockItem()` dari seam yang sama untuk akun pengganti; alokasi harus milik Order paid dan credential retired ditolak. Payload yang gagal divalidasi menghasilkan fallback aman per akun, tanpa parsing payload di Blade. OTP Mail Link tidak pernah di-fetch oleh server.

`TelegramCredentialDeliveryRenderer` menampilkan satu blok native `pre` berlabel `copy` per akun (`email | password | MFA/Auth Token` bila ada) untuk receipt dan Detail Pesanan, termasuk credential legacy. Payload legacy bebas tetap utuh; tutorial dan tombol OTP berada di luar blok salin. Offset/length memakai UTF-16, dan konversi custom emoji tidak mengubah teks di dalam blok. Pesan berisi credential memakai `protect_content = false` agar dapat disalin, sehingga juga dapat diteruskan/disimpan; eligibility paid, ownership Customer, privacy guidance, dan ledger ambiguity tetap berlaku. Kebijakan TXT otomatis untuk payload panjang/batch besar serta ekspor TXT manual tidak berubah.

Format legacy `email----password----link MFA` dideteksi otomatis oleh `CredentialDeliveryFormatter`, termasuk saat stok masuk lewat Input Cepat. Saat delivery, email, password, dan MFA ditampilkan pada baris berlabel terpisah; renderer Telegram aktif memakai entity `code` per nilai agar email/password dapat disalin satu per satu. Password dan MFA dipertahankan persis (termasuk simbol, Unicode, dan spasi); email hanya di-trim. Triplet kosong, multiline, atau dengan separator tambahan tidak dinormalisasi. Format pipe lama dan payload legacy bebas tetap memakai blok `pre` per akun. Tidak ada migrasi secret tersimpan atau fetch ke URL MFA. Regresi: `CredentialDeliveryFormatterTest` dan `LegacyCredentialDeliveryRenderingTest`.

Layout receipt disimpan sebagai `credential_layout_version = copy-v1`. Receipt lama tanpa marker tersebut memakai `TelegramCredentialDeliveryRendererV1` dengan batas unit/urutan tutorial dan token asli, termasuk saat worker melanjutkan group yang baru sebagian terkirim. Fallback mengikuti versi sumber; explicit resend membuat group baru, bukan mengubah sequence lama. Konversi/fallback custom emoji mempertahankan blok `pre` beserta offset UTF-16, termasuk caption entities JSON. Regresi mencakup legacy one-chunk credential, pending token setelah account unit sent, dan cached/rejected custom-emoji fallback.

`CheckoutMaintenance` adalah owner canonical untuk state **Checkout Maintenance** melalui [`CheckoutMaintenance::current()`](../../src/app/Services/CheckoutMaintenance/CheckoutMaintenance.php), `change()`, dan `guardNewOrder()`. `AdminCheckoutMaintenanceController` hanya mengorkestrasi request; role predicate `UserRole::canManageStoreOperations()` membatasi mutasi ke Owner/Admin, sedangkan `AdminDashboardStatsData` menyajikan status untuk semua role admin. `change()` mengunci row singleton `checkout_maintenance_settings`, memeriksa optimistic `version`, menormalkan note, mendeteksi no-op, lalu menulis setting dan `AuditLogger` dalam transaksi yang sama. Perubahan nyata menaikkan version dan menyimpan activation timestamp; deactivation selalu menghapus public note, sementara nilai lama tetap berada di audit.

`OrderCheckoutService` adalah owner transaksi checkout yang authoritative. Setelah reuse `checkout_key`, lock cart, reuse Order dari cart `checked_out`, dan validasi cart, ia mempertahankan urutan lock bersama `cart → customer_balance_accounts (Store Balance only) → checkout_maintenance_settings → cart items/products → ManualPaymentSetting`; rail non-Store Balance menghilangkan account step. `CheckoutPaymentPreparation::preflight()` sudah dijalankan sebelum transaksi dan lock; setelah lock serta validasi snapshot setting, `prepare()` membentuk immutable `CheckoutPaymentPlan` tanpa external I/O. `OrderCheckoutService` hanya mempersist plan generik lalu mengorkestrasi Order, reservation melalui `InventoryLedger`, credential allocation, Telegram Outbound Effect materialization, dan Cart transition. Karena `guardNewOrder()` berjalan di transaksi yang sama dan mutation admin memakai row lock yang sama, commit order menentukan race: checkout lebih dulu menghasilkan satu Order yang sah; activation lebih dulu menghasilkan tidak ada efek checkout baru. Order, Payment, reservation, credential allocation, notification, callback, reconciliation, expiry, cancellation, fulfillment, receipt, dan Sales Proof Post yang sudah ada tidak membaca state maintenance.

`CheckoutPricingSnapshot` (`src/app/Services/Checkout/CheckoutPricingSnapshot.php`) is created after Cart Item/Product and provider-setting locks. It resolves each locked Product's ordered tier schedule through `ProductQuantityPricing`, quotes each Cart Item once, and retains immutable per-Product IDR/USDT subtotals plus aggregate totals. Manual QRIS, KlikQRIS, Sakurupiah, Store Balance, Binance Pay, and BSC preparation consume those totals; Order and Order Item persistence consume the same snapshot. Product tier replacement must retain the existing Product lock so checkout observes a complete old or new schedule. The snapshot also owns the priced channel: `fromCartItems()` and `OrderCheckoutService::checkout()` accept one trusted `ResellerPricingContext` or none, so a quote preview and the charged Order total are built from the identical products, currencies and per-unit precedence. `OrderItem.quantity_price_tier_minimum` stays null when a reseller override replaced that currency's tier, and the supplier acquisition guard keeps using the charged (possibly reduced) IDR subtotal, so a lower reseller price cannot bypass supplier eligibility or minimum profit. Evidence: `ProductResellerPricingTest`, `AdminProductWorkspaceTest`, `AdminDashboardProductFormsTest` and `ProductResellerPricePostgresConcurrencyTest` (locked snapshot versus an in-flight Admin price/tier edit).

Adapter presentation tidak memiliki aturan cutoff sendiri. `StorefrontShopCheckout` hanya melakukan preflight non-authoritative sebelum membuat Customer/cart; guard service tetap wajib. `StorefrontResponseCache` memasukkan maintenance `version` ke cache identity sehingga request pertama setelah perubahan nyata tidak memakai HTML lama, sedangkan no-op mempertahankan identity. `StorefrontCatalogPageBuilder`/presenter dan view partial menampilkan notice serta mengganti CTA; `TelegramMenuIntents`, `TelegramCatalogIntents`, `TelegramCartIntents`, dan callback handlers mempertahankan browsing/stock/cart, sementara `TelegramCheckoutMaintenanceCallbackHandler` menyediakan Cek Status. Status callback terdaftar sekali pada stage customer setelah privacy guard dan tidak mengirim broadcast.

Entry point test terfokus adalah `tests/Feature/StorefrontShopCartTest.php` (guest Cart session isolation, expiry, stale snapshot, multi-item checkout, duplicate submit, maintenance rejection, and mixed fulfillment), `tests/Feature/StorefrontShopCheckoutTest.php` (direct checkout and payment rails), `tests/Feature/StorefrontProductCheckoutTest.php` (canonical Product review, page cutover, unavailable state, and recovery), `tests/Feature/CheckoutMaintenanceTest.php` (mutation, zero-side-effect rejection, existing native QRIS/status refresh, lifecycle continuation), `tests/Feature/PakasirWebhookTest.php` (authoritative callback, reconciliation, receipt/Sales Proof Post), `tests/Feature/Telegram/CheckoutMaintenanceTest.php`, and `tests/Feature/CheckoutMaintenancePostgresConcurrencyTest.php`. Browser customer evidence is in `tests/Browser/admin-dashboard-responsive.spec.js`; `npm run test:browser:customer` selects the `customer checkout:` cases covering home/shop/Product detail/Product Family, Cart, signed manual invoice, JavaScript-disabled form submission, failure recovery, browser back, keyboard/disclosures, reduced motion, zoom/reflow, and the 320×568, 390×844, 768×900, 1280×900, 1440×900, and 1920×1080 viewport matrix. Lane PostgreSQL dijalankan oleh `composer test:concurrency-gate`; race test mencakup kedua checkout locks/reservation hanya bila perubahan menyentuh boundary tersebut.

Issue #477 release evidence is the focused Storefront feature suite, the responsive customer browser suite, `npm run build`, the slug-repair dry run, and an HTTP 200 smoke check for `/assets/product-placeholder.svg`. Production mutation and deployment remain owned by the approved GitHub Actions release from `main`.

### 4.4 Buyer history dan transaksi KlikQRIS historis

`CustomerOwnedOrderQuery` adalah pemilik query histori pada `/akun/pesanan`: query selalu dibatasi oleh `customer_id`, mendukung filter `all`, `waiting`, `processing`, `completed`, dan `issue`, lalu melakukan pagination newest-first dengan query string tetap. `CustomerAccountController` hanya meneruskan hasil query dan status filter ke view. `/akun/pesanan/{order}` tetap customer-scoped; Order non-paid hanya menampilkan status/next step, sedangkan credential dan Recovery Key mengikuti aturan akses paid yang sudah ada.

`CustomerAccountOverviewReader` menyusun identitas, snapshot saldo melalui `CustomerBalanceOverviewReader`, dan hitungan pesanan melalui `CustomerOwnedOrderQuery::counts()`. Signed-in `/akun` memakai `customer.dashboard`; composer untuk view akun berbagi ringkasan dan memakai ulang snapshot halaman saldo yang sudah dibaca. `customer.partials.account-layout`/`account-profile` menyatukan profil, saldo, histori, keamanan, dan klaim. `CustomerIdentityController` menyediakan JSON identitas minimal dengan respons private/no-store; `storefront/account-identity.js` mengisi nama/avatar di navbar tanpa menaruh data pelanggan di HTML katalog yang di-cache publik.

Dashboard tidak lagi memiliki tab Riwayat KlikQRIS atau Withdraw, dan tidak melakukan fetch reporting remote untuk halaman tersebut. `AdminDashboardReportingData` beserta view reporting-nya telah dihapus. Payment, Order, audit, client provider, dan jalur settlement historis tetap ada; penghapusan UI tidak mengubah status transaksi atau inventori.

## 5. Checkout dan payment rails

### 5.1 Provider selection

Pergantian provider melalui Pengaturan hanya menerima `manual` dan `sakurupiah`. KlikQRIS dan Pakasir tidak dapat dipilih untuk pergantian baru. `PaymentProvider::normalize()` serta adapter legacy tetap mengenali provider transaksi historis agar provenance tidak hilang. Switch tidak mengubah Payment yang sudah dibuat; readiness Sakurupiah dan optimistic version tetap diperiksa oleh `PaymentProviderSwitcher`.

`CheckoutPaymentPreparationResolver` memetakan pasangan provider/method yang sudah dinormalisasi ke satu adapter preparation. `OrderCheckoutService` mengonsumsi adapter dan plan tanpa mengetahui aturan rail.

| Rail                 | `Payment.provider` | `Payment.method` | Intent/preparation/verification utama                                                                                             |
| -------------------- | ------------------ | ---------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| QR Manual            | `manual`           | `QR_MANUAL`      | `ManualQrisCheckoutIntent` + `ManualQrisCheckoutPaymentPreparation`, admin completion                                           |
| KlikQRIS Native QRIS | `klikqris`         | `qris`           | `KlikQrisCheckoutPaymentPreparation` + `KlikQrisNativeQrisService`                                                               |
| Binance Pay          | `manual`           | `BINANCE_PAY_ID` | `BinanceCheckoutPaymentPreparation` + `BinancePaymentVerification`                                                             |
| USDT BEP20/BSC       | `manual`           | `USDT_BEP20`     | `BscCheckoutPaymentPreparation` + `BscPaymentVerification`                                                                     |

Binance memakai `provider=manual` karena ia adalah metode di jalur payment manual yang berbeda dari provider QRIS; identifikasi Binance harus memakai `method`, bukan provider saja.

Payment Provider Switch berlaku hanya untuk checkout baru. Jangan memindahkan Payment lama ke provider baru, membuat fallback otomatis setelah provider create gagal, atau memakai provider yang aktif saat ini untuk menafsirkan Payment historis.

### 5.2 Checkout transaction

`OrderCheckoutService::checkout()` wraps order creation in a database transaction and retries unique-reference conflicts. It:

1. normalizes the intent, selects the `CheckoutPaymentPreparation` adapter, and runs `preflight()` before the transaction/checkout locks;
2. deduplicates by `checkout_key` when present;
3. locks the Cart and reuses an existing Order for a `checked_out` Cart;
4. validates that the Cart is active and not expired;
5. for a Store Balance intent, locks the Customer's `CustomerBalanceAccount` immediately after the Cart and before the maintenance guard;
6. calls `CheckoutMaintenance::guardNewOrder()` for every rail;
7. locks CartItem/Product and `ManualPaymentSetting` in canonical order;
8. revalidates the selected provider-setting snapshot and preflight identity inside the transaction;
9. calls `prepare()` after all checkout locks are held; this phase only performs transaction-local validation, allocation, and snapshot work;
10. creates the Order and asks [`CheckoutPaymentPlanWriter`](../../src/app/Services/Checkout/CheckoutPaymentPlanWriter.php) to persist the generic Payment plan;
11. creates OrderItem with delivery/usage-guide snapshots;
12. allocates credential stock when relevant;
13. records the inventory reservation through `InventoryLedger`;
14. materializes Telegram Outbound Effects and marks the Cart `checked_out`.

The common checkout lock order is `Cart -> CustomerBalanceAccount (Store Balance only) -> CheckoutMaintenance -> CartItem/Product -> ManualPaymentSetting`; non-Store Balance rails omit the account step. `CheckoutMaintenance::guardNewOrder()` owns the `checkout_maintenance_settings` singleton lock at its documented position. Order/Payment writes, Store Balance debit, and the paid lifecycle follow those locks in the same transaction. Store Balance refund uses `CustomerBalanceAccount -> Order -> Payment -> OrderItem/InventoryMovement/Product`, with the account acquired before lifecycle resource locks. Neither checkout nor refund performs provider, Telegram, or queue I/O while these locks are held.

### 5.3 Pakasir legacy settlement

Pakasir tidak lagi tersedia untuk checkout baru. Payment lama dengan `provider=pakasir` tetap immutable dan diproses melalui compatibility lane sampai retirement gate lulus. `PakasirCallbackProcessor` mencocokkan project/slug, merchant reference, amount, method, dan status; callback paid tetap melakukan Transaction Detail API verification sebelum memanggil `OrderLifecycle`.

`pakasir:reconcile` memproses Payment lama yang masih pending atau baru dilepas, dan provider cancellation attempt tetap idempotent. `pakasir:retirement-status --json` melaporkan settlement, legacy queue, dan owner-approval readiness; ketika `gate=awaiting_owner_approval`, owner dapat merekam approval dengan `php artisan pakasir:retirement-approve <owner-id-or-email>`. Contract migration tidak berada di expand path dan hanya boleh dijalankan setelah report menunjukkan `gate=ready_for_contract`.

Jangan membuat hosted checkout atau Native QRIS Pakasir baru, dan jangan memindahkan logic legacy ini ke KlikQRIS.
### 5.4 KlikQRIS Native QRIS

KlikQRIS adalah module yang memiliki boundary sendiri sesuai [ADR-0006](../adr/0006-klikqris-native-qris-and-pakasir-retirement.md). [`KlikQrisNativeQrisService`](../../src/app/Services/KlikQris/KlikQrisNativeQrisService.php) adalah satu-satunya tempat untuk:

- create dynamic QR;
- recover create outcome yang tidak pasti;
- persist provider payload yang disanitasi;
- mempersempit expiry Payment/Order/top-up;
- menentukan apakah QR aman dibayar;
- mengeluarkan safe outcome;
- menjalankan single-flight lock per Payment/top-up;
- mengamati dan mengirim owner alert actionable.

Outcome public-nya hanya:

| Outcome                 | Makna                                                                                                 |
| ----------------------- | ----------------------------------------------------------------------------------------------------- |
| `Payable`               | QR trusted, total final valid, dan deadline masih berlaku; hanya ini yang membawa payload presentasi. |
| `Creating`              | create sedang/baru akan berjalan; belum boleh bayar.                                                  |
| `RecoveryPending`       | hasil create lama tidak pasti; recovery dilakukan pada Payment/top-up dan invoice/merchant reference yang sama. |
| `ProviderTotalPending`  | provider belum memberi total final; jangan bayar subtotal produk.                                     |
| `InvalidProviderResult` | payload, QR media, atau data provider tidak valid/tepercaya.                                          |
| `Expired`               | deadline provider/order/top-up sudah lewat.                                                          |

Tidak ada automatic fallback KlikQRIS ke Manual dan tidak ada Payment atau
top-up target kedua. Store Bot/Storefront hanya mempresentasikan QR ketika
outcome `Payable`; outcome lain menjadi text/status retry.

`KlikQrisCallbackProcessor` memperlakukan callback sebagai signal. Untuk
Payment, ia melakukan status lookup authoritative, mencocokkan identity/amount/
total, lalu baru memanggil `OrderLifecycle`. Untuk Store Balance top-up, ia
memakai status lookup dan signature yang sama lalu mendelegasikan settlement
tepat satu kali ke `StoreBalanceTopUpService`/`StoreBalanceLedger`.
`klikqris:reconcile` memakai lock yang sama untuk Payment, sedangkan
`klikqris:reconcile-balance-top-ups` merekonsiliasi top-up secara bounded.
Sandbox/production readiness dipisahkan oleh [`KlikQrisEnvironment`](../../src/app/Enums/KlikQrisEnvironment.php).

`ReconcileKlikQrisPayments` membagi kandidat menjadi tiga kelas **disjoint**,
masing-masing dengan cursor `(created_at, id)` per environment:
`active` adalah Payment pending selain create ambigu; `outcome_unknown`
mencakup state `outcome_unknown` dan `recovery_unconfirmed`, apa pun status
lokalnya; `history` mencakup expired/failed dalam lookback 72 jam atau dengan
lookup 404 yang belum terverifikasi, selain create ambigu. Setiap kelas
non-kosong mendapat minimum satu giliran, history mendapat reserved share,
dan sisa kuota mengikuti prioritas active, unknown, history. Cap di bawah
jumlah kelas memakai rotasi lintas sweep. Cursor hanya wrap setelah kelasnya
habis; `--after` menyemai posisi ketiga kelas.

Issue #663 menambahkan metadata discovery pada Payment, bukan lifecycle kedua.
`KlikQrisNativeQrisService` menunda lookup 404 selama 5 menit, 30 menit, lalu
maksimum satu discovery per 24 jam. Selection mengecualikan row yang belum
due sebelum menghitung kuota; cache loss dan cursor reset tidak menghapus
delay. Metadata tidak mengubah `updated_at`, status, stok atau histori.
Evidence authoritative yang lolos processor existing menghapus metadata,
termasuk callback saat cooldown. Late paid tetap masuk Manual Fulfillment
Review. `unresolved_status_404>0` tetap membuat command exit 1 walaupun tick
tidak melakukan HTTP; liveness scheduler tetap terpisah dari task failure.
Sweep mempertahankan batas 10 Payment default (maksimum 100), execution
deadline monotonik 45 detik default, scan cap dan mutex existing.
Lihat [kontrak, sanitized evidence dan remediasi operator](../klikqris-reconcile-candidate-classes.md#legacy-http-404-and-durable-discovery-663).

Issue #654 propagates the remaining monotonic sweep deadline through `ProviderVerificationProfiles` to each KlikQRIS HTTP attempt and retry pause, including balance top-ups. Dispatch middleware rechecks the deadline immediately before transport and clamps request/connect timeouts to the remaining milliseconds; a sub-millisecond remainder never becomes cURL's unlimited zero timeout. Local DB/finalization work may finish after the I/O deadline. Completion reports `http_attempt_count`, `stop_reason` and `carry_over`; #650's class selection, quotas and cursors are unchanged.

### 5.5 Binance Pay

Binance Pay ID memakai [`BinanceCheckoutPaymentPreparation`](../../src/app/Services/Checkout/BinanceCheckoutPaymentPreparation.php) untuk membentuk snapshot verification mode, base/unique amount, auto-verification setting, receiver type/value, dan initial verification status. Adapter itu tidak melakukan history read atau transaksi verification. [`BinancePaymentVerification`](../../src/app/Services/Binance/BinancePaymentVerification.php) tetap menjadi owner matching evidence, attempt/grace policy, manual review, dan `OrderLifecycle` delegation; queue jobs tetap transport adapter.

Ada dua mode:

- `amount_unique`: matching berdasarkan nominal USDT unik dan receiver;
- `order_id`: customer mengirim Binance Order ID, dibatasi jumlah attempt dan punya retry/grace window.

Binance API key harus read-only dan dibatasi IP. History provider dibaca oleh `BinanceTransactionHistoryReader`; queue jobs (`VerifyBinanceAmountUnique`, `VerifyBinanceOrderId`) adalah transport adapter. Mismatch/late evidence tidak auto-claim; hasilnya dapat masuk manual review sesuai outcome.

### 5.6 USDT BEP20 (BSC)

USDT BEP20 memakai `provider=manual`, `method=USDT_BEP20`, dan authority BNB Smart Chain. [`BscPaymentVerification`](../../src/app/Services/Bsc/BscPaymentVerification.php) adalah pemilik discovery transfer dan verifikasi receipt logs, exact transfer predicate, confirmation/grace decision, sanitized evidence, explicit late-hash review, and single claim. Checkout baru mengalokasikan nominal USDT mikro yang unik per Payment; `payments.amount` menyimpan nominal bayar, sedangkan nominal katalog tersimpan di snapshot detail. Payment lama dengan mode `transaction_hash` tetap memakai TX hash customer. Verifier memanggil `OrderLifecycle`; ia tidak mengirim copy Telegram atau mengubah stock secara langsung.

`BscUniquePaymentAmount` tries the catalog amount first and then fixed 1,000-micro-USDT steps (`3.6`, `3.601`, `3.602`). Only pending BSC Payments whose Orders remain `pending_payment` occupy slots; paid/expired/cancelled Orders release them. The former 72-hour all-status reservation and 100-micro step are removed. Checkout's existing payment-setting lock still serializes allocation and insert. Snapshots already issued retain their exact amounts. Discovery filters candidate logs by block time before Payment creation so earlier same-amount transfers cannot cause a false multiple-match review. Shared-address reuse still cannot distinguish a closed invoice's delayed transfer inside a newer invoice's window; see [the allocation tradeoff in ADR-0003](../adr/0003-binance-payment-verification.md#bsc-readable-amount-allocation-2026-10-24). Regression coverage: `BscPaymentAllocationTest` (base, collision steps, closed-slot reuse and old-but-pending occupancy) and `BscPaymentVerificationTest::test_reused_amount_ignores_a_transfer_from_before_the_new_invoice`.

`BscConfirmationGrace` is the expiry guard. It accepts a grace timestamp only when matching successful evidence is stored with an inclusion block between Payment creation and the original deadline. `orders:expire-pending` therefore cannot be bypassed by a fabricated future timestamp. Scheduled reconciliation and status refresh stop when the Order leaves `pending_payment`; amount-unique history scans never run for terminal Orders. One already-queued legacy TX-hash job may verify its submitted hash once and route an exact late transfer through `OrderLifecycle` into `ManualFulfillmentReview`, leaving the Order terminal. Other late transfers require explicit evidence and manual handling.

Before releasing a due BSC Order, `orders:expire-pending` runs the authoritative verifier outside lifecycle transactions. This closes the race with background `bsc:reconcile`: an on-time exact transfer can claim the still-reserved Order before expiry. RPC errors, missing verification configuration and stale submissions skip release rather than assert that no payment exists; a successful empty scan still expires the unpaid Order. Existing confirmation-grace and late-payment rules remain unchanged.

Telegram `/payment` routes pending `USDT_BEP20` Orders to BSC verification, not QR Manual proof approval, even though both share `provider=manual`. A screenshot schedules the existing chain check without persisting a manual proof or approval effect. Amount-unique Payments retain automatic discovery; legacy Payments still require their transaction hash. Regression entry points: `BscPaymentVerificationTest::test_expiry_checks_for_an_on_time_transfer_before_releasing_the_order`, the RPC-error/unpaid expiry cases in that suite, and `BscCheckoutRailTest::test_payment_screenshot_triggers_chain_verification_not_manual_approval`. Transaction/locking guidance was checked through Context7 against [Laravel 13 pessimistic locking](https://github.com/laravel/docs/blob/13.x/queries.md#pessimistic-locking).

`BscReadiness` checks the database activation flag, approved chain/token metadata, RPC response, active receiver count, and one bounded `eth_getLogs` query. That probe does not guarantee access to the full history window required by an active `amount_unique` Payment. Receiver management is Owner-only: the submitted address is normalized and stored as an active `manual_verified` destination after re-authentication, without a wallet-signature challenge. Multiple distinct addresses are supported and the existing least-recently-assigned pool distributes new Payments across them. The BSC RPC endpoint is the only environment authority; chain/token identity, explorer, and operational limits are pinned in application configuration. Its short-lived result is shared across customer surfaces and invalidated after committed BSC setting/receiver mutations. Reconciliation scans bounded transfer-log windows only while both Payment and Order are pending and applies the legacy hash cadence only within that active window.

`BscCapabilityProbe` is the separate rail-qualification seam (A1): it classifies chain, contract code, decimals, `finalized` block, canonical receipt, and bounded transfer-log support as `verified`, `unsupported`, or `unknown`, and `isQualified()` is true only when every required capability is verified. `bsc:check-capabilities --json` exposes the sanitized report and exits non-zero for mismatch/unknown. The probe does not write payments, settings, or ledger state; funding activation stays no-go until H1/provider gates and the downstream rail tickets are satisfied.

### 5.7 Sakurupiah QRIS

Sakurupiah is an Owner-selectable provider for new QRIS checkout
(`provider=sakurupiah`, local `method=QRIS`, `currency=IDR`). The Owner selects
`QRIS` (default), `QRISC`, `QRISMU`, or `QRIS2` through the existing payment
settings Dashboard. Checkout preparation, post-commit invoice lifecycle,
callback verification, and safe presentation live in
[`src/app/Services/Sakurupiah/`](../../src/app/Services/Sakurupiah/).
`SakurupiahInvoiceLifecycleService` owns create/recovery and classifies only
safe outcomes (`Payable` carries presentation; all other states carry no
provider payload).

Production enablement follows the complete production credential pair in the
server environment: both Sakurupiah production credentials present means the
provider is enabled; a missing pair keeps it disabled. Stable provider
endpoints, callback routes, host policy, QRIS settlement defaults, and
operational budgets remain pinned in application configuration. Readiness still
checks the runtime channel, queue, scheduler, and payment schema before
provider I/O.

`SakurupiahChannelSettings` owns the Owner-only, optimistic-versioned channel
update and atomic audit. The DB-backed selection does not change the provider.
`SakurupiahChannelPolicy` owns supported codes, per-channel expiry and legacy
interpretation. Checkout and funding confirmation snapshot `sakurupiah_channel`
before provider create; every create, recovery and settlement verifies that
transaction's channel rather than the current setting. Historical observed
Payment channels are retained; missing historical channel evidence and old
top-ups remain QRISC. Readiness caches exact-channel observations and rejects
stale checkout snapshots. Automatic channel fallback is prohibited.


The QRIS fee is borne by the buyer (`fee_payer = customer` / `merchant_fee = 2`),
so the provider adds the channel fee on top of the order amount, the customer
pays the final total, and the merchant settles the full order amount.
`SakurupiahQrisContract` is the single rule read by readiness, the production
configuration validator, and the create request; a mismatched pair fails the
create locally. The provider echoes the payer as a word and reports the base
amount in the create response but the **final total** in transaction history, so
`SakurupiahInvoiceLifecycleService::expectedProviderAmounts()` defines the
amount evidence a Payment owns (base amount and recorded total) and the payer is
snapshotted with the presentation (`customer_pays_fee`).

`SakurupiahCallbackProcessor::reconcilePayment()` is the reconciliation seam:
under the same per-Payment cache lock used by create/callback/refresh, it
performs an authoritative status lookup, validates identity and amount evidence,
and delegates paid/expired transitions to `OrderLifecycle`. A missing provider
reference delegates to guarded lifecycle recovery: only definitive rejections
may create again on the same reference; ambiguous creates never do.
Late paid observations on terminal Orders become `ManualFulfillmentReview`
without auto-fulfillment.

Create outcomes are separated by whether the provider answered with its own
failure contract. A documented rejection (application status plus reason, on
either the HTTP-200 envelope or a non-retryable HTTP failure) proves that no
transaction exists for the merchant reference, so it is persisted as
`create_failed` with a sanitized `provider_create_last_error`
(`…|application_status:<status>|provider_message:<reason>`, identifiers
redacted and bounded) and the same reference is created again once the
condition is corrected, at most once per
`services.sakurupiah.create_retry_min_interval_seconds` (default 60). An
ambiguous outcome (transport failure, retryable HTTP status, unreadable
response) stays `outcome_unknown`/`recovery_unconfirmed` and is never
re-created; it is only re-observed. `isCustomerStatusRefreshable()` is the
customer-facing gate for both, and the reconciliation sweep delivers the
`SakurupiahCheckoutInstructions` presentation-recovery effect when a sweep
turns a Payment payable.

[`ReconcileSakurupiahPayments`](../../src/app/Console/Commands/ReconcileSakurupiahPayments.php)
(`sakurupiah:reconcile --chunk=100`) scans only Sakurupiah QRIS IDR Payments
with a merchant reference. Active and released lanes have independent rotating
`(created_at,id)` cursors, eager `order` loading and at most `--chunk` rows each.
The active lane runs first with half the remaining execution window; released
work gets the remainder of the same overall budget. Rows processed in one lane
are excluded from the other in that sweep. Paid Payments are excluded;
`create_failed` retries remain eligible while pending. Ordinary terminal
lookback uses `expired_at` (legacy fallback `created_at`), so observation writes
cannot restart it. Uncertain `outcome_unknown`/`recovery_unconfirmed` Payments
retain their existing discovery path beyond that lookback. It reports aggregate
counts and redacted identifiers. See the [checkout rejection and recovery
runbook](../runbook-sakurupiah-payment.md#intermittent-checkout-rejection-and-delayed-qris).

`SakurupiahRequestEvidence` records safe per-attempt operation, caller, merchant
HMAC fingerprint, timing, retries and closed response categories from the
client. Generic application `400` is not classified as throttling. Attempt
intervals permit overlap measurement but do not measure worker queue wait;
sweep `busy` and deadline outcomes remain separate. Checkout delivery records
actual response-derived `retry_notice_sent` versus `qris_sent` metadata for
Sakurupiah as for KlikQRIS. The [issue 661 evidence and deployment
comparison](../runbook-sakurupiah-payment.md#issue-661-post-order-recovery-evidence)
separates local fixture proof from unresolved provider/production attribution.

`SakurupiahRequestBackpressure` shares a scalar merchant/environment/endpoint
cooldown through the configured cache after an explicit rate rejection, so
separate checkout, webhook and reconciliation clients stop dispatching during
the same pause. The local minimum is 60 seconds; a longer valid `Retry-After`
extends it. Generic `400` and upstream ambiguity keep their existing semantics.
An unsent create remains a pre-request failure, not an unknown remote invoice;
already-ambiguous creates are never repeated. Both sweeps stop provider work on
cooldown; Payment reconciliation retains unvisited work and reports
`provider_throttled` with carry-over and a nonzero exit. See [incident evidence,
policy and local smoke limits](../runbook-sakurupiah-payment.md#merchant-backpressure-after-an-explicit-throttle).

The scheduler registers `sakurupiah:reconcile` every minute only when
`SakurupiahProviderReadiness::schedulingEnabledFor($runtime)` is true, i.e. the
environment is enabled, operationally ready, and the verification queue is the
supervised `default` queue. `sakurupiah:check-readiness --json` and
`ops:health --sakurupiah` are the operational surfaces;
[`SakurupiahOperationalMetrics`](../../src/app/Services/Sakurupiah/SakurupiahOperationalMetrics.php)
computes the bounded local backlog (`pending`, `verification_pending`,
`terminal_recent`, oldest age) without provider HTTP. The admin Order detail
exposes the sanitized payment/event summary and a manual reconcile action
(`POST /admin/payments/{payment}/reconcile`) through
`AdminPaymentOperations` + `PaymentPolicy::reconcile` (policy mengizinkan Owner/Admin/Finance; karena hanya Owner/Admin yang bisa masuk dashboard, reconcile praktis dijalankan oleh keduanya) and
writes the `payment.manually_reconciled` audit. The operational runbook is
[`docs/runbook-sakurupiah-payment.md`](../../docs/runbook-sakurupiah-payment.md).

### 5.8 Payment QR template presentation

Every customer-facing payment QR passes through
[`QrPresentation`](../../src/app/Services/Payments/QrPresentation.php).
`image()` handles provider QR images (KlikQRIS and Sakurupiah both deliver
PNG bytes over an allowlisted URL), while `payload()` renders a locally known
EMV payload at the largest whole-number scale that fits the template.

The template is owner-managed presentation data, not environment configuration:
one row in `payment_qr_template_settings` plus the uploaded PNG/JPEG/WebP file
under `payment-qr-templates/` on the public disk (maximum 1600 x 1600 px),
uploaded from Admin Settings → Pembayaran. The file is stored verbatim, so the
configured coordinates keep pointing at the same pixels; no resize step can
silently move the reserved area. [`QrTemplateInspector`](../../src/app/Services/Payments/QrTemplateInspector.php)
rejects a reserved area that is smaller than 240 px or not at least 90% white
before it can be saved.

The DB-backed `enabled` flag defaults to **OFF**, including existing rows when
the additive enablement migration is applied. Uploading/replacing a template
does not turn it on. Owner/Admin use the explicit ON/OFF action in the same
card (`admin.payment-qr-template.status`): OFF retains the image and geometry
and bypasses composition for provider bytes/local payloads. ON requires a saved
template file. Status changes serialize under the row lock, increment `version`
only on an actual transition and append `payment.qr_template.enabled|disabled`
audit events; repeating the same requested state is idempotent.
The separate delete action explicitly removes the image/coordinates and resets
the flag to OFF. The switch affects subsequent presentations in Telegram and
the storefront, not photos already sent or cached by a client. Deploy/migrate
first, restart workers for the new code, then upload/configure and explicitly
enable from Admin; later ON/OFF changes need no worker restart or rebuild.

Updates validate the image outside the transaction, then compare the observed
setting version and path under the row lock. A concurrent change rejects the
stale request through the `qrTemplate` error bag and cleans its unused upload.
Replacement/removal cleanup and audit snapshots use the actual locked row.

At runtime [`QrTemplatePlacementStore`](../../src/app/Services/Payments/QrTemplatePlacementStore.php)
reads the current placement for every presentation, including consecutive jobs
using the singleton top-up resolver; activation, replacement and removal do not
require a worker restart.
[`QrTemplateCompositor`](../../src/app/Services/Payments/QrTemplateCompositor.php)
paints the reserved square white, decodes the source QR and reconstructs its
module matrix with the same codewords, version, ECC and mask (not a payload
re-encode). It adds a four-module quiet zone and renders at the largest integer
**module** scale that fits, then centres the QR. This avoids underfilling when a
400px provider raster fits a 640px area only once as a whole image. Any missing
file, unreadable/undecodable image, out-of-bounds area, non-square QR, image
exceeding 1600 px on either axis, or source QR larger than the reserved square
returns the original bytes; a rectangular reserved area uses a centred square.
Both provider and template dimensions are checked before GD decoding, bounding
each decoded input to 2,560,000 pixels. Invalid QR input returns immediately
without decoding the template.

Call sites: `TelegramCheckoutIntents` (KlikQRIS and Sakurupiah checkout photos),
`KlikQrisStoreBalanceTopUpLifecycle` and `SakurupiahStoreBalanceTopUpLifecycle`
(top-up attachments), and `StorefrontShopOrderQrisController`
(`GET /orders/{order}/qris`). Manual `/payment` proof photos and Pakasir
historical renderings are intentionally unchanged. Coverage lives in
`tests/Unit/Services/Payments/QrTemplateCompositorTest.php`,
`tests/Feature/AdminPaymentQrTemplateTest.php`, and
`tests/Feature/Telegram/PaymentQrTemplateDeliveryTest.php`.

Fix verification (2026-10-24): the six focused QR/admin/storage/delivery suites
passed 50 tests / 359 assertions, including deterministic request interleavings
and activation/removal through a retained top-up rail. An isolated controller
smoke rejected a stale geometry update while preserving the winning image and
version. A 4000 x 4000 provider-shaped PNG (53,794 encoded bytes) returned
unchanged before GD allocation (observed RSS delta 300 KiB).
Live `LhieStoreTestBot` messages 1365/1366 exercised provider-image presentation
and locally rendered top-up attachments: both downloaded 1000 x 1000 JPEGs
decoded to the exact original fixture payload. The smoke used an in-memory DB,
zero Orders/Payments and no real transfer; it does not qualify bank-app scanning,
provider settlement or multiprocess PostgreSQL locking.

Enablement verification (2026-10-24): the focused suites passed 54 tests /
430 assertions. Real isolated Admin browser interaction covered upload while
OFF, ON, keyboard OFF, reactivation without re-upload, and explicit deletion;
the empty state disables activation. Desktop/light and 390px mobile/dark were
inspected with no horizontal overflow, 44px buttons, visible keyboard focus and
no browser errors. The switch contrast measured 5.18:1 in light and 8.67:1 in
dark. Persistent testing ON/OFF/ON through the same presentation/top-up instances
preserved the exact image and geometry. Live `LhieStoreTestBot` messages 1369
(OFF, 285px original) and 1370 (ON, 1254px supplied artwork) downloaded and
decoded to the same fixture payload; no payment was created. Testing was left
ON after the smoke. Production was not modified.

Module-fit audit/fix verification (2026-10-24): approved read-only production
inspection of `1edc0de2` found the supplied 1254px template at (490,430), area
690x640, already OFF. Three latest KlikQRIS checkout images and two top-up
images were all 400x400 with a 371px dark grid. The old whole-raster factor was
1, yielding about 127px of dark QR in a 430px chat preview. Local normalization
of one actual sample produced a 530px grid (about 182px in that preview), with
exact-payload JPEG q75 decoding at 1254/430/320px. The six focused suites passed
55 tests / 427 assertions, covering a 400px, version-9 synthetic source filling
at least 80% of the reserved width and decoding in a 320px chat preview.
Test-bot message 1371 used synthetic data with the same source geometry, not
production payment content; its downloaded JPEG also decoded at all three
sizes. Local five-run median composition cost on the supplied artwork was
806ms versus 480ms for the old pixel-fit operation; module decoding adds CPU
work, and these are not production throughput measurements. Production data,
template status, services and deployment were not changed. Bank-app scanning
and production load qualification remain unverified.

## 6. Order lifecycle dan inventory invariant

Flow normal:

```text
checkout
  → Order pending_payment + Payment pending + reservation
  → provider/manual verification
  → OrderLifecycle.applyPaymentPaid()
  → Payment paid + inventory sale + Order processing/completed
  → receipt/admin notification/Sales Proof Post via queue
  → credential email handoff row + SendOrderCredentialMail via queue
```

Flow pelepasan:

```text
pending_payment + deadline/cancel/reject
  → Payment expired/failed
  → Order expired/cancelled
  → reservation release + credential release
```

`OrderStateMachine` mengunci Order dan seluruh Payment terkait sebelum transisi. Paid transition bersifat idempotent: payment yang sudah paid tidak boleh membuat sale, receipt, atau notification duplicate. Setelah Order expired/cancelled/completed, jangan menjalankan polling provider atau scan amount-unique di background. Late Payment hanya masuk review lewat bukti eksplisit; jangan menambahkan direct status update di callback.

Refund penuh untuk Payment external yang sudah punya refund parsial terdaftar hanya boleh lewat `OrderStateMachine::applyPaymentRefunded()` setelah [`RemainderRefundService`](../../src/app/Services/Warranty/RemainderRefundService.php) mencatat sisa tanpa klaim sebagai satu `OrderRefund` confirmed memakai bukti transfer exact dari aksi operator. Refund berstatus `requested`/`uncertain` harus di-confirm atau di-recover lebih dulu; Store Balance tetap dikredit hanya sebesar sisa debit asli.

Perbedaan subtle: paid Pakasir secara default memproyeksikan Order ke `completed`, sementara rail lain masuk `processing`; manual completion dapat menyelesaikan Order setelah admin action. Selalu gunakan `OrderLifecycleProjection` untuk kebutuhan tampilan.

## 7. Webhook, scheduler, dan queue

### Webhook

| Endpoint                             | Ingress                               | Aturan utama                                                    |
| ------------------------------------ | ------------------------------------- | --------------------------------------------------------------- |
| `POST /api/webhook/telegram`         | `TelegramWebhookIngress`              | secret header, shape validation, dedupe update, queue processor |
| `POST /api/webhook/pakasir`          | `PakasirWebhookController`            | callback untrusted, Transaction Detail verification             |
| `POST /api/webhook/klikqris/sandbox` | `KlikQrisSandboxWebhookController`    | sandbox secret/readiness dan authoritative status               |
| `POST /api/webhook/klikqris`         | `KlikQrisProductionWebhookController` | production secret/readiness dan authoritative status            |
Semua webhook provider harus aman terhadap duplicate delivery, mismatch, unknown status, dan provider outage. Provider event/payload harus melalui sanitizer sebelum disimpan atau ditampilkan.

### Scheduler

Jadwal canonical ada di [`src/routes/console.php`](../../src/routes/console.php). Yang paling penting:

- `klikqris:reconcile` tiap menit bila environment KlikQRIS aktif dan scheduling enabled; kelas `active`, `outcome_unknown`, dan `history` mempertahankan cursor serta kuota #650; command berjalan di background dengan mutex `withoutOverlapping` yang dihitung dari execution budget dan safety margin;
- `klikqris:reconcile-balance-top-ups --chunk=100 --max-top-ups=10` tiap menit pada readiness gate yang sama; command hanya memproses top-up KlikQRIS yang sudah memiliki create state dalam batas chunk/count, tidak menahan database lock saat status provider dipanggil, dan deadline sweep-nya kini memakai jalur `store-balance:expire-top-ups` yang sama;
- `store-balance:expire-top-ups --chunk=100` tiap menit untuk seluruh rail top-up (termasuk rail `manual` yang tidak punya provider untuk dipoll) tanpa provider I/O dan tanpa readiness gate provider;
- `sakurupiah:reconcile --chunk=100` tiap menit hanya bila `SakurupiahProviderReadiness::scheduleRegistrationEnabledFor()` true (environment enabled, operational ready, verification queue `default` yang disupervisi); bounded page memakai predicate kandidat bersama, narrow select + eager `Order`, cursor `(created_at,id)` yang berputar, dan execution deadline; mutex `withoutOverlapping` dihitung dari execution budget dan provider lookup budget; setelah bootstrap, `schedulingEnabledFor()` memeriksa event scheduler yang terdaftar;
- `sakurupiah:reconcile-balance-top-ups --chunk=100 --max-top-ups=10` tiap menit hanya bila gate readiness Sakurupiah yang sama (`scheduleRegistrationEnabledFor()`) terbuka; command merekonsiliasi top-up rail Sakurupiah secara bounded melalui status lookup authoritative tanpa menahan database lock di HTTP;
- `pakasir:reconcile --chunk=100` tiap menit untuk payment legacy, di background; default maksimum 10 Payment, 30 percobaan HTTP termasuk retry, dan 45 detik I/O; cursor Redis `pakasir:reconcile:cursor` menyimpan posisi ID serta high-water mark sebelum setiap attempt, melanjutkan setelah restart dan berputar setelah satu pass selesai; mutex default dua menit;
- `orders:recover-credential-mails --limit=100 --since-hours=72` tiap menit untuk email data akun: baris durable adalah otoritas pengiriman, queue hanya wakeup, dan command mematerialisasi handoff yang belum tercatat oleh lifecycle hook;
- `binance:reconcile --chunk=100` tiap menit;
- `bsc:reconcile --chunk=100` tiap menit; BSC readiness/RPC failure dan backlog masuk owner alert;
- Telegram updates/payment-proof recovery and `telegram:reconcile-stock-notifications --chunk=25` remain scheduler work; the heavier `telegram:recover-outbound` ledger/projection sweep is owned by a dedicated supervised container.

The seven provider reconciliation commands (KlikQRIS/Sakurupiah Payments and balance top-ups, Pakasir, Binance, BSC) use the two-child pool in `ops:schedule-run`, not jobs on an unserved queue. Order/top-up expiry gets priority slots, not an exclusive foreground phase: Order expiry can perform BSC/Telegram I/O. Mail/update recovery and maintenance share the pool, so one slow task does not serially hold all polling. Two occupied slots still impose queue wait. Existing provider count/page/deadline limits remain unchanged. Each provider event overwrites its own latest-sweep `storage/logs/*-reconciliation.log` rather than appending indefinitely. See [the scheduler contract and disposable-runtime measurements](../klikqris-reconcile-candidate-classes.md#scheduler-isolation-and-deadlines-654).

- The dedicated worker's bounded `telegram:recover-outbound` sweep also
  repairs sent checkout-instruction effects whose Order notification marker is
  null before the cleanup scan, copying ledger metadata and never
  redispatching the already-sent effect. Unknown and manual-review effects
  remain outside this repair.

  Ready-effect dispatch uses `TelegramOutboundRecoveryPage::loadUnion()`:
  the shared ID cursor and limit apply inside each disjoint family before the
  globally limited merge. This avoids sorting the whole ready backlog without
  changing retry/receipt policy, claims or cursor-loss recovery. See the
  [#662 measurements and production attribution limits](../runbook-telegram-outbound-effect-contract.md#ready-effect-query-pressure-662).

- The same sweep repairs sent Admin Order-notification effects whose Order
  projection is stale, restoring safe pending metadata and pending/paid/expired
  timestamps. Only the latest sent version repairs a source/type projection;
  sent effects are never redispatched.
- `usdt-balance:reconcile --limit=500 --json` dan `usdt-balance:aggregate-accounting --limit=2000 --json` tiap lima menit (mutex 10 menit), `ops:balance-alerts --json` tiap lima menit, dan `usdt-balance:metrics --prune-days=30 --json` harian (#558). Rekonsiliasi terjadwal adalah jendela bounded per facet; sweep penuh lintas cursor tetap aksi operator (runbook). Alerts dan metrik tidak menyentuh uang dan tetap berjalan ketika funding/spending/delivery dijeda.
- cleanup login links tiap jam dan retained data tiap hari;
- daily admin stats job di queue `notifications`.

`orders:expire-pending` and `store-balance:expire-top-ups` are registered without
`runInBackground()` to receive priority slots from the bounded runner. All command
children, including those two, share its concurrency cap and keep their existing
overlap mutexes. Sakurupiah webhook retries
use six attempts with 5/10/15/30/60-second delays; status/history lookup failure
logs carry safe operation/error metadata without provider bodies or exception
messages. See [payment delay diagnosis](../runbook-sakurupiah-payment.md#paid-callback-received-but-order-still-pending).

The Docker `scheduler` service runs `php artisan ops:schedule-run` with a
384 MiB memory limit. `RunBoundedSchedule` uses a storage-backed cycle lock,
collects priority and ordinary command events, then drains both with at most
two child processes. Inline callbacks retain native behavior. The parent calls
Laravel event completion callbacks and
releases overlap mutexes itself; it does not spawn `schedule:finish` children.
The command handles Symfony termination signals without Symfony's immediate exit,
stops all active children before releasing their overlap mutexes and cycle lock.
Controlled shutdown skips completion callbacks and can recover promptly; abrupt
death still leaves mutexes to expire.
Child failures make the cycle fail without abandoning the other due tasks.
Heartbeat measures completed loop progress, not business success. The supervisor
passes `--cycle-id`; after fully draining normal zero/one-exit commands, the runner
writes the matching `scheduler-cycle-completed` token. With no new cgroup OOM-kill,
that evidence permits liveness to advance even when a task returns one. The cycle
still exits non-zero and its critical runtime episode stays open; only a fully
successful, OOM-free cycle resolves it. Callback failures, abnormal child exits
and interruption cannot publish completion. The OOM check precedes heartbeat and
recovery. Logs include child exit codes and `scheduler cycle duration_seconds`.
Provider success still requires its own completion evidence: exit zero can mean
a bounded pending sweep. Abrupt death
leaves overlap mutexes to expire; never clear a live worker's mutex.
Redis loss can still lose cache locks and cursors; financial fencing stays in DB
lifecycle services. The separate `outbound-recovery` service also has a 384 MiB limit
and runs one child per minute: per-facet pages are capped at 25, PostgreSQL
statements at 10 seconds, the recovery command at 40 seconds, and the supervisor
signals the child at 50 seconds before escalating to SIGKILL within 2 seconds.
Each recovery stage is isolated so one failed stage does not abort the rest of
the sweep. It logs process age/RSS and updates its own heartbeat only after
success; two consecutive failed sweeps open the runtime episode, and one
successful sweep resolves it. Both liveness checks expire after 180 seconds. Do
not add outbound recovery back to scheduler `runInBackground`.

`SendDailyAdminStatsNotification` mengirim Rekap Kemarin melalui `TelegramAdminStatsIntents`. Judul diberikan sebagai teks Unicode mentah ke formatter, yang merender icon tepat sekali. Jangan memanggil `renderLegacyIcons()` lagi pada judul yang sudah dirender: fallback glyph dapat cocok dengan source icon yang sama dan menghasilkan wrapper `<tg-emoji>` bersarang. `TelegramBotClient` mengubah wrapper internal yang valid menjadi entity `custom_emoji` untuk pesan plain text, atau Unicode fallback jika Telegram menolak custom emoji; marker `data-omp-icon` tidak boleh tampil kepada admin. Perhitungan statistik dan tombol Mini App Dashboard tetap terpisah dari rendering icon.

Statistik interaktif admin memakai `TelegramStoreStatsReport::growth()` untuk Hari Ini, Minggu Ini, Bulan Ini, dan Bulan Kemarin. Tiga periode aktif memakai pembanding durasi setara; Bulan Kemarin membandingkan dua bulan kalender penuh dengan akhir eksklusif. `TelegramAdminStatsIntents` mengirim satu pesan HTML dengan heading tebal, keterangan pembanding, dan blockquote per periode melalui `TelegramHtmlCaption`; nilai dinamis di-escape dan icon hanya dirender sekali. Receipt tetap terpisah per mata uang. Rework ini tidak mengubah recap otomatis kemarin, statistik publik, atau metrik Mini App Dashboard.

### Queue ownership

- `default` — Telegram update processing and bounded Sakurupiah webhook verification.
- `telegram-outbound` — checkout instructions, Store Balance top-up QR instructions, dan pending-order admin notification.
- `notifications` — receipt, paid/expired notifications, Sales Proof Post, credential email delivery (data akun pesanan), Store Balance top-up payment proof, cleanup instruction, KlikQRIS owner alert, and legacy Pakasir cancellation attempts.
- `telegram-broadcast` — admin broadcast dan price notification batches.
- `telegram-stock` — stock notification recipient batches; each job sends one effect and requeues the movement until complete.

Jobs yang mengirim efek eksternal memakai unique/claim markers agar retry tidak mengirim efek ganda. Queue job bukan tempat menaruh business transition baru; job memanggil service owner lalu dapat retry bila dependency eksternal gagal.

## 8. Concurrency, idempotency, dan security

Lock dan dedupe yang harus dipertahankan:

- `OrderCheckoutService`: database transaction + cart/item/setting row lock + `checkout_key` dedupe.
- Storefront submit: cache lock `storefront:checkout:<checkout-key>`.
- Provider switch: setting row lock + `payment_provider_version` optimistic conflict.
- Order lifecycle: lock Order dan Payment rows.
- KlikQRIS: cache lock `klikqris:callback-status:<payment-id>` untuk create, recovery, callback, reconciliation, dan alert; top-up memakai cache lock `klikqris:balance-top-up:<top-up-id>` untuk create/recovery/reconciliation.
- Telegram webhook: unique `update_id`, processing claim, retry/quarantine.
- Provider callbacks: `PaymentEvent`/`StoreBalanceTopUpEvent` idempotency/coalescing dan `processed_at`.
- Store Balance top-up settlement: Customer → `CustomerBalanceAccount` → `StoreBalanceTopUp` → `StoreBalanceTopUpEvent`; ledger credit uses `balance_top_up:<top-up-id>:credit`, while top-up merchant reference, funding-intent identity, event idempotency, and outbound effect key remain stable across retries.
- Structured stock intake: Product row lock + unique `(identity_kind, fingerprint)` + unique operation key; edit/delete use the same Product-before-Stock-Item lock order as checkout allocation.

Jangan mengurangi lock menjadi `exists()` check biasa pada jalur yang membuat payment, menjual/mencadangkan stok, atau mengirim paid effect. Jangan menggunakan `provider_create_state` di luar KlikQRIS service untuk menentukan UI.

Data boundary:

- Raw provider payload hanya masuk melalui sanitizer dan hanya boleh diakses role yang sesuai.
- QR media KlikQRIS harus trusted HTTPS host/path untuk environment aktif.
- Recovery Key plaintext hanya muncul saat issue awal; database hanya hash.
- Optional Support Contact bukan identitas customer dan disimpan terenkripsi.
- Log/error/report harus redacted; gunakan helper sanitizer/redactor yang sudah ada.

## 9. Peta file penting

| Area            | Entry point / folder                                                                                                                                                                                                                       |
| --------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| bootstrap       | [`src/bootstrap/app.php`](../../src/bootstrap/app.php), [`src/app/Providers/AppServiceProvider.php`](../../src/app/Providers/AppServiceProvider.php)                                                                                       |
| routes          | [`src/routes/web.php`](../../src/routes/web.php), [`src/routes/api.php`](../../src/routes/api.php), [`src/routes/console.php`](../../src/routes/console.php)                                                                               |
| domain model    | [`src/app/Models/`](../../src/app/Models/), [`src/app/Enums/`](../../src/app/Enums/)                                                                                                                                                       |
| checkout        | [`OrderCheckoutService`](../../src/app/Services/OrderCheckoutService.php), [`Services/Checkout/`](../../src/app/Services/Checkout/)                                                                                                        |
| checkout payment preparation | [`CheckoutPaymentPreparation`](../../src/app/Services/Checkout/CheckoutPaymentPreparation.php), [`CheckoutPaymentPreparationResolver`](../../src/app/Services/Checkout/CheckoutPaymentPreparationResolver.php), [`CheckoutPaymentPlan`](../../src/app/Services/Checkout/CheckoutPaymentPlan.php) |
| order/inventory | [`OrderStateMachine`](../../src/app/Services/OrderStateMachine.php), [`InventoryLedger`](../../src/app/Services/InventoryLedger.php), [`Services/Product*`](../../src/app/Services/)                                                       |
| Telegram        | [`TelegramWebhookIngress`](../../src/app/Services/Telegram/TelegramWebhookIngress.php), [`TelegramUpdateProcessor`](../../src/app/Services/Telegram/TelegramUpdateProcessor.php), [`Services/Telegram/`](../../src/app/Services/Telegram/) |
| Storefront      | [`src/app/Storefront/`](../../src/app/Storefront/), [`StorefrontShopCheckout`](../../src/app/Storefront/StorefrontShopCheckout.php)                                                                                                        |
| customer history | [`CustomerOwnedOrderQuery`](../../src/app/Services/Customer/CustomerOwnedOrderQuery.php), [`CustomerAccountController`](../../src/app/Http/Controllers/CustomerAccountController.php)                                                        |
| admin           | [`src/app/Http/Controllers/Admin/`](../../src/app/Http/Controllers/Admin/), [`AdminDashboardData`](../../src/app/Services/Admin/AdminDashboardData.php), [`AdminDashboardOverviewData`](../../src/app/Services/Admin/AdminDashboardOverviewData.php) |
| KlikQRIS        | [`KlikQrisClient`](../../src/app/Services/KlikQris/KlikQrisClient.php), [`src/app/Services/KlikQris/`](../../src/app/Services/KlikQris/)                                                                                                  |
| Pakasir         | [`src/app/Services/Pakasir/`](../../src/app/Services/Pakasir/)                                                                                                                                                                             |
| Binance         | [`src/app/Services/Binance/`](../../src/app/Services/Binance/)                                                                                                                                                                             |
| money receipts  | [`ExternalMoneyReceiptClaims`](../../src/app/Services/MoneyReceipts/ExternalMoneyReceiptClaims.php), [`Services/MoneyReceipts/`](../../src/app/Services/MoneyReceipts/)                                                                     |
| background work | [`src/app/Jobs/`](../../src/app/Jobs/), [`src/app/Console/Commands/`](../../src/app/Console/Commands/)                                                                                                                                     |
| config          | [`src/config/services.php`](../../src/config/services.php), [`src/config/storefront.php`](../../src/config/storefront.php), [`src/.env.example`](../../src/.env.example)                                                                   |
| schema          | [`src/database/migrations/`](../../src/database/migrations/)                                                                                                                                                                               |
Contract schema changes such as Pakasir column removal live under
`src/database/contract-migrations/` and are run only after the retirement
report is `ready_for_contract`; normal expand migration startup does not load
that directory.

## 10. Test map dan command

Gunakan test boundary yang sesuai:

- `tests/Feature/Telegram/` — command, callback, checkout, payment instructions, privacy, onboarding, dan webhook.
- `tests/Unit/Catalog/LocalizedProductCopyTest.php` — locale-to-column mapping, base fallback, empty-variant fallback, and column extraction for Product Details / Replacement & Refund Policy variants. `tests/Feature/Telegram/TelegramContentEditingTest.php` covers the `/admin` language picker, per-variant premium emoji metadata, clearing a variant, and the per-locale unit budget; `tests/Feature/AdminDashboardProductFormsTest.php` covers the dashboard language tabs, variant persistence, and the same budget; `tests/Feature/StorefrontProductPresentationTest.php`, `tests/Feature/StorefrontCatalogProductPresentationTest.php`, `tests/Feature/StorefrontProductFamilyDiscoveryTest.php`, dan `tests/Feature/Telegram/CatalogSearchTest.php` cover storefront/Bot resolution per locale with base fallback.
- `tests/Feature/Storefront/` dan `Storefront*Test.php` — catalog, guest checkout, signed invoice, recovery key, payment refresh.
- `tests/Feature/*Payment*Test.php`, `KlikQris*Test.php`, `Pakasir*Test.php`, `Binance*Test.php` — provider contract/lifecycle/reconciliation.
- `tests/Feature/Bsc*Test.php`, `tests/Unit/Services/Bsc/` — BSC readiness/verification plus capability and exact-amount contract fixtures (`verified`/`unsupported`/`unknown`, fail closed).
- `tests/Feature/OrderCartDomainTest.php` — cart/order/inventory transition.
- `tests/Feature/Admin*Test.php` — role, dashboard, provider switch, audit, manual completion.
- `tests/Feature/AdminBalanceAdjustmentDashboardTest.php` — exact Owner-only Telegram-ID lookup, preview without ledger effects, request-bound password, replay, full linked reversal from history, positive IDR tracking and paginated adjustment history; `tests/Browser/admin-dashboard-responsive.spec.js` covers the narrow-screen confirmation form. `tests/Feature/AdminBalanceAdjustmentTest.php` and `AdminBalanceAdjustmentPostgresConcurrencyTest.php` cover the reversal service, rollback/replay, recovery-source preservation, projection drift and reversal-vs-spend/reversal/late-settlement races.
- `tests/Unit/Services/UsdtBalance/UsdtMicroAmountTest.php` dan `UsdtBalanceSourceTest.php` — exact positive micro-USDT parsing, raw uint256→micro conversion, overflow/remainder rejection, installed brick/math 0.18 signatures, and typed economic-source identity/fingerprint closure.
- `tests/Feature/CustomerUsdtBalanceLedgerTest.php` — atomic entry/projection/accounting append, replay/conflict on key/source/fingerprint, one account per Customer, refund linkage/cap, full linked single reversal with mirrored legs, immutability, projection/cursor drift freeze, frozen accounts, overflow rollback, and IDR separation.
- `tests/Feature/CustomerUsdtBalancePostgresConcurrencyTest.php` — process races for parallel credits, last-funds debits, empty-account creation, refund cap, reversal-vs-spend, named constraints, runtime trigger/role denial of entry/accounting UPDATE/DELETE, and reader divergence; runs only through the PostgreSQL gate.
- `tests/Feature/UsdtBalanceReconciliationTest.php` — bounded cursor/limit/scoped scans, exact sums above the signed-integer range, projection/cursor/history drift with scoped idempotent freeze and stale-finding protection, orphan/held/wrong-currency/unrepresentable receipts, unmatched and overcredited differences, missing/unavailable/throwing custody adapters, deficit/surplus coverage, accounting aggregate idempotency/divergence, restore replay, movement replay/conflict, sanitized JSON, and command validation.
- `tests/Feature/UsdtReconciliationPostgresConcurrencyTest.php` — process evidence on disposable PostgreSQL: reconciliation during live postings never reports false drift inside one repeatable-read snapshot, concurrent accounting catch-up claims every event exactly once, and concurrent duplicate movement recording commits one row; runs only through the PostgreSQL gate.
- `tests/Feature/BalanceReceiptDeliveryPauseTest.php` — delivery pause defers balance receipt families while unrelated families keep delivering, deferred effects stay pending with zero attempts until resume, and funding/delivery pauses never block reconciliation or ledger credits.
- `tests/Feature/BalanceOperationsMetricsTest.php` — fixed report shape with zero defaults, posted/replayed counters, prune of aged events only, `--json` allowlisted keys, and bounded-cardinality assertions over the stored metric/outcome/bucket values.
- `tests/Feature/BalanceOperationsAlertTest.php` dan `tests/Feature/AdminBalanceOperationsPanelTest.php` — episode open/update/resolve with exactly one sanitized durable effect per fingerprint, missing admin target handling, and the read-only Stats-tab Kontrol Operasional card.
- `tests/Feature/BalanceRestoreGateTest.php` — restore gate blocks new funding/spending while reconciliation continues, close refusals without replay or with remaining drift, ambiguous-delivery quarantine without touching ledger/receipt history, and replay dry-run semantics.
- `tests/Feature/BalanceReleaseReadinessReportTest.php` — release readiness is read-only, rejects missing/stale/partial reconciliation evidence, reports receipt-cutover/restore/BSC qualification blockers, and never treats optional Binance funding as a primary-BSC blocker. The command and canary/rollback evidence contract are in [`docs/balance-o3-release-readiness.md`](../balance-o3-release-readiness.md).
- `tests/Feature/UsdtBalanceUnfreezeTest.php` — drift freeze cleared only after a clean re-proof, remaining drift and reorg incidents keep the freeze, dry-run and command exit codes, audit trail.
- `tests/Feature/BalanceOperationsPostgresConcurrencyTest.php` — process races on disposable PostgreSQL for the operational controls: restore gate vs concurrent reservations, delivery pause vs concurrent claims, concurrent alert evaluation producing exactly one episode + effect, unfreeze race, and metric UPDATE denial with retention DELETE allowed.
- `tests/Feature/CredentialIdentityFoundationTest.php` — additive encrypted persistence, normalization, registry backfill, and safe command output.
- `tests/Feature/ProductStockIntakeTest.php` — atomic Input Cepat/Input Akun, idempotency, duplicate identity, authorization, audit/movement/notification behavior.
- `tests/Feature/ProductStockIntakePostgresConcurrencyTest.php` — process races for same key, duplicate identity, intake/checkout, edit/checkout, and delete/checkout; runs only through the PostgreSQL gate.
- `tests/Feature/StructuredStockSecurityAndRolloutTest.php` — persisted readiness safety gate, fingerprint readiness, URL safety, malformed-row repair, and canary persistence scan.
- `tests/Feature/CredentialDeliveryPresentationTest.php` and `tests/Feature/Telegram/StructuredCredentialDeliveryTest.php` — shared paid projection, no serialization, ordered OTP/Auth Token delivery, and Manual Delivery Review ambiguity.
- `tests/Feature/Telegram/TelegramOutboundEffect*Test.php`, `AdminTelegram*OutboundEffectTest.php`, dan `AdminTelegramBroadcastQueueTest.php` — ledger protocol, Manual Delivery Review, Admin Broadcast recovery, contract, deletion-boundary, dan family behavior.
- `tests/Feature/Telegram/TelegramReceiptLatencyTimingTest.php` — initial/retry/recovery enqueue stage and timestamp propagation, missing-timestamp unknown semantics, first credential acknowledgement and receipt completion events without credential/chat values. `tests/Feature/TelegramLatencyBaselineCommandTest.php` covers disposable/postgres/option guards and `tests/Unit/TelegramLatencyBaselineReportTest.php` covers nearest-rank percentiles, unknown counts and the no-SLO report text.
- `tests/Feature/KlikQrisReportingTest.php` — provider reporting contract, pagination, retries, schema validation, redaction, and cache identity.
- `tests/Feature/AdminDashboardReportingTest.php` — active-tab isolation, role policy, local matching, filters, safe errors, pagination, and proof URL safety.
- `tests/Feature/CustomerAccountTest.php` — customer ownership, all Order filters/statuses, pagination, non-paid privacy, and paid credential continuity.
- `tests/Feature/StoreBalanceLedgerTest.php` — immutable ledger append, atomic projection update, idempotency and source replay, insufficient funds, immutability guards, and fail-safe snapshots.
- `tests/Feature/StoreBalanceFundingPolicyTest.php` — versioned Admin policy updates, strict bounded validation, role authorization, no-op/audit atomicity, and stale-version recovery.
- `tests/Feature/StoreBalanceReconciliationTest.php` — projection/ledger mismatch detection via `store-balance:check` without changing money, customer scoping, limit, and sanitized JSON output.
- `tests/Feature/StoreBalancePostgresConcurrencyTest.php` — process races for one idempotency claim, competing debits, parallel credits, duplicate source identity, competing Store Balance funding-intent submissions/claims, and PostgreSQL non-negative/positive/unique constraints plus one-stale-version funding-policy update; runs only through the PostgreSQL gate.
- `tests/Feature/ExternalMoneyReceiptClaimTest.php` — additive external receipt claim outcomes (claimed/replayed/conflict/review), alias and BSC parent reconciliation, rollback on alias collision, caller-transaction participation, model immutability, cross-target/adjustment coverage, and legacy-compatible BSC identity mapping.
- `tests/Feature/ExternalMoneyReceiptPostgresConcurrencyTest.php` — process races for one receipt claim across targets and replays, deterministic alias savepoint recovery, cross-target BSC parent review, and named PostgreSQL unique/check constraints; runs only through the PostgreSQL gate.
- `tests/Feature/ExternalMoneyReceiptWriterPostgresConcurrencyTest.php` — process races for checkout vs top-up writers contending for the same receipt (one funds, one conflicts and reserves) and concurrent writer replays without reservations; runs only through the PostgreSQL gate.
- `tests/Feature/ExternalMoneyReceiptBackfillTest.php` — bounded dry-run without writes, idempotent replay, interrupted/resumed cursor, settled Binance/BSC/QRIS/manual/top-up receipt targets, missing-identity and registry-conflict reservations, compensated funding sources, cutover report gate, and operator reservation resolution.
- `tests/Feature/ExternalMoneyReceiptWriterCutoverTest.php` — writer-level cross-target conflicts with blocking reservations, provider/namespace scoping for top-ups, owner-attestation namespace and hashed legacy references, compensation reservation idempotence/resolution, and runtime/backfill identity equality.
- `tests/Unit/Services/MoneyReceipts/MoneyReceiptIdentityTest.php` — canonical identity normalization (stable Binance receiver namespace across credential rotation, legacy BSC transaction hash), BSC parent-claim requirement, and money fingerprint binding.
- `tests/Feature/Telegram/StoreBalanceTopUpTest.php` — confirmation-to-provider quote, safe evidence redaction, durable QR instruction separation, exact requested credit settlement, and replayed confirmation; the shared top-up tests now pin the KlikQRIS rail via `KlikQrisSandboxScenarios::selectKlikQrisProvider()`.
- `tests/Feature/Telegram/StoreBalanceTopUpManualRailTest.php` — manual rail creation in `awaiting_confirmation`, `/payment` proof forwarding to the admin with approve/reject callbacks, operator confirmation/rejection audit and idempotency, and exact-credit settlement.
- `tests/Feature/Telegram/StoreBalanceTopUpSakurupiahRailTest.php` — Sakurupiah rail create/refresh, encrypted QR payload presentation, scheduled reconciliation settlement, and exact-credit credit.
- `tests/Feature/Telegram/StoreBalanceTopUpProviderUnavailableTest.php` — fail-closed `provider_unavailable` for a rail that is not operationally ready, with no QR claimed or sent and no provider request.
- `tests/Feature/KlikQrisNativeQrisPostgresConcurrencyTest.php` — Payment and Store Balance top-up provider single-flight races, same-target recovery, and one instruction-effect materialization; runs only through the PostgreSQL gate.
- `tests/Feature/Telegram/StoreBalanceHomeTest.php` — private home greeting → Profile → Store Activity → prompt ordering, fresh Store Balance independent of the global metrics cache, fail-safe unavailable projection, localized missing value, and public-safe group home.
- `tests/Feature/Telegram/ProductFamilyDiscoveryTest.php` dan `tests/Feature/Telegram/CatalogSearchTest.php` — family discovery entry rendering (label `▸ N Plan »`, effective Product custom emoji icons on Plan buttons, label stock, and `product:<id>` plus its list origin token for single-Plan families and standalone entries), inherited/glyph-only/premium overrides across Plan text/buttons and public `/stock`, signed family option callbacks, origin/back restoration, dan copy per locale.
- `tests/Feature/Telegram/CatalogIconResolverTest.php` — rantai resolusi ikon katalog per Product: glyph Product, custom emoji yang di-capture, glyph default, nilai kosong, dan capability yang sedang tidak tersedia.
- `tests/Feature/Telegram/ProductIconCaptureTest.php` — alur admin ikon produk: entri menu hanya untuk admin, daftar produk dari database (termasuk katalog kosong), prompt per produk, capture `custom_emoji_id` dari entity custom emoji, penolakan ID tak dikenal, penghapusan emoji, dan penolakan non-admin.
- `tests/Feature/Telegram/MenuIconCaptureTest.php` — admin menu-icon and message-copy icon lists, premium custom-emoji and plain-Unicode capture, translation rendering before dynamic interpolation, navigation application, clearing, and non-admin rejection.
- `tests/Feature/CatalogIconCheckCommandTest.php` — `catalog:icons-check` verifies Product, menu, and message-copy identifiers against Telegram and can clear a remembered rejection.
- `tests/Feature/Telegram/TelegramRoutineNavigationTest.php` — webhook-level single-message navigation evidence: Home → Balance → Home → Language → Home → Orders → Support on one canonical message, preset/custom Store Balance confirmation intents, strict amount recovery, confirm/cancel/back/expiry/stale-policy replay behavior, zero pre-confirm provider/Payment/ledger effects, stale/ambiguous/missing-root recovery, catalog navigation on the root, long-screen text-root replacement, group privacy, and id/en/ms screen copy.
- `tests/Feature/Telegram/TelegramNavigationRootTest.php` dan `TelegramNavigationRootPostgresConcurrencyTest.php` — root establishment/replacement/lease semantics dan race PostgreSQL-nya.
- `tests/Feature/Telegram/StartMetricsTest.php` — canonical successful Order and Telegram Customer metrics with bounded staleness cache.
- `tests/Unit/Suppliers/` and `tests/Feature/SupplierClientTest.php` — exact money/rounding/overflow, five provider contracts, request budgets, bounded parsing and failure evidence.
- `tests/Feature/SupplierAdminTest.php` — role/CSRF, encrypted keys, no session/audit echo, optimistic mapping/pricing, account-change and recovery guards.
- `tests/Feature/SupplierAvailabilityTest.php` — stock/balance/profit/freshness projections, query bounds, no false restock, sync leases and scheduler fairness.
- `tests/Feature/SupplierRateAuditCommandTest.php` — read-only rate report, explicit `--fix` correction, audit row, availability refresh, idempotency, refusal of an out-of-band replacement and constant query cost per mapped product.
- `tests/Feature/SupplierFulfillmentTest.php` and `SupplierPostgresConcurrencyTest.php` — real checkout/payment/credential/refund boundaries, partial/missing economic evidence, fallback, durable recovery, concurrent wallet claims and global lock order.
- `tests/Feature/HistoricalOrderRefundTest.php`, `HistoricalOrderRefundPostgresConcurrencyTest.php` and `ManualFulfillmentReviewTest.php`: historical partial/missing refund entitlements, approval guards, pending-refund guards, operator-confirmed remainder registration before a full external refund, and review resolution boundaries.
- `tests/Support/NonTransactionalDatabaseTestCase.php` — isolated real transaction boundaries without rolling back irreversible migrations; in-memory SQLite or guarded disposable PostgreSQL only.
- `tests/Unit/Services/` — pure parser/policy/outcome/verification behavior.
- `tests/JavaScript/*.test.js` — Node unit lane (`npm run test:js`, enforced in CI) for the consumer-visible Alpine module contracts: Family quick-buy selection/quantity/checkout href, checkout review quotes/sticky action/form validity, mobile navigation focus/scroll lock/Escape, and Admin Account Input revision safety. Tests import the module factories and assert behavior rather than pinning entrypoint implementation text.
- `tests/Browser/admin-dashboard-responsive.spec.js` — customer checkout/page-flow evidence, admin behavior, reporting navigation, and responsive checks. The `customer checkout:` cases are the focused Storefront lane; they assert geometry and interaction behavior rather than screenshot-only output. Entry lanes are `npm run test:browser:admin` (full Admin/responsive slice) and `npm run test:browser:customer` (Storefront slice), both rebuilding assets first.
- After changing a checkout payment method, the sticky geometry browser check polls until bottom padding covers the measured bar height before checking viewport alignment and final-content clearance. This waits for the `ResizeObserver` layout update without weakening the overlap bounds or adding a fixed sleep.
- `tests/Browser/` — real browser responsive/admin/Docker smoke.
- `src/resources/js/storefront.js` — retained imperative services only: analytics event transport (`trackStorefrontEvent`), scroll progress/reveal/header-elevation observers, media frames, FAQ accordion, verified-purchase activity polling, native QRIS image fallback, payment countdown/status polling and actions, catalog filter focus, invoice follow-up details, and support funnel listeners. Family quick-buy, checkout review, and mobile navigation state are owned solely by the Alpine modules in `src/resources/js/storefront/` (`familyQuickBuy`, `checkoutReview`, `mobileMenu`).

Perintah umum:

```bash
cd src
composer test
composer test:foundation
composer test:concurrency-gate
npm run test:js
npm run build
npm run test:browser:admin
npm run test:browser:customer
```

`composer test:concurrency-gate` memerlukan PostgreSQL disposable dan dipakai ketika perubahan menyentuh lock/stock/checkout/payment. `npm run test:js` menjalankan Node unit test untuk modul Alpine tanpa browser. `npm run build` diperlukan bila perubahan menyentuh Blade asset, Vite entry, CSS, atau JavaScript; kedua lane Playwright membangun asset terlebih dahulu.

Structured stock availability uses [`runbook-structured-account-stock-intake-rollout.md`](../../docs/runbook-structured-account-stock-intake-rollout.md). Never expose Input Akun before the bounded backfill, persisted readiness check, conflict resolution, and non-production end-to-end proof are recorded.

### Admin dashboard shell primitives

The reusable presentation seam is anonymous Blade components under
`src/resources/views/components/admin-dashboard/`: `shell`, `navigation`,
`navigation-item`, `topbar`, and `mobile-drawer`. They accept server-built,
permission-filtered navigation groups and render escaped links with an
allowlisted SVG path map. Navigation renderers enhance real server-built anchors
through `handleSidebarNavigation()` to retain client fragments without replacing
explicit destination hashes. `adminDashboard.goToTab()` retains full-page
navigation and product-create intent; authorization and data loading remain
server-owned. `mobile-drawer` renders the dialog/backdrop and delegates
focus, close and navigation events to the composed Alpine lifecycle.

Admin primitive defaults use neutral surfaces, muted violet accent tokens,
one-pixel borders, restrained shadows, readable regular-case text, and
44px minimum controls. The tokens are scoped through `admin-*` CSS variables
and Tailwind theme aliases; Storefront presentation remains outside that
scope. Component contracts are covered by
`AdminDashboardBladeComponentsTest`, while dashboard behavior remains covered
by the existing `AdminDashboard*` feature tests.

The violet accent is split by role so both themes keep AA text contrast:
`--admin-accent` fills controls, `--admin-accent-ink` is the foreground on
those fills, `--admin-accent-strong` is accent-colored text/icons on neutral
and `--admin-accent-soft` backgrounds, and `--admin-accent-hover` is the fill
hover. `x-admin-dashboard.button` therefore declares `hover:*` per variant
instead of one shared hover fill, and `--admin-focus` stays the ring/outline
color. Rendered ratios and consumer coverage are recorded in `DESIGN.md` and
`docs/agents/admin-dashboard-sidebar-cutover.md`.

`x-admin-dashboard.file-upload` is the shared image-upload control for the
catalog default, product, product family and store-channel surfaces. It keeps
the native `<input type="file">` (Tab/Space/Enter activation, unchanged
`name`/`accept`/`required`) visually replaced by a ≥44px label that owns the
hover and focus ring, with the filename feedback slot beside it. Its contract
is covered in the browser lane, not by markup assertions. Category management
has no upload control, and the raw storefront-banner inputs use the same
minimum target plus the admin focus outline.

`x-admin-dashboard.modal-shell` owns the dialog width through one `size` prop
(`sm|md|lg|xl|2xl|3xl`, default `lg`); consumers no longer pass their own
`max-w-*`. The scroll box comes from the shared CSS hooks instead of per-modal
math: `.admin-modal-backdrop` is `--tg-viewport-height`/`100dvh` tall with
safe-area padding and `.admin-modal-shell` is `max-height: 100%` with
`overflow-y: auto` and `overscroll-behavior: contain`, so the height never
outgrows a phone viewport or the Telegram Mini App window. The raw settings
confirmation dialogs (`admin-payment-provider-switch-dialog`,
`admin-checkout-maintenance-dialog`) and the Owner release modal reuse those two
hooks rather than re-declaring `vh` limits. A `min-width: 0` rule on the shell's
structural, control and text children stops a grid or flex box from refusing to
shrink below an input's or button label's intrinsic width, which is what pushed
dialogs sideways at phone widths with enlarged text; modal action rows sit in
`grid-cols-1 … sm:grid-cols-2` so their buttons stack instead of squeezing
below 640px. Produk's product
modal is `2xl` and splits its body into `data-product-modal-grid`
(`grid lg:grid-cols-2`) with a sticky action footer, so the product form fits a
desktop viewport instead of one long scroll; the product-family, stock intake
and category modals were widened the same way and the family form lays its
fields out in a two-column grid with the image cards side by side.

`AdminProductCreateController` accepts an optional `product_family_id` and
assigns the new Product through `ProductFamilyManagementService::updateMembership()`
inside the same transaction, so the family-group shortcut
(`addProductToFamily(<id>)` → `openProductForm(familyId)`) writes grouping at
create time and no longer focuses an unrelated Product's assignment select. A
`ProductFamilyCategoryMismatch` rolls the create back and returns the
`productCreate` bag error on `product_family_id` (uploads discarded);
`product_family_id` use is create-only because membership edits keep their own
Product-card form.

Operational surfaces (#603) now render entirely through those primitives:
Produk (catalog tab, product card, product/family/category modals, stock intake
and management modals, storefront banners), Pesanan (`admin.dashboard` sales
tab, `admin.orders.show`, Manual Fulfillment Review index/detail, Manual
Delivery Review index/detail), and Supplier (connections, private catalog,
connection form, product pricing policy, purchases/recovery,
`admin/suppliers/layout.blade.php`). The custom
`supplier-*` CSS family and its `[data-theme="dark"] .supplier-alert` override
were deleted; the supplier workspace is now identified by
`data-supplier-workspace` (used by the browser lane) instead of
`.supplier-workspace`. Per-file detail, the light/dark contrast measurements,
and the per-item antislop gate live in
`docs/agents/admin-dashboard-sidebar-cutover.md`. No route, form field, error
bag, `old()`, permission gate, Alpine state key, or domain rule changed.

Summary, communication, reporting and finance surfaces render through the
primitives. Ringkasan uses `stats-tab` for daily sales, the 7/30-day chart,
actionable attention, and recent orders.

### Ringkasan reporting periods and Pesanan invoice search

`AdminDashboardOverviewData` owns every Ringkasan aggregate. `period` accepts
`harian` (7/30-day window), `mingguan` (8 Monday–Sunday buckets), `bulanan` (12
month buckets) and `bulan` (one selected month, day buckets); `month` accepts a
bounded `YYYY-MM` inside `months.bounds` so the picker can only request the
recent past. The response carries `period` (points, total, average, peak, axis,
`has_sales`, `partial_label`) plus `months` (current, previous, optional
selected tile with a comparison delta) and `period_options`. Weekly and monthly
buckets come from `PaymentReceiptReport::monthlySalesReceipts()`, which shares
`successfulSalesReceiptBuckets()` with `dailySalesReceipts()`; each Ringkasan
request issues at most four aggregated receipts queries. `salesChart` (Alpine,
`resources/js/admin-dashboard.js`) owns chart hover/focus tooltips and keyboard
focus on `[data-chart-bar]`; `tests/JavaScript/admin-sales-chart.test.js` covers
the tooltip clamp, and the browser lane covers period switching, hover/keyboard
tooltip parity and month selection.

Pesanan accepts `invoice` (`AdminDashboardSalesData::forSales()` normalizes it to
upper-case `[A-Z0-9-]`, ignores fewer than 3 characters and never passes LIKE
wildcards). Each sales row now carries `credential_delivery`, derived from
persisted facts only: the latest `receipt.credentials` effect state/`sent_at`,
`orders.telegram_receipt_sent_at`, the `receipt.txt_download` interaction trace
and the `storefront.credentials_opened` audit aggregate (one bounded query per
page). A streamed order whose receipt was never materialized reports "Belum
dikirim" instead of failing; unknown/manual-review states link to Manual
Delivery Review. Pengaturan uses `settings-tab` for
payment/store controls, IDR/USDT policy, and balance diagnostics in separate
sections. Siaran, Store Channel, Funnel, Kompensasi top-up, and linked pages
`admin/balance-reports/index`, `admin/store-balance/adjustments`, and
`admin/store-balance/usdt-review` retain their existing operations. The retired
KlikQRIS reporting tabs no longer render. The password-confirmation page keeps its
form/POST/intended-redirect contract and now uses the same primitives instead
of raw neo-brutal classes. `x-admin-dashboard.badge` aliases the server
`success`/`warning`/`danger`/`info` tones onto its emerald/amber/rose/blue
palettes so state badges keep their semantic colors. The `admin-dashboard-input`
and `earnings-bar-*` CSS classes were deleted once their last consumer moved to
`x-admin-dashboard.input`/the accent-filled track; `.neo-input` remains a
Storefront-only class.

Desktop cutover (#594): `admin/dashboard.blade.php` mounts `shell` with named
sidebar/topbar/status/content slots, one `adminDashboard` root, and one document
content scroll. At >=1024px the grouped sidebar is persistent and 240px wide;
below that breakpoint #595 replaces bottom navigation/Lainnya with a labeled
topbar trigger and grouped drawer. The shared compact topbar uses canonical group/tab context,
existing store/maintenance status, store action, theme preference, and Admin
identity. Summary values formerly in the hero now precede the unchanged stats
partial; share/copy remain in Link toko. The old hero, compact-header,
desktop-tab-strip, and mobile-navigation partials and their unused CSS have been removed.

The dashboard and linked operational shell render the same navigation model in
three progressively enhanced forms: persistent desktop sidebar, Alpine drawer,
and a no-JavaScript `<details>` fallback. The fallback is links-only and cannot
change server authorization.

`AdminDashboardData::navigation()` preserves query filters on fallback tab links
and removes `product_form` when leaving product creation. Alpine preserves the
current hash and uses `window.location.assign()` unchanged. Owner adjustment
navigation reuses `BalanceAdjustmentDashboardReadiness`; the controller/routes
still enforce access. Active-tab data remains built by `forActiveTab()`, never
the eager builder. Existing section IDs, return targets, modal IDs, refs and
initialization remain unchanged.

Render/navigation repairs (#601): the authorized active tab body is server-rendered without `x-cloak`/`x-show`; only interactive overlays need Alpine to become visible. The shared topbar is sticky on dashboard and linked pages. Its lifecycle-owned height observer supplies native hash/focus scroll padding and restores the prior inline height on destruction, with a conservative CSS fallback for no-JavaScript pages. `focusReturnTarget()` aligns section starts below the header rather than centering oversized sections; focus/highlight and stable return targets remain intact. Ordinary and modified navigation clicks preserve the current client fragment only when the server destination has no explicit hash. Passing-after smoke and the per-item antislop gate are recorded in [the sidebar cutover notes](admin-dashboard-sidebar-cutover.md).

The no-JavaScript topbar fallback uses font-relative budgets of 18rem below 640px and 10rem at wider widths, plus the safe-area inset. Native payment-settings headings remained visible at 200% text size from 320 to 1280px. Stats revenue rows wrap at the narrow boundary instead of widening the document.

`x-admin-dashboard.topbar` bounds its own height on phones: the group label and page title render as single truncated lines, the status badges sit in one horizontally scrollable strip, and the actions stay on one non-wrapping line whose store link may shrink. Without that, 200% text at 320px inflated the sticky bar to 613px (taller than the viewport) and covered the page content underneath.

Mobile lifecycle (#595): `resources/js/admin-dashboard/navigation.js` owns
`sidebarOpen`, visible opener restoration, overflow-style ownership,
breakpoint listener and cancelable next-tick focus. The root explicitly calls
`initNavigation()` before existing modal initialization and
`destroyNavigation()` from `destroy()`. Modal state watchers close the drawer
without restoring competing focus; an already-open modal prevents opening it.
`modal-focus.js` centralizes the shared focusable-element, Tab containment and
focus-containment helpers without replacing existing feature modal restoration.
`app.js` also registers `dashboardNavigation` for standalone shell consumers.
`AdminDashboardData::navigationFor()` exposes the same permission-filtered model to linked operational pages. `src/resources/views/components/admin-dashboard/linked-shell.blade.php` mounts those pages (order detail, supplier workflows, and manual review queues) inside the shared desktop sidebar/mobile drawer without loading an inactive dashboard tab or duplicating authorization. The linked shell preserves the active dashboard destination and keeps response navigation free of credentials and provider secrets.
The drawer releases its own lock on close, navigation, desktop resize and root
removal, preserving pre-existing inline overflow values/priorities. Shell
content, topbar and skip link are inert while the drawer is modal. A nonmodal
details menu renders the same authorized groups before Alpine initializes or
when JavaScript is disabled; interactive overlays alone are cloaked.
Navigation heading IDs use renderer-specific prefixes.

`admin-dashboard-navigation.test.js` covers focus races, stale destruction,
lock ownership, breakpoint cleanup, modal precedence and URL/create intent.
The responsive browser lane exercises the 375x900 drawer, every tab,
keyboard/backdrop/resize/root-removal cleanup, no-JavaScript links and existing
product/stock modal behavior. Safe-area CSS consumes available Telegram inset
variables with browser fallbacks; real Android/iOS evidence remains the
[#605 human release gate](admin-dashboard-release-parity.md), not a claim established by viewport emulation.

`AdminDashboardNavigationContractTest` exercises direct Owner/Admin URLs,
current sidebar links, absent inactive tab subtrees, and filter/create-state
boundaries. `admin-dashboard-responsive.spec.js` exercises desktop links,
all ten direct tabs, browser back/forward/hash/filter state, no-JavaScript
navigation, existing feature modals, and the 1023/1024px breakpoint. The #605
operational/financial browser cases cover 320/390/768/1023/1024/1280px in
both themes, including private supplier catalog and product pricing pages.
The overflow helper observes original widths and unscrollable clipped body
text, excluding deliberately zero-area CSS clips for screen-reader labels;
it never writes `overflow-x: visible` before measuring. No-JavaScript
Owner/Admin cases also scroll to a substantive native control or data row in
every authorized tab and verify that the topbar does not cover it. Native supplier
import/mapping/manual-pricing browser coverage checks invalid POST, browser
back, inactive draft/copy, unchanged retail and zero purchases. The removed
Ringkasan heading/styled-node snapshot was not monetary evidence; overview
PHP tests retain real metrics/earnings ownership.

The [release parity evidence ledger](admin-dashboard-release-parity.md)
records exact commands, base revision plus tested source hashes, failures,
skips/not-run cases, screenshot locations and historical immutable rollback
identity. Root text enlargement is not native 200% browser zoom, and browser
CSS/inset emulation is not Telegram Android/iOS acceptance. No production
deployment or human sign-off is implied by these checks.


### Paid receipt latency baseline and queue-wait contract (#649)

`SendTelegramReceipt` owns the queue-wait boundary. Every dispatch site now
passes an ISO `job_enqueued_at` plus an `enqueue_stage`/`enqueue_reason` pair
(`first_enqueue`, `retry_enqueue`, `recovery_enqueue`); the initial paid handoff,
manual/supplier fulfillment handoffs, the shared effect dispatcher and
`telegram:recover-outbound` are covered. A missing timestamp logs
`queue_wait_ms: null` with `queue_wait_unknown: true`; it is never coerced to
zero. `OrderStateMachine` logs `dogfood.telegram.paid_committed` at the
post-commit handoff, `KlikQrisCallbackProcessor` logs
`dogfood.telegram.payment_observed` with both provider `paid_at` and application
observation time, and `ReceiptDeliveryPipeline` logs the first credential-bearing
Telegram acknowledgement and receipt-group completion. The admin notification
pipeline logs its own Telegram acknowledgement. Button webhook ACK stays in
`dogfood.telegram.webhook_ack` (`ack_kind`, `action_state`) and is separate from
the queued action result (`dogfood.telegram.handler_finished`).

`App\Services\Telegram\LatencyBaseline\TelegramLatencyBaseline` runs actual
checkout, authoritative paid lifecycle, receipt/admin pipelines and Redis workers
against disposable PostgreSQL/Redis. Issue #656 extends #649 with separate bounded
batches, same-chat callback/provider bursts, provider outage, definite/ambiguous
Telegram failures, pin failure/replay and lost-Redis-wakeup recovery. The worker
bootstrap installs bounded HTTP fakes and blocks stray HTTP; no production
service binding changes. Receipt transport auditing checks recipient ownership
without retaining content; a final cross-scenario ledger/transport audit catches
later duplicate sends, settlement or stock mutations after recovery.

`LatencyBaselineReport` keeps sample/unknown/failure/retry counts and raw stage
timestamps; it withholds p95 below 20 known samples and emits no p99. Ordered
successor pickups are not counted as retries. Expected ambiguous delivery remains
`unknown` with missing ACK/completion boundaries, not a fabricated zero latency.
The `telegram:latency-baseline` command fails on other missing boundaries or
correctness failures. `scripts/telegram-latency-baseline.sh` uses an internal
disposable network; `scripts/telegram-latency-compare.sh` snapshots baseline and
integration source and overlays measurement code only, preserving historical
business owners. Neither script installs a scheduler or persistent monitor.
Contract and limits: [telegram-latency-baseline.md](../../docs/telegram-latency-baseline.md).
Integrated evidence and rollout recommendation:
[telegram-latency-gate-evidence.md](../../docs/telegram-latency-gate-evidence.md).
Verification entry points: `tests/Feature/Telegram/TelegramReceiptLatencyTimingTest.php`,
`tests/Feature/TelegramLatencyBaselineCommandTest.php` and
`tests/Unit/TelegramLatencyBaselineReportTest.php`.

## 11. Checklist perubahan untuk AI

Sebelum membuka patch:

- [ ] Istilah baru cocok dengan `CONTEXT.md`.
- [ ] ADR relevan sudah dibaca; keputusan provider/Recovery Key/Telegram idempotency tidak dilanggar.
- [ ] Business rule ditempatkan di service owner, bukan di controller/view/job adapter.
- [ ] Rail-specific Payment assembly stays behind `CheckoutPaymentPreparation`; `preflight()` runs before checkout locks and `prepare()` runs after locks without external I/O.
- [ ] Payment lama tidak berubah provider dan callback tidak langsung menandai paid.
- [ ] `KlikQRIS` tidak membuat Payment atau top-up target/fallback baru; recovery memakai Payment/top-up dan merchant reference yang sama.
- [ ] Inventory reservation/sale/release tetap melalui `InventoryLedger`.
- [ ] Retry/idempotency/row lock tetap diuji pada boundary yang benar.
- [ ] Payload/credential/QR secret tidak bocor ke log, response, fixture, atau docs.
- [ ] README/runbook/ADR diperbarui jika command, flow, atau operational invariant berubah.
- [ ] Telegram Outbound Effect changes preserve the sole ledger seam, explicit Ambiguous Telegram Delivery review, and the deferred Admin Broadcast handoff documented in [`telegram-outbound-effect-architecture.md`](../../docs/telegram-outbound-effect-architecture.md).
- [ ] Test/lint/build yang relevan dijalankan dan hasilnya dicatat.
