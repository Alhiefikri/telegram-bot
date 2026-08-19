# Validasi Logic App Bot + Web

Tanggal audit: 2026-08-17  
Status: **Gate 1 selesai dan disetujui; hasilnya menjadi dasar Stage 2 planning**  
Scope: logic aplikasi, business logic, race condition, idempotency, dan flow Telegram/Web checkout, Order, Payment, serta pengiriman pesan/instruksi. Tidak ada kode aplikasi, config/env, production server, atau database production yang diubah.

## Ringkasan lingkungan yang diverifikasi

- Struktur repo sesuai prompt: root memiliki `src/`, `docs/`, `docker-compose.yml`, `supervisord.conf`, dan `Dockerfile`.
- Aplikasi Laravel terpasang pada versi patch **v13.16.1** (`composer show laravel/framework`). `src/composer.json` mensyaratkan PHP `^8.3`; versi PHP/image production yang disebut prompt tidak saya jadikan fakta kode tanpa verifikasi tambahan.
- Suite biasa memakai SQLite in-memory, cache array, queue sync, dan session array (`src/phpunit.xml:28-35`).
- Tidak ada server production yang diakses. Tidak ada `composer test:concurrency-gate` karena reproduksi yang dibuat di tahap ini menguji durable state/dispatch gap dan claim reset secara deterministik, bukan lock race PostgreSQL. Gate implementasi untuk outbox/checkout tetap perlu menjalankan concurrency gate.
- Context7 tidak tersedia/terhubung pada sesi ini. Fallback yang dipakai adalah dokumentasi resmi Laravel 13.x, Telegram Bot API, dan AWS yang dicantumkan pada bagian sumber.

## Status akhir per temuan

| Temuan | Status | Root cause singkat | Bukti reproduksi | Arah perbaikan yang divalidasi |
|---|---|---|---|---|
| 1. Claim outbound Telegram di-reset saat exception ambigu | **Confirmed** | Semua pola utama melakukan claim durable sebelum I/O, lalu mengosongkan claim pada `Throwable`; client mengubah timeout/connection error menjadi exception biasa sehingga status remote tidak diketahui | Temporary test receipt gagal pada assertion claim harus tetap ada; source cross-check menunjukkan pola yang sama pada checkout, payment proof, broadcast, stock, price, admin, dan expired notification | Durable outbound effect/outbox dengan state `unknown` untuk hasil ambigu; jangan blind retry credential/receipt; gunakan idempotent effect state, bukan hanya `ShouldBeUnique` |
| 2. Checkout dispatch gap | **Confirmed** | `OrderCheckoutService::checkout()` commit dan return lebih dulu; callback baru dispatch instruksi setelah return. `afterCommit()` tidak membuat dispatch durable ketika tidak ada transaksi terbuka lagi | Temporary test membuat checkout committed lalu mensimulasikan crash sebelum dispatch; recovery tidak mendorong job instruksi | Transactional outbox ditulis dalam transaksi Order/Payment/reservation; scheduler/dispatcher mengambil row pending, termasuk row tanpa claim |
| 3. `SalesProofPost::STATUS_SENDING` dapat stranded | **Confirmed** | `sending` dipakai sebagai claim dan job berikutnya sengaja skip status itu; tidak ada query recovery untuk stale `SalesProofPost` di command/scheduler | Temporary test menaruh post pada `sending`, menjalankan recovery, dan gagal menemukan handling/requeue | Tambahkan stale-state reconciliation ke effect state bersama. Hasil ambigu harus menjadi `unknown`/manual review, bukan otomatis mengirim ulang public post secara buta |
| 4. `CustomerLoginLink` mass assignment lemah | **Partially true** | Model memakai `$guarded = []`, sehingga field sensitif seperti `used_at` dapat diisi lewat `create()`. Namun seluruh call site aplikasi yang ditemukan memakai array internal terkurasi; tidak ditemukan request mentah yang diteruskan | Temporary test membuktikan `used_at` tersimpan melalui mass assignment dan gagal pada assertion allowlist | Ganti dengan explicit fillable/`#[Fillable]`; `used_at` hanya boleh diubah lewat assignment domain/internal |
| 5. Admin login throttle per login+IP, bukan global per-IP | **Confirmed** | Key dibentuk sebagai `lower(login).'|'.ip`; lima identifier berbeda dari satu IP memakai lima bucket berbeda | Temporary test mengirim lima login buruk dengan identifier berbeda dari satu IP; percobaan keenam menerima “Email atau password...” bukan pesan rate limit | Pertahankan bucket per identifier sebagai defense-in-depth dan tambah bucket global per IP. Pertimbangkan `RateLimiter::increment()` atomik untuk menghindari check-then-hit race |
| 6a. Coverage tambahan claim-reset outbound | **Confirmed as scope expansion of #1** | `TelegramAdminOrderNotifier`, `TelegramOrderExpiredNotifier`, dan forwarding payment proof juga mengosongkan claim pada exception ambigu | Source cross-check; pola identik dengan temuan #1 | Masukkan semua outbound effect ke kebijakan claim/state yang sama, bukan memperbaiki hanya receipt/checkout |
| 6b. Observasi limiter check/hit non-atomik | **Observation, not separately ticketed yet** | Controller memakai `tooManyAttempts()` lalu `hit()` terpisah. Dokumentasi Laravel memperingatkan check dan increment terpisah dapat bermasalah pada request simultan | Tidak dibuat loop concurrency terpisah karena test harness memakai cache array/SQLite dan tidak menyediakan boundary atomic yang representatif | Evaluasi bersama perbaikan #5; implementasi perlu test concurrency yang sesuai backend production |

