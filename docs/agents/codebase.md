# Codebase guide untuk agent dan AI

Dokumen ini adalah peta implementasi aktual repository. Gunakan bersama [CONTEXT.md](../../CONTEXT.md) dan [ADR](../adr/). `CONTEXT.md` hanya berisi bahasa domain; detail modul, flow, dan invariant berada di sini.

## Cara memakai dokumen ini

Sebelum mengubah behavior:

1. Baca istilah yang relevan di [`CONTEXT.md`](../../CONTEXT.md).
2. Baca ADR yang menyentuh area perubahan. Untuk pembayaran KlikQRIS/Pakasir, mulai dari [ADR-0006](../adr/0006-klikqris-native-qris-and-pakasir-retirement.md); untuk checkout Telegram, [ADR-0002](../adr/0002-telegram-direct-checkout-idempotency.md).
3. Cari entry point dari tabel file di bagian akhir, lalu ikuti service yang memiliki ownership atas aturan tersebut.
4. Ubah boundary service dan test behavior-nya; jangan menyalin aturan bisnis ke controller, view, job, atau adapter channel.

Dokumen ini menjelaskan _mengapa_ file-file utama saling berhubungan. Source code tetap menjadi sumber kebenaran untuk detail method dan schema.

## 1. Topology repository dan runtime

```text
repository root
├── src/                    Laravel application
├── docs/                   ADR, runbook, specs, agent guidance
├── scripts/                release gate, smoke test, benchmark, backup
├── docker-compose.yml      app + Nginx + PostgreSQL + workers + scheduler
├── docker-entrypoint.sh    migration/optimization saat container start
└── supervisord.conf        PHP-FPM dan queue worker pools
```

`src/` memakai PHP 8.3 dan Laravel 13. Aset frontend dibangun dengan Vite, Tailwind, dan Alpine. Runtime production-like terdiri dari:

- PHP-FPM/Laravel app di belakang Nginx;
- PostgreSQL sebagai database utama;
- database-backed queue dengan worker pool terpisah;
- service scheduler yang menjalankan Laravel scheduler tiap menit;
- Telegram Bot API, Pakasir, KlikQRIS, dan Binance sebagai dependency eksternal sesuai konfigurasi.

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

`Product` adalah katalog yang dapat aktif/nonaktif, dapat berada dalam `Category`, dan punya `stock` serta `reserved_stock`. `availableStock()` menghitung stok numerik yang tidak direservasi; untuk produk credential-managed, jumlah `ProductStockItem` yang belum dialokasikan ikut membatasi availability.

Gunakan `Product::publicVisible()`, `availableForPurchase()`, `withAvailability()`, dan `availableStock()` daripada mengulang query visibility/stock di caller. Perubahan stok direkam lewat `InventoryLedger`/`EloquentInventoryLedger` dan menghasilkan `InventoryMovement` bertipe `reservation`, `sale`, `release`, atau `adjustment`.

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

### Store Channel dan Sales Proof Post

`StoreChannelSetting` menyimpan konfigurasi Store Channel, Community Gate, dan Sales Proof Post. `SalesProofPost` dipublish setelah payment paid dan inventory sale tercatat; publikasi dilakukan job sehingga OrderLifecycle tidak mengirim pesan Telegram langsung.

## 3. Ownership aturan bisnis

| Aturan                                   | Pemilik canonical                                                                                                                                                                  | Adapter/caller yang tidak boleh mengambil alih         |
| ---------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------ |
| membuat Order/Payment dan reservasi stok | [`OrderCheckoutService`](../../src/app/Services/OrderCheckoutService.php)                                                                                                          | controller, Telegram router, storefront view           |
| transisi paid/cancel/expiry/refund       | [`OrderLifecycle`](../../src/app/Services/OrderLifecycle.php) dan [`OrderStateMachine`](../../src/app/Services/OrderStateMachine.php)                                              | callback controller, provider client                   |
| provider baru untuk checkout             | [`PaymentProviderSwitcher`](../../src/app/Services/Admin/PaymentProviderSwitcher.php) dan [`PaymentProviderResolver`](../../src/app/Services/Checkout/PaymentProviderResolver.php) | channel-specific provider selection                    |
| reservation/sale/release stock           | [`InventoryLedger`](../../src/app/Services/InventoryLedger.php)                                                                                                                    | model/controller langsung mengubah stock               |
| KlikQRIS create/recovery/payability      | [`KlikQrisNativeQrisService`](../../src/app/Services/KlikQris/KlikQrisNativeQrisService.php)                                                                                       | Store Bot/Storefront mengklasifikasi persistence state |
| provider status verification             | callback processor + provider adapter masing-masing                                                                                                                                | callback payload langsung menandai paid                |
| customer order access                    | [`StorefrontOrderAccess`](../../src/app/Storefront/StorefrontOrderAccess.php)                                                                                                      | controller menyimpan plaintext Recovery Key            |
| customer-facing copy                     | Telegram/Storefront presentation adapter                                                                                                                                           | domain service mengirim pesan channel                  |

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

