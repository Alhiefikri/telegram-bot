# Codebase guide untuk agent dan AI

Dokumen ini adalah peta implementasi aktual repository. Gunakan bersama [CONTEXT.md](../../CONTEXT.md) dan [ADR](../adr/). `CONTEXT.md` hanya berisi bahasa domain; detail modul, flow, dan invariant berada di sini.

## Cara memakai dokumen ini

Sebelum mengubah behavior:

1. Baca istilah yang relevan di [`CONTEXT.md`](../../CONTEXT.md).
2. Baca ADR yang menyentuh area perubahan. Untuk pembayaran KlikQRIS/Pakasir, mulai dari [ADR-0006](../adr/0006-klikqris-native-qris-and-pakasir-retirement.md); untuk checkout Telegram, [ADR-0002](../adr/0002-telegram-direct-checkout-idempotency.md). Untuk assembly payment checkout, gunakan [accepted Checkout Payment Assembly Architecture](../superpowers/specs/2026-08-25-checkout-payment-assembly-architecture.md) sebagai spesifikasi seam dan invariant.
3. Cari entry point dari tabel file di bagian akhir, lalu ikuti service yang memiliki ownership atas aturan tersebut.
4. Ubah boundary service dan test behavior-nya; jangan menyalin aturan bisnis ke controller, view, job, atau adapter channel.

Dokumen ini menjelaskan _mengapa_ file-file utama saling berhubungan. Source code tetap menjadi sumber kebenaran untuk detail method dan schema.
## Configuration boundary

`ENV` hanya untuk credential/secret dan nilai deployment yang memang tidak boleh berada di database. Jangan menambah ENV untuk data katalog atau presentation customer. Icon Product wajib nullable di database dan diedit dari Admin Dashboard; perubahan tidak boleh membutuhkan rebuild image atau clear config.

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
- Telegram Bot API, KlikQRIS, Binance, and (until the retirement gate passes) Pakasir legacy settlement as external dependencies.

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

`ProductStockIntake` adalah pemilik canonical untuk penambahan positif melalui **Input Cepat** dan **Input Akun**. Ia menormalkan seluruh batch sebelum transaksi, mengunci Product, mengklaim idempotency key dan `CredentialIdentity`, membuat `ProductStockItem`, menaikkan numeric stock, membuat satu adjustment movement/audit, lalu menyerahkan restock notification setelah commit. Controller `AdminProductStockController` dan `AdminProductAccountStockController` hanya adapter; mereka tidak boleh mengubah stock atau membuat identity secara langsung.

`CredentialIdentity` menyimpan fingerprint HMAC keyed untuk identity yang memang tidak boleh dipakai ulang, saat ini hanya Auth Token eksplisit; email tetap menjadi data akun biasa dan tidak lagi diklaim sebagai identity registry. Fingerprint bukan plaintext. `CredentialIdentityFingerprint` memakai override ENV opsional atau kunci acak persisten di `storage/app/private`; `BackfillCredentialIdentityRegistry` memproses retained Product Stock Items secara bounded dan hanya melaporkan row IDs/counts, lalu menyimpan readiness singleton yang dikunci ke digest fingerprint key hanya setelah full commit tanpa unresolved row. `CredentialIdentityBackfillReadinessService` adalah runtime gate untuk Input Akun; deployment otomatis menjalankan backfill setelah migration, dan `credentials:check-identity-readiness` adalah deployment check. Tidak ada environment feature switch untuk menyalakan atau mematikan Input Akun.

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
The shared public-safe invoice image is owned by
[`PaidInvoiceRenderer`](../../src/app/Services/PaidInvoice/PaidInvoiceRenderer.php)
and accepts only the immutable
[`PaidInvoicePresentation`](../../src/app/Services/PaidInvoice/PaidInvoicePresentation.php)
value. `PaidInvoiceOrderPresentationAdapter` reads paid Order snapshots and the
canonical paid Payment; `PaidInvoiceSyntheticPresentationAdapter` reads one
selected available Product in memory for Fake Sales Proof. Buyer receipts and
real/fake Store Channel posts use the same renderer; fake publication never
creates an Order, Payment, inventory movement, or durable Sales Proof effect.

