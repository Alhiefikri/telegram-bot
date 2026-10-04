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

The [source audit and lock graph](../balance-a0-baseline.md) maps actual money writers, nested checkout/lifecycle/supplier/warranty/refund/compensation callers and I1–I12 gaps. Its [lock-site appendix](../balance-a0-lock-sites.md) names each explicit acquisition by file/symbol. [ADR-0019](../adr/0019-idr-usdt-economic-sources-and-receipt-claims.md) is **Proposed**, not accepted: it defines typed economic sources, additive USDT ledger, global receipt aliases/parent transaction claims, counterpart accounting and durable work. Runtime ownership below remains current; these documents do not introduce or activate new services.

Start downstream work from the audit's per-flow lock graph and remediation register, including event-first lifecycle entry and historical Supplier/SKU resource unions. Preserve the IDR ledger/provider history, prepare without network inside locks, and reuse Telegram Outbound Effect recovery. H0/H1/A1/H2 own operational policy, custody/provider qualification and production sign-off. A1's [capability report](../balance-a1-bsc-capability.md) records the BSC chain/token/decimals, `finalized`/canonical-receipt/log capability classification (`verified`/`unsupported`/`unknown`, fail closed), Binance C2C funding decision, and immutable fee/network/custody snapshot contract; a qualified RPC probe is not funding, custody, or legal authorization. Admin IDR adjustment lookup is exact numeric Telegram ID for an already registered Customer, with identity/amount preview and server revalidation; username does not identify the recipient and unknown IDs never create Customers.

## Configuration boundary (current runtime)

The [H1 custody decision](../balance-h1-custody-decision.md) and [ADR-0021](../adr/0021-usdt-exclusive-external-receivers.md) select permanently Customer-exclusive public receivers with external signing for the BSC funding design. Receiver capacity/control, token/bridge provenance, legal/provider qualification, treasury/gas and external returns remain unproven; #539 remains open and activation no-go. Binance Pay funding is separately unqualified. These decisions do not activate or change existing checkout.

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

Queue Redis bersifat volatile: restart Redis dapat menghilangkan queued jobs dan session/cache/lock state. Startup menolak cutover selama tabel database queue berisi job; rollback ke driver database menolak berpindah selama queue Redis tidak kosong atau tidak dapat diperiksa. Lihat [ADR 0014](../adr/0014-redis-runtime-state.md) dan [runbook cutover](../runbook-redis-runtime-cutover.md).

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

### Product dan stock

`Product` adalah katalog yang dapat aktif/nonaktif, dapat berada dalam `Category`, dan punya `stock` serta `reserved_stock` untuk inventori lokal. `localAvailableStock()` membatasi numeric stock dengan credential lokal yang belum dialokasikan; credential hasil pembelian supplier yang sudah order-owned tidak ikut pool lokal. `availableStock()` menambahkan proyeksi `supplier_available_stock` hanya sampai `supplier_available_until`.

Gunakan `Product::publicVisible()`, `availableForPurchase()`, `withAvailability()`, dan `availableStock()` daripada mengulang query visibility/stock di caller. Perubahan stok lokal direkam lewat `InventoryLedger`/`EloquentInventoryLedger` dan menghasilkan `InventoryMovement` bertipe `reservation`, `sale`, `release`, atau `adjustment`. Tipe `availability` adalah sinyal transisi kapasitas eksternal untuk pipeline restock, bukan Stock Intake atau mutasi stok lokal.

`ProductStockIntake` adalah pemilik canonical untuk penambahan positif melalui **Input Cepat** dan **Input Akun**. Ia menormalkan seluruh batch sebelum transaksi, mengunci Product, mengklaim idempotency key dan `CredentialIdentity`, membuat `ProductStockItem`, menaikkan numeric stock, membuat satu adjustment movement/audit, lalu menyerahkan restock notification setelah commit. `StockNotificationDeliveryPipeline` mematerialisasi teks recipient sebelum bounded delivery batches; untuk adjustment ia menyimpan tier terendah yang baru reachable pada `StockNotificationEvent`, sehingga retry/recovery tidak membaca ulang Product/tier yang sudah berubah. Controller `AdminProductStockController` dan `AdminProductAccountStockController` hanya adapter; mereka tidak boleh mengubah stock atau membuat identity secara langsung.
`ProductStockItemManager::deleteAvailableBatch` owns manual credential deletion (single deletion delegates to it). Bulk deletion accepts 1–100 distinct IDs, locks Product then Available items in ID order, and rejects the whole selection if any item is stale, allocated, retired, missing, or belongs to another Product. It reads only IDs, records one adjustment/audit with IDs and counts (never credentials), retains credential identity history, and aggregates the full deleted quantity after commit. The Admin checklist selects only the bounded currently displayed Available items and reloads the open modal after deletion. It locks Product and the Available item, creates a negative `adjustment` movement plus audit row, records the `available > 0 → 0` transition, and only after commit records the deletion in the 20-second per-Product Fake Sales Proof aggregation window. The aggregation job runs on `telegram-stock`; it never sends Telegram while the stock transaction is open. Sold-out and restock fan-out both use `StockNotificationDeliveryPipeline`, which sends the Store Channel copy plus every eligible Telegram Customer without a channel-membership gate. A sold-out customer delivery is localized and has no checkout button; a stale sold-out event is finalized without sending after restock.

Stock Notification delivery claims up to 25 due recipient effects per job, reserves the same `TelegramBroadcastRateLimiter` budget as Admin Broadcast, and sends requests concurrently with a 25-request ceiling. A limiter miss leaves effects unclaimed and delays the continuation; `telegram:recover-outbound` routes stock source discovery through `StockNotificationDeliveryPipeline::reconcile()` and excludes stock recipient effects from its generic due-effect sweep. Family-specific discovery passes `effectType` to the ledger policy so non-receipt scans never evaluate receipt predecessor subqueries. PostgreSQL partial dispatch and recipient cursor indexes keep terminal HTTP 400/403 history out of dispatch scans; they are built concurrently outside migration transactions. The dedicated stock reconciliation runs in the background with a 45-minute overlap mutex.

`CredentialIdentity` menyimpan fingerprint HMAC keyed untuk identity yang memang tidak boleh dipakai ulang, saat ini hanya Auth Token eksplisit; email tetap menjadi data akun biasa dan tidak lagi diklaim sebagai identity registry. Fingerprint bukan plaintext. `CredentialIdentityFingerprint` memakai override ENV opsional atau kunci acak persisten di `storage/app/private`; `BackfillCredentialIdentityRegistry` memproses retained Product Stock Items secara bounded dan hanya melaporkan row IDs/counts, lalu menyimpan readiness singleton yang dikunci ke digest fingerprint key hanya setelah full commit tanpa unresolved row. `CredentialIdentityBackfillReadinessService` adalah runtime gate untuk Input Akun; deployment otomatis menjalankan backfill setelah migration, dan `credentials:check-identity-readiness` adalah deployment check. Tidak ada environment feature switch untuk menyalakan atau mematikan Input Akun.

### Supplier acquisition