`TelegramWebhookIngress` memeriksa secret header, `update_id`, dan bentuk update yang didukung. Payload diperkecil sebelum disimpan. Duplicate update tidak membuat pekerjaan bisnis kedua; update yang belum diproses diklaim oleh processor job.

`TelegramMessageRouter` menangani command/text/menu. `TelegramCallbackRouter` menjalankan urutan guard:

1. callback handler stage `BeforePrivacy`;
2. `TelegramChatPrivacyGuard` — sebagian besar aksi customer harus private chat;
3. `BeforeCommunityGate`;
4. `TelegramCommunityGate` — bila aktif dan Telegram tidak bisa membuktikan membership, akses gagal tertutup;
5. `AfterCommunityGate` handlers.

Handler callback didaftarkan terpusat di [`AppServiceProvider`](../../src/app/Providers/AppServiceProvider.php). Tambah handler baru ke pipeline dan tentukan stage-nya; jangan membuat router kedua.

`TelegramCheckoutIntents` adalah adapter checkout Telegram. Ia membaca Payment provider yang sudah tersimpan, memanggil lifecycle provider bila perlu, lalu menerjemahkan hasil menjadi payment instruction. Payment instruction dan outbound send dikerjakan oleh job unique di queue yang sesuai.

### 4.2 Web Storefront

Route utama ada di [`src/routes/web.php`](../../src/routes/web.php):

- public catalog: `/`, `/produk/{product}`, `/shop`;
- guest checkout: `POST /shop/checkout`;
- signed invoice/order: `/shop/orders/{order}`;
- signed status refresh/cancel dan Binance Order ID submission;
- Recovery Key order access: `/akses-pesanan`;
- customer account/login link: `/akun/*`;
- admin: `/admin/*` di balik `admin.access`.

`StorefrontShopCheckout` membuat customer web, cart, Order, Payment, reservation, dan Recovery Key melalui service yang sama dengan domain checkout. Checkout key berbasis session/product/quantity/name/payment method memberi idempotency untuk submit ganda. Native QRIS dibuat pada Payment yang sudah ada; kegagalan pembuatan tidak membuat order atau payment kedua.

Invoice web memakai signed URL dan status refresh. `StorefrontShopPaymentStatusRefresh` dapat menjalankan recovery native QRIS, provider status lookup, atau Binance verification, tetapi paid transition tetap melalui `OrderLifecycle`. `StorefrontOrderAccess` hanya mengeluarkan plaintext Recovery Key saat issue awal; database menyimpan hash dan session grant bersifat terbatas waktu.

### 4.3 Admin dashboard

Admin request melewati `AuthorizeAdminAccess`, yang menempelkan `adminUser` ke request. Role canonical ada di [`UserRole`](../../src/app/Enums/UserRole.php): `owner`, `admin`, `finance`, `support`.

- Owner/Admin: katalog dan sebagian setting.
- Owner: mengganti Payment Provider Switch.
- Owner/Admin/Finance: rekonsiliasi dan complete manual order sesuai policy.
- Owner/Admin/Finance/Support: melihat order/payment dan resend receipt sesuai policy.

`AdminPaymentModeController` hanya mengorkestrasi request. Validasi readiness dan optimistic version conflict berada di `UpdatePaymentModeRequest`/`PaymentProviderSwitcher`; switch dan audit log ditulis atomik dalam transaksi. Switch provider lama tidak mengubah Payment yang sudah ada.

`CheckoutMaintenance` adalah module singleton untuk state **Checkout Maintenance**. `AdminCheckoutMaintenanceController` hanya mengorkestrasi request; role predicate `UserRole::canManageStoreOperations()` membatasi mutasi ke Owner/Admin, sedangkan `AdminDashboardStatsData` menyajikan status untuk semua role admin. Mutation memakai optimistic `version`, lock row PostgreSQL, no-op detection, dan `AuditLogger`. Storefront memakai `CheckoutMaintenanceState` pada cache identity dan CTA presentation; Store Bot mendaftarkan callback status pada `AfterCommunityGate` dan mengganti CTA checkout baru tanpa menghapus akses katalog atau keranjang.