## 3. Ownership aturan bisnis

| Aturan                                                        | Pemilik canonical                                                                                                                                                                                                 | Adapter/caller yang tidak boleh mengambil alih                         |
| ------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- |
| shared checkout transaction, idempotency, dan side-effect orchestration | [`OrderCheckoutService`](../../src/app/Services/OrderCheckoutService.php)                                                                                                                                        | controller, Telegram router, storefront view, payment rail adapter     |
| rail-specific Payment assembly dan preflight/prepare           | adapter [`CheckoutPaymentPreparation`](../../src/app/Services/Checkout/CheckoutPaymentPreparation.php) yang dipilih oleh [`CheckoutPaymentPreparationResolver`](../../src/app/Services/Checkout/CheckoutPaymentPreparationResolver.php) | `OrderCheckoutService`, provider resolver, channel adapter              |
| generic Payment plan persistence                                | [`CheckoutPaymentPlanWriter`](../../src/app/Services/Checkout/CheckoutPaymentPlanWriter.php)                                                                                                                      | rail adapter menulis Payment langsung                                 |
| positive stock intake + identity claim                          | [`ProductStockIntake`](../../src/app/Services/ProductStockIntake.php), [`CredentialIdentity`](../../src/app/Models/CredentialIdentity.php)                                                                       | admin controller, model caller, browser adapter                         |
| transisi paid/cancel/expiry/refund                              | [`OrderLifecycle`](../../src/app/Services/OrderLifecycle.php) dan [`OrderStateMachine`](../../src/app/Services/OrderStateMachine.php)                                                                            | callback controller, provider client                                   |
| provider baru untuk checkout                                    | [`PaymentProviderSwitcher`](../../src/app/Services/Admin/PaymentProviderSwitcher.php) dan [`PaymentProviderResolver`](../../src/app/Services/Checkout/PaymentProviderResolver.php)                              | channel-specific provider selection                                    |
| reservation/sale/release stock                                  | [`InventoryLedger`](../../src/app/Services/InventoryLedger.php)                                                                                                                                                    | model/controller langsung mengubah stock                               |
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

`TelegramWebhookIngress` memeriksa secret header, `update_id`, dan bentuk update yang didukung. Payload diperkecil sebelum disimpan. Duplicate update tidak membuat pekerjaan bisnis kedua; update yang belum diproses diklaim oleh processor job.
`TelegramUpdateProcessor` serializes message and callback routing per Telegram chat with a distributed cache lock. The critical section includes selection/query-session transitions and inline message edits, so parallel workers cannot apply out-of-order UI state for one chat; transport ambiguity is never converted into a blind send fallback.

`TelegramMessageRouter` menangani command/text/menu. `TelegramCallbackRouter` menjalankan urutan guard:

1. callback handler stage `BeforePrivacy`;
2. `TelegramChatPrivacyGuard` — sebagian besar aksi customer harus private chat;
3. `BeforeCommunityGate`;
4. `TelegramCommunityGate` — bila aktif dan Telegram tidak bisa membuktikan membership, akses gagal tertutup;
5. `AfterCommunityGate` handlers.

Handler callback didaftarkan terpusat di [`AppServiceProvider`](../../src/app/Providers/AppServiceProvider.php). Tambah handler baru ke pipeline dan tentukan stage-nya; jangan membuat router kedua.

`TelegramCatalogIntents` consumes `CatalogBrowse::browsePublic()` for the
Product Family and standalone Product discovery list, while optional category
filtering and search retain their existing Product projection. The list shows
up to ten entries per page; family option callbacks use the signed compact
`TelegramFamilyCallbackToken` and resolve the existing Product ID only after
the current public family projection and page are revalidated. Product Detail,
quantity, payment, Cart, Order, and outbound-effect ownership remains unchanged.
For inline family navigation, the callback `message_id` is threaded through the
catalog presenter so `editMessageText` replaces the original catalog message.
Product quote cards use Telegram HTML `<blockquote>` formatting; command/deep-link
entry points without an originating message use the send path, while
deterministic edit rejection may use a safe new-message fallback and transport
ambiguity never triggers one.