BuyMail extends the supplier boundary through `BuyMailClient` (fixed-origin Bearer auth, whole-IDR profile, account-unit delivery, terminal partial quantities, idempotent purchase/reference recovery, multipart warranty proof and supplier deposits). `SupplierAccountUnit` retains private account identity, deadline and immutable per-unit retail entitlement. `App\Services\Warranty` owns per-account claims, explicit confirmation, separate buyer refund/replacement settlement and localized private access. `OrderRefund` registers an immutable partial entitlement; only StoreBalanceLedger moves Customer Store Balance and it caps cumulative partial credits against the original debit. Full-order refunds credit only the remaining amount. A full external refund with registered partial refunds records the unclaimed remainder as one confirmed `OrderRefund` from the same operator transfer evidence before the full-refund transition; pending registered refunds must be confirmed or recovered first. See [ADR-0017](../adr/0017-per-account-warranty-and-partial-refunds.md), [ADR-0018](../adr/0018-operator-confirmed-remainder-refunds.md) and [BuyMail runbook](../runbook-buymail.md).

Slow acquisition/replacement uses the `supplier` connection, `suppliers` queue and its dedicated worker; urgent new proof uploads use `supplier-refunds`, prioritized before `supplier-claims` for reads/deposits/webhooks/confirmation. `suppliers:recover-warranty` rediscovers due PostgreSQL obligations after Redis loss. Signed webhook inbox is a wakeup for authoritative GET, not refund approval or Customer credit authority. Warranty status notices and the once-per-unit buyer reminder 30 minutes before `refund_deadline` use the existing Telegram outbound ledger and ambiguity policy; `suppliers:notify-warranty-deadlines` materializes the reminder from durable unit state (skipping retired, confirmed, claimed, unclaimable, or expired units, with delivery re-checks) and the recovery command re-dispatches it. MissingFulfillmentRefundService registers only definitively missing quantity; it never treats uncertain supplier outcomes as refundable missing units.

[`SupplierManager`](../../src/app/Services/Suppliers/SupplierManager.php) owns private connections, encrypted API keys, optimistic versions and account-change guards. [`ProductSupplierManager`](../../src/app/Services/Suppliers/ProductSupplierManager.php) owns equivalent-SKU mapping, ranked moves and safe detach (Product-first serialization, optimistic identities/versions, bounded decryption of Product-scoped retained snapshots, all purchase history guarded, atomic audit, after-commit availability refresh), inactive store-owned draft import and Product pricing policies. These do not replace Product identity or Customer Store Balance.

[`SupplierClient`](../../src/app/Services/Suppliers/SupplierClient.php) normalizes seven fixed HTTPS provider contracts (including NyStore Official and ToolOraX/ShopBot), decimal economic tokens, bounded response bodies, rate budgets and safe rejection versus ambiguity. No supplier HTTP runs inside financial transactions. [`SupplierCatalogSync`](../../src/app/Services/Suppliers/SupplierCatalogSync.php) claims a durable read lease, fetches outside locks, then validates generation/mapping and applies observations under ordered Product → Supplier → Catalog locks. Failed reads retain prior observations; expired observations cannot authorize checkout.

[`SupplierAvailability`](../../src/app/Services/Suppliers/SupplierAvailability.php) projects stock/balance/commitment/profit-bounded capacity into Product for constant-query customer catalog reads. It hands genuine unavailable-to-available transitions to existing Telegram/Store Channel and Web waitlist pipelines. [`SupplierPricing`](../../src/app/Services/Suppliers/SupplierPricing.php) owns integer manual/percent/fixed/hybrid rules; retail tiers and immutable Order Item commercial snapshots remain authoritative.

[`SupplierFulfillmentService`](../../src/app/Services/Suppliers/SupplierFulfillmentService.php) reserves external wallet/SKU commitments during checkout, buys only after verified payment, and finalizes under the same Order/resource locks as refund. All candidate wallets/catalog rows are prelocked in ascending ID order across the whole cart, not per-item supplier order. A persisted attempt/token/reference survives queue loss. Trusted known IDs recover by GET; non-idempotent ambiguous POSTs never buy again or switch supplier, and local replay rejection never resolves original ambiguity. Correlated exact identity and recognized status gate economic evidence independently from distinct complete delivery. Encrypted `trusted_delivery_payload` cannot be populated from untrusted raw observations; metadata-only/subset recovery retains stronger acquired evidence, while conflicting sets require review. Manual replacement preserves an unknown original debit, but immediately settles one already proven and committed.

`OrderItem.local_quantity`/`supplier_quantity` separate reservation ownership. Local first; mixed Orders stay Paid/Processing until every external unit is delivered. Completion then uses existing receipt, guest notification and Sales Proof Post seams. Supplier credentials are order-owned `ProductStockItem` rows with unique purchase/unit identity; refund permanently retires them, never restocks them. Public Product/Order Item/credential serialization hides eager-loaded source relationships and economic metadata.

The scheduler launches `suppliers:sync --limit=5` and `suppliers:recover --limit=25` before payment/notification sweeps, each minute in the background with a two-minute overlap mutex. Sync skips accounts read within the last minute, discovers external balance/stock changes on subsequent ticks, retains the five-minute checkout freshness limit, and uses attempted-at fairness and durable per-account leases. Commands discover durable PostgreSQL work; Redis jobs are wakeups only. Setup, qualification and reviewed recovery are in the [supplier runbook](../runbook-supplier-api-reseller.md) and [ADR-0016](../adr/0016-private-supplier-fulfillment-and-capacity.md).

[`SupplierHealthService`](../../src/app/Services/Suppliers/SupplierHealthService.php) owns durable low-balance/error/review episodes and safe event snapshots, never transport or purchasing. `suppliers:notify-health --limit=25` runs every fifteen minutes in background with a ten-minute overlap mutex. PostgreSQL cursors fairly rotate bounded connection/obligation discovery; the event, dedupe state, `low_balance_alerted_at` materialization marker and unique outbound effect commit atomically. `SupplierHealthDeliveryPipeline` claims/finalizes through the existing Telegram outbound ledger; unknown delivery is not blindly resent, and generic outbound recovery rediscovers pending work after Redis loss. Sync no longer marks alerts: `balance_observed_at` distinguishes actual wallet observations from catalog-only freshness while unsettled debit blocks wallet refresh. Null/stale balance does not imply zero or recovery; available integer micros <= positive threshold triggers low balance. See the supplier runbook for cadence, private target, redaction and operator recovery.

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
refresh invoice `QRISC` lewat adapter Sakurupiah dan menyimpan payload QR
terenkripsi, dan `ManualStoreBalanceTopUpLifecycle` membuat top-up
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

### Store Channel dan Sales Proof Post