Entry point test terfokus adalah `tests/Feature/CheckoutMaintenanceTest.php`, `tests/Feature/Telegram/CheckoutMaintenanceTest.php`, dan `tests/Feature/CheckoutMaintenancePostgresConcurrencyTest.php` (lane PostgreSQL dijalankan oleh `composer test:concurrency-gate`).

## 5. Checkout dan payment rails

### 5.1 Provider selection

`PaymentProvider::allowed()` saat ini adalah `manual`, `pakasir`, dan `klikqris`. `ManualPaymentSetting` menyimpan provider aktif, versi setting, TTL, dan flow Pakasir. `PaymentProviderResolver` mengubah setting + payment method menjadi `CheckoutPaymentIntent`:

| Rail                 | `Payment.provider` | `Payment.method` | Intent/service utama                         |
| -------------------- | ------------------ | ---------------- | -------------------------------------------- |
| QR Manual            | `manual`           | `QR_MANUAL`      | `ManualQrisCheckoutIntent`, admin completion |
| Pakasir hosted       | `pakasir`          | `hosted`         | `PakasirCheckoutIntent`, hosted URL          |
| Pakasir Native QRIS  | `pakasir`          | `api_qris`       | `PakasirNativeQrisService`                   |
| KlikQRIS Native QRIS | `klikqris`         | `qris`           | `KlikQrisNativeQrisService`                  |
| Binance Pay          | `manual`           | `BINANCE_PAY_ID` | `BinancePaymentVerification`                 |

Binance memakai `provider=manual` karena ia adalah metode di jalur payment manual yang berbeda dari provider QRIS; identifikasi Binance harus memakai `method`, bukan provider saja.

Payment Provider Switch berlaku hanya untuk checkout baru. Jangan memindahkan Payment lama ke provider baru, membuat fallback otomatis setelah provider create gagal, atau memakai provider yang aktif saat ini untuk menafsirkan Payment historis.

### 5.2 Checkout transaction

`OrderCheckoutService::checkout()` membungkus pembuatan checkout dalam database transaction dan retry conflict unique reference. Ia:

1. dedupe berdasarkan `checkout_key` bila ada;
2. lock Cart dan reuse Order yang sudah ada untuk Cart `checked_out`;
3. validasi Cart aktif dan belum kedaluwarsa;
4. memanggil `CheckoutMaintenance::guardNewOrder()` sebelum side effect checkout baru;
5. lock CartItem/Product/provider setting lalu validasi intent dan readiness;
6. membuat Order + Payment pending;
7. membuat OrderItem dengan snapshot delivery/usage guide;
8. mengalokasikan credential stock bila relevan;
9. merekam reservation inventory;
10. menandai Cart `checked_out`.

Payment amount untuk Binance `amount_unique` dialokasikan oleh `BinanceUniquePaymentAmount`; nominal IDR QRIS tidak boleh diperlakukan sebagai floating point.

### 5.3 Pakasir

Pakasir adalah provider legacy yang masih harus melayani Payment lama. Ia memiliki hosted checkout dan Native QRIS API. `PakasirNativeQrisService` menyimpan hasil create yang tervalidasi pada Payment yang sama, mempersempit expiry lokal mengikuti expiry provider, dan melakukan recovery melalui Transaction Detail bila hasil create meragukan.

Webhook Pakasir hanya intake. `PakasirCallbackProcessor` mencocokkan project/slug, merchant reference, amount, method, dan status; untuk callback paid ia tetap melakukan Transaction Detail API verification sebelum memanggil `OrderLifecycle`. `pakasir:reconcile` juga memproses pending dan payment yang baru saja expired/failed dalam lookback window.

Native Pakasir memiliki hosted URL sebagai jalur tampilan/fallback pada adapter Telegram. Ini adalah behavior legacy Pakasir; jangan menyalinnya ke KlikQRIS.

### 5.4 KlikQRIS Native QRIS

KlikQRIS adalah module yang memiliki boundary sendiri sesuai [ADR-0006](../adr/0006-klikqris-native-qris-and-pakasir-retirement.md). [`KlikQrisNativeQrisService`](../../src/app/Services/KlikQris/KlikQrisNativeQrisService.php) adalah satu-satunya tempat untuk:

- create dynamic QR;
- recover create outcome yang tidak pasti;
- persist provider payload yang disanitasi;
- mempersempit expiry Payment/Order;
- menentukan apakah QR aman dibayar;
- mengeluarkan safe outcome;
- menjalankan single-flight lock per Payment;
- mengamati dan mengirim owner alert actionable.

Outcome public-nya hanya:

| Outcome                 | Makna                                                                                                 |
| ----------------------- | ----------------------------------------------------------------------------------------------------- |
| `Payable`               | QR trusted, total final valid, dan deadline masih berlaku; hanya ini yang membawa payload presentasi. |
| `Creating`              | create sedang/baru akan berjalan; belum boleh bayar.                                                  |
| `RecoveryPending`       | hasil create lama tidak pasti; recovery dilakukan pada Payment/invoice yang sama.                     |
| `ProviderTotalPending`  | provider belum memberi total final; jangan bayar subtotal produk.                                     |
| `InvalidProviderResult` | payload, QR media, atau data provider tidak valid/tepercaya.                                          |
| `Expired`               | deadline provider/order sudah lewat.                                                                  |

Tidak ada automatic fallback KlikQRIS ke Manual dan tidak ada Payment kedua. Store Bot/Storefront hanya mempresentasikan QR ketika outcome `Payable`; outcome lain menjadi text/status retry.

`KlikQrisCallbackProcessor` memperlakukan callback sebagai signal. Ia melakukan status lookup authoritative, mencocokkan identity/amount/total, lalu baru memanggil `OrderLifecycle`. `klikqris:reconcile` memakai lock yang sama, memulihkan Payment outcome-unknown, dan dapat mengeskalasi kondisi actionable ke owner. Sandbox/production readiness dipisahkan oleh [`KlikQrisEnvironment`](../../src/app/Enums/KlikQrisEnvironment.php).

### 5.5 Binance Pay

Binance diverifikasi oleh [`BinancePaymentVerification`](../../src/app/Services/Binance/BinancePaymentVerification.php), bukan oleh controller atau Telegram copy. Ada dua mode:

- `amount_unique`: matching berdasarkan nominal USDT unik dan receiver;
- `order_id`: customer mengirim Binance Order ID, dibatasi jumlah attempt dan punya retry/grace window.

Binance API key harus read-only dan dibatasi IP. History provider dibaca oleh `BinanceTransactionHistoryReader`; queue jobs (`VerifyBinanceAmountUnique`, `VerifyBinanceOrderId`) adalah transport adapter. Mismatch/late evidence tidak auto-claim; hasilnya dapat masuk manual review sesuai outcome.

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

`OrderStateMachine` mengunci Order dan seluruh Payment terkait sebelum transisi. Paid transition bersifat idempotent: payment yang sudah paid tidak boleh membuat sale, receipt, atau notification duplicate. Payment terlambat pada Order expired/cancelled ditangani oleh aturan late-payment yang sudah ada; jangan menambahkan direct status update di callback.

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

- `klikqris:reconcile` tiap menit bila environment KlikQRIS aktif dan scheduling enabled;
- `pakasir:reconcile --chunk=100` tiap menit untuk payment legacy;
- `binance:reconcile --chunk=100` tiap menit;
- `orders:expire-pending` tiap menit;
- recovery Telegram updates/outbound dan payment proofs tiap menit;
- cleanup login links tiap jam dan retained data tiap hari;
- daily admin stats job di queue `notifications`.

Scheduler service hanya menjalankan `php artisan schedule:run`; jangan menggandakan jadwal dengan menjalankan command reconciliation sebagai daemon lain.

### Queue ownership

- `default` — Telegram update processing.
- `telegram-outbound` — checkout instructions dan pending-order admin notification.
- `notifications` — receipt, paid/expired notifications, Sales Proof Post, cleanup instruction, KlikQRIS owner alert.
- `telegram-broadcast` — broadcast dan price/stock notification batches.
- `binance-verification` — Binance verification jobs.

Jobs yang mengirim efek eksternal memakai unique/claim markers agar retry tidak mengirim efek ganda. Queue job bukan tempat menaruh business transition baru; job memanggil service owner lalu dapat retry bila dependency eksternal gagal.

## 8. Concurrency, idempotency, dan security

Lock dan dedupe yang harus dipertahankan:

- `OrderCheckoutService`: database transaction + cart/item/setting row lock + `checkout_key` dedupe.
- Storefront submit: cache lock `storefront:checkout:<checkout-key>`.
- Provider switch: setting row lock + `payment_provider_version` optimistic conflict.
- Order lifecycle: lock Order dan Payment rows.
- KlikQRIS: cache lock `klikqris:callback-status:<payment-id>` untuk create, recovery, callback, reconciliation, dan alert.
- Telegram webhook: unique `update_id`, processing claim, retry/quarantine.
- Provider callbacks: `PaymentEvent` idempotency/coalescing dan `processed_at`.

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
| order/inventory | [`OrderStateMachine`](../../src/app/Services/OrderStateMachine.php), [`InventoryLedger`](../../src/app/Services/InventoryLedger.php), [`Services/Product*`](../../src/app/Services/)                                                       |
| Telegram        | [`TelegramWebhookIngress`](../../src/app/Services/Telegram/TelegramWebhookIngress.php), [`TelegramUpdateProcessor`](../../src/app/Services/Telegram/TelegramUpdateProcessor.php), [`Services/Telegram/`](../../src/app/Services/Telegram/) |
| Storefront      | [`src/app/Storefront/`](../../src/app/Storefront/), [`StorefrontShopCheckout`](../../src/app/Storefront/StorefrontShopCheckout.php)                                                                                                        |
| admin           | [`src/app/Http/Controllers/Admin/`](../../src/app/Http/Controllers/Admin/), [`src/app/Services/Admin/`](../../src/app/Services/Admin/)                                                                                                     |
| KlikQRIS        | [`src/app/Services/KlikQris/`](../../src/app/Services/KlikQris/)                                                                                                                                                                           |
| Pakasir         | [`src/app/Services/Pakasir/`](../../src/app/Services/Pakasir/)                                                                                                                                                                             |
| Binance         | [`src/app/Services/Binance/`](../../src/app/Services/Binance/)                                                                                                                                                                             |
| background work | [`src/app/Jobs/`](../../src/app/Jobs/), [`src/app/Console/Commands/`](../../src/app/Console/Commands/)                                                                                                                                     |
| config          | [`src/config/services.php`](../../src/config/services.php), [`src/config/storefront.php`](../../src/config/storefront.php), [`src/.env.example`](../../src/.env.example)                                                                   |
| schema          | [`src/database/migrations/`](../../src/database/migrations/)                                                                                                                                                                               |

## 10. Test map dan command

Gunakan test boundary yang sesuai:

- `tests/Feature/Telegram/` — command, callback, checkout, payment instructions, privacy/community gate, webhook.
- `tests/Feature/Storefront/` dan `Storefront*Test.php` — catalog, guest checkout, signed invoice, recovery key, payment refresh.
- `tests/Feature/*Payment*Test.php`, `KlikQris*Test.php`, `Pakasir*Test.php`, `Binance*Test.php` — provider contract/lifecycle/reconciliation.
- `tests/Feature/OrderCartDomainTest.php` — cart/order/inventory transition.
- `tests/Feature/Admin*Test.php` — role, dashboard, provider switch, audit, manual completion.
- `tests/Unit/Services/` — pure parser/policy/outcome/verification behavior.
- `tests/Browser/` — real browser responsive/admin/Docker smoke.

Perintah umum:

```bash
cd src
composer test
composer test:foundation
composer test:concurrency-gate
npm run build
```

`composer test:concurrency-gate` memerlukan PostgreSQL disposable dan dipakai ketika perubahan menyentuh lock/stock/checkout/payment. `npm run build` diperlukan bila perubahan menyentuh Blade asset, Vite entry, CSS, atau JavaScript.

## 11. Checklist perubahan untuk AI

Sebelum membuka patch:

- [ ] Istilah baru cocok dengan `CONTEXT.md`.
- [ ] ADR relevan sudah dibaca; keputusan provider/Recovery Key/Telegram idempotency tidak dilanggar.
- [ ] Business rule ditempatkan di service owner, bukan di controller/view/job adapter.
- [ ] Payment lama tidak berubah provider dan callback tidak langsung menandai paid.
- [ ] KlikQRIS tidak membuat Payment/fallback baru; recovery memakai Payment yang sama.
- [ ] Inventory reservation/sale/release tetap melalui `InventoryLedger`.
- [ ] Retry/idempotency/row lock tetap diuji pada boundary yang benar.
- [ ] Payload/credential/QR secret tidak bocor ke log, response, fixture, atau docs.
- [ ] README/runbook/ADR diperbarui jika command, flow, atau operational invariant berubah.
- [ ] Test/lint/build yang relevan dijalankan dan hasilnya dicatat.