`TelegramCheckoutIntents` adalah adapter checkout Telegram. Ia membaca Payment provider yang sudah tersimpan, memanggil lifecycle provider bila perlu, lalu menerjemahkan hasil menjadi payment instruction. Payment instruction dan outbound send dikerjakan oleh job unique di queue yang sesuai.

Untuk delivery durable, [`TelegramOutboundEffectLedgerProtocol`](../../src/app/Services/Telegram/TelegramOutboundEffectLedgerProtocol.php) adalah satu-satunya seam state: claim/lease, token-checked finalization, ambiguity, retry scheduling, terminal failure, dan receipt ordering. [`TelegramOutboundEffectPolicy`](../../src/app/Services/Telegram/TelegramOutboundEffectPolicy.php) memasok limit dan policy family tanpa memindahkan state machine ke adapter. `TelegramOutboundEffectService` hanya mematerialisasi effect, melakukan lookup, dan menyimpan resend history; queue mapping berada di `TelegramOutboundEffectDispatcher`. Delivery/projection adapter per family adalah `CheckoutInstructionDeliveryPipeline`, `ReceiptDeliveryPipeline`, `AdminOrderNotificationDeliveryPipeline`, `ManualPaymentProofDeliveryPipeline`, `SalesProofDeliveryPipeline`, `AdminBroadcastDeliveryPipeline`, `StockNotificationDeliveryPipeline`, dan `ProductPriceChangeNotificationService`. Jangan menambahkan claim/finalization pass-through baru ke service materializer atau job. Boundary lengkap, scope #286, recovery, dan Manual Delivery Review ada di [`telegram-outbound-effect-architecture.md`](../../docs/telegram-outbound-effect-architecture.md); migration/incident runbook ada di [`runbook-telegram-outbound-effect-contract.md`](../../docs/runbook-telegram-outbound-effect-contract.md).

Receipt successor/readiness is exposed by
`TelegramOutboundEffectLedgerProtocol::nextReceiptEffect($groupKey, $afterSequence)`.
Pass `0` for initial materialization; pass the completed sequence for a
continuation. Materialization, delivery, recovery, and Manual Delivery Review
must consume this seam rather than repeat sequence/state selection.

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
continuation memblokir child berikutnya. Explicit text-fallback memakai aturan
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

- public catalog: `/`, `/shop`, `/produk-keluarga/{productFamily:slug}`, and `/produk/{product}`;
- family discovery is built by `StorefrontFamilyPageBuilder` from the shared credential-free `CatalogBrowse` projection. `/produk-keluarga/{slug}` is canonical/indexable discovery; `/produk/{product}` remains the SKU-specific detail and waitlist contract.
- guest cart: `GET /shop/cart`, `POST /shop/cart/items`, `PATCH /shop/cart/items/{product}`, and `DELETE /shop/cart/items/{product}`;
- guest checkout review and submit: `GET /shop/checkout` and `POST /shop/checkout`;
- signed invoice/order: `/shop/orders/{order}`;
- signed status refresh/cancel dan Binance Order ID submission;
- Recovery Key order access: `/akses-pesanan`;
- customer account/login link: `/akun/*`;
- admin: `/admin/*` di balik `admin.access`.
`GET /shop` renders the canonical full catalog. Its bounded query state is
`q`, `category` (public slug or id), `sort` (`recommended`, `newest`,
`price_asc`, or `price_desc`), and `page`; the shared `CatalogBrowse` projection
collapses Product Family members into one card while retaining standalone
Products. Pagination and category links carry only normalized state. The
homepage remains the curated, cached entry point.

`StorefrontGuestCart` binds the active guest Cart to the server-side
storefront session key and creates a fresh web Customer when the previous Cart
is checked out or expired. Cart mutations remain owned by `CartService`; the
cart and checkout review presenters show current product values without
accepting browser prices or names as order data.