`StoreChannelSetting` menyimpan target Store Channel dan konfigurasi Sales Proof Post. Home `/start` menampilkan CTA channel opsional ketika username/link tersedia; membership channel tidak menjadi syarat akses Store Bot. `SalesProofPost` dipublish setelah payment paid dan inventory sale tercatat; publikasi dilakukan job sehingga OrderLifecycle tidak mengirim pesan Telegram langsung.
The shared public-safe invoice image is owned by
[`PaidInvoiceRenderer`](../../src/app/Services/PaidInvoice/PaidInvoiceRenderer.php)
and accepts only the immutable
[`PaidInvoicePresentation`](../../src/app/Services/PaidInvoice/PaidInvoicePresentation.php).
`PaidInvoiceOrderPresentationAdapter` reads paid Order snapshots and the
canonical paid Payment; its immutable item values include the nullable
`quantity_price_tier_minimum` captured on each Order Item. `PaidInvoiceSyntheticPresentationAdapter`
uses the same `ProductQuantityPricing` quote for manual, automatic, and
deleted-stock Fake Sales Proofs, including the aggregated quantity and the
one-percent provider fee; `SalesProofPostFormatter` renders subtotal, fee when
non-zero, and total after fee. `PaidInvoiceTopUpPresentationAdapter` remains
separate. Buyer receipts and real/fake Store Channel posts use the same
renderer; fake publication never creates an Order, Payment, Sale movement, or
durable Sales Proof effect. Manual stock deletion remains an inventory
adjustment/audit, while its cache-locked 20-second aggregation only controls
the public fake proof.
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
| Store Balance funding policy admin/customer chooser                 | [`StoreBalanceFundingPolicyManager`](../../src/app/Services/StoreBalance/StoreBalanceFundingPolicyManager.php) | controller admin, form, atau callback mempercayai nominal langsung |
| Store Balance top-up provider rail lifecycle                    | [`StoreBalanceTopUpLifecycle`](../../src/app/Services/StoreBalance/TopUp/StoreBalanceTopUpLifecycle.php) yang dipilih [`StoreBalanceTopUpLifecycleResolver`](../../src/app/Services/StoreBalance/TopUp/StoreBalanceTopUpLifecycleResolver.php) | `StoreBalanceTopUpService`, provider resolver, atau adapter Telegram memilih perilaku rail |
| manual Store Balance top-up operator confirmation               | [`ManualStoreBalanceTopUpConfirmationService`](../../src/app/Services/StoreBalance/TopUp/ManualStoreBalanceTopUpConfirmationService.php) | callback Telegram menandai paid/expired atau mengkredit saldo langsung |
| KlikQRIS create/recovery/payability                             | [`KlikQrisNativeQrisService`](../../src/app/Services/KlikQris/KlikQrisNativeQrisService.php)                                                                                                                     | Store Bot/Storefront mengklasifikasi persistence state                  |
| provider status verification                                    | callback processor + provider adapter masing-masing                                                                                                                                                               | callback payload langsung menandai paid                                |
| Telegram outbound effect state dan delivery ledger              | [`TelegramOutboundEffectLedgerProtocol`](../../src/app/Services/Telegram/TelegramOutboundEffectLedgerProtocol.php) dan service materialization-nya                                                                  | checkout caller, queue job, channel adapter                              |
| customer order access                                           | [`StorefrontOrderAccess`](../../src/app/Storefront/StorefrontOrderAccess.php)                                                                                                                                      | controller menyimpan plaintext Recovery Key                             |
| customer-facing copy                                            | Telegram/Storefront presentation adapter                                                                                                                                                                            | domain service mengirim pesan channel                                   |
| paid credential projection                                      | [`CredentialDeliveryPresentation`](../../src/app/Services/Credentials/CredentialDeliveryPresentation.php)                                                                                                         | Telegram/Web adapter parsing encrypted payloads                         |

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
`product:<id>` to skip the Plan list. The Web Storefront family-card list also
displays the Product name. `/start family_<id>` still opens the Plan list.
Committed Order and OrderItem changes invalidate the cached catalog projection.
Product Detail, quantity, payment, Cart, Order, and outbound-effect ownership
remains unchanged.

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
Products by Product Family, renders each family-effective icon (including a
captured Telegram custom emoji), and sends masked HTML links to
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


Catalog icons resolve through [`CatalogIconResolver`](../../src/app/Services/Telegram/CatalogIconResolver.php). Each Product owns an optional Unicode `icon` fallback and an optional captured `custom_emoji_id`; nothing is inferred from a Product name. [`CatalogFamilyIconResolver`](../../src/app/Services/Catalog/CatalogFamilyIconResolver.php) selects one shared family icon from public members in family order and snapshots that selection for family Product detail, quantity prompts, and Order Items. Capture stores Telegram's associated Unicode `emoji` as the fallback, while plain emoji capture stores that glyph directly. The Admin Dashboard may edit the fallback.

Telegram message text and captions render available custom emoji identifiers with `<tg-emoji emoji-id="...">glyph</tg-emoji>`; plain text requests convert those wrappers to custom emoji entities. Converting wrappers must rebase every caller-supplied entity offset that follows a removed wrapper, otherwise tap-to-copy values point at the wrong text. Inline keyboard labels use Unicode fallback text, while navigation buttons may carry `icon_custom_emoji_id`. Product icons remain Product-owned with the Admin Dashboard editing its Unicode fallback, and every live-Product Telegram copy resolves through [`TelegramProductNameRenderer`](../../src/app/Services/Telegram/TelegramProductNameRenderer.php) (`renderProduct`/`renderSelection`) so a variant inherits the family-effective icon. Rejected custom emoji requests are retried once without wrappers or button identifiers. Rejection capability is scoped separately for bot messages and Store Channel posts, so an unsupported channel post cannot disable custom emoji in private bot chats; the Store Channel probe is channel-scoped too, and Telegram silently strips channel custom emoji rather than rejecting them when the bot lacks the Fragment-purchased username entitlement. Every button on the Home inline keyboard sets Telegram Bot API `style=primary` to request blue styling.

A Product Family presents one shared icon in its heading, every Plan row, and each family Product's detail and quantity prompts: the first public member in family order with a captured identifier supplies both values; otherwise the first public member with a glyph supplies it, with `🛍️` as the final default. Order Items snapshot this same effective icon at checkout so receipts and Sales Proof Posts retain the family presentation. Multi-Plan discovery entries use the compact Unicode marker `▸` in their count label; Plan-selection buttons show only the option number, without a separate arrow. When the family has a captured custom emoji, every Plan-selection button also carries that shared identifier. Product Detail returns to the family Plan list whenever the Product belongs to a public family with more than one Plan, including when opened without family navigation (deep link, notification, or single-Plan family entry); `TelegramCatalogIntents::familyNavigationFromProduct()` resolves that family page from the Product itself. A standalone Product keeps its category list.

Shared static-copy icons use `message.*` rows alongside navigation `menu.*` rows in `telegram_ui_icons`. `TelegramTranslationCatalog::translate()` resolves source glyphs in trusted translation templates before interpolation. Hardcoded static prefixes call `TelegramUiIcons::renderLegacyIcons()` only for static copy. Telegram Product-name copy uses `TelegramProductNameRenderer` with Product-owned or immutable family-effective icon data, except catalog Product and Product Family presentation, which stays in `TelegramCatalogIntents`/`CatalogIconResolver` to preserve family icon rules. Other customer, payment, credential, and broadcast values remain separate.
Admins open Product icons and then choose Menu icons or Message text icons; `menuicon:` and `messageicon:` handle capture. `TelegramBotClient` renders plain text/caption wrappers as custom emoji entities, and inline keyboard labels use Unicode fallbacks. Store Channel posts attempt custom emoji when Telegram permits the bot; an unsupported channel falls back to Unicode and its rejection is cached separately from bot-message capability. `catalog:icons-check` audits Product, menu, and message-copy IDs.

Every Telegram copy that names an individual Product uses its Product-owned icon or the immutable family-effective icon selected at checkout; it never borrows a `menu.*` or `message.*` slot. Shared notification/order copies use `TelegramProductNameRenderer`; catalog Product-name rows use `CatalogIconResolver`, while Product Family headings and option labels keep the family icon contract.
Checkout snapshots the effective Product/Family icon fields on `OrderItem`, while price-change events snapshot the Product-owned icon alongside Product name for delayed delivery.