---

## 1. Outbound Telegram: claim dihapus saat exception ambigu

### Status

**Confirmed.** Temuan lebih luas daripada contoh awal. Masalahnya bukan hanya satu kolom, melainkan kebijakan umum: hasil remote yang tidak diketahui diperlakukan sebagai kegagalan definitif, claim langsung dilepas, lalu retry dapat mengirim ulang effect yang mungkin sudah diterima Telegram.

### Root cause aktual

#### Receipt dan instruksi checkout

- `TelegramReceiptNotifier::send()` mengunci Order dan menulis claim sebelum Telegram I/O pada `src/app/Services/Telegram/TelegramReceiptNotifier.php:37-53`.
- Jika salah satu chunk `sendMessage()` melempar exception, catch mengosongkan `telegram_receipt_send_started_at` pada `:103-119`.
- Sukses baru menulis `telegram_receipt_sent_at` dan mengosongkan claim pada `:121-126`.
- `SendManualCheckoutNotifications` melakukan hal yang sama: claim `:53-63`, reset pada catch `:70-81`, sukses pada `:83-84`.
- Pola reset identik ada pada:
  - `src/app/Jobs/SendKlikQrisCheckoutInstructions.php:39-86`
  - `src/app/Jobs/SendPakasirCheckoutInstructions.php:38-75`
  - `src/app/Jobs/SendBinanceCheckoutInstructions.php:50-101`

`TelegramBotClient` menangkap `ConnectionException` dan mengubahnya menjadi `RuntimeException` pada `src/app/Services/Telegram/TelegramBotClient.php:111-125`. Setelah normalisasi itu, caller tidak memiliki informasi apakah Telegram tidak menerima request, menerima tetapi response hilang, atau koneksi putus setelah remote side effect.

#### Payment proof, broadcast, stock, dan price notification

- `TelegramManualPaymentIntents::forwardSubmittedManualPaymentProof()` mengklaim Payment pada `src/app/Services/Telegram/TelegramManualPaymentIntents.php:167-190`, lalu mereset `payment_proof_forwarding_started_at` dan token pada exception di `:232-245`.
- `TelegramBroadcastService` mengklaim recipient pada `:343-374`, lalu mereset `send_started_at` dan token pada `:390-443`. Recovery query memang mencari row null atau stale pada `:173-195`, sehingga reset exception membuat retry langsung eligible.
- `StockNotificationDeliveryPipeline` mengklaim recipient pada `:167-181`, lalu mengosongkan `claim_token` dan `claim_expires_at` pada `:183-194`.
- `ProductPriceChangeNotificationService` mengklaim Store Channel pada `:116-148` dan recipient pada `:244-264`; kedua catch mereset claim pada `:164-187` dan `:279-305`.
- Kode price notification sendiri mengakui batasan ini: `src/app/Services/Telegram/ProductPriceChangeNotificationService.php:270-272` menyatakan bahwa Telegram tidak menyediakan transactional exactly-once send dan crash setelah send sebelum `sent_at` dapat menghasilkan duplicate saat recovery.

#### Temuan tambahan 6a

Pola yang sama juga ada di luar daftar awal:

- `TelegramAdminOrderNotifier::sendOnce()` claim pada `src/app/Services/Telegram/TelegramAdminOrderNotifier.php:116-125` dan reset pada `:131-157`.
- `TelegramOrderExpiredNotifier` claim pada `src/app/Services/Telegram/TelegramOrderExpiredNotifier.php:23-34` dan reset pada `:91-109`.
- Forwarding payment proof dirinci di atas.

`TelegramBuyerCheckoutInstructionCleaner` lebih konservatif untuk operasi delete: ia membedakan error “already deleted” dari `RequestException` dan mempertahankan claim lease untuk error transport tertentu (`src/app/Services/Telegram/TelegramBuyerCheckoutInstructionCleaner.php:59-84`, `:95-109`). Saya tidak mengklasifikasikannya sebagai temuan baru pada scope ini karena effect-nya delete dan sudah memiliki perlakuan error khusus, tetapi pola state-nya perlu disejajarkan saat remediasi umum.

### Bukti reproduksi

Draft test tidak menyentuh production dan belum di-commit:

- `src/tests/Feature/AuditValidationTemporaryTest.php:35-65`, test `test_ambiguous_telegram_receipt_failure_keeps_the_durable_claim`, yang memanggil notifier secara langsung.
- `src/tests/Feature/AuditValidationTemporaryTest.php:69-99`, test `test_public_telegram_checkout_keeps_claim_after_ambiguous_customer_send`, yang melewati `POST /api/webhook/telegram`, ingress, callback processor, checkout, sync queue, dan Telegram HTTP client boundary.

Skenario:

1. Buat Order Paid/Completed dengan Telegram customer.
2. Fake `sendMessage` menandai bahwa request “accepted”, lalu melempar `ConnectionException` seperti timeout setelah remote acceptance.
3. Jalankan notifier.
4. Assert claim tetap ada agar automatic retry tidak mengirim ulang receipt.