`StorefrontShopCheckout` composes direct-buy and cart review submissions through
the same Cart and `OrderCheckoutService` transaction. Cart checkout keys include
the complete ordered item snapshot and selected payment rail. The signed review
snapshot detects product, price, visibility, stock, or cart-quantity changes;
authoritative product locks still revalidate all items before Order creation.

`StorefrontShopCheckout` membuat customer web, cart, Order, Payment, reservation, dan Recovery Key melalui service yang sama dengan domain checkout. Checkout key berbasis session, snapshot cart lengkap, buyer name, dan payment rail memberi idempotency untuk submit ganda. Native QRIS dibuat pada Payment yang sudah ada; kegagalan pembuatan tidak membuat order atau payment kedua.
Checkout Web Storefront mewajibkan persetujuan eksplisit atas kebijakan syarat/refund yang dikonfigurasi sebelum membuat Customer, Cart, Order, Payment, reservation, atau side effect provider. Versi `storefront.policies.terms.version` dan timestamp persetujuan disnapshot pada Order; modal hanya memaparkan base amount USDT yang aman terpisah dari subtotal IDR.

Invoice web memakai signed URL dan status refresh. `StorefrontShopPaymentStatusRefresh` dapat menjalankan recovery native QRIS, provider status lookup, atau Binance verification, tetapi paid transition tetap melalui `OrderLifecycle`. `StorefrontOrderAccess` hanya mengeluarkan plaintext Recovery Key saat issue awal; database menyimpan hash dan session grant bersifat terbatas waktu.

### 4.3 Admin dashboard

Admin request melewati `AuthorizeAdminAccess`, yang menempelkan `adminUser` ke request. Role canonical ada di [`UserRole`](../../src/app/Enums/UserRole.php): `owner`, `admin`, `finance`, `support`.

- Owner/Admin: katalog dan sebagian setting.
- Owner: mengganti Payment Provider Switch.
- Owner/Admin/Finance: rekonsiliasi dan complete manual order sesuai policy.
- Owner/Admin/Finance/Support: melihat order/payment dan resend receipt sesuai policy.

`AdminPaymentModeController` hanya mengorkestrasi request. Validasi readiness dan optimistic version conflict berada di `UpdatePaymentModeRequest`/`PaymentProviderSwitcher`; switch dan audit log ditulis atomik dalam transaksi. Switch provider lama tidak mengubah Payment yang sudah ada.

Untuk stok, `AdminProductAccountStockController` menerima JSON Account Input (1–20 kartu) dan mengembalikan hanya metadata aman. `AdminProductStockController` mempertahankan redirect/flash contract Input Cepat. `ProductStockItemManager` hanya mengedit atau menghapus Available rows; Reserved, Paid, retired, dan repair-required rows tetap read-only. Invalid `credential_format` atau encrypted structured payload diproyeksikan sebagai `REPAIR` tanpa secret.

`CredentialDeliveryPresentation` adalah seam bersama Store Bot dan Web Storefront. Ia memeriksa ulang paid eligibility, customer ownership, ordering, format legacy/structured, dan effective tutorial; DTO-nya hanya hidup di memory dan sengaja menolak serialization/debug secret output. Telegram menggunakan [`TelegramReceiptNotifier`](../../src/app/Services/Telegram/TelegramReceiptNotifier.php) + receipt ledger; Web menggunakan [`StorefrontShopSignedInvoice`](../../src/app/Storefront/StorefrontShopSignedInvoice.php) + Recovery Key/access policy. OTP Mail Link tidak pernah di-fetch oleh server.

`CheckoutMaintenance` adalah owner canonical untuk state **Checkout Maintenance** melalui [`CheckoutMaintenance::current()`](../../src/app/Services/CheckoutMaintenance/CheckoutMaintenance.php), `change()`, dan `guardNewOrder()`. `AdminCheckoutMaintenanceController` hanya mengorkestrasi request; role predicate `UserRole::canManageStoreOperations()` membatasi mutasi ke Owner/Admin, sedangkan `AdminDashboardStatsData` menyajikan status untuk semua role admin. `change()` mengunci row singleton `checkout_maintenance_settings`, memeriksa optimistic `version`, menormalkan note, mendeteksi no-op, lalu menulis setting dan `AuditLogger` dalam transaksi yang sama. Perubahan nyata menaikkan version dan menyimpan activation timestamp; deactivation selalu menghapus public note, sementara nilai lama tetap berada di audit.