`TelegramCheckoutIntents` adalah adapter checkout Telegram. Ia membaca Payment provider yang sudah tersimpan, memanggil lifecycle provider bila perlu, lalu menerjemahkan hasil menjadi payment instruction. Payment instruction dan outbound send dikerjakan oleh job unique di queue yang sesuai.

Cancellation yang berhasil melalui `OrderLifecycle` mengantrekan `DeleteBuyerCheckoutMessages` setelah commit, bukan menunggu sweep recovery. Cleaner menghapus instruksi checkout yang dilacak untuk semua rail dan pesan detail order terakhir; completion menunggu receipt terkirim sebelum cleanup. Callback hanya menghapus langsung pesan klik yang tidak dilacak, untuk menghindari dua pemilik penghapusan pesan yang sama. Recovery memakai tracking pesan tanpa whitelist provider. Kegagalan verifikasi provider, termasuk HTTP 404 KlikQRIS, tetap fail closed: tidak dianggap bukti unpaid atau cancellation berhasil.
Instruksi hasil resend/recovery juga dihapus melalui ID pada checkout effect berstatus `Sent`, dengan claim penghapusan durable; receipt dan effect non-checkout tidak disentuh. Response checkout yang tiba setelah cancellation mengantrekan cleanup lagi setelah ID tersimpan. Recovery juga menemukan instruksi ledger-only saat projection Order tidak memiliki ID pesan.
Pemeriksaan state setelah pengiriman hanya membaca ID, status Order, dan marker receipt; tidak memuat ulang graph Payment.

Telegram checkout instructions use Telegram `code` entities for tap-to-copy values: the Binance Pay receiver ID and exact USDT amount in both photo captions and text fallbacks, and the exact USDT amount plus snapshotted BSC receiving address in BSC messages. Those entities are best-effort decoration: a value that cannot be located on its own line is skipped so the instruction still reaches the customer as plain text, and `CustomEmojiCapability` rebases the offsets when custom-emoji wrappers are converted.

Untuk delivery durable, [`TelegramOutboundEffectLedgerProtocol`](../../src/app/Services/Telegram/TelegramOutboundEffectLedgerProtocol.php) adalah satu-satunya seam state: claim/lease, token-checked finalization, ambiguity, retry scheduling, terminal failure, dan receipt ordering. [`TelegramOutboundEffectPolicy`](../../src/app/Services/Telegram/TelegramOutboundEffectPolicy.php) memasok limit dan policy family tanpa memindahkan state machine ke adapter. `TelegramOutboundEffectService` hanya mematerialisasi effect, melakukan lookup, dan menyimpan resend history; queue mapping berada di `TelegramOutboundEffectDispatcher`. Delivery/projection adapter per family adalah `CheckoutInstructionDeliveryPipeline`, `ReceiptDeliveryPipeline`, `AdminOrderNotificationDeliveryPipeline`, `ManualPaymentProofDeliveryPipeline`, `SalesProofDeliveryPipeline`, `AdminBroadcastDeliveryPipeline`, `StockNotificationDeliveryPipeline`, `ProductPriceChangeNotificationService`, `BalanceTopUpPaymentProofDeliveryPipeline`, `BalanceTopUpOwnerAlertDeliveryPipeline`, dan `BalanceTopUpChannelProofDeliveryPipeline`. Jangan menambahkan claim/finalization pass-through baru ke service materializer atau job. Boundary lengkap, scope #286, recovery, dan Manual Delivery Review ada di [`telegram-outbound-effect-architecture.md`](../../docs/telegram-outbound-effect-architecture.md); migration/incident runbook ada di [`runbook-telegram-outbound-effect-contract.md`](../../docs/runbook-telegram-outbound-effect-contract.md).

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


`ReceiptDeliveryPipeline` marks only the first successful receipt unit whose transient presentation contains credentials, then asks `TelegramCredentialPinService` to pin that message silently. Pin state is stored on `Order` for idempotency; the outbound-effect recovery command retries only the safe pin side effect and never re-sends credentials. `TelegramOrderIntents` presents up to ten customer-owned successful paid orders (`processing`/`completed`) at a time with deterministic newest-first keyset pagination and compact invoice/status buttons; each selected invoice still resolves through the customer-owned detail query. `/status` adds a bounded latest-item preview, payment/order guidance and deadline, a signed invoice payment link for an unexpired pending order, and current/previous-calendar-month paid spending (Order creation month in the application timezone; refunded Orders excluded). Its month buttons open customer-owned lists of all Order states with ten rows per page; compact `om:YYYYMM` cursors retain the month across adjacent older/newer pages and stay within Telegram’s 64-byte callback limit.
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
Storefront hero is an image-only 4:1 banner carousel. Up to five ordered banners, alt text, and optional store-local links are managed in Admin → Produk and stored in `StorefrontSetting.homepage_banners`. `StorefrontBanners` owns atomic edits, optimistic version checks, audit, and file cleanup; the existing after-commit settings observer invalidates the homepage cache. Empty slots are omitted, and a neutral empty banner is shown until the owner uploads images. The best-seller rail uses the same native-scroll carousel in `src/resources/js/storefront/carousels.js`. Rotation pauses on hover, focus, touch, hidden tabs, off-screen sections, and reduced motion. Images in cards, banners, and detail use `object-fit: contain`; detail pages share a native dialog preview with Escape/focus restoration. Shared presentation styles live in `storefront-enhancements.css`, imported by both public and customer bundles. Images remain DB/Admin-owned; there are no additional catalog/presentation ENV settings. Verified purchase activity uses the existing verified feed as a dismissible toast and measures the sticky purchase bar to avoid covering it.
Storefront Product cards and detail covers read the optional Product `icon` and custom image from the database/Admin product record. Runtime presentation uses a neutral generic fallback when both are absent; it does not map product names to brand-specific cover content. The separate ChatGPT login guide remains informational content, not catalog data.

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

### 4.3 Admin dashboard

Admin request melewati `AuthorizeAdminAccess`, yang menempelkan `adminUser` ke request. Role canonical ada di [`UserRole`](../../src/app/Enums/UserRole.php): `owner`, `admin`, `finance`, `support`.

- Owner/Admin: katalog dan sebagian setting.
- Owner: mengganti Payment Provider Switch.
- Owner/Admin/Finance: rekonsiliasi dan complete manual order sesuai policy.
- Owner/Admin/Finance/Support: melihat order/payment dan resend receipt sesuai policy.
- Supplier connections, private catalog/pricing and purchase recovery: Owner/Admin only; API key/provider changes and operational recovery require password confirmation within 15 minutes. Finance/Support cannot read this surface.

`AdminPaymentModeController` hanya mengorkestrasi request. Validasi readiness dan optimistic version conflict berada di `UpdatePaymentModeRequest`/`PaymentProviderSwitcher`; switch dan audit log ditulis atomik dalam transaksi. Switch provider lama tidak mengubah Payment yang sudah ada.

Untuk stok, `AdminProductAccountStockController` menerima JSON Account Input (1–20 kartu) dan mengembalikan hanya metadata aman. `AdminProductStockController` mempertahankan redirect/flash contract Input Cepat. `ProductStockItemManager` hanya mengedit atau menghapus Available rows; Reserved, Paid, retired, dan repair-required rows tetap read-only. Invalid `credential_format` atau encrypted structured payload diproyeksikan sebagai `REPAIR` tanpa secret.