Perintah:

```text
cd src && composer test -- --filter=AuditValidationTemporaryTest
```

Hasil aktual pada kode sekarang:

```text
FAIL ambiguous telegram receipt failure keeps the durable claim
An ambiguous send outcome must remain claimed instead of being retried automatically.
Failed asserting that null is not null.

Tests: 6 failed (21 assertions)
```

Assertion `telegramAccepted === true` lolos; yang gagal adalah claim memang sudah di-reset menjadi `NULL` oleh catch production.

Pada reproduksi public-path, request `POST /api/webhook/telegram` juga mencapai
checkout dan outbound job. Fake `sendPhoto` menandai request accepted lalu
melempar timeout. Assertion claim harus tetap ada gagal pada baris 95 karena
kolom `manual_checkout_customer_notification_started_at` juga menjadi `NULL`.
Dengan demikian, temuan #1 tidak hanya terlihat pada unit seam notifier, tetapi
juga pada jalur customer Telegram yang benar-benar menerima callback checkout.

Sebagai validasi integration boundary tambahan, test
`test_public_checkout_reaches_a_real_local_telegram_http_boundary_before_ambiguous_failure`
membuat TCP server lokal sementara, mengarahkan `TelegramBotClient` ke server
tersebut, menerima request `sendPhoto`, lalu menutup koneksi tanpa response.
Server mencatat path `/sendPhoto`, marker acceptance terbentuk, dan flow public
meninggalkan claim `NULL`. Test ini **passed (7 assertions)** untuk membuktikan
bahwa request benar-benar melewati Guzzle/Laravel HTTP ke boundary jaringan
nyata, bukan hanya `Http::fake`. Server lokal dipakai karena memanggil Telegram
production tetap dilarang oleh batasan audit read-only.

### Skenario production konkret

Worker mengambil claim receipt/instruction, Telegram menerima `sendMessage` atau `sendPhoto`, tetapi koneksi timeout sebelum response masuk. Queue worker melihat exception. Catch mengosongkan claim. Retry queue atau command recovery kemudian menganggap effect belum pernah dicoba dan mengirim ulang. Untuk receipt yang berisi kredensial, customer dapat menerima dua pesan atau dua set chunk yang sama. Untuk checkout instruction, customer dapat melihat duplicate QR/payment instruction. Untuk proof/admin/broadcast, duplicate notification dapat muncul.

### Pendekatan perbaikan yang divalidasi

1. **Transactional outbox untuk durability intent.** Row effect dibuat pada transaksi yang membuat/mengubah aggregate; dispatcher hanya memproses row committed.
2. **State hasil pengiriman eksplisit.** Minimal bedakan `pending`, `sending`, `sent`, `unknown`, dan `failed`. `unknown` dipakai untuk timeout/connection/process uncertainty, bukan langsung dikonversi menjadi `failed` yang boleh retry.
3. **Policy per effect.** Receipt/kredensial memakai kebijakan konservatif: jangan automatic resend pada `unknown`; tampilkan reconciliation/manual action. Notification yang memang menerima duplicate dapat memiliki kebijakan retry berbeda, tetapi harus eksplisit.
4. **Gunakan claim token/lease untuk ownership, bukan sebagai bukti bahwa Telegram pasti belum menerima.** `ShouldBeUnique` tetap berguna untuk mengurangi duplicate queue job, tetapi tidak menyelesaikan duplicate remote side effect setelah response hilang.

Rujukan resmi:

- Laravel Queues 13.x, [Unique Jobs](https://laravel.com/docs/13.x/queues#unique-jobs), [Jobs & Database Transactions](https://laravel.com/docs/13.x/queues#jobs-and-database-transactions), dan [max attempts/backoff](https://laravel.com/docs/13.x/queues#specifying-max-job-attempts-timeout-values).
- Telegram Bot API [sendMessage](https://core.telegram.org/bots/api#sendmessage) dan [sendPhoto](https://core.telegram.org/bots/api#sendphoto). Parameter resmi yang diperiksa tidak mendokumentasikan outbound `idempotency_key`; API mengembalikan response `ok/result`, tetapi tidak memberi caller mekanisme dedupe transaksi yang dapat dipakai app ini.
- AWS Prescriptive Guidance, [Transactional outbox pattern](https://docs.aws.amazon.com/prescriptive-guidance/latest/cloud-design-patterns/transactional-outbox.html): dual write DB + notification, outbox row dalam transaksi, serta consumer idempotency karena duplicate delivery mungkin terjadi.
- AWS Builders’ Library, [Making retries safe with idempotent APIs](https://aws.amazon.com/builders-library/making-retries-safe-with-idempotent-APIs/): timeout membuat client tidak tahu apakah side effect sudah terjadi; retry aman memerlukan idempotent operation atau reconciliation.
- AWS SQS, [At-least-once delivery](https://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/standard-queues-at-least-once-delivery.html): consumer harus dirancang idempotent karena message dapat diterima lebih dari sekali.

### Nasib test sementara

**Disimpan sebagai draft belum di-commit** di `src/tests/Feature/AuditValidationTemporaryTest.php`. Test sengaja meng-encode behavior aman dan saat ini merah; akan menjadi calon regression test setelah keputusan state/outbox disetujui.

---

## 2. Checkout dispatch gap

### Status

**Confirmed.** Existing `checkout_key` dan cache lock dari ADR-0002 mencegah duplicate checkout mutation, tetapi tidak menjamin outbound instruction dispatch durable.

### Root cause aktual

- `OrderCheckoutService::checkout()` menjalankan `DB::transaction(...)` pada `src/app/Services/OrderCheckoutService.php:36-45`, lalu mengembalikan Order setelah transaksi selesai.
- Di `TelegramCheckoutCallbackHandler::checkout()`, hasil checkout diterima pada `src/app/Services/Telegram/Callbacks/TelegramCheckoutCallbackHandler.php:227-237`.
- Instruksi baru dipilih dan didispatch setelah `checkout()` return pada `:252-260` melalui `dispatchCheckoutInstructions()`.
- Method itu melakukan `::dispatch(...)` lalu `.afterCommit()` pada `:263-273`.

Pada titik `:259`, transaksi internal `OrderCheckoutService` sudah commit. Karena tidak ada transaksi terbuka yang membungkus boundary ini, `.afterCommit()` tidak mengubah fakta bahwa masih ada window proses mati antara commit Order/reservation dan pemanggilan dispatch.

Recovery command hanya mencari checkout customer notification yang claim-nya sudah stale:

- `src/app/Console/Commands/RecoverTelegramOutboundEffects.php:59-83` memakai `where('manual_checkout_customer_notification_started_at', '<=', $cutoff)`.
- Row dengan `manual_checkout_customer_notification_started_at IS NULL` tidak termasuk query recovery.
- Scheduler menjalankan command setiap menit pada `src/routes/console.php:18`, tetapi command tidak dapat menemukan Order yang prosesnya mati sebelum claim dibuat.

### Bukti reproduksi

Draft test `test_recovery_rediscovers_a_committed_checkout_with_no_notification_claim` pada `src/tests/Feature/AuditValidationTemporaryTest.php:67-99`:

1. `OrderCheckoutService::checkout()` menyelesaikan commit Order, Payment, reservation, dan cart state.
2. Test secara eksplisit memperlakukan proses sebagai crash sebelum callback memanggil `dispatchCheckoutInstructions()`.
3. Jalankan `telegram:recover-outbound`.
4. Assert `SendManualCheckoutNotifications` harus ditemukan.

Hasil aktual:

```text
FAIL recovery rediscovers a committed checkout with no notification claim
The expected [App\Jobs\SendManualCheckoutNotifications] job was not pushed.
```

Ini bukan PostgreSQL lock race; ini durable handoff gap yang dapat direproduksi dengan SQLite test database.

### Skenario production konkret

Telegram callback membuat Order pending payment, Payment, inventory reservation, dan menandai cart checked out. PHP worker/container kemudian mati setelah transaksi commit tetapi sebelum `::dispatch()` dipanggil. Customer tidak menerima QR/manual/Binance instruction. Reservation tetap menahan stock sampai expiry/reconciliation, sementara customer tidak memiliki instruksi pembayaran.

### Pendekatan perbaikan yang divalidasi

- Buat durable outbound effect/outbox row **di transaksi checkout yang sama** dengan Order, Payment, dan reservation.
- Dispatcher boleh dipicu segera setelah commit untuk latency, tetapi scheduler/recovery harus dapat menemukan semua row `pending`, termasuk row yang belum pernah memiliki claim.
- `checkout_key` dan cache lock ADR-0002 tetap menjadi mekanisme idempotensi checkout intent. Jangan membuat lock checkout kedua yang menduplikasi mekanisme tersebut.
- Job effect harus claim row dengan `lockForUpdate()` dan menyimpan effect identity yang deterministic.

Rujukan:

- Laravel [Jobs & Database Transactions](https://laravel.com/docs/13.x/queues#jobs-and-database-transactions) menjelaskan fungsi `afterCommit` sebagai penundaan dispatch sampai transaksi terbuka commit; ini bukan durable outbox.
- Laravel [Pessimistic Locking](https://laravel.com/docs/13.x/queries#pessimistic-locking) mendukung `lockForUpdate()` untuk claim transition di database.
- AWS [Transactional outbox pattern](https://docs.aws.amazon.com/prescriptive-guidance/latest/cloud-design-patterns/transactional-outbox.html) secara langsung membahas failure setelah DB commit tetapi sebelum notification terkirim.

### Nasib test sementara

**Disimpan sebagai draft belum di-commit.** Assertion saat ini sengaja memodelkan outcome customer yang diinginkan, yaitu recovery menemukan effect tanpa claim. Bentuk final test dapat berubah menjadi assertion terhadap outbox row/state setelah keputusan desain Gate 2.

---

## 3. `SalesProofPost::STATUS_SENDING` tidak dipulihkan

### Status

**Confirmed.** Kode sengaja menghindari duplicate public Sales Proof Post dengan cara yang dapat membuat post tidak pernah maju lagi.

### Root cause aktual

- `PublishSalesProofPost` mengambil row dan menolak memproses jika status sudah `sent` **atau `sending`** pada `src/app/Jobs/PublishSalesProofPost.php:91-98`.
- Sebelum Telegram I/O, job menulis `STATUS_SENDING` dan menaikkan attempts pada `:114-117`.
- Jika process crash/kill setelah Telegram menerima request tetapi sebelum status `sent` ditulis, PHP tidak mencapai catch di `:123-141`; row tertinggal di `sending`.
- `RecoverTelegramOutboundEffects` tidak memiliki query `SalesProofPost` sama sekali (`src/app/Console/Commands/RecoverTelegramOutboundEffects.php:36-167`).
- Scheduler hanya mendaftarkan generic outbound recovery pada `src/routes/console.php:18`; tidak ada command Sales Proof stale-state recovery.
- Admin retry hanya menerima `STATUS_FAILED` pada `src/app/Http/Controllers/Admin/AdminSalesProofRetryController.php:21-30`.
- Dashboard hanya memberi `can_retry` ketika status failed pada `src/app/Services/Admin/AdminDashboardSalesData.php:266-299`; `sending` hanya ditampilkan sebagai “Sending”.

### Bukti reproduksi

Draft test `test_recovery_requeues_a_sales_proof_post_stranded_in_sending` pada `src/tests/Feature/AuditValidationTemporaryTest.php:101-120` membuat post berstatus `sending`, menjalankan `telegram:recover-outbound`, dan menunggu ada handling `PublishSalesProofPost`.

Hasil aktual:

```text
FAIL recovery requeues a sales proof post stranded in sending
The expected [App\Jobs\PublishSalesProofPost] job was not pushed.
```

Existing test `src/tests/Feature/Telegram/PaymentProofAdminCallbackTest.php:1086-1099` juga mengunci perilaku saat ini: retry job melewati post `STATUS_SENDING` dan status tetap `sending`.

### Skenario production konkret

Payment sudah paid dan inventory sale sudah tercatat. Job membuat claim `sending`, Telegram channel menerima post, lalu worker mati sebelum `telegram_message_id`, `sent_at`, dan `status=sent` tersimpan. Jika post sebenarnya tidak diterima, hasilnya tetap sama dari sisi database. Tanpa reconciliation, dashboard menampilkan “Sending” selamanya dan tidak ada retry/operator action.

### Pendekatan perbaikan yang divalidasi

- Tambahkan stale claim reconciliation. Jangan langsung mengirim ulang `sending` secara otomatis karena Telegram tidak memberi idempotency key dan status remote tidak dapat dipastikan dari row lokal.
- Transisi stale `sending` ke state eksplisit seperti `unknown`/`manual_review`, tampilkan alasan dan operator action.
- Retry otomatis hanya untuk state yang definitively belum dikirim, misalnya outbox `pending` yang belum pernah memulai I/O. Retry `unknown` harus membutuhkan keputusan/manual verification agar tidak menggandakan public post.
- Satukan state ini dengan effect claim yang direkomendasikan pada temuan #1 dan #2, bukan membuat recovery table terpisah yang kembali memiliki dual-write gap.

Rujukan:

- AWS [Making retries safe with idempotent APIs](https://aws.amazon.com/builders-library/making-retries-safe-with-idempotent-APIs/) untuk kasus timeout/uncertain remote side effect.
- Telegram [sendMessage](https://core.telegram.org/bots/api#sendmessage) untuk parameter/response resmi, tanpa documented outbound idempotency key.
- AWS [Transactional outbox pattern](https://docs.aws.amazon.com/prescriptive-guidance/latest/cloud-design-patterns/transactional-outbox.html) dan [at-least-once delivery](https://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/standard-queues-at-least-once-delivery.html).

### Nasib test sementara

**Disimpan sebagai draft belum di-commit.** Test ini adalah sentinel bahwa stale `sending` tidak boleh diam-diam hilang dari operational workflow. Assertion final perlu disesuaikan dengan state `unknown/manual_review` yang disetujui pada Gate 2.

---

## 4. `CustomerLoginLink` menggunakan mass assignment protection lemah

### Status

**Partially true.** Weakness pada model benar dan dapat dibuktikan, tetapi audit call site tidak menemukan request mentah yang saat ini diteruskan ke `CustomerLoginLink::create()`.

### Root cause aktual

- `src/app/Models/CustomerLoginLink.php:7-13` memakai `protected $guarded = []`.
- Schema `src/database/migrations/2026_07_14_000002_add_customer_account_fields.php:17-25` memiliki field sensitif `token_hash`, `expires_at`, `used_at`, dan `requested_ip`.
- Satu-satunya application create call site yang ditemukan adalah `src/app/Services/Customer/CustomerAuthenticationService.php:16-26`, dan array-nya dibangun internal dari normalized email, generated token hash, expiry, dan request IP.
- Controller hanya meneruskan email hasil validation pada `src/app/Http/Controllers/CustomerAccountController.php:38-43`.
- Test/create call site lain adalah fixture test, bukan request runtime.

Jadi belum ada bukti attacker dapat mengirim `used_at` langsung lewat endpoint login link sekarang. Namun model boundary tetap mengizinkan caller masa depan atau code path tidak sengaja menulis `used_at`, `expires_at`, `token_hash`, atau field lain melalui mass assignment.

### Bukti reproduksi

Draft test `test_customer_login_link_does_not_mass_assign_its_used_state` pada `src/tests/Feature/AuditValidationTemporaryTest.php:122-134` mencoba payload internal dengan `used_at=now()` dan mengharapkan allowlist menolak field tersebut.

Hasil aktual:

```text
FAIL customer login link does not mass assign its used state
The one-time-used state must not be writable through an untrusted mass-assignment payload.
Failed asserting that Carbon Object ... is null.
```

Artinya `used_at` benar-benar tersimpan melalui `CustomerLoginLink::create()`.

### Skenario production konkret

Saat ini tidak ada raw request propagation yang ditemukan, sehingga ini bukan exploit endpoint yang sudah terbukti. Risiko muncul ketika developer menambah field dari `$request->validated()` atau memakai `CustomerLoginLink::create($payload)` pada endpoint/service baru. Caller tersebut dapat mengubah link menjadi sudah dipakai, memperpanjang expiry, mengganti token hash, atau memalsukan IP.

### Pendekatan perbaikan yang divalidasi

- Gunakan explicit allowlist sesuai pola model Laravel 13 di repo, misalnya `#[Fillable(['email', 'token_hash', 'expires_at', 'requested_ip'])]` atau `$fillable` ekuivalen.
- Jangan masukkan `used_at` ke allowlist. Perubahan `used_at` tetap memakai assignment eksplisit di `consume()`.
- Tambahkan regression test yang memastikan unexpected sensitive fields tidak ikut tersimpan.

Rujukan resmi: Laravel [Mass Assignment](https://laravel.com/docs/13.x/eloquent#mass-assignment). Dokumentasi membedakan `$fillable` sebagai allowlist dan `$guarded` sebagai pendekatan blocklist; untuk model berisi token/state keamanan, allowlist lebih konservatif.

### Nasib test sementara

**Disimpan sebagai draft belum di-commit.**

### Feedback loop acceptance-aligned dan traceability

Setelah validasi awal, saya menjalankan seam publik yang benar-benar dipakai
customer/admin, bukan hanya test sementara yang memanggil service atau model
secara langsung. Semua test berikut berjalan terhadap aplikasi Laravel melalui
HTTP route/console command/job boundary dengan database test SQLite in-memory.

| Requirement Gate 1 | Public/integration check | Observed result |
|---|---|---|
| Verifikasi ingress Telegram dan callback checkout | `Tests\\Feature\\Telegram\\WebhookIngressTest` dan dua test pada `Tests\\Feature\\Telegram\\CartCheckoutTest` yang mem-post ke `POST /api/webhook/telegram`, melewati ingress, update persistence, callback router, checkout, serta Telegram HTTP boundary | **59 test passed (411 assertions)** pada webhook/customer/admin feature set; checkout callback **2 passed (15 assertions)**. Order/payment dan outbound effect saat ini berperilaku sesuai implementasi existing, sehingga seam publik terbukti aktif. |
| Verifikasi Web Storefront yang juga berada dalam scope audit | `Tests\\Feature\\StorefrontShopCheckoutTest` dan `Tests\\Feature\\Storefront\\KlikQrisStorefrontTest`, melalui `POST /shop/checkout`, signed order/status routes, native QRIS provider boundary, payment refresh, and single-flight behavior | **47 test passed (598 assertions)**. Public web checkout, order/payment creation, reservation/recovery behavior, signed status refresh, and KlikQRIS/Pakasir integration seams all executed successfully against the test database. |
| Baseline acceptance seluruh project tanpa draft audit | `composer test -- --exclude-filter=AuditValidationTemporaryTest`, sehingga semua existing unit/feature/integration tests tetap masuk dan hanya draft red-capable audit test dikecualikan | **1.402 test passed, 34 skipped, 9.904 assertions**. Ini mengonfirmasi bahwa artefak audit dan reproduksi tambahan tidak mengubah source aplikasi dan baseline project tetap hijau. |
| #1: ambiguous Telegram send tidak boleh dilepas sebagai retry-safe | `AuditValidationTemporaryTest::test_ambiguous_telegram_receipt_failure_keeps_the_durable_claim`, `test_public_telegram_checkout_keeps_claim_after_ambiguous_customer_send`, serta `test_public_checkout_reaches_a_real_local_telegram_http_boundary_before_ambiguous_failure`; dua test terakhir melewati `POST /api/webhook/telegram`, callback processor, checkout, sync queue, dan Telegram HTTP boundary | Dua test red-capable **fail sesuai hipotesis** karena claim menjadi `NULL`. Test boundary lokal **passed (7 assertions)** dan mencatat request jaringan aktual sebelum koneksi ditutup. Production Telegram tidak disentuh karena audit read-only. |
| #2: checkout intent harus durable setelah order commit | Public callback test di atas membuktikan jalur checkout real sampai queue boundary; `AuditValidationTemporaryTest::test_recovery_rediscovers_a_committed_checkout_with_no_notification_claim` kemudian menjalankan `telegram:recover-outbound` terhadap commit yang sengaja dihentikan sebelum dispatch | Public path **passed** untuk checkout normal; recovery gap **terbukti** karena command tidak dispatch job. Crash di antara dua operasi tidak dapat disuntikkan dari HTTP acceptance test tanpa mengubah runtime, jadi boundary crash direpresentasikan oleh commit service + command recovery dan dinyatakan sebagai constraint. |
| #3: stranded `SalesProofPost::STATUS_SENDING` harus terlihat/recoverable | Existing end-user/admin behavior: `PaymentProofAdminCallbackTest::test_sales_proof_post_retry_skips_in_progress_send_after_worker_crash` dan `AdminDashboardSalesRenderingTest::test_sales_proof_manual_retry_uses_publisher_path_without_changing_order_state`; temporary test menjalankan recovery command terhadap row `sending` | **2 test passed (11 assertions)** untuk perilaku current public/admin path, dan temporary recovery assertion **fail** karena tidak ada handling stale `sending`. Ini adalah bukti langsung bahwa status dapat tetap macet dan retry admin hanya menerima `failed`. |
| #4: customer login link tidak menerima state sensitif dari input | `CustomerAccountTest` menjalankan `POST /akun/login-link` dan `POST /akun/login-link/consume`, menguji hash-only storage serta single-use session; temporary model test mencoba payload `used_at` | Public customer flow **passed**; hardening assertion **fail** karena model internal tetap menyimpan `used_at` bila mass-assigned. Tidak ada raw request propagation ditemukan, sehingga status tetap Partially true, bukan exploit endpoint yang sudah terbukti. |
| #5: satu IP harus dibatasi lintas identifier login | `AuditValidationTemporaryTest::test_admin_login_throttle_blocks_a_sixth_bad_attempt_from_the_same_ip_across_logins` mengirim enam `POST /admin/login` dari IP sama dengan enam identifier berbeda | **Fail sesuai hipotesis**: attempt keenam masih menerima pesan kredensial salah, bukan rate-limit. Ini adalah HTTP acceptance path, bukan pemanggilan `RateLimiter` langsung. |
| Skenario skip scope dan read-only | Command/test dijalankan hanya pada database test; tidak ada SSH, production DB, migration, config/env, atau source application change | **Terpenuhi**. Tidak ada kategori RAM/PostgreSQL tuning/Docker resource yang dimasukkan ke planning. |
| Official approach evidence | Laravel 13 queues/transactions/locking/rate-limit/mass-assignment docs, Telegram Bot API, AWS transactional outbox/idempotency/at-least-once docs | **Terpenuhi**. Link dan klaim per pendekatan dicantumkan pada bagian sumber dan tiap finding. |

Output acceptance run yang baru:

```text
cd src && composer test -- tests/Feature/Telegram/WebhookIngressTest.php tests/Feature/CustomerAccountTest.php tests/Feature/AdminDashboardAccessTest.php
Tests: 59 passed (411 assertions)

cd src && composer test -- tests/Feature/Telegram/CartCheckoutTest.php --filter='test_checkout_callback_creates_manual_order_sends_payment_instructions_and_notifies_admin|test_manual_checkout_enqueues_outbound_notifications_without_inline_photo_or_admin_send'
Tests: 2 passed (15 assertions)

cd src && composer test -- tests/Feature/Telegram/PaymentProofAdminCallbackTest.php tests/Feature/AdminDashboardSalesRenderingTest.php --filter='test_sales_proof_post_retry_skips_in_progress_send_after_worker_crash|test_sales_proof_manual_retry_uses_publisher_path_without_changing_order_state'
Tests: 2 passed (11 assertions)

cd src && composer test -- tests/Feature/StorefrontShopCheckoutTest.php tests/Feature/Storefront/KlikQrisStorefrontTest.php
Tests: 47 passed (598 assertions)

cd src && composer test -- --exclude-filter=AuditValidationTemporaryTest
Tests: 1,402 passed, 34 skipped (9,904 assertions)

cd src && composer test -- --filter=test_public_telegram_checkout_keeps_claim_after_ambiguous_customer_send
Tests: 1 failed (2 assertions)
Failure: public checkout path must retain the claim after an ambiguous Telegram send; failed because the claim was NULL.

cd src && composer test -- --filter=test_public_checkout_reaches_a_real_local_telegram_http_boundary_before_ambiguous_failure
Tests: 1 passed (7 assertions)
Observed: the local TCP server recorded `/sendPhoto`, marked the request accepted, closed the connection, and the public flow left the claim NULL.
```

Kesimpulan feedback loop: hasil audit sekarang memiliki dua lapis bukti yang
terpisah. Public-path tests memastikan flow yang akan diterima customer/admin
memang dieksekusi, sedangkan draft red-capable tests menunjukkan perilaku aman
yang belum ada dan gagal pada kode sekarang. Karena tidak ada perubahan behavior
yang dilakukan pada Stage 1, acceptance checks ini tidak dimaksudkan sebagai
regression pass; mereka adalah bukti reproduksi dan baseline yang dapat menjadi
regression test pada tahap implementasi.

---

## 5. Admin login rate limiting hanya per login+IP

### Status

**Confirmed.** Selain bucket scope yang terlalu sempit, ada observasi sekunder bahwa check dan hit dilakukan terpisah.

### Root cause aktual

`src/app/Http/Controllers/Admin/AdminLoginController.php`:

- Key dibangun sebagai `Str::lower($login).'|'.$request->ip()` pada `:40-44`.
- Block dilakukan terhadap key itu pada `:44-49`.
- Bad credential dan forbidden account hanya melakukan `RateLimiter::hit($throttleKey, 60)` pada `:64-79`.
- Sukses hanya membersihkan bucket identifier tersebut pada `:88-90`.

Akibatnya satu IP dapat mencoba identifier berbeda tanpa berbagi counter. Ini berbeda dari precedent `ApplicationRateLimiterRegistrar` untuk customer login, yang memiliki bucket per email+IP **dan** bucket per IP pada `src/app/Bootstrap/ApplicationRateLimiterRegistrar.php:141-149`.

### Bukti reproduksi

Draft test `test_admin_login_throttle_blocks_a_sixth_bad_attempt_from_the_same_ip_across_logins` pada `src/tests/Feature/AuditValidationTemporaryTest.php:136-164`:

1. POST lima bad login dengan email berbeda dari IP `198.51.100.77`.
2. POST login keenam dari IP sama dengan email baru.
3. Harapkan pesan rate limit.

Hasil aktual:

```text
FAIL admin login throttle blocks a sixth bad attempt from the same ip across logins
Expected: Email atau password admin tidak cocok.
To contain: Terlalu banyak percobaan login
```

Lima assertion awal terhadap bad credentials lolos; bucket global per IP memang belum ada.

### Skenario production konkret

Attacker memakai satu IP dan menggilir username/email admin. Setiap identifier mendapat lima percobaan sendiri, sehingga jumlah percobaan password dari satu IP dapat meningkat berkali-kali lipat dari limit nominal lima. Ini memperbesar ruang brute force dan beban hash password.

### Pendekatan perbaikan yang divalidasi

- Pertahankan `identifier+IP` untuk mencegah fokus brute force pada satu account.
- Tambahkan counter global `admin-login:ip:{ip}` dengan limit konservatif yang disepakati saat planning.
- Saat gagal, hit kedua bucket. Saat sukses, jangan membersihkan bucket global IP secara otomatis hanya karena satu login berhasil; kebijakan reset harus dibahas karena keberhasilan pada satu identifier tidak membuktikan IP aman.
- Laravel 13 rate limiting docs menyarankan memakai nilai `increment()` untuk endpoint dengan request simultan, karena `tooManyAttempts()` lalu `increment()` terpisah dapat mengalami race dan `increment()` pada store tertentu bersifat atomik.

Rujukan resmi: Laravel [Rate Limiting](https://laravel.com/docs/13.x/rate-limiting), khususnya manual increment/atomic increment dan clearing attempts.

### Observasi concurrency yang belum dipromosikan menjadi finding terpisah

Controller saat ini memakai pola check-then-hit terpisah. Saya tidak mengklaim reproduksi concurrency lokal karena `phpunit.xml` memaksa cache array, bukan backend production yang memberi atomic counter lintas worker. Ini dicatat sebagai acceptance criterion untuk ticket hardening #5, bukan sebagai temuan terpisah pada Gate 1.

### Nasib test sementara

**Disimpan sebagai draft belum di-commit.**

---

## Bukti command dan perubahan lokal

Syntax check:

```text
cd src && php -l tests/Feature/AuditValidationTemporaryTest.php
No syntax errors detected
```

Reproduksi gabungan:

```text
cd src && composer test -- --filter=AuditValidationTemporaryTest
Tests: 6 failed, 1 passed (28 assertions)
```

Working tree setelah audit:

```text
?? docs/audit/
?? src/tests/Feature/AuditValidationTemporaryTest.php
```

Tidak ada perubahan pada `src/app`, `src/config`, `src/routes`, migration, production, `CONTEXT.md`, atau ADR. Tidak ada commit dibuat.

## Sumber dokumentasi resmi yang dipakai

1. Laravel Framework v13.16.1 package metadata: diverifikasi dengan `composer show laravel/framework`.
2. [Laravel 13 Queues](https://laravel.com/docs/13.x/queues): unique jobs, jobs/database transactions, attempts/backoff.
3. [Laravel 13 Query Builder](https://laravel.com/docs/13.x/queries): pessimistic locking dan `lockForUpdate()`.
4. [Laravel 13 Rate Limiting](https://laravel.com/docs/13.x/rate-limiting): limiter key, manual attempts, atomic `increment`, dan clearing.
5. [Laravel 13 Eloquent Mass Assignment](https://laravel.com/docs/13.x/eloquent#mass-assignment): `$fillable`/`$guarded` protection.
6. [Telegram Bot API](https://core.telegram.org/bots/api), terutama [sendMessage](https://core.telegram.org/bots/api#sendmessage) dan [sendPhoto](https://core.telegram.org/bots/api#sendphoto): parameter dan response resmi. Tidak ditemukan parameter/documented outbound idempotency key.
7. [AWS Transactional Outbox Pattern](https://docs.aws.amazon.com/prescriptive-guidance/latest/cloud-design-patterns/transactional-outbox.html): solusi dual-write DB + notification dan consumer idempotency.
8. [AWS Builders’ Library: Making retries safe with idempotent APIs](https://aws.amazon.com/builders-library/making-retries-safe-with-idempotent-APIs/): timeout/ambiguous side effect dan kebutuhan idempotency/reconciliation.
9. [AWS SQS at-least-once delivery](https://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/standard-queues-at-least-once-delivery.html): duplicate delivery dan idempotent consumer.

## Gate 1 decision

Validasi source dan feedback loop selesai. Status yang dapat dibawa ke planning adalah:

- Confirmed: #1, #2, #3, #5, dan expansion #6a.
- Partially true: #4.
- Observation untuk dibahas bersama #5: check/hit limiter non-atomik.
- False positive: tidak ada dari lima temuan awal.

**Berhenti di Gate 1. Saya menunggu konfirmasi eksplisit manusia sebelum menjalankan `/grill-with-docs` atau menulis planning/ADR/CONTEXT final.**