`OrderCheckoutService` adalah owner transaksi checkout yang authoritative. Setelah reuse `checkout_key`, lock cart, reuse Order dari cart `checked_out`, dan validasi cart, ia mempertahankan urutan lock `cart → checkout_maintenance_settings → cart items/products → ManualPaymentSetting`. `CheckoutPaymentPreparation::preflight()` sudah dijalankan sebelum transaksi dan lock; setelah lock serta validasi snapshot setting, `prepare()` membentuk immutable `CheckoutPaymentPlan` tanpa external I/O. `OrderCheckoutService` hanya mempersist plan generik lalu mengorkestrasi Order, reservation melalui `InventoryLedger`, credential allocation, Telegram Outbound Effect materialization, dan Cart transition. Karena `guardNewOrder()` berjalan di transaksi yang sama dan mutation admin memakai row lock yang sama, commit order menentukan race: checkout lebih dulu menghasilkan satu Order yang sah; activation lebih dulu menghasilkan tidak ada efek checkout baru. Order, Payment, reservation, credential allocation, notification, callback, reconciliation, expiry, cancellation, fulfillment, receipt, dan Sales Proof Post yang sudah ada tidak membaca state maintenance.

Adapter presentation tidak memiliki aturan cutoff sendiri. `StorefrontShopCheckout` hanya melakukan preflight non-authoritative sebelum membuat Customer/cart; guard service tetap wajib. `StorefrontResponseCache` memasukkan maintenance `version` ke cache identity sehingga request pertama setelah perubahan nyata tidak memakai HTML lama, sedangkan no-op mempertahankan identity. `StorefrontCatalogPageBuilder`/presenter dan view partial menampilkan notice serta mengganti CTA; `TelegramMenuIntents`, `TelegramCatalogIntents`, `TelegramCartIntents`, dan callback handlers mempertahankan browsing/stock/cart, sementara `TelegramCheckoutMaintenanceCallbackHandler` menyediakan Cek Status. Status callback terdaftar sekali setelah privacy/community gate dan tidak mengirim broadcast.

Entry point test terfokus adalah `tests/Feature/StorefrontShopCartTest.php` (guest cart session isolation, expiry, stale snapshot, multi-item checkout, duplicate submit, maintenance rejection, and mixed fulfillment), `tests/Feature/StorefrontShopCheckoutTest.php` (direct checkout and payment rails), `tests/Feature/CheckoutMaintenanceTest.php` (mutation, zero-side-effect rejection, existing native QRIS/status refresh, lifecycle continuation), `tests/Feature/PakasirWebhookTest.php` (authoritative callback, reconciliation, receipt/Sales Proof Post), `tests/Feature/Telegram/CheckoutMaintenanceTest.php`, dan `tests/Feature/CheckoutMaintenancePostgresConcurrencyTest.php`. Lane PostgreSQL dijalankan oleh `composer test:concurrency-gate`; race test mencakup kedua commit-order outcome, duplicate version/audit prevention, and lock-order completion.

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

`OrderCheckoutService::checkout()` membungkus pembuatan checkout dalam database transaction dan retry conflict unique reference. Ia:

1. menormalisasi intent, memilih adapter `CheckoutPaymentPreparation`, dan menjalankan `preflight()` sebelum transaksi/checkout locks;
2. dedupe berdasarkan `checkout_key` bila ada;
3. lock Cart dan reuse Order yang sudah ada untuk Cart `checked_out`;
4. validasi Cart aktif dan belum kedaluwarsa, lalu memanggil `CheckoutMaintenance::guardNewOrder()`;
5. lock CartItem/Product dan `ManualPaymentSetting` dalam urutan canonical;
6. memvalidasi ulang selected provider-setting snapshot serta preflight identity di dalam transaksi;
7. memanggil `prepare()` setelah seluruh checkout lock tersedia; fase ini hanya melakukan validation, allocation, dan snapshot work yang transaction-local;
8. membuat Order dan meminta [`CheckoutPaymentPlanWriter`](../../src/app/Services/Checkout/CheckoutPaymentPlanWriter.php) mempersist Payment plan generik;
9. membuat OrderItem dengan snapshot delivery/usage guide;
10. mengalokasikan credential stock bila relevan;
11. merekam reservation inventory melalui `InventoryLedger`;
12. mematerialisasi Telegram Outbound Effects dan menandai Cart `checked_out`.

OrderCheckoutService tidak memetakan rail, menghitung field Binance/BSC, atau melakukan external I/O dari fase `prepare()`. Aturan amount/receiver/verification rail tetap berada pada preparation adapter dan service verification masing-masing.

### 5.3 Pakasir legacy settlement

Pakasir tidak lagi tersedia untuk checkout baru. Payment lama dengan `provider=pakasir` tetap immutable dan diproses melalui compatibility lane sampai retirement gate lulus. `PakasirCallbackProcessor` mencocokkan project/slug, merchant reference, amount, method, dan status; callback paid tetap melakukan Transaction Detail API verification sebelum memanggil `OrderLifecycle`.

`pakasir:reconcile` memproses Payment lama yang masih pending atau baru dilepas, dan provider cancellation attempt tetap idempotent. `pakasir:retirement-status --json` melaporkan settlement, legacy queue, dan owner-approval readiness; ketika `gate=awaiting_owner_approval`, owner dapat merekam approval dengan `php artisan pakasir:retirement-approve <owner-id-or-email>`. Contract migration tidak berada di expand path dan hanya boleh dijalankan setelah report menunjukkan `gate=ready_for_contract`.

Jangan membuat hosted checkout atau Native QRIS Pakasir baru, dan jangan memindahkan logic legacy ini ke KlikQRIS.
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

Binance Pay ID memakai [`BinanceCheckoutPaymentPreparation`](../../src/app/Services/Checkout/BinanceCheckoutPaymentPreparation.php) untuk membentuk snapshot verification mode, base/unique amount, auto-verification setting, receiver type/value, dan initial verification status. Adapter itu tidak melakukan history read atau transaksi verification. [`BinancePaymentVerification`](../../src/app/Services/Binance/BinancePaymentVerification.php) tetap menjadi owner matching evidence, attempt/grace policy, manual review, dan `OrderLifecycle` delegation; queue jobs tetap transport adapter.

Ada dua mode:

- `amount_unique`: matching berdasarkan nominal USDT unik dan receiver;
- `order_id`: customer mengirim Binance Order ID, dibatasi jumlah attempt dan punya retry/grace window.

Binance API key harus read-only dan dibatasi IP. History provider dibaca oleh `BinanceTransactionHistoryReader`; queue jobs (`VerifyBinanceAmountUnique`, `VerifyBinanceOrderId`) adalah transport adapter. Mismatch/late evidence tidak auto-claim; hasilnya dapat masuk manual review sesuai outcome.

### 5.6 USDT BEP20 (BSC)

USDT BEP20 memakai `provider=manual`, `method=USDT_BEP20`, dan authority BNB Smart Chain. [`BscPaymentVerification`](../../src/app/Services/Bsc/BscPaymentVerification.php) adalah pemilik receipt/log validation, exact transfer predicate, confirmation/grace decision, sanitized evidence, late-payment detection, and single claim. It calls `OrderLifecycle`; it does not send Telegram copy or update stock directly.

`BscConfirmationGrace` is the expiry guard. It accepts a grace timestamp only when matching successful evidence is stored with an inclusion block between Payment creation and the original deadline. `orders:expire-pending` therefore cannot be bypassed by a fabricated future timestamp. If a terminal Order later receives an exact transfer, BSC records evidence, marks the financially valid Payment paid through `OrderLifecycle`, creates `ManualFulfillmentReview`, and leaves the Order terminal.