`CredentialDeliveryPresentation` adalah seam bersama Store Bot dan Web Storefront. Ia memeriksa ulang paid eligibility, customer ownership, ordering, format legacy/structured, dan effective tutorial; DTO-nya hanya hidup di memory dan sengaja menolak serialization/debug secret output. Telegram menggunakan [`TelegramReceiptNotifier`](../../src/app/Services/Telegram/TelegramReceiptNotifier.php) + receipt ledger; Web menggunakan [`StorefrontShopSignedInvoice`](../../src/app/Storefront/StorefrontShopSignedInvoice.php) + Recovery Key/access policy. Halaman Warranty memakai proyeksi sempit `forStockItem()` dari seam yang sama untuk akun pengganti; alokasi harus milik Order paid dan credential retired ditolak. Payload yang gagal divalidasi menghasilkan fallback aman per akun, tanpa parsing payload di Blade. OTP Mail Link tidak pernah di-fetch oleh server.

`CheckoutMaintenance` adalah owner canonical untuk state **Checkout Maintenance** melalui [`CheckoutMaintenance::current()`](../../src/app/Services/CheckoutMaintenance/CheckoutMaintenance.php), `change()`, dan `guardNewOrder()`. `AdminCheckoutMaintenanceController` hanya mengorkestrasi request; role predicate `UserRole::canManageStoreOperations()` membatasi mutasi ke Owner/Admin, sedangkan `AdminDashboardStatsData` menyajikan status untuk semua role admin. `change()` mengunci row singleton `checkout_maintenance_settings`, memeriksa optimistic `version`, menormalkan note, mendeteksi no-op, lalu menulis setting dan `AuditLogger` dalam transaksi yang sama. Perubahan nyata menaikkan version dan menyimpan activation timestamp; deactivation selalu menghapus public note, sementara nilai lama tetap berada di audit.

`OrderCheckoutService` adalah owner transaksi checkout yang authoritative. Setelah reuse `checkout_key`, lock cart, reuse Order dari cart `checked_out`, dan validasi cart, ia mempertahankan urutan lock bersama `cart → customer_balance_accounts (Store Balance only) → checkout_maintenance_settings → cart items/products → ManualPaymentSetting`; rail non-Store Balance menghilangkan account step. `CheckoutPaymentPreparation::preflight()` sudah dijalankan sebelum transaksi dan lock; setelah lock serta validasi snapshot setting, `prepare()` membentuk immutable `CheckoutPaymentPlan` tanpa external I/O. `OrderCheckoutService` hanya mempersist plan generik lalu mengorkestrasi Order, reservation melalui `InventoryLedger`, credential allocation, Telegram Outbound Effect materialization, dan Cart transition. Karena `guardNewOrder()` berjalan di transaksi yang sama dan mutation admin memakai row lock yang sama, commit order menentukan race: checkout lebih dulu menghasilkan satu Order yang sah; activation lebih dulu menghasilkan tidak ada efek checkout baru. Order, Payment, reservation, credential allocation, notification, callback, reconciliation, expiry, cancellation, fulfillment, receipt, dan Sales Proof Post yang sudah ada tidak membaca state maintenance.

`CheckoutPricingSnapshot` (`src/app/Services/Checkout/CheckoutPricingSnapshot.php`) is created after Cart Item/Product and provider-setting locks. It resolves each locked Product's ordered tier schedule through `ProductQuantityPricing`, quotes each Cart Item once, and retains immutable per-Product IDR/USDT subtotals plus aggregate totals. Manual QRIS, KlikQRIS, Sakurupiah, Store Balance, Binance Pay, and BSC preparation consume those totals; Order and Order Item persistence consume the same snapshot. Product tier replacement must retain the existing Product lock so checkout observes a complete old or new schedule.

Adapter presentation tidak memiliki aturan cutoff sendiri. `StorefrontShopCheckout` hanya melakukan preflight non-authoritative sebelum membuat Customer/cart; guard service tetap wajib. `StorefrontResponseCache` memasukkan maintenance `version` ke cache identity sehingga request pertama setelah perubahan nyata tidak memakai HTML lama, sedangkan no-op mempertahankan identity. `StorefrontCatalogPageBuilder`/presenter dan view partial menampilkan notice serta mengganti CTA; `TelegramMenuIntents`, `TelegramCatalogIntents`, `TelegramCartIntents`, dan callback handlers mempertahankan browsing/stock/cart, sementara `TelegramCheckoutMaintenanceCallbackHandler` menyediakan Cek Status. Status callback terdaftar sekali pada stage customer setelah privacy guard dan tidak mengirim broadcast.

Entry point test terfokus adalah `tests/Feature/StorefrontShopCartTest.php` (guest Cart session isolation, expiry, stale snapshot, multi-item checkout, duplicate submit, maintenance rejection, and mixed fulfillment), `tests/Feature/StorefrontShopCheckoutTest.php` (direct checkout and payment rails), `tests/Feature/StorefrontProductCheckoutTest.php` (canonical Product review, page cutover, unavailable state, and recovery), `tests/Feature/CheckoutMaintenanceTest.php` (mutation, zero-side-effect rejection, existing native QRIS/status refresh, lifecycle continuation), `tests/Feature/PakasirWebhookTest.php` (authoritative callback, reconciliation, receipt/Sales Proof Post), `tests/Feature/Telegram/CheckoutMaintenanceTest.php`, and `tests/Feature/CheckoutMaintenancePostgresConcurrencyTest.php`. Browser customer evidence is in `tests/Browser/admin-dashboard-responsive.spec.js`; `npm run test:browser:customer` selects the `customer checkout:` cases covering home/shop/Product detail/Product Family, Cart, signed manual invoice, JavaScript-disabled form submission, failure recovery, browser back, keyboard/disclosures, reduced motion, zoom/reflow, and the 320×568, 390×844, 768×900, 1280×900, 1440×900, and 1920×1080 viewport matrix. Lane PostgreSQL dijalankan oleh `composer test:concurrency-gate`; race test mencakup kedua checkout locks/reservation hanya bila perubahan menyentuh boundary tersebut.

Issue #477 release evidence is the focused Storefront feature suite, the responsive customer browser suite, `npm run build`, the slug-repair dry run, and an HTTP 200 smoke check for `/assets/product-placeholder.svg`. Production mutation and deployment remain owned by the approved GitHub Actions release from `main`.

### 4.4 Buyer history dan KlikQRIS reporting

`CustomerOwnedOrderQuery` adalah pemilik query histori pada `/akun/pesanan`: query selalu dibatasi oleh `customer_id`, mendukung filter `all`, `waiting`, `processing`, `completed`, dan `issue`, lalu melakukan pagination newest-first dengan query string tetap. `CustomerAccountController` hanya meneruskan hasil query dan status filter ke view. `/akun/pesanan/{order}` tetap customer-scoped; Order non-paid hanya menampilkan status/next step, sedangkan credential dan Recovery Key mengikuti aturan akses paid yang sudah ada.

