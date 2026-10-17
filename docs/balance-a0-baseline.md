# Balance A0: source audit, contracts and lock graph

Date: 2026-10-04. Scope: [#537](https://github.com/Alhiefikri/telegram-bot/issues/537), A0 of [#536](https://github.com/Alhiefikri/telegram-bot/issues/536). Source baseline: `56294d70bdda9c9a0722a6b7c2eb3949211f1e81` plus the worktree inspected on this date. This is a documentation deliverable; new architecture is [Proposed ADR-0019](adr/0019-idr-usdt-economic-sources-and-receipt-claims.md), not accepted or activated. Local draft epic attachments are supplementary; the GitHub epic is canonical.

## Evidence and audit method

Read the actual implementations below, their nested services, models, migrations and caller adapters, rather than copying the accepted ADR lock-order summary. Search `src/app` for `StoreBalanceLedger`, `creditTopUp`, `creditOwnerCompensation`, `refundPartial`, `applyPaymentPaid`, `applyPaymentRefunded`, `applyManualCompletion`, projection table names, supplier balance writes, `lockForUpdate`, `DB::transaction`, `afterCommit` and receipt claims. The lock-site appendix records file/symbol/line for acquisitions in these flows; line numbers are a baseline locator, not a replacement for following the call graph.

Current installed dependencies were checked in `src/vendor/composer/installed.json`: Brick Math 0.18.0 and Laravel 13.34.0; composer requires Laravel ^13.8 and PHP ^8.3. Context7 resolved `/laravel/docs/__branch__13.x` and `/brick/math`. [Laravel transaction documentation](https://github.com/laravel/docs/blob/13.x/database.md) documents retried closures; [query locking documentation](https://github.com/laravel/docs/blob/13.x/queries.md) places pessimistic locks inside transactions. [Queue documentation](https://github.com/laravel/docs/blob/13.x/queues.md) delays dispatch until outer commit and discards rolled-back work. These guarantees do not create a durable outbox: that conclusion follows from the repo's volatile Redis architecture ([ADR-0014](adr/0014-redis-runtime-state.md)) and source discovery requirements.

Context7's Brick results reference main, so method/enum syntax was cross-checked against installed `src/vendor/brick/math/src/BigDecimal.php`, `BigNumber.php` and `RoundingMode.php`, and [the 0.18.0 source](https://github.com/brick/math/tree/0.18.0/src). Exact scale conversion rejects required rounding; integer conversion rejects overflow. Do not copy main-version exception/API assumptions into the installed ^0.18 contract. No Binance Merchant/C2C or custody API qualification is inferred from library docs.

## Actual economic writers and callers

Paths below are relative to `src/app/Services` unless linked otherwise. Each row names the money/state owner and callers; UI/status writers are distinguished from actual balance postings.

| Owner/file and symbol | Actual effect and caller boundary | Required downstream treatment |
| --- | --- | --- |
| [StoreBalanceLedger](../src/app/Services/StoreBalance/StoreBalanceLedger.php) `apply`, `credit`, `creditTopUp`, `creditOwnerCompensation`, `debit`, `refund`, `refundPartial` | Sole app writer of Customer IDR projection/entries. `apply` locks account, checks projection, deduplicates source/key, bounds arithmetic, inserts entry then saves projection. Generic `credit` has no production caller found; still a public money boundary. | Keep this authority; add typed authorization/fingerprint rather than a second writer. |
| [StoreBalanceTopUpService](../src/app/Services/StoreBalance/StoreBalanceTopUpService.php) `settleVerifiedEvidence` | Customer/account/top-up/event locks; evidence matching and eligibility; `creditTopUp`; paid target and settled event. KlikQRIS/Sakurupiah/manual lifecycle and reconciliation reach it. | R1/R2 receipt + resolution authority; preserve amount/fee separation and provider snapshot. |
| [ManualStoreBalanceTopUpConfirmationService](../src/app/Services/StoreBalance/TopUp/ManualStoreBalanceTopUpConfirmationService.php) `approve`, `reject` | Operator decision evidence, not authenticated external receipt. Approval uses common settlement; rejection/expiry creates no credit. | Existing operator semantics remain; new funded adjustments require separate authoritative receipt qualification. |
| [StoreBalanceOwnerCompensationService](../src/app/Services/StoreBalance/StoreBalanceOwnerCompensationService.php) `release` | Owner-authorized compensation, unique top-up compensation, `creditOwnerCompensation`; Customer/account/top-up serialize against settlement. | Backfill compensation identity as well as paid rows; one resolution for settlement/compensation. Existing source remains `owner_compensation`. |
| [OrderCheckoutService](../src/app/Services/OrderCheckoutService.php) `checkout`, `checkoutOnce` | Pricing/item snapshot, inventory/supplier reservations, Order and Payment; IDR `debit` then lifecycle paid in same transaction. Preflight runs before locks; prepare afterward. | U10 must select USDT account before products, preserve full-payment atomicity and internal rail identity. |
| [CheckoutPaymentPreparation](../src/app/Services/Checkout/CheckoutPaymentPreparation.php), [CheckoutPaymentPlanWriter](../src/app/Services/Checkout/CheckoutPaymentPlanWriter.php) | Preparation implementations return persisted rail snapshots; writer assembles Payment. Store Balance preparation is IDR-only; BSC/Binance external Payment is not a balance posting. | No network in `prepare`; never turn historical external Payments into an internal balance rail. |
| [OrderStateMachine](../src/app/Services/OrderStateMachine.php) `applyPaymentPaid`, `applyManualCompletion`, `applyPaymentRefunded`, `refundStoreBalance` | Concrete implementation of `OrderLifecycle`; whole IDR refund calls ledger, capped by original debit minus partial credits; paid transitions allocate local credentials and make supplier obligations pending. | Preserve original currency/debit and entitlement; U11 extends selected-currency seam, not live FX. |
| [WarrantySettlementService](../src/app/Services/Warranty/WarrantySettlementService.php) `choose`, `confirmExternalRefund` | Verified claim/unit creates OrderRefund; IDR Store Balance path calls `refundPartial`; external path records exact transfer evidence only. Replacement choice spends Supplier Balance, not Customer balance. | Account before Order even for claim selection; same-currency U11 refund; do not credit external refunds into IDR. |
| [MissingFulfillmentRefundService](../src/app/Services/Warranty/MissingFulfillmentRefundService.php) `request`, `confirmExternal` | Definitively missing units register one entitlement; IDR partial credit or exact external evidence; completion calls supplier/lifecycle. | Guard ambiguous purchases and cumulative debit/refund cap, including full refund after partial. |
| [RemainderRefundService](../src/app/Services/Warranty/RemainderRefundService.php) `registerConfirmedExternal` | Registers confirmed unclaimed external remainder under caller transaction, no money transfer or balance credit. | Keep ADR-0018 remainder semantics and unique reference; do not masquerade goodwill as a deposit. |
| [ManualFulfillmentResolutionService](../src/app/Services/Admin/ManualFulfillmentResolutionService.php) `resolve`, `refund`; [AdminOrderOperations](../src/app/Services/Admin/AdminOrderOperations.php) `completeManualOrder` | Review adapter acquires IDR account first for Store Balance refund; delegates lifecycle/refund registration. Admin complete delegates lifecycle. | Extend the early selected-account lock for USDT; controllers never mutate projections. |
| [BinancePaymentVerification](../src/app/Services/Binance/BinancePaymentVerification.php) `confirm` | Authenticated transaction history inspected before transaction; Order/Payment, sets provider reference then manual completion. Unique Payment provider reference is the current double-claim guard. | R2 replaces cross-target authority with global claim; history amount matching is not qualified funding attribution. |
| [BscPaymentVerification](../src/app/Services/Bsc/BscPaymentVerification.php) `claim`, `recordLatePayment`, `persistEvidence` | RPC evidence outside claim transaction; Payment-bound transfer evidence unique tx hash, Payment detail/version and lifecycle. Late Order money goes to review. | R1 parent tx + log claims; preserve late Order vs late IDR top-up distinction. |
| [KlikQrisCallbackProcessor](../src/app/Services/KlikQris/KlikQrisCallbackProcessor.php) `applyAuthoritativeTransaction`; [SakurupiahCallbackProcessor](../src/app/Services/Sakurupiah/SakurupiahCallbackProcessor.php) `verifyAndApplyLocked`, `reconcileLocked`; [PakasirCallbackProcessor](../src/app/Services/Pakasir/PakasirCallbackProcessor.php) `applyPaid` | Authoritative lookup/signature matching before lifecycle paid. KlikQRIS/Sakurupiah cache locks are single-flight coordination, not DB row locks. Pakasir is historical only. | R2 includes callback, poll/reconcile, late/review, manual completion and pending targets, not just funding adapters. |
| [SupplierFulfillmentService](../src/app/Services/Suppliers/SupplierFulfillmentService.php) `reserve`, `hold`, `unhold`, `commitDebit`, `finalizeBuyMail` | Operator Supplier balance and SKU commitments; cost/debit evidence, acquisition and fallback. Supplier HTTP between durable claim/finalize transactions. | Keep separate from Customer liability and refund destination. No new Customer account acquired inside supplier worker finalization. |
| [WarrantyReplacementService](../src/app/Services/Warranty/WarrantyReplacementService.php) `process`; [WarrantyClaimService](../src/app/Services/Warranty/WarrantyClaimService.php) `process` | Supplier replacement hold/release/debit; claims observe independently verified refund outcome and invalidate stale wallet observation instead of adding presumed credit. | Preserve ambiguity holds; predeclare historical supplier/SKU resources before locking. |
| [SupplierCatalogSync](../src/app/Services/Suppliers/SupplierCatalogSync.php) `sync`; [SupplierDepositService](../src/app/Services/Suppliers/SupplierDepositService.php) `apply` | Sync writes authoritative operator wallet observation; deposit marks paid/invalidation, explicitly does not add balance that a profile observation may already include. | Separate observed treasury asset from Customer subledger credit; preserve lease/account/credential/version checks. |
| [SupplierAvailability](../src/app/Services/Suppliers/SupplierAvailability.php), [SupplierManager](../src/app/Services/Suppliers/SupplierManager.php), [ProductSupplierManager](../src/app/Services/Suppliers/ProductSupplierManager.php) | Configuration/availability and coherent resource locking, not Customer money. | Include in lock graph because they share Products/Suppliers/SKUs with checkout and workers. |
| [StoreBalanceTopUpOutbound](../src/app/Services/Telegram/StoreBalanceTopUpOutbound.php), [OrderStateMachine](../src/app/Services/OrderStateMachine.php) outbound methods, [WarrantyNotificationPipeline](../src/app/Services/Warranty/WarrantyNotificationPipeline.php) | Durable Telegram effects/source recovery; delivery failure is independent of money. `StoreBalanceTopUpSettled` implements ShouldDispatchAfterCommit. | U7 persists/reconstructs committed work through existing outbound ledger; no new Telegram transport seam. |

[CustomerBalanceAccount](../src/app/Models/CustomerBalanceAccount.php) excludes balance from mass assignment; [StoreBalanceEntry](../src/app/Models/StoreBalanceEntry.php) forbids model update/delete. The [IDR table migration](../src/database/migrations/2026_09_10_000002_create_store_balance_tables.php) enforces unique Customer account, positive amounts, nonnegative projection and unique source/key, but model hooks are not a database append-only policy. Its Customer/account FKs use cascade deletion, so historical retention is also a gap: I1/R2 must qualify an additive RESTRICT/privilege migration without deleting or rewriting IDR entries. [StoreBalanceReconciliation](../src/app/Services/StoreBalance/StoreBalanceReconciliation.php) checks without repairing. No Customer USDT account/ledger or balance adjustment request implementation was found. Existing `Payment.amount` USDT micro and Supplier wallet micros do not supply those missing aggregates.

Channel entry points: Admin manual completion controller → AdminOrderOperations; Admin review controller → ManualFulfillmentResolutionService; Telegram checkout/balance callbacks → checkout/intent/top-up owners; provider callback/reconcile jobs → respective processors; warranty customer/Admin adapters → settlement services. Queue jobs are invocation adapters, not extra money authority. Recovery/cancellation callers include [CustomerPendingOrderCancellation](../src/app/Services/CustomerPendingOrderCancellation.php) and [ProviderCancellationAttempts](../src/app/Services/ProviderCancellationAttempts.php), which delegate the same lifecycle.

## Lock graph: observed edges

Arrows mean first acquisition within the enclosing transaction; re-locking a row already held does not add a new graph edge. Newly inserted rows are owned by that transaction. Batch resource IDs must be deterministic. All symbols and explicit acquisition sites are in [the appendix](balance-a0-lock-sites.md).

| Flow / owning symbols | Observed order, including nested calls |
| --- | --- |
| Checkout `OrderCheckoutService::checkoutOnce` | Cart → IDR account (`StoreBalanceLedger::lockForTransaction`, only IDR rail) → CheckoutMaintenanceSetting (`CheckoutMaintenance::guardNewOrder`) → CartItems product order → Products ID order (`EloquentInventoryLedger::lockedCheckoutProducts`) → Suppliers ID order → SupplierCatalogItems ID order (`SupplierFulfillmentService::lockCheckoutResources`/`lockResources`) → ManualPaymentSetting → created Order/Payment → paid lifecycle inventory/credentials. |
| Intent `StoreBalanceFundingIntentService::open/claim`; target `StoreBalanceTopUpService::createOrReuseConfirmedIntent` | Open: Customer → FundingPolicy → pending Intent. Claim: Customer → Intent → FundingPolicy if pending. Root matching here is an unlocked read. Target creation: Customer → confirmed Intent → navigation Root → existing Top-up. Creation does not move money; do not merge it into account settlement while holding intent/root first. |
| Funding `StoreBalanceTopUpService::settleVerifiedEvidence` | Customer → account → Top-up → Top-upEvent → entry/projection; settled notification dispatch deferred until commit. |
| Compensation `StoreBalanceOwnerCompensationService::release` | Customer → account → Top-up → Compensation lookup/create → entry/projection. Serializes same-target settlement/compensation; distinct source names alone would not do so. |
| Whole refund `OrderStateMachine::applyPaymentRefunded` | IDR account via `lockStoreBalanceAccount` → Order → Payments ID order via `withLockedOrderPayments` → supplier order resources via `assertRefundable` → ledger refund → supplier release/inventory/credential retirement. |
| Supplier order resources `SupplierFulfillmentService::lockedOrderResources` | OrderItems product order → Products ID order → Suppliers ID order (union snapshot + current mappings) → SupplierCatalogItems ID order → SupplierFulfillments ID order → SupplierPurchases ID order. Reused by review, refund, claim/finalize, retry, recovery and manual delivery. |
| Inventory `EloquentInventoryLedger::orderInventoryState`; credentials `ProductCredentialAllocator::allocate` | OrderItems → InventoryMovements → Products; allocation locks available ProductStockItems after Product. Inventory called by lifecycle under Order/Payment; checkout already holds its Products. |
| Warranty `WarrantyClaimService::lockUnit/lockForSettlement` | Order → Payments → supplier order resources → historical unit Supplier → SupplierAccountUnit → ProductStockItem → WarrantyClaim. `WarrantySettlementService::choose` prefixes account; external confirmation omits account and locks OrderRefund after claim. |
| Missing refunds `MissingFulfillmentRefundService::request` | Account → Order → Payments → supplier order resources → Fulfillment (already held) → OrderRefund → ledger `refundPartial` (account already held) → supplier completion. External confirm: Order → Payment → supplier resources → refund, without Customer ledger credit. |
| Replacement `WarrantyReplacementService::process` | Warranty order/unit/claim chain above → historical purchase SupplierCatalogItem → warranty SupplierPurchase; HTTP after claim commit; finalize reacquires chain and verifies token. |
| Admin review `ManualFulfillmentResolutionService::resolve` | Account first for IDR refund → Order → Payments → supplier order resources → ManualFulfillmentReview → lifecycle; remainder registration locks OrderRefunds ID order. |
| BSC `BscPaymentVerification::claim/recordLatePayment` | Order → Payment → PaymentBscDetail → tx evidence → lifecycle inventory or ManualFulfillmentReview; `persistEvidence` locks Payment/detail separately. Current whole-tx uniqueness is Payment-bound. |
| Binance `BinancePaymentVerification::confirm` | Order → Payment → unique provider_reference write → manual completion/lifecycle; no Customer account mutation. |
| External BSC checkout `BscCheckoutPaymentPreparation::prepare` → `BscPaymentReceiverPool::selectLockedReceiver` | After checkout ManualPaymentSetting, locks eligible receivers in assignment-time/ID order and saves selected receiver assignment time; no HTTP. This checkout pool is currently shared, not a permanent Customer funding receiver registry. |
| Receiver Admin `BscReceiverManager::verify/renewChallenge/manualVerify/activate/deactivate` | Receiver → ownership Challenge for verification; other mutations lock Receiver alone. ERC1271 verification currently invokes RPC inside the receiver transaction; see remediation below. |
| KlikQRIS `KlikQrisCallbackProcessor::applyAuthoritativeTransaction` | PaymentEvent → Order → Payments via lifecycle → inventory/credentials. Redis payment lock wraps lookup; it does not change row-lock order. |
| Sakurupiah/Pakasir | Network verification then lifecycle Order → Payments → inventory/credentials. PaymentLifecycleCoordinator locks Order → Payment for short local persistence callbacks; cache locks may span HTTP but DB money transaction must not. |
| Availability / supplier Admin / sync | Products ID order → Suppliers ID order → CatalogItems ID order (where needed) → mapping/config state. Snapshot HTTP precedes sync apply. |
| Supplier deposit `SupplierDepositService::process/reconcileKnownDeposit` | Supplier → SupplierDeposit; HTTP outside claim/finalize; paid deposit invalidates sync, no Product lock afterward inside the transaction. |

### Ordering findings and prescribed repair

1. **Event-first lifecycle inversion risk (R2).** KlikQRIS enters lifecycle while holding PaymentEvent. Proposed receipt/inbox settlement wants Order/Payment/receipt/event, so retaining event → Order would create a reverse edge. Current audit establishes the order difference, not a reproduced deadlock. R2 must acquire Order/Payments before the event and route all callback/reconciliation consumers through that common claim transaction. Verify parallel callback/poll/expiry with the same event/target in disposable PostgreSQL; Redis unavailable must not weaken it.
2. **Historical supplier late acquisition (U10/U11, verified before integration).** `WarrantyClaimService::lockUnit` acquires the unit Supplier after the current/snapshotted supplier/catalog union. Replacement then acquires the original purchase SKU after the claim. A historical supplier/SKU absent from current mappings/snapshots can add Supplier or SKU out of ascending order after later-class locks. This is conditional: reacquiring an already locked row is harmless. Pre-read the complete historical unit/replacement supplier/SKU ID union; lock all Suppliers then all SKUs in ascending ID order before fulfillments/purchases/units/claim. Revalidate membership under Order lock; changes require outer retry, never a late missing-resource lock. Test two different Orders sharing crossed supplier sets, retired mappings, replacements, sync and refund. Do not claim a fix was implemented in A0.
3. **Inventory movement placement (U10/U11).** Lifecycle inventory and supplier resource helpers place InventoryMovement on different sides of Product. Same-Order paths currently serialize on Order; a cycle is not established by class-order difference alone. Before a new cross-order resource coordinator is introduced, acquire its order-local movement rows before Products or restrict movement acquisition to rows protected by the already-held Order. Preserve Product → Supplier → SKU across Orders; prove with PostgreSQL races.
4. **Intent/policy order (U6).** Open locks policy before intent; claim locks intent before policy; target creation locks intent before root. Current paths acquire Customer first, and intent rows are Customer-owned, so the audit does not establish a live cycle. USDT intent must retain that prefix and exit the confirmation transaction before settlement. Never take Customer after account or Order while still holding a root/intent.
5. **Nested ledger account reacquisition (U11).** Warranty/missing/full-refund branches already prelock IDR account. A USDT adapter must prelock the selected currency account at the same outer boundary, including Admin review and a partial refund that reaches full refund. Do not call a newly account-locking refund under Order/Payment without this prefix. Pure external verification has no account effect and must exit before entering any balance lifecycle requiring one.
6. **Receiver qualification network boundary (A1/U4).** `BscReceiverManager::verify` calls [BscWalletOwnershipVerifier](../src/app/Services/Bsc/BscWalletOwnershipVerifier.php); its ERC1271/smart-contract branch calls RPC while Receiver/Challenge locks are held in a five-attempt transaction. This is an existing non-money Admin qualification path, not a permissible pattern for funding settlement. U4 must verify network evidence outside its transaction and recheck challenge hash/owner/expiry/unused status plus receiver snapshot/version under lock before applying ownership. Test concurrent challenge renewal, replay and stale network evidence. A0 specifies the boundary and does not silently claim the baseline already satisfies it.

### Proposed compatible extensions

- USDT checkout (U10 #555 implemented): existing Cart → selected currency account → shared funding-policy row barrier → maintenance → CartItems/Products → Supplier union → SKU union → payment setting → created Order/Payment/lifecycle. The shared policy lock serializes pause decisions with the Owner policy manager's exclusive lock, not with other spend readers. Receipt-free internal purchase source, frozen USDT pricing; no FX or network in preparation.
- Funding: Customer → selected account → Top-up → resolution/request → globally ordered receipt parents/receipts/aliases → entry/accounting/audit/work. Resolution competitors use the same top-up lock/unique identity. Adjustment: Customer → IDR account → immutable request → receipt or original entry reference → posting. Two-account locks, if a future legitimate operation needs them, sort by currency/Customer and require another graph review; transfers are out of scope.
- Refund: selected account → Order → Payments → existing supplier/inventory chain → entitlement/refund → posting/outbound. Resource-free funded adjustment never reaches Cart or Order. Global receiver assignment is a separate transaction and never appends money; settle revalidates immutable assignment.
- Receipt-only ingestion holds no money/account lock and never calls lifecycle while retaining receipt-first locks. Settlement owns receipt consumption under the target graph. Parent/alias insertion conflicts use savepoint/upsert or full rollback followed by a fresh transaction, not catch-and-continue in an aborted PostgreSQL transaction.

## I1–I12 review against actual callers

Baseline means what source proves today, not that future USDT acceptance has passed. Verification lane names below are existing regression evidence entry points; no SQLite claim substitutes for PostgreSQL races.

| Invariant | Actual baseline / gap | Contract and verification owner |
| --- | --- | --- |
| I1 one account/currency | Customer account unique migration and ledger creation; no USDT account. | U1 additive unique USDT account/currency CHECK; account-create race. |
| I2 sole writer | Ledger `apply` owns IDR entry/projection; checkout, lifecycle, top-up, compensation and partial-refund callers delegate. Public generic `credit` still accepts strings. | I1/U1 typed sources, no controller projection writes; StoreBalanceLedgerTest. |
| I3 exact positive bounded funds | Ledger positive/int/overflow/nonnegative checks; BscTokenAmount and BinanceUsdtAmount are exact conversion utilities; business USDT caps absent. | U1/U3 bounded positive parser and cap/conditional debit; SupplierMoneyTest existing precision evidence, new USDT boundary tests. |
| I4 immutable atomic posting | IDR entry+projection in one transaction, model immutability; cascade FKs and no DB ledger mutation trigger/role proof or Accounting Event. | U1/I1/R2 migration retention/privileges/atomic event; rollback/crash tests, not model-hook tests alone. |
| I5 one source/key, payload conflict | Ledger `findEntry` compares account/kind/amount/source; DB unique source/key; funding intent and compensation uniqueness. Full semantic fingerprint absent. | R1/I1/U1 fingerprint + request-version conflict; concurrent replay and changed payload tests. |
| I6 one external receipt globally | Binance Payment reference and BSC Payment evidence tx uniqueness only; top-up/compensation separate tables. | R1/R2 alias/parent registry and all writer cutover/backfill; checkout/top-up/adjustment cross-claim races. |
| I7 immutable payment snapshot | Checkout plan writer and top-up provider snapshot; provider-switch semantics maintained. USDT funding snapshot absent. | U5/U6 snapshot/version revalidation; existing rail switch/preparation tests plus custody policy change races. |
| I8 original refund/reversal cap | Ledger whole/partial uses original debit and cumulative refund totals; Warranty/Missing entitlements; external remainder registration. No linked adjustment reversal. | I3/U11 same units/currency, partial+full concurrency; WarrantyClaimTest, BuyMailWarrantyPostgresConcurrencyTest and new USDT cases. |
| I9 UI is not money authority | Home reads snapshot; settled event is after commit; outbound ledger manages messages separately. | U7/U9 durable work; lost wakeup/sent receipt failure must preserve exactly one posting. |
| I10 drift blocks mutation | Ledger full-sum guards; top-up latest-entry cursor guard; reconciliation reports without repair. Persistent USDT freeze absent. | U1/U2/O2 freeze/pause and explicit recovery; StoreBalanceReconciliationTest, injected drift. |
| I11 currencies stay separate | IDR Store Balance gates and external USDT Payment amount; external refunds do not credit IDR. | U1/U10/U11 USDT ledger internal rail; no implicit conversion, mixed-currency report tests. |
| I12 ambiguous funds unavailable | IDR evidence match, owner compensation review; supplier ambiguous purchase blocks refund; BSC late Payment review. USDT Customer attribution/custody/finality unqualified. | H1/A1/U5/U7/U8 hard gates; wrong-owner/hash, unresolved create, reorg, late compensated deposit tests. |

State examples and typed source, receipt, accounting and outbox schemas/contracts are in ADR-0019. Critical race targets: two debits of last funds, callback+poll+review, two Customers one hash, parent tx logs across targets, alias vs chain consumer, approval stale/parallel, expiry vs late settle, compensation vs settle, full+partial refund, process death at every commit/dispatch boundary and Redis loss. V1 proves these in disposable PostgreSQL; V2 measures bounded hot paths/backlog; H2 alone executes production qualification.

## Open decisions: evidence, owner and gate

| Decision still open | Evidence needed / current limitation | Owner / blocking ticket |
| --- | --- | --- |
| Maker-checker staffing or explicit single-operator exception | Named independent approver and permissions; existing manual rail/Owner compensation is not an adjustment-policy approval. | Operator/Owner H0 #538; I2 stays gated. |
| IDR adjustment categories, reason taxonomy, limits, retention and reversal recovery | Approved operational policy; funds already spent must enter review, never negative balance. | Owner/Finance H0 #538; I1/I3 design with I2 activation gated. |
| USDT minimum/presets/per-account and aggregate caps, pause ownership, invoice expiry/SLO | Approved exact-unit amounts, incident roles and escalation thresholds; proposed SLOs are not measurements. | Owner/Finance H0 #538, U3 #548, V2 #560. |
| Legal/customer-funds model, disclosures and permitted external return process | Authorized jurisdiction/provider review; no automatic withdrawal/trading/transfer implied. | Authorized operator/legal H1 #539. |
| Custody/provider and permanent exclusive receiver capacity | Ownership/permission, address inventory, no cross-pool reuse, no keys in app; public checkout receiver readiness is insufficient. | Operator H1 #539; technical qualification A1 #540, U4 #549. |
| BSC finality/reorg policy and RPC/custody guarantees | Primary contract evidence for canonical/finalized support, token decimals/contract, outage/fallback and post-settlement incident procedure. | Provider/custody operator A1 #540, U5 #550, U8 #553. |
| Optional Binance funding eligibility | Authenticated intent/beneficiary binding; Merchant docs cannot stand in for C2C/history account contract. Default disabled until proven. | Operator/provider H1/A1 #539/#540. |
| QRIS canonical economic ID/stable account namespace and legacy alias mapping | Adapter-specific primary docs/operator evidence; inspect settled/reserved/compensated evidence in disposable copies without leaking secrets. | R1/R2 implementers #541/#542; ambiguous history blocks cutover. |
| Accounting chart, settlement venue/fee/treasury and asset evidence | Finance-approved balanced legs and asset reconciliation sources; no live FX aggregation. | Finance H0/H1, U1/U2 #546/#547, O1 #557. |
| DB writer privileges/append-only enforcement, retention and restore procedure | Separate migration/runtime roles, tested restricted writes and approved restore authority. Existing model hooks and cascade IDR FKs are weaker. | I1/R2 #543/#542, U1 #546, O2 #558, V1 #559. |
| Lock remediation qualification | Event-first and historical supplier union changes with source diff and crossed-resource PostgreSQL races, including Redis loss. | R2 #542, U10/U11 #555/#556; V1 #559 verifies. |
| Production readiness, rollout/canary/sign-off | Green technical gates, custody/policy approval, restore/rollback evidence and exact release identity on Actions main/VPS. | O3 #561 and operator H2 #562. No A0 authorization. |

## A0 verification and PR evidence

The source matrix and appendix cover ledger, checkout/payment preparation, historical external payment writers, top-up/manual/late/compensation, full/partial/external/remainder refund, supplier wallet/commitment/sync/deposit and warranty replacement. I1–I12 each have actual caller evidence, a gap classification and downstream owner. Relative links and appendix source lines are checked against the worktree; lock orders above were followed through nested calls. This proves the baseline documentation, not PostgreSQL runtime safety of future features.

Reviewable PR description: “Document issue #537's actual economic writers and lock graph, with Proposed ADR-0019 for typed sources, separate USDT ledger, global receipt aliases/parent claims, accounting and durable work. Add glossary/map pointers and record open policy/custody/provider gates. Validation: source/lock/link audit; no runtime changes or production mutation. Event-first and historical Supplier late-lock remediation is specified for downstream implementation; no production or PostgreSQL race qualification claimed.”

## S03 atomic dual-balance checkout (#620)

Implementation update, 2026-10-16. [#620](https://github.com/Alhiefikri/telegram-bot/issues/620)
implements [#617 sections 4.2 and 6](https://github.com/Alhiefikri/telegram-bot/issues/617)
after #619's completed shared pricing cutover. This section supersedes the
checkout prefix in the historical A0/U10 graph above. It does not activate an
API, funding, custody or production deployment.

### Current lock edges

`OrderCheckoutService::checkoutOnce` resolves an existing checkout before new
money locks. For a new internal balance purchase it acquires Customer shared
before Cart, matching `CartService::activeCartFor`/`currentActiveCartFor`'s Customer-first
ordering. The shared Customer row fences preference updates and avoids a late
Customer FK lock while creating a missing second account. It also serializes
against Customer-first top-up/adjustment writers without introducing an
account-to-Customer edge.

| Flow | Acquisition order and ownership |
| --- | --- |
| Explicit balance checkout | Customer shared → Cart → named account → funding policy shared → restore singleton shared → maintenance → CartItems by Product → Products by ID → Suppliers by ID → SKUs by ID → payment setting → newly inserted Order/Payment/items → paid lifecycle and inventory. |
| Automatic preference checkout | Same chain, with **IDR account before USDT account**, even for `usd_first`; both are validated before Product locks. IDR uses `StoreBalanceLedger::lockForPurchase`, USDT uses `CustomerUsdtBalanceLedger::lockForTransaction`. Preference controls selection, never lock order. |
| External checkout | Existing Cart → maintenance → CartItems/Products → Suppliers/SKUs → setting → rail-specific local preparation. No balance preference or new wallet locks. |
| IDR top-up/compensation/adjustment | Existing Customer exclusive → IDR account → target/request/resolution/receipt resources → ledger. No later Cart, USDT account or Product lock. |
| USDT top-up settlement | Existing Customer exclusive → USDT account → target/evidence/resolution/receipt resources → typed funding entry/accounting/outbound. Provider evidence is prepared before this chain. It never acquires IDR afterward. |
| Whole refund / Admin refund review | Original selected account → Order → Payments by ID → supplier/inventory resource union → registered entitlement/remainder → original-currency ledger credit and durable effects. No Customer lock, other wallet, preference or spending barrier. |
| Warranty / missing-unit refund | Original selected account → Order/Payments → Product/Supplier/SKU union, including historical unit/replacement resources → fulfillment/purchase/unit/claim → OrderRefund → capped ledger credit. Full-refund re-entry reuses the same account. Ambiguous supplier outcomes remain held/reviewed. |
| Pause/policy writer | `CustomerUsdtFundingPolicyManager` takes policy exclusive before changing pauses. No account/Cart/Product acquisition afterward. Checkout's shared barrier fences both automatic selection and explicit IDR/USDT debit through commit. |
| Restore open/close/replay-finalize | `BalanceRestoreGateService` takes restore singleton exclusive. New balance checkout holds its shared row through commit; a missing singleton fails closed. Replay/provider work precedes the short finalize transaction. |

Re-locking an already held selected account inside the ledger/lifecycle is not a
reverse edge. Refund/top-up writers never acquire the other wallet after their
selected wallet. New automatic checkout therefore adds IDR → USDT, not USDT →
IDR. Customer locks precede Cart/account locks; preference changes use ordinary
Customer updates. A new adapter must not enter checkout while retaining
Order/Payment, supplier, policy-exclusive or restore-exclusive locks.

### Selection and authority

`BalanceCheckoutRequest` carries a nullable trusted override and exact expected
totals (whole IDR and micro-USDT). Null override reads the persisted Customer
enum, default `idr_first`. A priced primary requires an expected total; a
secondary without consent cannot be spent. All supplied candidates must still
match the locked pricing snapshot before any selection. Stale secondary
consent rejects even when primary funds suffice. Missing USD pricing can fall
back to an authorized IDR quote; no conversion or split occurs.

Both automatic wallets must be healthy, even when one lacks a price. Freeze,
projection drift, missing trusted control state, spending/restore pause, stock
failure and uncertain supplier fulfillment are never fallback signals. A failed
checkout rolls back its Order/Payment, debit, allocations, reservations and
transaction-created accounts. All new balance checkout rails now observe the
same spending/restore barriers, extending the prior USDT-only spending scope;
funding/rail pauses remain scope-specific and refunds stay available.

`balance_checkout_snapshot` freezes resolved preference/source, currency,
amount and expected totals on Order insertion. PostgreSQL and model guards
reject later snapshot changes. Existing Orders retain null snapshots. The
Payment and original debit still authorize full/warranty remainder refunds;
public USD serializers must map existing internal USDT at the boundary, not
rewrite money history. API durable request fingerprints/admission (#621/#623)
and private preference controls (#627) remain their own tickets.

### Disposable runtime evidence

Standalone PHP bootstrapped the actual Laravel checkout/lifecycle against
PostgreSQL 16 in `issue620_smoke_test`, separate from the race database. Initial
typed disposable credits were IDR 20,000 and USDT 5,000,000 micro, with four
local credential units. Queue dispatch was faked and HTTP was denied: this
proves local transactions and durable obligations, not live provider funding,
Telegram delivery or custody qualification.

| Observed step | IDR balance | USDT micro balance | Available stock | Allocated units | Retired units |
| --- | ---: | ---: | ---: | ---: | ---: |
| Saved `usd_first`, paid 2,000,000 micro-USDT | 20,000 | 3,000,000 | 3 | 1 | 0 |
| Same-key replay after preference and both prices changed | 20,000 | 3,000,000 | 3 | 1 | 0 |
| USD quote 4,000,000 micro insufficient, authorized IDR 10,000 fallback | 10,000 | 3,000,000 | 2 | 2 | 0 |
| IDR-only override rejected during spending pause | 10,000 | 3,000,000 | 2 | 2 | 0 |
| Both original-currency refunds, each replayed | 20,000 | 5,000,000 | 2 | 2 | 2 |

Every observed `reserved_stock` was zero after the operation. Final state was
two Orders, two Payments, one purchase debit and one refund credit per currency.
Refunds retired exposed credentials instead of returning them to stock.
Preference/price changes did not change the accepted USDT 2,000,000 Payment or
its selection snapshot. Zero HTTP requests occurred.

Measured SQL/elapsed times in that cold, sequential fixture: saved-preference
checkout 75 statements / 378.63 ms; replay 9 / 32.84 ms; fallback 75 / 152.66 ms;
two refunds plus their replays 245 / 506.97 ms. These are local fixture
observations, not throughput or production latency guarantees.

A separate 20-purchase sequential run alternated `idr_first`/`usd_first` through
actual checkout, starting with IDR 100,000, USDT 20,000,000 micro and 20 units.
It ended with both balances zero, all 20 units allocated, stock and reservations
zero, and no HTTP requests. Measured p50/p95 were 223.21/327.36 ms wall time and
124.84/173.97 ms SQL time. SQL statement frequencies were 72 (10 purchases),
74 (9) and 94 (1 cold path); these measurements include existing paid lifecycle
and durable effects, not only candidate selection. Peak in-flight work was one,
so this is not load/capacity evidence. `pg_stat_database.deadlocks` was zero in
both disposable databases at the observed snapshot; one `pg_stat_activity`
snapshot found no Lock wait. That point sample does not measure cumulative lock
wait duration or prove contention-free operation.

### PostgreSQL process results

PostgreSQL **16.15**, isolated `issue620_test`, `RUN_CONCURRENCY_TESTS=1`:
the existing checkout/refund/settlement lanes passed **26 tests / 312 assertions**.
Two added three-process uncommitted pause/restore barrier cases then passed
**2 tests / 30 assertions**. Each barrier writer retained its actual control
row lock until `pg_blocking_pids` showed both opposite-preference checkout
processes waiting; after commit both rejected, with IDR 15,000, USDT 2,000,000
micro, zero Orders/Payments/allocations and both stock units unchanged.

The other process cases assert:

| Contention | Exact committed money and units enforced by the tests |
| --- | --- |
| Opposite automatic modes plus explicit bot IDR, last IDR funds | One IDR 15,000 debit; balances IDR 0 / USDT 1 micro; one allocated unit, two available, no reservations; two losing Carts remain active. |
| Opposite automatic modes, one purchase in each currency | One IDR 15,000 debit and one USDT 2,000,000 micro debit; balances 0 / 0; two allocated units, stock/reservations 0. |
| First creation of empty secondary account | One IDR purchase; both balances 0; one allocated and one available unit, no reservations. |
| Last stock across Customers | One allocated unit and one paid Order; loser retains IDR 30,000 and USDT 5,000,000 micro. Winner retains IDR 15,000 / USDT 5,000,000 if IDR selected, or IDR 30,000 / USDT 3,000,000 if USDT selected. |
| Automatic spend vs duplicate original refund and IDR/USDT top-ups (five processes) | Exactly one original USDT 2,000,000 refund. If new purchase is IDR: balances IDR 15,000 / USDT 4,000,000 micro; if USDT: IDR 30,000 / USDT 2,000,000 micro. Two allocated units, one retired, stock/reservations 0. |
| Three matching automatic checkouts, then preference/price edit and replay | One USDT 2,000,000 debit/Payment, original `idr_first` account resolution retained; balances IDR 1 / USDT 0; one allocated unit. |
| Warranty partial/full refund vs automatic checkout | Original refund sum exactly USDT 2,000,001 micro, capped to its debit; new automatic Order spends USDT 2,000,000. Final USDT balance 3,000,000 micro, no IDR entries; one new allocated unit. |
| Actual Binance settlement vs automatic checkout | Verified fixture credit USDT 300,000 micro plus spend 200,000, ending at 600,000 micro; IDR 0; one allocated unit. Immutable receipt balance-after is exactly 600,000 or 800,000 according to credit/spend ordering. Provider HTTP asserts transaction depth 0; checkout asserts no HTTP. |

The PostgreSQL guards also rejected an invalid preference and attempted Order
snapshot relabeling. Existing accounting/receipt failure injection still rolled
back money and inventory. Existing supplier-capacity and ambiguous-refund
protections passed in the same lanes.

Final database counters were **2 detected deadlocks in the race database and
0 in the smoke database**, not the zero seen in the earlier point sample.
Server logs locate both in the existing three-way **external Binance
transaction/Order-ID alias** test: receipt/alias inserts contended with external
checkout's `stock_notification_recipients` insert while top-up settlement held
Customer rows. They recovered through existing transaction retries; that test
still produced exactly one economic result. That external-rail scenario does
not use `BalanceCheckoutRequest` or the new dual-wallet lock prefix. No detected
deadlock in these logs involved the new dual-wallet or pause/restore cases.
This result is not a claim that the repository's entire external notification/
receipt graph is deadlock-free; that external edge remains a production
qualification concern.

Commands ran locally, not in CI:

```sh
php vendor/bin/phpunit tests/Feature/UsdtBalanceCheckoutTest.php \
  tests/Feature/OrderCartDomainTest.php tests/Feature/UsdtOrderRefundTest.php \
  tests/Feature/ProductResellerPricingTest.php

# Disposable PostgreSQL connection variables selected issue620_test on port 15620.
RUN_CONCURRENCY_TESTS=1 php vendor/bin/phpunit -c phpunit.postgres.xml \
  tests/Feature/UsdtBalanceCheckoutPostgresConcurrencyTest.php \
  tests/Feature/UsdtOrderRefundPostgresConcurrencyTest.php \
  tests/Feature/UsdtTopUpSettlementPostgresConcurrencyTest.php

RUN_CONCURRENCY_TESTS=1 php vendor/bin/phpunit -c phpunit.postgres.xml \
  tests/Feature/UsdtBalanceCheckoutPostgresConcurrencyTest.php \
  --filter test_control_writer_fences_both_preferences_before_any_debit

php vendor/bin/phpunit tests/Feature/UsdtBalanceCheckoutTest.php \
  tests/Feature/OrderCartDomainTest.php tests/Feature/UsdtOrderRefundTest.php \
  tests/Feature/ProductResellerPricingTest.php \
  tests/Feature/Telegram/UsdtBalanceCheckoutTest.php \
  tests/Feature/Telegram/CartCheckoutTest.php \
  tests/Feature/CheckoutMaintenanceTest.php tests/Feature/BuyMailFulfillmentTest.php
```

The first focused SQLite run passed 103 tests / 1,224 assertions; the final
expanded checkout/Telegram/maintenance/supplier batch passed **158 tests /
1,702 assertions**. New value/schema files passed Pint; touched existing import
lists passed scoped formatting without restyling unrelated code. Standalone
runtime scripts used the actual service/lifecycle rather than PHPUnit test
methods; their opening credits and transport/queue restrictions were disposable
fixtures. No live money, provider, Telegram, CI/CD, production access, activation,
commit or push occurred.

After verification, the standalone smoke/profile scripts, temporary formatter
configuration, disposable PostgreSQL container and its volume were removed.

## S06 API order path (#623)

Implementation update, 2026-10-16. [#623](https://github.com/Alhiefikri/telegram-bot/issues/623)
implements [#617 sections 4.2, 5, 5.1 and 6](https://github.com/Alhiefikri/telegram-bot/issues/617)
on top of the S03 checkout, S04 admission/revalidation and S05 reads. It activates
no funding, custody, provider or production deployment. Design:
[ADR-0025](adr/0025-api-order-idempotency-claims.md); contract:
[Customer API orders](customer-api-orders.md).

### Added lock edges

`App\Services\CustomerApi\CustomerApiOrderCheckout::place` composes one deterministic
prefix in front of the unchanged S03 chain:

| Flow | Acquisition order and ownership |
| --- | --- |
| API order (`POST /api/v1/orders`) | Initial unique claim insertion commits separately. Financial transaction: Customer shared → shared credential row (`CustomerApiPrincipal::revalidateForMutation`) → claim row FOR UPDATE plus token check → newly inserted isolated API Cart + item → S03 chain (IDR before USDT, funding policy shared, restore shared, maintenance, CartItems by Product, Products by ID, Suppliers by ID, SKUs by ID, payment setting) → Order/Payment/items → paid lifecycle, debit and allocation. Claim lock remains held through commit. |
| API replay / lost response | claim read only, then the canonical accepted Order; no money, Cart, Product or credential lock is acquired, and current stock and price are never consulted. |
| API claim takeover | one atomic conditional `UPDATE` on an expired lease of the claim row; the loser answers 409 `in_progress` without acquiring financial locks. |

A worker that lost its claim token cannot enter checkout. A live financial transaction
holds the claim row, so expiry alone cannot authorize a competing commit; takeover
serializes against that transaction. Acceptance rejects a stale token rather than
silently leaving an unaccepted paid Order.

`CustomerApiCredentials::rotate/disable` still take Customer exclusive then the credential
row, and `CartService` still takes Customer before Cart, so no path acquires Customer (or
the credential row) after the shared checkout prefix, and the credential shared row is
retained through commit. The API Cart is inserted inside the checkout transaction with
`carts.origin = api`; `CartService`, the Telegram cancel writes and the storefront cart
sources all filter `origin = customer`, so an API cart can never be the Customer's
Telegram/Web Cart, including when `checkoutOnce` flips a `checked_out` cart back to
`active` for a cancelled Order. Money and stock remain exclusively owned by
`OrderCheckoutService`, `StoreBalanceLedger`, `CustomerUsdtBalanceLedger`,
`EloquentInventoryLedger` and `ProductCredentialAllocator`; the claim layer writes no
money and adds no second checkout.

### Superseded note

The historical "Cart → IDR account" and "Existing Cart → maintenance" prefixes above still
describe the Telegram/Web rails. The API rail owns its own isolated cart and never reaches a
customer cart.

### Verification evidence (#623)

See [Customer API orders](customer-api-orders.md) for the recorded SQLite, PostgreSQL and
disposable HTTP results. `migrate:fresh` drops tables but not the PL/pgSQL guard functions a
migration installs, and the shipped S04 credential migration created its
`guard_customer_api_credential()` function with a bare `CREATE FUNCTION`, so a second fresh
migration on a reused PostgreSQL database failed with `SQLSTATE[42723] Duplicate function`
(observed on `main`: 16/16 errors in `UsdtBalanceCheckoutPostgresConcurrencyTest`). Both that
migration and this ticket's `2026_10_16_130000_create_customer_api_order_claims.php` now use
`CREATE OR REPLACE FUNCTION`, matching the S03 convention; the shared test helper was left
unchanged.


## S11 FK-compatible Product locks and sold-out handoff (#628)

The S06 API Cart item insert takes an implicit PostgreSQL Product `KEY SHARE`
lock before the canonical checkout chain. Two different Customers can both
hold that FK lock: `Product FOR UPDATE` after maintenance then forms a cycle
with the other request waiting for maintenance. The integrated 80-attempt
HTTP workload observed 237 deadlocks and 79 unavailable responses before the
correction; a deterministic two-Customer FK barrier also failed before it.

Checkout inventory, inventory lifecycle and supplier lifecycle Product locks
now use `FOR NO KEY UPDATE` on PostgreSQL. This excludes concurrent stock/price
writers and deletion/key changes while remaining compatible with FK checks.
The Customer/credential/claim/wallet/policy/restore/maintenance/resource order
is unchanged. Other database drivers retain the original update lock.
Nested paid/supplier paths must not upgrade these Product locks back to
`FOR UPDATE` while another isolated API Cart can hold its FK lock.

Normal and late sold-out sales now register the existing durable
`StockNotificationEvent` instead of expanding all recipients in the financial
transaction. Aftercommit dispatch and the existing stock recovery facet
perform bounded recipient work; recipient-rendering failure cannot roll back
money. This also removes the eager recipient-FK edge described in the
historical S03 deadlock observations above, without claiming that every
unrelated lock graph is deadlock-free.

Current commands, before/after observations, PostgreSQL/read performance,
crash/queue loss and operator limitations:
[S11 integrated acceptance](customer-api-s11-acceptance.md).