`BscReadiness` checks the database activation flag, approved chain/token metadata, RPC response, bounded `eth_getLogs` support (or an explicitly configured indexed source), and active receiver count. Receiver management is Owner-only: the submitted address is normalized and stored as an active `manual_verified` destination after re-authentication, without a wallet-signature challenge. Multiple distinct addresses are supported and the existing least-recently-assigned pool distributes new Payments across them. Its short-lived result is shared across customer surfaces and invalidated after committed BSC setting/receiver mutations. [`BscCheckoutPaymentPreparation`](../../src/app/Services/Checkout/BscCheckoutPaymentPreparation.php) runs `preflight()` before checkout locks, then `prepare()` revalidates only the locked setting/configuration/receiver snapshot, selects the receiver, allocates the unique amount, calculates expected token units, and builds the `PaymentBscDetail` snapshot. `prepare()` performs no BSC RPC or other external I/O; `OrderCheckoutService` consumes only the resulting plan. `bsc:reconcile` uses the existing `binance-verification` queue and also checks late-payment candidates, readiness, and backlog alerts. `ops:health --bsc` is the release/operations health surface; receiver destinations remain database-managed and Storefront availability is version-invalidated after committed setting/receiver mutations.

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

### Queue ownership

- `default` — Telegram update processing.
- `telegram-outbound` — checkout instructions dan pending-order admin notification.
- `notifications` — receipt, paid/expired notifications, Sales Proof Post, cleanup instruction, KlikQRIS owner alert, and legacy Pakasir cancellation attempts.
- `telegram-broadcast` — broadcast dan price/stock notification batches.

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

- `tests/Feature/Telegram/` — command, callback, checkout, payment instructions, privacy/community gate, webhook.
- `tests/Feature/Storefront/` dan `Storefront*Test.php` — catalog, guest checkout, signed invoice, recovery key, payment refresh.
- `tests/Feature/*Payment*Test.php`, `KlikQris*Test.php`, `Pakasir*Test.php`, `Binance*Test.php` — provider contract/lifecycle/reconciliation.
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
- `tests/Unit/Services/` — pure parser/policy/outcome/verification behavior.
- `tests/Browser/admin-dashboard-responsive.spec.js` — customer/admin browser behavior, reporting navigation, and responsive checks.
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

Structured stock availability uses [`runbook-structured-account-stock-intake-rollout.md`](../../docs/runbook-structured-account-stock-intake-rollout.md). Never expose Input Akun before the bounded backfill, persisted readiness check, conflict resolution, and non-production end-to-end proof are recorded.

## 11. Checklist perubahan untuk AI

Sebelum membuka patch:

- [ ] Istilah baru cocok dengan `CONTEXT.md`.
- [ ] ADR relevan sudah dibaca; keputusan provider/Recovery Key/Telegram idempotency tidak dilanggar.
- [ ] Business rule ditempatkan di service owner, bukan di controller/view/job adapter.
- [ ] Rail-specific Payment assembly stays behind `CheckoutPaymentPreparation`; `preflight()` runs before checkout locks and `prepare()` runs after locks without external I/O.
- [ ] Payment lama tidak berubah provider dan callback tidak langsung menandai paid.
- [ ] KlikQRIS tidak membuat Payment/fallback baru; recovery memakai Payment yang sama.
- [ ] Inventory reservation/sale/release tetap melalui `InventoryLedger`.
- [ ] Retry/idempotency/row lock tetap diuji pada boundary yang benar.
- [ ] Payload/credential/QR secret tidak bocor ke log, response, fixture, atau docs.
- [ ] README/runbook/ADR diperbarui jika command, flow, atau operational invariant berubah.
- [ ] Telegram Outbound Effect changes preserve the sole ledger seam, explicit Ambiguous Telegram Delivery review, and the deferred Admin Broadcast handoff documented in [`telegram-outbound-effect-architecture.md`](../../docs/telegram-outbound-effect-architecture.md).
- [ ] Test/lint/build yang relevan dijalankan dan hasilnya dicatat.