Reporting KlikQRIS hanya tersedia sebagai tab read-only pada dashboard yang sama: `/admin?tab=riwayat` untuk histori transaksi dan `/admin?tab=withdraw` untuk status pencairan. `AdminDashboardController` meneruskan tab/filter ke `AdminDashboardData`, lalu `AdminDashboardReportingData` memanggil `KlikQrisClient` hanya ketika tab reporting tersebut aktif. Endpoint provider `/qris/history?page=N` dicocokkan ke `Payment.merchant_ref`/Order lokal untuk link detail atau warning mismatch; observasi provider, termasuk `SUCCESS`, tidak mengubah Order, Payment, inventory, fulfillment, receipt, atau Sales Proof Post.

Tab Withdraw memakai `UserRole::canReconcilePayments()`, sehingga hanya Owner/Admin/Finance yang dapat melihat data payout; Support menerima state forbidden. Filter status, order ID, tanggal, limit, dan page dinormalisasi sebelum dikirim ke `/withdraw/orders`. Proof URL hanya menjadi link bila lolos validasi HTTPS dan host allowlist environment; nilai lain ditampilkan sebagai teks aman.

Reporting memakai cache singkat yang memasukkan environment dan seluruh parameter query tanpa credential. `KlikQrisClient` mengulang connection error, HTTP 408, 429, dan 5xx dalam batas budget provider; readiness, HTTP, JSON, schema, transport, dan validation failure diterjemahkan menjadi notice aman di dashboard. API key dan merchant ID tetap server-side. Perubahan layout reporting berada di `.admin-mini-shell`/view tab dan diuji browser pada 320, 390, 768, 1280, 1440, dan 1920px; tabel lebar hanya memiliki overflow pada container-nya.

## 5. Checkout dan payment rails

### 5.1 Provider selection

`PaymentProvider::allowed()` untuk checkout baru adalah `manual` dan `klikqris`. `PaymentProvider::normalize()` tetap mengenali `pakasir` agar Payment historis tidak kehilangan provenance. `ManualPaymentSetting` menyimpan provider aktif, versi setting, dan TTL; expand migration memindahkan setting Pakasir aktif ke KlikQRIS, sementara kolom checkout flow dipertahankan sampai contract release gated. `PaymentProviderResolver` mengubah setting + payment method menjadi `CheckoutPaymentIntent` dan menolak Pakasir untuk checkout baru.

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

`ReconcileKlikQrisPayments` memakai **fair rotating cursor** yang durable: posisi `(created_at, id)` terakhir disimpan di cache per environment (`klikqris:reconcile:cursor:{environment}`) dan setiap sweep scheduler melanjutkan dari posisi tersebut. Saat seluruh kandidat eligible sudah mendapat giliran dalam satu siklus, cursor di-reset sehingga kandidat lama yang masih stuck dapat dikunjungi lagi — tanpa men-starve Payment yang lebih baru. Sweep dibatasi `--max-payments` (default 10) **dan** execution budget (`KLIKQRIS_RECONCILE_MAX_EXECUTION_SECONDS`, default 45s) yang diukur dengan jam monotonik; batas ini lebih pendek dari mutex `withoutOverlapping` scheduler sehingga satu provider lambat tidak membuat sweep berikutnya overlap. Override manual `--after=<timestamp>` melanjutkan dari timestamp `created_at` tertentu.

### 5.5 Binance Pay

Binance Pay ID memakai [`BinanceCheckoutPaymentPreparation`](../../src/app/Services/Checkout/BinanceCheckoutPaymentPreparation.php) untuk membentuk snapshot verification mode, base/unique amount, auto-verification setting, receiver type/value, dan initial verification status. Adapter itu tidak melakukan history read atau transaksi verification. [`BinancePaymentVerification`](../../src/app/Services/Binance/BinancePaymentVerification.php) tetap menjadi owner matching evidence, attempt/grace policy, manual review, dan `OrderLifecycle` delegation; queue jobs tetap transport adapter.

Ada dua mode:

- `amount_unique`: matching berdasarkan nominal USDT unik dan receiver;
- `order_id`: customer mengirim Binance Order ID, dibatasi jumlah attempt dan punya retry/grace window.

Binance API key harus read-only dan dibatasi IP. History provider dibaca oleh `BinanceTransactionHistoryReader`; queue jobs (`VerifyBinanceAmountUnique`, `VerifyBinanceOrderId`) adalah transport adapter. Mismatch/late evidence tidak auto-claim; hasilnya dapat masuk manual review sesuai outcome.

### 5.6 USDT BEP20 (BSC)

USDT BEP20 memakai `provider=manual`, `method=USDT_BEP20`, dan authority BNB Smart Chain. [`BscPaymentVerification`](../../src/app/Services/Bsc/BscPaymentVerification.php) adalah pemilik discovery transfer dan verifikasi receipt logs, exact transfer predicate, confirmation/grace decision, sanitized evidence, explicit late-hash review, and single claim. Checkout baru mengalokasikan nominal USDT mikro yang unik per Payment; `payments.amount` menyimpan nominal bayar, sedangkan nominal katalog tersimpan di snapshot detail. Payment lama dengan mode `transaction_hash` tetap memakai TX hash customer. Verifier memanggil `OrderLifecycle`; ia tidak mengirim copy Telegram atau mengubah stock secara langsung.

`BscConfirmationGrace` is the expiry guard. It accepts a grace timestamp only when matching successful evidence is stored with an inclusion block between Payment creation and the original deadline. `orders:expire-pending` therefore cannot be bypassed by a fabricated future timestamp. Scheduled reconciliation and status refresh stop when the Order leaves `pending_payment`; amount-unique history scans never run for terminal Orders. One already-queued legacy TX-hash job may verify its submitted hash once and route an exact late transfer through `OrderLifecycle` into `ManualFulfillmentReview`, leaving the Order terminal. Other late transfers require explicit evidence and manual handling.

`BscReadiness` checks the database activation flag, approved chain/token metadata, RPC response, active receiver count, and one bounded `eth_getLogs` query. That probe does not guarantee access to the full history window required by an active `amount_unique` Payment. Receiver management is Owner-only: the submitted address is normalized and stored as an active `manual_verified` destination after re-authentication, without a wallet-signature challenge. Multiple distinct addresses are supported and the existing least-recently-assigned pool distributes new Payments across them. The BSC RPC endpoint is the only environment authority; chain/token identity, explorer, and operational limits are pinned in application configuration. Its short-lived result is shared across customer surfaces and invalidated after committed BSC setting/receiver mutations. Reconciliation scans bounded transfer-log windows only while both Payment and Order are pending and applies the legacy hash cadence only within that active window.

`BscCapabilityProbe` is the separate rail-qualification seam (A1): it classifies chain, contract code, decimals, `finalized` block, canonical receipt, and bounded transfer-log support as `verified`, `unsupported`, or `unknown`, and `isQualified()` is true only when every required capability is verified. `bsc:check-capabilities --json` exposes the sanitized report and exits non-zero for mismatch/unknown. The probe does not write payments, settings, or ledger state; funding activation stays no-go until H1/provider gates and the downstream rail tickets are satisfied.

### 5.7 Sakurupiah QRIS

Sakurupiah is an Owner-selectable provider for new QRIS checkout
(`provider=sakurupiah`, local `method=QRIS`, provider API `method=QRISC`,
`currency=IDR`). Checkout preparation, post-commit invoice lifecycle, callback
verification, and safe presentation live in [`src/app/Services/Sakurupiah/`](../../src/app/Services/Sakurupiah/).
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

