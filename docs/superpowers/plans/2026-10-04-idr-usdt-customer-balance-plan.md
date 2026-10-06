# Plan implementasi: IDR additions dan Customer USDT Balance

Issue canonical: [#536](https://github.com/Alhiefikri/telegram-bot/issues/536).

[Tiket implementasi dan dependency](2026-10-04-idr-usdt-customer-balance-tickets.md) — 26 child tickets; keputusan operasional/custody dan aktivasi tetap gated.

Tanggal: 2026-10-04. Status: **Draft; deliverable sesi ini plan/spec, bukan implementasi fitur**.

Referensi: [spec](../specs/2026-10-04-idr-usdt-customer-balance-design.md), [riset](../../research/2026-10-04-idr-usdt-wallet-sources.md), [implementation map](../../agents/codebase.md), [ADR IDR ledger](../../adr/0008-store-balance-ledger.md), [ADR rail funding](../../adr/0010-store-balance-top-up-payment-rails.md), [ADR late settlement](../../adr/0011-store-balance-late-settlement-and-owner-alerting.md). Setiap work package menjadi issue implementasi setelah draft disetujui; tidak membuka task ready-for-agent ketika ownership/provider gate belum terjawab.

## 1. Peta hasil dan urutan dependensi

```text
P0 baseline + keputusan produk + legal/provider readiness
 ├─ P1 receipt registry/backfill/cutover ──────────────┐
 ├─ P2 IDR adjustment + approval ── IDR canary        │
 └─ P3 USDT ledger/accounting/policy ── P4 intent UX  │
                                      └─ P5 USDT rail/ownership
P1 + P3 + P4 + P5 ── P6 settlement/recovery
P3 + P6 ── P7 USDT checkout/refund
P2 + P6 + P7 ── P8 admin/customer reporting/ops
P1..P8 ── P9 security/race/fault/performance evidence ── P10 canary
```

P1 dan P2 terhubung ketika IDR adjustment menggunakan receipt external; promotional grant tidak meniru receipt. Tidak ada top-up USDT sebelum semua writer checkout menggunakan registry. P0 keputusan yang belum bisa dibuktikan tidak menghalangi desain/ledger scaffolding, tetapi menghalangi deposit publik dan klaim provider-ready.

## 2. Work packages dan commit kecil

### P0 — Baseline, domain dan architecture gates

Baca source bukan hanya docs: `StoreBalanceLedger`, `StoreBalanceTopUpService`, `StoreBalanceOwnerCompensationService`, `StoreBalanceFundingIntentService`, `OrderCheckoutService`, `OrderLifecycle`, `CheckoutPaymentPreparation`, supplier/refund ownership, Binance/BSC inspection dan evidence migrations, Customer authentication serta Telegram outbound protocol. Catat active provider/readiness berdasarkan staging tanpa mengambil secrets; produksi tidak diaudit/mutasi dalam sesi planning ini.

Deliverable:

1. ADR proposed untuk saldo USDT terpisah, money precision, receipt/alias ownership, treasury accounting, dan non-withdrawal terms; update glossary/implementation map setelah accepted.
2. Lock graph seluruh jalur nyata (checkout, supplier wallet, full/partial refund, compensation, settings, registry) dan kontrak API owner. Cari jalur terbalik sebelum menambah lock.
3. Decision record custody/receiver provisioning, supported token contract/issuer/bridge, provider terms/customer funds, RPC finality, actor/customer assurance, approval staffing, limits/presets/fee/exposure caps, beta allowlist. Nilai bisnis disimpan DB/Admin.
4. Baseline staging query counts, ledger size/mutation cost, available VPS CPU/RAM/DB connections dan provider request budgets; tidak memakai data customer mentah dalam report.

Exit: reviewer dapat membedakan fixed invariants dari keputusan terbuka, dan tiap unresolved gate punya owner/evidence yang diminta. Estimasi baru ditetapkan setelah P0/P5 spike; ukuran fase bukan janji durasi.

### P1 — Global receipt claim dan compatibility cutover

Commit 1: additive registry + parent BSC transaction claims + alias schema, unique indexes/constraints, no mutation callers changed. Canonicalization tested dengan network/account namespace yang stabil saat credential rotation.

Commit 2: shared claim service immutable `VerifiedMoneyReceipt`/`ClaimOutcome` (`claimed`, `replayed`, `conflict`, `review`); payload fingerprint includes beneficiary, currency, amount, target dan immutable evidence. Semua SQL unique conflict recovery aman terhadap aborted transactions.

Commit 3: bounded dry-run/backfill checkpoint untuk Binance Payment references, BSC settled evidence dan legacy pending reservations/compensations; verify no duplicate/ambiguous identities, aliases capital deposit vs on-chain tidak double-funded. Tidak mengubah status lama atau nominal historis. Registry denylist/reservations menyimpan bukti legacy yang tidak bisa dinormalisasi sampai review.

Commit 4: route checkout confirmation ke registry atomik dengan paid lifecycle; enforce target version and backwards-compatible lookup. Shared BSC parent tx claim mempertahankan konservatisme legacy, walau logs indexed terpisah. QRIS authoritative receipts/manual funded additions ikut registry dengan namespace provider benar.

Commit 5: staged cutover report membuktikan semua writer lama/baru menggunakan registry dan backfill lengkap; simulasikan mixed-version deploy, disable USDT selama old worker aktif, drain/restart worker sesuai existing deployment. Jangan bergantung pada dual-write tanpa atomic ownership.

Exit: satu receipt dari proses bersaing atau dua adapter tidak bisa mendanai dua target; race checkout/top-up future fixture terbukti PostgreSQL. Legacy payment flow dan provider snapshots tetap valid.

### P2 — Admin penambahan IDR yang aman

Commit 1: request/approval schema, enum distinct `admin_adjustment_credit`, reversal reference/cap; extend IDR ledger canonical methods tanpa unrestricted caller `credit` atau projection assignment. Append accounting/audit/durable notification dalam transaction yang sama.

Commit 2: permission policies, maker/approver segregation dan fresh-auth approval. Request fingerprint/version, preview, amount/reason/funding category/receipt binding. Same request+key replay; changed payload conflict. Funded receipt masuk registry P1; grant memerlukan economic accounting category.

Commit 3: Admin Dashboard exact numeric Telegram ID search untuk Customer terdaftar (unknown ID tidak auto-create), preview identitas/nominal, dan form/request list/detail/approve/reject, localized feedback, safe exports dan immutable history. Tanpa operator kedua, approval unavailable dengan readiness reason yang jelas.

Commit 4: reversal/recovery dan stale permission/session tests; existing Owner compensation tetap top-up-specific dan tidak menjadi bypass arbitrary addition. Unresolved money credited by compensation harus reserved terhadap settlement receipt replay.

Exit: customer tepat menerima satu amount IDR; perubahan saldo/audit gagal seluruhnya jika insert/outbox error; reject/replay tidak mengubah saldo; no unauthorized operator/customer mutation.

### P3 — USDT account, ledger, exact amount dan accounting

Commit 1: exact money value object/string serializer dan tests; reuse `BinanceUsdtAmount`/`BscTokenAmount` hanya bila contract positif/overflow sesuai input boundary. Installed Brick ^0.18 API diverifikasi; tidak menyalin API main tanpa check.

Commit 2: additive account/ledger/accounting-event schema + FK/positive/nonnegative/unique guards, ledger DB immutability triggers dan runtime role privilege runbook; empty-account initialization race aman.

Commit 3: `CustomerUsdtBalanceLedger` owner dengan `snapshot`, `lockForTransaction`, `creditVerifiedTopUp`, `debitPurchase`, `refundEntitlement`, `reverseApprovedEntry`; typed economic source, currency, amount, idempotency/fingerprint. Arbitrary public credit API dilarang. Projection/cursor version diperbarui atomik; no HTTP di closure.

Commit 4: per-currency liability/treasury reconciliation bounded dan sanitized report; accounting event source unique; UI unavailable/frozen state. No silent repair atau full-history aggregate setiap read.

Exit: feature + PostgreSQL races menguji sum/sequence/projection, lost update, insufficient funds, replay mismatch, DB UPDATE/DELETE denied, overflow, rollback dan separation dari IDR.

### P4 — USDT policy, confirmation dan UX

Commit 1: versioned DB/Admin policy (min/max/presets, expiry, network, fee, caps, pause, rollout allowlist), strict validation against operational rail readiness. Nonsecret content bukan ENV.

Commit 2: Customer-owned intent + callback/nonce binding ke chat/root/currency/version/expiry; one confirmation creates one top-up; stale version cancels safely. Atomic cap check per customer untuk open requests/deposits; treasury exposure gate authoritative tidak hanya Redis throttle.

Commit 3: Telegram balance screen, currency picker, preset/custom input, preview/confirm/cancel, safe status and history. Reuse canonical navigation root dan Telegram Outbound Effect family protocol, tidak membuat send path kedua.

Exit: valid confirmation baru punya provider work; segala invalid/stale/public-group/replayed callback tidak menambah target, provider request, atau ledger. Localization id/en/ms mengikuti repo.

### P5 — Rail spike, custody dan immutable invoice attribution

Commit 1: `UsdtTopUpRail` contract: readiness, create/recover same reference, authoritative inspect dan immutable snapshot. Extract pure BSC inspection dari Order-bound verification; adapter tidak memanggil order paid lifecycle ketika funding balance.

Commit 2: exclusive receiver provisioning dan permanent customer mapping + verified custody proof. Global receiver registry melarang cross-table reuse oleh checkout/top-up. Address tidak pernah direassign, tidak dibagi dengan checkout; capacity exhaustion = unavailable. No seed/private key/signing in app. Jika custody provider belum dipilih, provisioning interface dan contract fake saja; activation blocked.

Commit 3: BSC chain/token/decimals/readiness/finality validation, bounded RPC log ingestion dengan durable checkpoints, canonical block tracking/reorg recovery. Exact Transfer receipt dikaitkan ke bound customer intent; attacker submission tidak mengalihkan beneficiary.

Commit 4 opsional: Binance Pay top-up bila authoritative reference/payer ownership binding dan merchant eligibility terbukti melalui kontrak provider resmi yang benar. Existing C2C `/sapi/v1/pay/transactions` tidak disamakan dengan Merchant webhook/Capital deposits. Kalau tidak terbukti, disable rail dengan alasan; jangan menyatakan USDT funding seluruhnya selesai lewat rail placeholder.

Exit: staging contract proof untuk BSC primary rail, authoritative attribution/finality, actual token metadata/custody capacity, timeout create recovery dan provider policy. Network fee/amount/receiver presentation tervalidasi.

### P6 — Settlement, review, late money dan crash recovery

Commit 1: durable inbox/work state + one top-up resolution identity; settle account/target/receipt/accounting/audit/outbound atomik. Evidence version recheck setelah HTTP; lost lease result tidak dapat overwrite settlement baru.

Commit 2: periodic reconciliation dan expiry sweep chunk/checkpoint/fairness/backoff. Redis dispatch optimization, PostgreSQL recovery authority; notification materialization lewat existing outbound seam, ambiguous Telegram delivery mengikuti Manual Delivery Review.

Commit 3: operator review wrong amount/multiple transfers/sub-micro/unknown invoice/creation unknown, late valid money, compensation dan external-refund evidence. Revised resolution tidak mengubah original invoice/evidence. Nonrepresentable difference tercatat unresolved treasury, bukan rounding silent.

Commit 4: reorg incident/freeze protocol dan treasury coverage evidence. Reorg setelah spend tidak otomatis debit user atau delete entry; terapkan authorized recovery case.

Exit: callback/poll/expiry/compensation/operator race menghasilkan satu effect; crash di setiap cutpoint dan Redis flush tidak menghilangkan dana atau menggandakan effect. Review/outstanding money observable.

### P7 — Checkout dan refund memakai saldo USDT

Commit 1: internal `UsdtBalanceCheckoutIntent`/payment preparation; preflight tanpa lock, prepare tanpa external I/O; eligibility actual USDT price/tier snapshot. Pilih currency account sebelum stock/supplier locks, sesuai P0 lock graph.

Commit 2: debit currency USDT + Order/Payment/inventory/lifecycle/outbound transaction; return replayed order untuk checkout key yang sama, insufficient tidak meninggalkan paid order/reservation. Sales/invoice/reporting menggunakan currency/units benar.

Commit 3: refund penuh/parsial untuk verified entitlement terhubung original USDT debit, warranty dan missing fulfillment; cumulative cap termasuk external/internal refund cases. Tidak memakai IDR-only refund seam tanpa currency validation.

Commit 4: customer confirmation/receipt/history yang memperjelas USDT spend/refund, termasuk order-linked entry; zero external provider payment creation ketika rail internal dipilih.

Exit: competing orders tidak overspend; lost request response replay tidak double debit; partial/full/refund/fulfillment races tidak overrefund/duplicate sale. Existing IDR, external Binance/BSC checkout dan supplier invariants lolos regression.

### P8 — Admin, customer web dan operational controls

Commit 1: Admin per-currency saldo/entry/top-up/review/treasury reports, filters/keyset pagination, state badges, reason/audit/reference masked. Customer web read-only perlu authenticated account identity; guest/recovery token order tidak bisa melihat wallet.

Commit 2: DB-managed funding/spend/rail pauses + safe operational readiness UI, metrics cardinality bounded, alerting untuk mismatches/collisions/stuck work/custody coverage. Reconcile uang masuk berjalan ketika funding pause.

Commit 3: incident/runbook restore/PITR, outbox/reconcile recovery, operator review, receiver/token rotation immutable snapshots, permission/secret rotation, nonnegative-safe reversal dan additive rollback. Deployment mengikuti CI GitHub Actions main/VPS, bukan Railway.

Exit: operator dapat menelusuri economic receipt -> top-up -> ledger -> balance -> accounting -> outbound tanpa membuka secrets; customer ownership dan browser responsive/accessibility lulus.

### P9 — Verification evidence, bukan checklist deklaratif

Jalankan suites feature/unit yang relevan, PostgreSQL disposable multiprocess, contract scenarios, fault injection dan performance profile di bawah. Simpan report dengan commit SHA, stack versions, seed/data shape, concurrency/process count, provider/RPC fake vs real distinction dan hasil numerik. Tidak memakai production DB untuk destructive race tests. Satu failed invariant/missing custody proof memblokir funding activation.

### P10 — Rollout dan rollback

1. Expand schema + immutable ledger/registry protection; backfill/report dry run dan indexed query plan sebelum public UI.
2. IDR adjustment canary dual-operator untuk allowlist; balance/receipt/audit/outbox reconciliation nol drift.
3. USDT ledger/UI read-only, semua writer registry cutover selesai, primary rail readiness serta provider/legal/custody gates passed.
4. USDT small-value funding canary dengan dedicated customer receivers dan daily treasury/ledger reconciliation; real finalized successful deposit + failed/wrong/late cases documented.
5. USDT spend/refund canary setelah debit/refund gates passed; scoped rollout per Customer, increasing exposure berdasarkan evidence. Funding canary boleh mendahului spend hanya dengan terms dan review handling eksplisit, bukan dianggap final feature completion.
6. Public enable setelah P1–P9 pass, canary sehat minimal 7 hari usulan, sign-off operator dan keputusan bisnis selesai.

Rollback: pause new funding/spend via DB sesuai risk; pertahankan old deposit reconcile/refund/review, jangan delete ledger/claims atau rollback migration uang. Jika kode lama tidak memahami currency/registry, jangan deploy rollback yang membuka writer lama; gunakan compatibility release. Redis restart bukan alasan recreate invoices. Restore freeze lalu external history replay/reconciliation sebelum mutation reopen.

## 3. Kontrak service dan adapter

```text
CustomerBalanceReader(customerActor) -> {IDR snapshot, USDT snapshot}
BalanceAdjustmentService.request(actor, customer, amount, category, reason, nonce)
BalanceAdjustmentService.approve(approver, requestId, expectedVersion)
UsdtFundingIntentService.open/confirm/cancel(customerActor, normalizedInput, nonce)
UsdtTopUpRail.createOrRecover(topUpSnapshot) -> Payable | Unknown | Unavailable
UsdtTopUpRail.inspect(topUpSnapshot) -> VerifiedMoneyReceipt | Pending | Review
ExternalMoneyReceiptClaims.claim(verifiedReceipt, economicTarget) -> ClaimOutcome
CustomerUsdtBalanceLedger.*(authorizedEconomicSource, exactAmount, key)
UsdtTopUpSettlement.settle(topUpId, evidenceVersion, verifiedReceipt)
CustomerUsdtBalanceReconciliation.check(scope, cursor, limit) -> report
```

Semua mutation signature implementation wajib menerima authority/economic source yang tervalidasi, bukan `customer_id + amount` dari controller sebagai izin kredit. Controller/job/Telegram callback hanya adapter. Ledger tidak tahu JSON provider, Telegram chat atau custody credentials. Rail tidak menulis projection/Order paid. Outbound ledger tetap pemilik delivery idempotency tunggal.

## 4. Test matrix yang memetakan spec

| Requirement | Evidence/test boundary wajib | Gate |
| --- | --- | --- |
| I1/I3/I11, exact currency | amount parser boundaries, uint256/18 decimals -> micro exact, overflow, strict JSON string, DB checks, IDR untouched | P3 |
| I2/I4, ledger authority | transaction rollback tiap write, immutable ledger DB denial, projection/cursor mismatch fail-closed, accounting/outbound atomicity | P2/P3/P6 |
| I5, replay | same key same payload; key/source with amount/customer/currency berbeda konflik; approval/confirmation duplicate | P2/P3/P4 |
| I6, receipt globally unique | checkout/topup/adjustment same tx, Binance alias/chain alias, parent tx multi-log legacy, credential rotation namespaces | P1/P6 |
| I7, snapshots | switch provider/token/receiver/fee/finality/policy setelah intent/topup; existing target tidak berubah | P4/P5 |
| I8, refund/reversal | full+partial+warranty/missing entitlement races, original debit/customer/currency check, capped reversal, spent balance review | P2/P7 |
| I9, durable workflows | crash before/after provider response/commit/dispatch/ACK, Redis wipe, notification failure, stale lease/attempt result | P6 |
| I10, trust | projection drift/latest cursor drift/accounting mismatch, freeze effect, read unavailable, scoped reconciliation | P3/P8 |
| I12, money authenticity | wrong chain/token/receiver/customer, failed receipt, public stolen hash, timestamp window, insufficient finality, invalid/replayed signature, incomplete history | P5/P6 |
| Auth/admin/web | IDOR read/mutate/history/cursor, group privacy, nonce reuse cross-actor, revoked permissions, maker=self approver, expired auth, CSRF, recovery token denied | P2/P4/P8 |
| Race correctness | independent DB processes: two debits last funds; parallel credits; empty account; approve+approve; poll+callback+expiry+compensation; deadlock retry closure | P9 |
| Performance | representative dataset, query plan/index, hot vs distinct accounts, bounded history/ingestion, provider outage and backlog recovery, no global row hotspot | P9 |

Test placement usulan: `tests/Feature/CustomerUsdtBalanceLedgerTest.php`, `CustomerUsdtBalancePostgresConcurrencyTest.php`, `ExternalMoneyReceiptClaimTest.php`, `ExternalMoneyReceiptPostgresConcurrencyTest.php`, `AdminBalanceAdjustmentTest.php`, `UsdtTopUpSettlementTest.php`, `UsdtBalanceCheckoutRefundTest.php`, Telegram/web ownership tests dan provider/RPC contract tests. Extend shared test fixtures; hindari assertions yang cuma menyalin implementation.

Command verifikasi implementasi mengikuti repo:

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

Selected tests selama commit, full applicable gates sebelum release. Concurrency gate pada PostgreSQL disposable; browser/build jika UI berubah. Tambahkan evidence checks `usdt-balance:check`, receipt completeness dan treasury reconciliation melalui command baru yang dibatasi/readonly; command names/exit semantics difinalkan saat implementation. Performance workload spec dijalankan dengan script/report khusus setelah P0, bukan memasukkan benchmark noisy dalam unit suite.

## 5. Definition of Done dan no-go

Plan/spec selesai ketika baseline current repo tercatat, scope IDR admin additions dan USDT stored/top-up/spend/refund jelas, Context7 + primary-source research tercantum, schema/state/ownership/locking/security/performance/ops/test strategy serta tahap implementasi dapat direview; belum mensyaratkan build fitur.

Implementasi **belum** selesai jika hanya UI saldo, hanya IDR, hanya external Binance checkout, atau USDT nominal dikonversi otomatis ke IDR. Public funding **no-go** bila attribution receiver/provider belum terbukti, global claim writers/backfill belum lengkap, token/finality/custody/legal permission belum jelas, ledger/receipt/treasury mismatch ada, concurrency/fault evidence gagal, atau backlog tidak terkendali.

Risk highest priority: public hash stealing, checkout/topup double funding, supplier/refund lock inversion, provider create ambiguity, DB->Redis delivery gap, historical ledger direct writes, reorg after spending, custody backing deficit, serta unbounded provider history polling. Masing-masing memiliki preventive guard, deterministic test dan runbook outcome di spec; tidak ditutup dengan klaim “best practice” saja.