`SakurupiahCallbackProcessor::reconcilePayment()` is the reconciliation seam:
under the same per-Payment cache lock used by create/callback/refresh, it
performs an authoritative status lookup (never a create), validates identity
and amount evidence, and delegates paid/expired transitions to `OrderLifecycle`.
Late paid observations on terminal Orders become `ManualFulfillmentReview`
without auto-fulfillment.

[`ReconcileSakurupiahPayments`](../../src/app/Console/Commands/ReconcileSakurupiahPayments.php)
(`sakurupiah:reconcile --chunk=100`) scans only Sakurupiah QRIS IDR Payments
with a merchant reference in bounded eligible states, ordered deterministically
by `(created_at, id)` with eager `order` loading. Terminal rows are bounded by
a recent `updated_at` lookback. It reports only aggregate counts and redacted
identifiers. The Postgres partial index
`payments_sakurupiah_reconcile_cursor_idx` matches the scan predicate.

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
`AdminPaymentOperations` + `PaymentPolicy::reconcile` (Owner/Admin/Finance) and
writes the `payment.manually_reconciled` audit. The operational runbook is
[`docs/runbook-sakurupiah-payment.md`](../../docs/runbook-sakurupiah-payment.md).

## 6. Order lifecycle dan inventory invariant

Flow normal:

```text
checkout
  → Order pending_payment + Payment pending + reservation
  → provider/manual verification
  → OrderLifecycle.applyPaymentPaid()
  → Payment paid + inventory sale + Order processing/completed
  → receipt/admin notification/Sales Proof Post via queue
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

- `klikqris:reconcile` tiap menit bila environment KlikQRIS aktif dan scheduling enabled; mutex `withoutOverlapping` dihitung dalam menit dari execution budget sweep ditambah retry/timeout status provider dan safety margin, sehingga satu lookup terburuk yang dimulai sebelum deadline tetap tidak overlap;
- `klikqris:reconcile-balance-top-ups --chunk=100 --max-top-ups=10` tiap menit pada readiness gate yang sama; command hanya memproses top-up KlikQRIS yang sudah memiliki create state dalam batas chunk/count, tidak menahan database lock saat status provider dipanggil, dan deadline sweep-nya kini memakai jalur `store-balance:expire-top-ups` yang sama;
- `store-balance:expire-top-ups --chunk=100` tiap menit untuk seluruh rail top-up (termasuk rail `manual` yang tidak punya provider untuk dipoll) tanpa provider I/O dan tanpa readiness gate provider;
- `sakurupiah:reconcile --chunk=100` tiap menit hanya bila `SakurupiahProviderReadiness::scheduleRegistrationEnabledFor()` true (environment enabled, operational ready, verification queue `default` yang disupervisi); bounded page memakai predicate kandidat bersama, narrow select + eager `Order`, cursor `(created_at,id)` yang berputar, dan execution deadline; mutex `withoutOverlapping` dihitung dari execution budget dan provider lookup budget; setelah bootstrap, `schedulingEnabledFor()` memeriksa event scheduler yang terdaftar;
- `sakurupiah:reconcile-balance-top-ups --chunk=100 --max-top-ups=10` tiap menit hanya bila gate readiness Sakurupiah yang sama (`scheduleRegistrationEnabledFor()`) terbuka; command merekonsiliasi top-up rail Sakurupiah secara bounded melalui status lookup authoritative tanpa menahan database lock di HTTP;
- `pakasir:reconcile --chunk=100` tiap menit untuk payment legacy;
- `binance:reconcile --chunk=100` tiap menit;
- `bsc:reconcile --chunk=100` tiap menit pada worker queue yang sama; BSC readiness/RPC failure dan backlog masuk owner alert;
- recovery Telegram updates/outbound and payment proofs tiap menit; `telegram:recover-outbound` mengubah lease kadaluarsa menjadi `unknown`, memperbaiki projection sent manual payment-proof/receipt/Sales Proof yang marker atau read projection-nya hilang, menyinkronkan projection recipient Stock Notification dan aggregate Inventory Movement untuk lease yang dipagari menjadi `unknown`, lalu hanya mengirim effect `pending`/`failed` yang dispatchable; recipient `unknown` tetap tidak dikirim ulang dan tidak menyelesaikan movement.

- `telegram:recover-outbound` also repairs sent checkout-instruction effects
  whose Order notification marker is null before the cleanup scan, copying
  ledger metadata and never redispatching the already-sent effect. Unknown and
  manual-review effects remain outside this repair.

- `telegram:recover-outbound` also repairs sent Admin Order-notification
  effects whose Order projection is stale, restoring safe pending metadata and
  pending/paid/expired timestamps. Only the latest sent version repairs a
  source/type projection; sent effects are never redispatched.
- cleanup login links tiap jam dan retained data tiap hari;
- daily admin stats job di queue `notifications`.

Scheduler service hanya menjalankan `php artisan schedule:run`; jangan menggandakan jadwal dengan menjalankan command reconciliation sebagai daemon lain.

`SendDailyAdminStatsNotification` mengirim Rekap Kemarin melalui `TelegramAdminStatsIntents`. Judul diberikan sebagai teks Unicode mentah ke formatter, yang merender icon tepat sekali. Jangan memanggil `renderLegacyIcons()` lagi pada judul yang sudah dirender: fallback glyph dapat cocok dengan source icon yang sama dan menghasilkan wrapper `<tg-emoji>` bersarang. `TelegramBotClient` mengubah wrapper internal yang valid menjadi entity `custom_emoji` untuk pesan plain text, atau Unicode fallback jika Telegram menolak custom emoji; marker `data-omp-icon` tidak boleh tampil kepada admin. Perhitungan statistik dan tombol Mini App Dashboard tetap terpisah dari rendering icon.

Statistik interaktif admin memakai `TelegramStoreStatsReport::growth()` untuk Hari Ini, Minggu Ini, Bulan Ini, dan Bulan Kemarin. Tiga periode aktif memakai pembanding durasi setara; Bulan Kemarin membandingkan dua bulan kalender penuh dengan akhir eksklusif. `TelegramAdminStatsIntents` mengirim satu pesan HTML dengan heading tebal, keterangan pembanding, dan blockquote per periode melalui `TelegramHtmlCaption`; nilai dinamis di-escape dan icon hanya dirender sekali. Receipt tetap terpisah per mata uang. Rework ini tidak mengubah recap otomatis kemarin, statistik publik, atau metrik Mini App Dashboard.

### Queue ownership

- `default` — Telegram update processing and bounded Sakurupiah webhook verification.
- `telegram-outbound` — checkout instructions, Store Balance top-up QR instructions, dan pending-order admin notification.
- `notifications` — receipt, paid/expired notifications, Sales Proof Post, Store Balance top-up payment proof, cleanup instruction, KlikQRIS owner alert, and legacy Pakasir cancellation attempts.
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
| admin           | [`src/app/Http/Controllers/Admin/`](../../src/app/Http/Controllers/Admin/), [`AdminDashboardData`](../../src/app/Services/Admin/AdminDashboardData.php), [`AdminDashboardReportingData`](../../src/app/Services/Admin/AdminDashboardReportingData.php) |
| KlikQRIS        | [`KlikQrisClient`](../../src/app/Services/KlikQris/KlikQrisClient.php), [`src/app/Services/KlikQris/`](../../src/app/Services/KlikQris/)                                                                                                  |
| Pakasir         | [`src/app/Services/Pakasir/`](../../src/app/Services/Pakasir/)                                                                                                                                                                             |
| Binance         | [`src/app/Services/Binance/`](../../src/app/Services/Binance/)                                                                                                                                                                             |
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
- `tests/Feature/Storefront/` dan `Storefront*Test.php` — catalog, guest checkout, signed invoice, recovery key, payment refresh.
- `tests/Feature/*Payment*Test.php`, `KlikQris*Test.php`, `Pakasir*Test.php`, `Binance*Test.php` — provider contract/lifecycle/reconciliation.
- `tests/Feature/Bsc*Test.php`, `tests/Unit/Services/Bsc/` — BSC readiness/verification plus capability and exact-amount contract fixtures (`verified`/`unsupported`/`unknown`, fail closed).
- `tests/Feature/OrderCartDomainTest.php` — cart/order/inventory transition.
- `tests/Feature/Admin*Test.php` — role, dashboard, provider switch, audit, manual completion.
- `tests/Feature/CredentialIdentityFoundationTest.php` — additive encrypted persistence, normalization, registry backfill, and safe command output.
- `tests/Feature/ProductStockIntakeTest.php` — atomic Input Cepat/Input Akun, idempotency, duplicate identity, authorization, audit/movement/notification behavior.
- `tests/Feature/ProductStockIntakePostgresConcurrencyTest.php` — process races for same key, duplicate identity, intake/checkout, edit/checkout, and delete/checkout; runs only through the PostgreSQL gate.
- `tests/Feature/StructuredStockSecurityAndRolloutTest.php` — persisted readiness safety gate, fingerprint readiness, URL safety, malformed-row repair, and canary persistence scan.
- `tests/Feature/CredentialDeliveryPresentationTest.php` and `tests/Feature/Telegram/StructuredCredentialDeliveryTest.php` — shared paid projection, no serialization, ordered OTP/Auth Token delivery, and Manual Delivery Review ambiguity.
- `tests/Feature/Telegram/TelegramOutboundEffect*Test.php`, `AdminTelegram*OutboundEffectTest.php`, dan `AdminTelegramBroadcastQueueTest.php` — ledger protocol, Manual Delivery Review, Admin Broadcast recovery, contract, deletion-boundary, dan family behavior.
- `tests/Feature/KlikQrisReportingTest.php` — provider reporting contract, pagination, retries, schema validation, redaction, and cache identity.
- `tests/Feature/AdminDashboardReportingTest.php` — active-tab isolation, role policy, local matching, filters, safe errors, pagination, and proof URL safety.
- `tests/Feature/CustomerAccountTest.php` — customer ownership, all Order filters/statuses, pagination, non-paid privacy, and paid credential continuity.
- `tests/Feature/StoreBalanceLedgerTest.php` — immutable ledger append, atomic projection update, idempotency and source replay, insufficient funds, immutability guards, and fail-safe snapshots.
- `tests/Feature/StoreBalanceFundingPolicyTest.php` — versioned Admin policy updates, strict bounded validation, role authorization, no-op/audit atomicity, and stale-version recovery.
- `tests/Feature/StoreBalanceReconciliationTest.php` — projection/ledger mismatch detection via `store-balance:check` without changing money, customer scoping, limit, and sanitized JSON output.
- `tests/Feature/StoreBalancePostgresConcurrencyTest.php` — process races for one idempotency claim, competing debits, parallel credits, duplicate source identity, competing Store Balance funding-intent submissions/claims, and PostgreSQL non-negative/positive/unique constraints plus one-stale-version funding-policy update; runs only through the PostgreSQL gate.
- `tests/Feature/Telegram/StoreBalanceTopUpTest.php` — confirmation-to-provider quote, safe evidence redaction, durable QR instruction separation, exact requested credit settlement, and replayed confirmation; the shared top-up tests now pin the KlikQRIS rail via `KlikQrisSandboxScenarios::selectKlikQrisProvider()`.
- `tests/Feature/Telegram/StoreBalanceTopUpManualRailTest.php` — manual rail creation in `awaiting_confirmation`, `/payment` proof forwarding to the admin with approve/reject callbacks, operator confirmation/rejection audit and idempotency, and exact-credit settlement.
- `tests/Feature/Telegram/StoreBalanceTopUpSakurupiahRailTest.php` — Sakurupiah rail create/refresh, encrypted QR payload presentation, scheduled reconciliation settlement, and exact-credit credit.
- `tests/Feature/Telegram/StoreBalanceTopUpProviderUnavailableTest.php` — fail-closed `provider_unavailable` for a rail that is not operationally ready, with no QR claimed or sent and no provider request.
- `tests/Feature/KlikQrisNativeQrisPostgresConcurrencyTest.php` — Payment and Store Balance top-up provider single-flight races, same-target recovery, and one instruction-effect materialization; runs only through the PostgreSQL gate.
- `tests/Feature/Telegram/StoreBalanceHomeTest.php` — private home greeting → Profile → Store Activity → prompt ordering, fresh Store Balance independent of the global metrics cache, fail-safe unavailable projection, localized missing value, and public-safe group home.
- `tests/Feature/Telegram/ProductFamilyDiscoveryTest.php` dan `tests/Feature/Telegram/CatalogSearchTest.php` — family discovery entry rendering (label `▸ N Plan »`, shared custom emoji icons on family Plan buttons, label stock, and `product:<id>` for single-Plan families), signed family option callbacks, origin/back restoration, dan copy per locale.
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
- `tests/Feature/SupplierFulfillmentTest.php` and `SupplierPostgresConcurrencyTest.php` — real checkout/payment/credential/refund boundaries, partial/missing economic evidence, fallback, durable recovery, concurrent wallet claims and global lock order.
- `tests/Feature/WarrantyClaimTest.php`, `WarrantyDeadlineReminderTest.php`, `BuyMailFulfillmentTest.php` and `ManualFulfillmentReviewTest.php` — per-account claims, pre-deadline reminder materialization/delivery/eligibility/recovery and the operator near-deadline monitor, partial/missing refund entitlements, pending-refund guards, operator-confirmed remainder registration before a full external refund, and review resolution boundaries.
- `tests/Support/NonTransactionalDatabaseTestCase.php` — isolated real transaction boundaries without rolling back irreversible migrations; in-memory SQLite or guarded disposable PostgreSQL only.
- `tests/Unit/Services/` — pure parser/policy/outcome/verification behavior.
- `tests/JavaScript/*.test.js` — Node unit lane (`npm run test:js`, enforced in CI) for the consumer-visible Alpine module contracts: Family quick-buy selection/quantity/checkout href, checkout review quotes/sticky action/form validity, mobile navigation focus/scroll lock/Escape, and Admin Account Input revision safety. Tests import the module factories and assert behavior rather than pinning entrypoint implementation text.
- `tests/Browser/admin-dashboard-responsive.spec.js` — customer checkout/page-flow evidence, admin behavior, reporting navigation, and responsive checks. The `customer checkout:` cases are the focused Storefront lane; they assert geometry and interaction behavior rather than screenshot-only output. Entry lanes are `npm run test:browser:admin` (full Admin/responsive slice) and `npm run test:browser:customer` (Storefront slice), both rebuilding assets first.
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
