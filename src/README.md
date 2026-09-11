# Telegram Bot Commerce — Panduan Operasional High-Level

Dokumen ini menjelaskan gambaran besar cara kerja project Telegram bot commerce ini: alur customer, pembayaran, operasi admin, dan hal-hal penting untuk menjalankan aplikasi di production.

Dokumen ini **bukan** API reference detail dan tidak membahas setiap class/method. Untuk detail teknis, lihat file referensi di bagian akhir.

Untuk agent/AI yang perlu memahami ownership aturan bisnis, alur lintas Telegram/Web Storefront/Admin, invariant payment, dan peta file, baca [panduan codebase](../docs/agents/codebase.md). Istilah canonical ada di [CONTEXT.md](../CONTEXT.md), sedangkan keputusan arsitektur ada di [docs/adr](../docs/adr/).

## 1. Overview Project

Store Bot digunakan sebagai antarmuka penjualan lewat Telegram. Customer membuka katalog, memilih produk, memasukkan jumlah, checkout, lalu menerima instruksi pembayaran. Admin mengelola katalog/order dari backend Laravel dan menjalankan operasi seperti menyelesaikan order manual, mengirim ulang receipt, dan rekonsiliasi pembayaran.

Komponen utama:

- **Store Bot**: antarmuka Telegram untuk customer. Update dari Telegram diterima melalui webhook `POST /api/webhook/telegram`.
- **Web Storefront**: katalog publik dan guest checkout melalui `/shop`, signed invoice, dan Recovery Key; tidak bergantung pada Telegram.
- **Laravel backend**: menyimpan customer, catalog, cart, order, payment, audit log, dan menjalankan business flow.
- **Payment Provider Switch**: owner memilih `Manual` atau `KlikQRIS` untuk checkout baru. Setting Pakasir aktif dimigrasikan ke KlikQRIS; Payment lama tetap memakai provider asal.
- **Payment rails**: Manual QR, KlikQRIS Native QRIS, Binance Pay ID, dan USDT BEP20 pada BNB Smart Chain. Pakasir hanya menjadi compatibility lane untuk Payment lama sampai retirement gate lulus.
- **Admin operations**: operasi manual oleh admin/support/finance, seperti validasi pembayaran manual, resend receipt, dan rekonsiliasi payment legacy.
- **Queue**: memproses update Telegram, instruksi checkout, notification, receipt, Sales Proof Post, broadcast, dan verifikasi Binance/BSC pada queue terpisah yang sama.
- **Scheduler**: menjalankan expiry order, reconciliation KlikQRIS/Pakasir legacy/Binance/BSC, recovery Telegram, cleanup, dan statistik harian.

Batasan dokumen: fokus pada pemahaman sistem dan operasional production. Detail implementasi, schema database, dan API internal sebaiknya dibaca langsung dari codebase/test.

## 2. User / Customer Flow

Alur customer berjalan dari Telegram:

1. Customer mengirim `/start`.
2. Bot membuat atau menggunakan ulang data customer Telegram, lalu mengirim satu banner dengan metrik toko terlokalisasi dan persistent main menu. Tautan `/start product_ID` langsung membuka detail produk.
3. Customer juga bisa membuka katalog dengan `/katalog`, atau mencari produk langsung dengan `/cari keyword`.
4. `/produk`, `/product`, dan tombol Lihat Produk menampilkan entri Product Family dan Product mandiri; setiap baris hanya memuat nomor, icon, nama, dan total stok, maksimal 10 entri per halaman. Tombol family memakai icon Product dan maksimal dua per baris.
5. Customer menekan satu family untuk melihat quote setiap Product/SKU di pesan katalog yang sama. Tombol opsi memakai ikon yang sama dan maksimal dua per baris; setiap quote dirender sebagai blok kutipan Telegram.
6. Customer memilih satu Product option. Callback mengedit pesan yang sama menjadi Product Detail ketika callback membawa `message_id`; Product ID asli tetap diteruskan ke quantity, Cart, checkout, Order, inventory, waitlist, dan delivery tanpa membuat entitas variant baru.
7. Product Detail memisahkan informasi produk, harga/stok/deskripsi, quantity/subtotal, kontrol quantity, payment rail, dan navigasi kembali.
8. Quantity dimulai dari `1`, dapat dinaikkan/diturunkan dengan `−`/`+`, atau diisi sekali melalui `Enter custom quantity`. Input custom yang tidak valid tetap di mode input; Cancel kembali ke quantity sebelumnya yang masih valid terhadap live stock.
9. Customer menekan QRIS, atau Binance bila rail tersebut aktif dan produk memiliki harga USDT. Tidak ada halaman payment-choice atau generic checkout tambahan dalam direct purchase flow.
10. Saat checkout, sistem membuat Order dan Payment dengan status awal pending, lalu mengirim instruksi payment melalui queue Telegram outbound.
11. Customer dapat membatalkan Cart aktif dengan `/batal`, mengecek status order terakhir dengan `/status` atau `/pesanan`, melihat riwayat dengan `/riwayat`, dan membuka detail invoice dengan `/order NOMOR_INVOICE`.
12. Untuk melihat contoh family seperti Canva, ChatGPT, Gemini, Netflix, dan Surfshark di database lokal, jalankan `php artisan catalog:seed-demo`.

Diagram sederhana:

```text
/start, /katalog, /produk, atau tombol Lihat Produk
        ↓
Daftar Product Family dan Product mandiri (maksimal 10 entri per halaman)
        ↓
Pilih family untuk melihat opsi Product/SKU
        ↓
Product Detail (quantity 1)
        ↓
− / + atau Enter custom quantity
        ↓
QRIS atau Binance langsung
        ↓
Order + payment pending dibuat
        ↓
Instruksi payment dikirim
        ↓
Payment paid / expired / failed / refunded
```

Catatan:

- Selection state memiliki token singkat dan live-stock revalidation. Tombol lama, malformed, atau tampered tidak membuat Cart, Order, Payment, reservation, provider instruction, maupun Telegram Outbound Effect.
- QRIS selalu ditampilkan lebih dulu; Binance hanya muncul ketika konfigurasi Binance aktif dan produk memiliki harga USDT.
- `/batal` membatalkan Cart aktif, bukan membatalkan seluruh histori Order.
- `/cari keyword` mencari product aktif. Hasilnya memakai tombol angka yang sama untuk membuka Product Detail.
- `/status` dan `/pesanan` menampilkan Order terakhir customer dengan kombinasi status Order dan status Payment.
- `/riwayat` menampilkan hingga 5 Order terakhir customer Telegram tersebut dengan tombol detail; `/order NOMOR_INVOICE` menampilkan detail Order milik customer yang sama.
- Ikon katalog family/option disimpan sebagai data Product melalui field opsional `Ikon katalog` di modal Tambah/Edit produk. Kosongkan field untuk memakai icon brand default.
- Nilai icon katalog dapat diubah langsung dari Admin Dashboard tanpa mengubah `.env`, rebuild image, atau clear config.
- Store Bot memakai `editMessageText` dengan `callback_query.message.message_id` untuk family, category, quote, detail, dan navigasi balik satu pesan. `sendMessage` hanya dipakai bila tidak ada pesan asal atau Telegram menolak edit secara definitif; timeout/connection failure tidak memicu blind resend.

## 3. Payment Flow

### 3.1 Status utama

Order status yang digunakan:

- `pending_payment`
- `processing`
- `completed`
- `cancelled`
- `expired`

Payment status yang digunakan:

- `pending`
- `paid`
- `failed`
- `expired`
- `refunded`

### 3.2 Kapan order dan payment dibuat

Order dibuat saat checkout dari cart aktif yang valid. Pada saat itu sistem:

- membuat order dengan status `pending_payment`;
- membuat payment dengan status `pending`;
- menyimpan item order;
- melakukan reservasi stok;
- menetapkan waktu kedaluwarsa berdasarkan konfigurasi TTL pending payment.

Jika cart yang sama sudah pernah checkout, service checkout akan menggunakan order yang sudah ada agar tidak membuat duplikasi order.

### 3.3 Manual payment

Pada jalur manual:

1. Customer checkout dari Telegram.
2. Sistem membuat order dan payment provider `manual`, status `pending`.
3. Bot mengirim foto QR manual LHIE STORE beserta invoice, total, batas pembayaran, dan instruksi singkat, dengan tombol `Sudah Bayar` dan `Batalkan`.
4. Setelah pembayaran dilakukan, customer menekan `Sudah Bayar`. Bot memvalidasi ulang Order milik customer dan mengedit caption QR yang sama menjadi instruksi pengiriman bukti foto melalui `/payment`.
5. Admin memvalidasi pembayaran di luar sistem, misalnya dari bukti transfer customer.
6. Admin menjalankan operasi “complete manual order” dari dashboard/admin operation.
7. Sistem menandai payment sebagai `paid`, mengurangi stok dari reservasi menjadi penjualan, mengubah order menjadi `completed`, lalu mengirim receipt lewat queue.

Manual payment dianggap pending sejak checkout sampai admin menyelesaikan order atau order kedaluwarsa/dibatalkan oleh proses lain.

### 3.4 Pakasir legacy settlement

Pakasir is retired for new checkout. It is no longer available in the Payment Provider Switch, and neither Store Bot nor Web Storefront may create a new Pakasir Payment. Existing `provider=pakasir` Payments remain immutable historical records.

Until the retirement gate passes, the compatibility lane may process an existing pending Pakasir Payment. Callback/webhook input remains untrusted; paid is applied only after Transaction Detail verification matches the stored merchant reference, amount, project, completed status, and payment method when available.

- `PAID`: the existing Payment goes through `OrderLifecycle`, inventory sale, receipt, and any eligible Sales Proof Post exactly once.
- `EXPIRED`/`FAILED`: the existing Order is released through the normal lifecycle and inventory reservation rules.
- provider cancellation attempts remain idempotent and sanitized.
- `php artisan pakasir:retirement-status --json` reports terminal Payments, cancellation work, queued legacy payloads, and owner approval. Record approval with `php artisan pakasir:retirement-approve <owner-id-or-email>` only after settlement is clear.

Do not relabel historical Pakasir Payments as KlikQRIS or Manual. Do not use this compatibility lane to create hosted checkout or Native QRIS transactions.

### 3.5 KlikQRIS Native QRIS

KlikQRIS dipilih melalui Payment Provider Switch di **Admin → Mode pembayaran** dan hanya berlaku untuk checkout baru. Lifecycle terpusat di `App\Services\KlikQris\KlikQrisNativeQrisService`, yang mengurus create, recovery, persistence, expiry, payability, safe outcome, dan single-flight lock per Payment.

Outcome yang dapat diterima caller adalah `Payable`, `Creating`, `RecoveryPending`, `ProviderTotalPending`, `InvalidProviderResult`, dan `Expired`. Store Bot maupun Web Storefront hanya boleh menampilkan QR ketika outcome `Payable`; outcome lain tidak boleh menampilkan QR, total final, atau raw provider data.

Jika hasil create tidak pasti, sistem melakukan status recovery pada Payment/invoice yang sama. Tidak ada Payment kedua dan tidak ada automatic fallback ke Manual. Callback KlikQRIS hanya signal; `KlikQrisCallbackProcessor` melakukan status lookup authoritative sebelum memanggil `OrderLifecycle`. Scheduler menjalankan `klikqris:reconcile` ketika environment aktif. Reconciliation memakai cursor rotasi durable per environment: tiap sweep melanjutkan dari posisi terakhir, sehingga Payment yang selalu eligible (mis. `pending`/`outcome_unknown` yang belum maju) tidak men-starve Payment yang lebih baru. Sweep juga dibatasi execution budget sehingga satu provider lambat tidak membuat siklus berikutnya overlap. Detail keputusan ada di [ADR-0006](../docs/adr/0006-klikqris-native-qris-and-pakasir-retirement.md) dan runbook ada di [KlikQRIS sandbox validation](../docs/klikqris-sandbox-validation.md).

### 3.6 Binance Pay ID

Binance disimpan sebagai `provider=manual` dengan `method=BINANCE_PAY_ID`, sehingga caller harus memeriksa `method` dan tidak boleh menyimpulkan rail hanya dari provider. Verifikasi otomatis dikelola `BinancePaymentVerification` dalam mode `amount_unique` atau `order_id`; mismatch dan bukti terlambat tidak auto-claim dan dapat masuk manual review.

### 3.7 USDT BEP20 (BSC)

USDT BEP20 adalah Binance Payment Rail kedua dengan `provider=manual` dan `method=USDT_BEP20`. Authority-nya adalah BNB Smart Chain, bukan Binance Pay history. Payment menyimpan snapshot BSC Receiving Address, approved USDT contract, expected token units, unique payable amount, TX-hash attempts, confirmation state, dan sanitized evidence.

BSC tetap disabled sampai Owner mengaktifkan `bsc_payment_enabled` dari dashboard setelah `bsc:check-readiness --json` menunjukkan RPC BNB Smart Chain Mainnet, approved contract metadata/18 decimals, dukungan `eth_getLogs`, dan minimal satu receiver aktif. Address disimpan di database, tidak di ENV atau hardcoded source. ENV hanya berisi `BSC_RPC_URL`; identity jaringan/token, explorer, timeout, scan limit, dan readiness TTL dipin di application configuration. Owner cukup memasukkan address EVM yang valid; address baru langsung disimpan sebagai receiver aktif tanpa wallet-signature challenge. Mutasi receiver tetap memerlukan Owner re-authentication dan meng-invalidate readiness serta Storefront response cache setelah commit.

`bsc:reconcile` memakai worker `binance-verification` yang sama. Transfer exact yang sudah sukses tetapi belum mencapai tiga confirmations tetap `confirming`; RPC failure menjadi `provider_error` dan tidak melepaskan reservasi. Transfer yang masuk setelah Order expired/cancelled menjadi Late Payment: lifecycle menandai Payment paid, membuat `ManualFulfillmentReview`, mempertahankan Order terminal, dan tidak melakukan fulfillment otomatis. Owner menyelesaikannya melalui manual-review flow.

## 4. Admin Flow

Admin flow berfokus pada operasi support/finance, bukan percakapan Telegram customer.

Kemampuan admin yang terlihat di codebase:

- melihat dan mengelola data order/payment melalui admin panel;
- menyelesaikan manual order setelah pembayaran diverifikasi;
- mengirim ulang receipt untuk order paid yang memiliki customer Telegram;
- mengelola setting pembayaran manual seperti instruksi QR manual, kontak admin, dan mode Binance/BSC;
- mencatat audit log untuk operasi penting admin.

Alur operasional umum:

```text
Customer checkout
        ↓
Order masuk pending payment
        ↓
Admin cek dashboard/order
        ↓
Jika manual: validasi bukti bayar
        ↓
Klik/eksekusi complete manual order
        ↓
Receipt dikirim ke customer via queue
        ↓
Audit log operasi admin tersimpan
```

```text
        ↓
Callback masuk otomatis atau status belum sinkron
        ↓
Admin dapat trigger reconcile
        ↓
        ↓
Status internal diperbarui bila payload valid
        ↓
Audit log manual reconciliation tersimpan
```

Receipt hanya bisa dikirim ulang untuk order yang sudah paid dan masih dalam status operasional yang sesuai (`processing` atau `completed`) serta memiliki customer Telegram.

## 5. Operational Guide

### 5.0 Docker startup and dogfood smoke checks

The production Docker Compose stack pins migration ownership and startup
optimization in `docker-compose.yml`; the release workflow overrides those
flags explicitly for one-off migration commands. The normal production `.env`
does not need lifecycle or performance tuning variables.

The entrypoint runs `php artisan optimize` after the release-controlled
migration step. This creates Laravel's production cache artifacts for the live
container environment: cached config, routes, events, and compiled Blade views.
The command runs at container startup rather than during `docker build`,
because the cached config contains runtime values such as `APP_KEY`, database
credentials, Telegram credentials, and Pakasir credentials. Do not bake
`bootstrap/cache/config.php` into the image.

The production-like Docker stack also starts a dedicated `scheduler` service from the same application image. The scheduler service does not run migrations; it waits for the healthy app service, then invokes Laravel's scheduler with `php artisan schedule:run` every minute and writes a heartbeat to `storage/framework/scheduler-heartbeat`.

Production-like local dogfooding should target Docker/Nginx/PostgreSQL, not `php artisan serve` with SQLite:

```bash
./scripts/dogfood-volume-preflight.sh

POSTGRES_PASSWORD=dogfood_password \
APP_KEY=base64:replace-with-generated-key \
TELEGRAM_BOT_TOKEN=dogfood-token \
TELEGRAM_WEBHOOK_SECRET=dogfood-secret \
DOGFOOD_TIMING_ENABLED=true \
DOGFOOD_TIMING_LOG_CHANNEL=stderr \
DOGFOOD_FAKE_TELEGRAM_OUTBOUND=true \
WEB_PORT=18080 \
docker compose -p telegram-bot-dogfood up -d --build

SMOKE_COMPOSE_PROJECT=telegram-bot-dogfood \
BASE_URL=http://127.0.0.1:18080 ./scripts/smoke-production.sh

POSTGRES_PASSWORD=dogfood_password WEB_PORT=18080 \
docker compose -p telegram-bot-dogfood exec app \
php artisan admin:bootstrap-owner owner@example.test

PLAYWRIGHT_BASE_URL=http://127.0.0.1:18080 \
PLAYWRIGHT_ADMIN_EMAIL=owner@example.test \
PLAYWRIGHT_ADMIN_PASSWORD='replace-with-password' \
PLAYWRIGHT_TELEGRAM_WEBHOOK_SECRET='replace-with-secret' \
npm run test:browser:docker

POSTGRES_PASSWORD=dogfood_password WEB_PORT=18080 \
docker compose -p telegram-bot-dogfood exec --user laravel \
  -e DOGFOOD_ADMIN_EMAIL=owner@example.test \
  -e DOGFOOD_ADMIN_PASSWORD='replace-with-password' \
  -e DOGFOOD_FAKE_TELEGRAM_OUTBOUND=true \
  app \
php scripts/perf-dogfood.php > storage/logs/perf-dogfood.json
```

Use `./scripts/benchmark-production.sh` as the default repeatable synthetic Docker release gate. It owns its fixtures, starts the Docker/Nginx/PostgreSQL/Supervisor topology, uses fake Telegram outbound by default, enforces budgets, and fails on non-zero final queue depth or failed jobs.

Use `php scripts/perf-dogfood.php` when you need the broader scenario matrix and per-scenario timing JSON. With `DOGFOOD_FAKE_TELEGRAM_OUTBOUND=true`, the broad harness runs in `synthetic` mode: Telegram outbound goes through `FakeTelegramBotClient`, and fixture-dependent real-external scenarios are reported under `blocked_scenarios` when their fixtures are absent. With fake outbound disabled, the harness runs in `real-external` mode and only real disposable Telegram, Store Channel, callback, and photo fixtures should be supplied.

External dogfood scenarios require real disposable fixtures. Leave missing values blank; `php scripts/perf-dogfood.php` records those scenarios as `blocked` before queue work starts instead of using fake Telegram data. Its JSON output includes `run_mode`, `blocked_scenarios`, and `final_queue_state`; after a synthetic run, `final_queue_state.jobs_depth` should be `0` and `final_queue_state.passes_ops_check_failed_jobs_max_0` should be `true`.

Dogfood timing logs should use `DOGFOOD_TIMING_LOG_CHANNEL=stderr` for production-like Docker runs, and the broad perf script should be executed with `docker compose exec --user laravel app`. The perf script also forces its own Laravel logger to `stderr`. This keeps benchmark instrumentation in Docker logs instead of writing additional timing records to `storage/logs`, so a CLI timing run cannot make PHP-FPM or the Supervisor queue worker lose write access to Laravel's file logs.

- `DOGFOOD_TELEGRAM_PHOTO_FILE_ID`: real Telegram `photo.file_id` from the dogfood bot chat for `/payment` proof forwarding.
- `DOGFOOD_ADMIN_EMAIL`, `DOGFOOD_ADMIN_PASSWORD`: safe dogfood admin account used by the performance script to log in through the real admin form and submit CSRF-protected admin mutations. If unset, the script falls back to `PLAYWRIGHT_ADMIN_EMAIL` and `PLAYWRIGHT_ADMIN_PASSWORD`.
- `DOGFOOD_STORE_CHANNEL_CHAT_ID`, `DOGFOOD_STORE_CHANNEL_USERNAME`, `DOGFOOD_STORE_CHANNEL_LINK`: disposable Store Channel target where the bot can post.
- `DOGFOOD_STORE_CHANNEL_MEMBER_USER_ID`, `DOGFOOD_STORE_CHANNEL_NON_MEMBER_USER_ID`: Telegram identities used to measure Community Gate member/non-member paths.
- `DOGFOOD_TELEGRAM_CALLBACK_QUERY_ID`: fresh callback query ID from a real Telegram tap; it expires quickly, so set it only for that run.

Run `./scripts/dogfood-volume-preflight.sh` before a production-like performance run. It lists Docker volumes for the `telegram-bot-dogfood` Compose project by the `com.docker.compose.project` label and exits non-zero when existing dogfood volumes are present. Existing dogfood volumes can preserve old PostgreSQL roles/databases and make a fresh build look unhealthy for reasons unrelated to current code.

If the dogfood volumes are intentionally disposable, preserve any raw artifacts first: copy performance JSON files, app logs, Docker logs, screenshots, and notes from `storage/logs/`, `.slim/deepwork/`, or other run-specific locations. Then reset only the dogfood project volumes:

```bash
./scripts/dogfood-volume-preflight.sh --reset --yes
```

For parallel dogfood runs, pass an explicit dogfood project name:

```bash
./scripts/dogfood-volume-preflight.sh --project telegram-bot-dogfood-ci --reset --yes
```

The helper refuses destructive resets for project names that do not contain `dogfood`; do not use it for default or production Compose projects.

The production smoke checks `/up`, app operational health, and the scheduler heartbeat when `SMOKE_COMPOSE_PROJECT` or `COMPOSE_PROJECT_NAME` is set. The Docker browser smoke validates the deployed HTTP surface, admin login/dashboard, mobile overflow, unauthenticated admin redirect, and Telegram webhook fail-closed behavior. It does **not** prove end-to-end Telegram delivery through Telegram's real network unless the environment is configured with a real bot token, a public HTTPS webhook URL, and a controlled Telegram test account.

The perf dogfood JSON includes `manual_checkout_qr_report`, which breaks the manual QR checkout into default update queue wait, update-worker processing, manual outbound notification job queue wait/processing, order checkout, QR file read, customer `sendPhoto`, and admin `sendMessage` timings. Use the dominant step from that report before making any checkout optimization.

For a repeatable production-like benchmark with pass/fail budgets and no real Telegram sends for synthetic handler scenarios, run:

```bash
./scripts/benchmark-production.sh
```

The command writes `report.json` and `report.md` under `artifacts/production-benchmark/<timestamp>/`, reports p50/p95/max latency, status counts, queue depth, failed jobs, health, and container memory, and exits non-zero when budgets fail. Details and budget environment variables are documented in `../docs/production-benchmark.md`.

Run the PostgreSQL concurrency deploy gate before shipping changes that affect inventory, cart, checkout, payment, order expiry, or admin stock operations.

For a local machine without a PostgreSQL service, use the disposable Docker-backed gate:

```bash
cd src
composer test:concurrency-gate:local
```

The local wrapper starts `postgres:16-alpine` on `127.0.0.1:55432`, waits for readiness, runs the same gate, removes the container on exit, and retains the report under a writable temporary directory. Override `CONCURRENCY_POSTGRES_PORT`, `CONCURRENCY_POSTGRES_IMAGE`, or `CONCURRENCY_GATE_ARTIFACT_DIR` when needed. The default credentials are disposable local-test values only.

If PostgreSQL is already running, the lower-level gate can use it directly:

```bash
cd src
CONCURRENCY_DB_HOST=127.0.0.1 \
CONCURRENCY_DB_PORT=5432 \
CONCURRENCY_DB_ADMIN_DATABASE=postgres \
CONCURRENCY_DB_ADMIN_USERNAME=postgres \
CONCURRENCY_DB_ADMIN_PASSWORD='postgres-password' \
CONCURRENCY_DB_APP_USERNAME=postgres \
CONCURRENCY_DB_APP_PASSWORD='postgres-password' \
composer test:concurrency-gate
```

Both commands create a one-off PostgreSQL database named like `telegram_bot_concurrency_test_*`, run the `concurrency` PHPUnit group with `RUN_CONCURRENCY_TESTS=1`, fail if PHPUnit reports any skipped concurrency test, and drop the database during cleanup. Attach the generated `report.md` to the issue or PR so reviewers can see the test count, skipped count, failures, and exit code.

Use the faster default suite for ordinary edits:

```bash
cd src
composer test
```

Use the concurrency gate only when stock/order behavior or deployment readiness depends on real PostgreSQL locking semantics. The default suite remains SQLite-backed for fast feedback and should not be treated as proof that process-level stock races are safe.

### 5.1 Webhook Telegram

Endpoint aplikasi:

```text
POST /api/webhook/telegram
```

Hal penting:

- gunakan HTTPS di production;
- set webhook Telegram ke URL public endpoint tersebut;
- kirim/konfigurasi secret yang cocok dengan `TELEGRAM_WEBHOOK_SECRET`;
- request harus memiliki header `X-Telegram-Bot-Api-Secret-Token` yang valid;
- payload yang diproses adalah `message` dan `callback_query`;
- endpoint memiliki rate limiter `telegram`.

Jika secret Telegram tidak dikonfigurasi di production, aplikasi dirancang untuk fail fast.

### 5.2 Webhook dan validasi pembayaran otomatis

#### Pakasir

Endpoint aplikasi:

```text
POST /api/webhook/pakasir
```

Hal penting:

- gunakan HTTPS di staging/production;
- callback Pakasir adalah intake tidak tepercaya; paid hanya diterapkan setelah Transaction Detail API cocok dengan `merchant_ref`, amount, project, status `completed`, dan payment method bila tersedia;
- endpoint memiliki rate limiter `pakasir-webhook`; callback Pakasir valid yang identik tidak disimpan tanpa batas, tetapi di-coalesce berdasarkan hash payload ter-sanitasi;
- jangan menaruh API key, bot token, chat ID privat, URL privat, atau raw `payment_number` QRIS di komentar issue, log, screenshot, atau payload admin.

Untuk compatibility lane Payment Pakasir lama, verifikasi callback tetap berjalan melalui Transaction Detail dan reconciliation. Sebelum menghapus lane legacy, jalankan report redacted:

```bash
php artisan pakasir:retirement-status --json
php artisan pakasir:retirement-approve <owner-id-or-email>
php artisan migrate --path=database/contract-migrations/2026_08_26_000001_drop_pakasir_checkout_flow.php --force --isolated
```

Report must show `gate=ready_for_contract`, `settlement_ready=true`, `queue_safe=true`, `non_terminal_payments=0`, `dispatchable_cancellation_work=0`, `legacy_queue_payloads=0`, and `owner_approved=true` before running `database/contract-migrations/2026_08_26_000001_drop_pakasir_checkout_flow.php`. Until then, retain every Pakasir webhook, reconciliation, cancellation, and queue compatibility handler. Do not create a new Pakasir transaction to test the provider.

#### KlikQRIS

Endpoint aplikasi:

```text
POST /api/webhook/klikqris/sandbox
POST /api/webhook/klikqris
```

Gunakan endpoint sandbox hanya dari environment non-production dengan readiness KlikQRIS sandbox yang lengkap. Validasi sanitized report menggunakan:

```bash
php artisan klikqris:validate-sandbox <payment-uuid-or-merchant-ref> --create --reconcile
```

Callback KlikQRIS tidak cukup untuk menandai paid. Sistem harus mencocokkan Payment/provider identity, nominal, total final, dan status authoritative. Jangan menaruh API key, merchant ID, signature, QR URL query secret, raw QR base64, atau payload provider mentah pada report.

### 5.3 Queue worker

Queue diperlukan untuk pekerjaan background seperti pemrosesan update Telegram, pengiriman instruksi checkout manual, notifikasi admin, dan pengiriman receipt.

Checklist operasional:

- pastikan `QUEUE_CONNECTION` sesuai kebutuhan production; default project menggunakan database queue;
- jalankan queue worker di process manager seperti Supervisor;
- monitor tabel/job failed jobs;
- pastikan queue `default` diproses untuk update Telegram;
- pastikan queue `telegram-outbound` diproses untuk instruksi pembayaran manual dan notifikasi pending order yang berasal dari checkout;
- pastikan queue `notifications` diproses oleh worker pool terpisah karena receipt pembayaran, paid/expired notifications, Sales Proof Post, dan cleanup instruksi checkout dikirim melalui queue tersebut;
- pastikan queue `telegram-stock` diproses oleh worker terpisah untuk restock/added-stock notifications; satu job hanya mengirim satu recipient lalu melanjutkan pekerjaan melalui self-dispatch yang idempotent;
- worker count and sleep settings are pinned in `supervisord.conf`; scale them through a reviewed infrastructure change rather than adding more application `.env` knobs.

Production-like Docker runs separate Supervisor-managed workers: `laravel-update-worker` handles `default`, `laravel-outbound-worker` handles `telegram-outbound`, `laravel-notification-worker` handles `notifications`, `laravel-broadcast-worker` handles `telegram-broadcast`, and `laravel-stock-worker` handles `telegram-stock`. Stock intake commits only the durable notification handoff; recipient rendering/materialization runs after commit in bounded batches, so admin stock requests do not hold inventory transactions while querying every Telegram customer.

Manual checkout notification retry behavior:

- the job is unique per order while queued/running, so duplicate checkout callbacks cannot enqueue concurrent sends for the same order;
- successful customer QR/photo delivery sets `orders.manual_checkout_customer_notified_at`;
- recovery repairs a sent checkout effect's Order projection from ledger metadata before cleanup and never resends that effect;
- successful admin notification delivery, or a configured no-op because no admin chat exists, sets `orders.manual_checkout_admin_notified_at`;
- if the customer send succeeds and the admin send fails, retry sends only the admin notification;
- if the customer send fails before the timestamp is written, retry sends the customer instructions again because Telegram delivery was not confirmed durably.

Admin Broadcast memakai queue telegram-broadcast dengan batch maksimal 25 recipient dan shared cache limiter yang dipatok di application configuration pada `25` pesan/detik, sehingga laju tetap di bawah batas broadcast Telegram 30 pesan/detik. Respons HTTP 429 memakai nilai retry_after yang dibatasi; recipient yang belum due tetap pending dan ditemukan kembali oleh command telegram:recover-outbound setelah window retry aman.

- Restock notification delivery uses the same durable recipient/effect ledger as other Telegram fan-outs. Recipient snapshots are materialized in pages of 100, while the stock queue job sends at most one Telegram effect per execution and requeues remaining work.

Sebelum release perubahan copy Store Bot, jalankan audit katalog tiga locale:

```bash
php artisan telegram:translation-audit
```

Contoh command umum Laravel:

```bash
php artisan queue:work
```

Sesuaikan opsi worker, queue, timeout, dan supervisor config dengan environment production.

### 5.4 Scheduler / cron

Scheduler menjalankan command periodik dari `routes/console.php`:

```text
klikqris:reconcile --chunk=100 setiap menit jika environment aktif
pakasir:reconcile --chunk=100  setiap menit hanya untuk Payment legacy sampai retirement gate passed
binance:reconcile --chunk=100  setiap menit
sakurupiah:reconcile --chunk=100 setiap menit jika environment Sakurupiah enabled dan operational ready
orders:expire-pending           setiap menit
telegram:recover-updates        setiap menit
telegram:forward-payment-proofs setiap menit
telegram:recover-outbound       setiap menit
cleanup login links              setiap jam
cleanup retained data            setiap hari
```

Production-like Docker menjalankan service `scheduler` yang memanggil `php artisan schedule:run` setiap menit. Service ini menulis heartbeat ke `storage/framework/scheduler-heartbeat`; cek dengan:

```bash
php artisan ops:health --scheduler-heartbeat=180
```

Untuk deployment non-Docker, gunakan scheduler platform/host cron yang menjalankan Laravel scheduler setiap menit:

```bash
* * * * * cd /path/to/src && php artisan schedule:run >> /dev/null 2>&1
```

Sesuaikan path dengan lokasi deploy. Jangan jalankan command terjadwal satu per satu sebagai model runtime production; biarkan Laravel scheduler membaca `routes/console.php`.

### 5.5 Health check

Endpoint HTTP health Laravel:

```text
GET /up
```

Command operasional project:

```bash
php artisan ops:health --db --queue-schema --queue-depth --supervisor
php artisan ops:health --scheduler-heartbeat=180
```

Opsi tersedia:

- `--db`: cek koneksi database;
- `--queue-schema`: cek tabel penting untuk database queue/session/cache;
- `--supervisor`: cek proses yang dikelola Supervisor sesuai konfigurasi command.
- `--scheduler-heartbeat=<detik>`: cek heartbeat scheduler masih baru.

Smoke check admin sebelum deploy:

```bash
../scripts/smoke-admin-assets.sh
../scripts/smoke-admin-rendering.sh
```

`smoke-admin-assets.sh` menjalankan `npm run build`, lalu memastikan `public/build/manifest.json` berisi entry theme Filament admin yang dibutuhkan `/admin/login`.
`smoke-admin-rendering.sh` menjalankan build, membuat database SQLite sementara, login ke admin lewat browser pada viewport 375px, lalu memastikan Dashboard, Categories, Products, Payments, dan Audit Logs tidak memiliki overflow horizontal dokumen, aksi tabel tetap terjangkau, icon Filament tidak berukuran abnormal, atau scroll height kosong yang berlebihan.

### 5.6 Expire pending orders

Command manual:

```bash
php artisan orders:expire-pending --chunk=100
```

Fungsi:

- mencari order `pending_payment` yang sudah melewati `expires_at`;
- mengubah order menjadi `expired`;
- mengubah payment pending menjadi `expired`;
- melepas reservasi stok.

### 5.7 Reconcile Pakasir

Command manual:

```bash
php artisan pakasir:reconcile --chunk=100
```

Fungsi:

- mengambil Transaction Detail untuk payment Pakasir pending;
- memverifikasi project, `merchant_ref`, amount original, status, dan payment method bila tersedia;
- menerapkan status paid hanya setelah detail Pakasir valid;
- menyinkronkan expiry provider bila Transaction Detail mengirim `expired_at`.

### 5.8 Reconcile KlikQRIS

Command manual:

```bash
php artisan klikqris:reconcile --chunk=100
```

Fungsi:

- memproses Payment KlikQRIS yang pending atau memiliki outcome create yang perlu dipulihkan;
- menggunakan lock Payment yang sama dengan customer refresh dan callback;
- mengambil status provider authoritative dan menerapkannya melalui `KlikQrisCallbackProcessor`/`OrderLifecycle`;
- mengirim owner alert hanya untuk outcome actionable sesuai retry budget.

Command akan skip dengan aman bila KlikQRIS disabled untuk environment tersebut, dan gagal tertutup bila provider aktif tetapi readiness tidak lengkap.

### 5.9 Logging dan monitoring

Hal yang perlu dimonitor:

- error log Laravel;
- failed jobs;
- queue length/backlog;
- status scheduler berjalan tepat waktu;
- audit log admin untuk operasi manual penting;
- hasil `ops:health`.

Command ringan untuk memeriksa jumlah failed jobs:

```bash
php artisan ops:check-failed-jobs --max=0
```

## 6. Environment / Configuration Checklist

The root `.env.example` is intentionally short. Docker Compose supplies stable
database, queue, session, mail-default, worker, provider endpoint, timeout, and
rate-limit settings from the repository. The production `.env` only needs:

- [ ] `APP_ENV=production` and the real HTTPS `APP_URL`.
- [ ] `APP_KEY` and `POSTGRES_PASSWORD`.
- [ ] `TELEGRAM_BOT_TOKEN` and `TELEGRAM_WEBHOOK_SECRET`.
- [ ] `TRUSTED_PROXIES`/`TRUSTED_HOSTS` only when the deployment uses a reverse proxy or host allowlist.
- [ ] Admin identifiers/access policy when the corresponding Telegram/admin controls are used.
- [ ] Credentials for an enabled payment rail only: Pakasir legacy settlement, Binance verification, BSC RPC, KlikQRIS, or Sakurupiah.
- [ ] `GPG_RECIPIENT` or `GPG_PASSPHRASE` only on the VPS when the destructive contract workflow requires an encrypted backup.

Payment mode, provider endpoint, callback URL, fee, expiry, retry, rate-limit,
worker, and presentation values are not `.env` inputs. Payment Provider Switch
and Admin Dashboard settings control the mutable operational choices that
belong in the database.

## 7. Troubleshooting

### Bot tidak merespons

Kemungkinan penyebab:

- webhook Telegram belum diarahkan ke URL production yang benar;
- `TELEGRAM_BOT_TOKEN` salah;
- secret webhook tidak cocok;
- queue worker tidak berjalan;
- update Telegram masuk tetapi job gagal;
- rate limit tercapai.

Langkah cek:

- cek endpoint `GET /up`;
- cek log Laravel saat Telegram mengirim update;
- cek konfigurasi webhook Telegram di Bot API;
- cek `TELEGRAM_WEBHOOK_SECRET` dan header secret;
- cek queue worker dan failed jobs;
- jalankan `php artisan ops:health --db --queue-schema --queue-depth --supervisor`.

### Webhook Telegram gagal

Kemungkinan penyebab:

- URL tidak HTTPS atau sertifikat tidak valid;
- route salah, seharusnya `/api/webhook/telegram`;
- header `X-Telegram-Bot-Api-Secret-Token` tidak sesuai;
- payload bukan `message` atau `callback_query`;
- aplikasi tidak bisa akses database.

Langkah cek:

- pastikan domain public dapat diakses Telegram;
- cek access log/status code webhook;
- cek secret yang didaftarkan di Telegram;
- cek error log Laravel;
- cek koneksi database dengan `php artisan ops:health --db`.

### Pakasir tidak sinkron / belum paid

Kemungkinan penyebab:

- `PAKASIR_SLUG` atau `PAKASIR_API_KEY` salah/kosong sehingga Transaction Detail API tidak bisa memverifikasi payment.
- Payment Pakasir legacy tidak lagi memiliki checkout flow baru; gunakan `pakasir:retirement-status --json` untuk memeriksa kapan compatibility lane boleh dihapus.
- `order_id`/`merchant_ref`, nominal, project/slug, status, atau payment method dari callback/detail tidak cocok dengan payment internal.
- callback ditolak oleh throttle `pakasir-webhook`, shape gate, atau replay cache payload gagal.

Langkah cek:

- cek payment event yang masuk dan status payment internal;
- cek log untuk pesan validasi callback/reconciliation;
- jalankan reconcile manual hanya setelah credential Pakasir benar, karena paid tetap harus dikonfirmasi Transaction Detail API.

### KlikQRIS tidak menampilkan QR / belum paid

Kemungkinan penyebab:

- environment KlikQRIS belum ready atau provider switch belum diaktifkan owner;
- create masih `Creating`, `RecoveryPending`, atau `ProviderTotalPending`;
- QR URL tidak trusted, total final tidak valid, atau expiry provider sudah lewat;
- callback diterima tetapi status authoritative belum `SUCCESS`;
- scheduler/reconciliation atau queue worker tidak berjalan.

Langkah cek:

- cek readiness dan environment tanpa mencetak credential;
- buka invoice yang sama dan gunakan `Cek Pembayaran`, jangan membuat checkout kedua;
- cek `PaymentEvent` yang sudah disanitasi dan status Payment/Order;
- jalankan `php artisan klikqris:reconcile --chunk=100` di environment yang benar;
- gunakan `php artisan klikqris:validate-sandbox <payment> --create --reconcile` hanya pada sandbox disposable.

### Order pending tidak expired

Kemungkinan penyebab:

- scheduler tidak berjalan;
- command `orders:expire-pending` gagal;
- `expires_at` belum lewat;
- order bukan status `pending_payment`;
- ada error saat release stok/reservasi.

Langkah cek:

- pastikan service `scheduler` Docker sehat atau cron/platform menjalankan `php artisan schedule:run` setiap menit;
- jalankan manual `php artisan orders:expire-pending --chunk=100`;
- cek log Laravel;
- cek status order dan nilai `expires_at`;
- cek failed jobs bila expiry dipicu dari proses lain.

### Receipt tidak terkirim

Kemungkinan penyebab:

- queue worker mati atau queue `notifications` tidak diproses;
- customer tidak memiliki Telegram ID;
- order belum `paid`;
- order tidak dalam status yang valid untuk resend receipt;
- token Telegram salah atau API Telegram gagal.

Langkah cek:

- cek queue worker dan failed jobs;
- cek log pengiriman Telegram;
- pastikan order payment status `paid`;
- untuk resend, pastikan order berada di `processing` atau `completed` dan customer punya Telegram ID;
- cek `TELEGRAM_BOT_TOKEN`.

### Queue menumpuk atau failed

Kemungkinan penyebab:

- worker tidak berjalan;
- worker hanya memproses queue tertentu dan melewatkan queue penting;
- database queue table belum tersedia;
- timeout atau koneksi eksternal bermasalah.
- real Telegram/Pakasir outbound lambat dan memperpanjang durasi handler, walaupun queue wait masih rendah.

Langkah cek:

- jalankan `php artisan ops:health --queue-schema --supervisor`;
- jalankan `php artisan ops:check-failed-jobs --max=0` atau sesuaikan threshold operasional;
- cek proses Supervisor/systemd/container;
- cek tabel `jobs` dan `failed_jobs`;
- cek log exception job;
- bila sedang mendiagnosis burst latency, jalankan dogfood benchmark bersih dan bandingkan `queue_wait_ms`, `worker_processing_ms`, `dogfood.outbound_http`, failed jobs, dan memory sebelum menambah worker atau memisah queue;
- restart worker setelah deploy/config change sesuai prosedur Laravel.

### Admin operation tidak tercatat

Kemungkinan penyebab:

- operasi tidak melewati service admin yang mencatat audit;
- user admin tidak terdeteksi;
- transaksi gagal sebelum audit log dibuat;
- tabel/model audit bermasalah.

Langkah cek:

- pastikan operasi dilakukan dari flow admin yang resmi;
- cek log error saat operasi admin;
- cek data audit log untuk action seperti `order.manual_completed`, `order.receipt_resent`, atau `payment.manually_reconciled`;
- cek permission/role admin bila operasi tidak muncul.

## 8. Important Files / Reference

- `../docs/agents/codebase.md` — peta implementasi untuk agent/AI: ownership service, aggregate, flow Telegram/Web Storefront/Admin, payment rails, invariant, dan test map.
- `../CONTEXT.md` — glossary domain canonical.
- `../docs/adr/0006-klikqris-native-qris-and-pakasir-retirement.md` — keputusan KlikQRIS Native QRIS dan retirement Pakasir.
- `src/app/Http/Controllers/TelegramWebhookController.php` — validasi secret Telegram, filter payload, simpan update, dispatch job pemrosesan update.
- `src/app/Services/Telegram/TelegramUpdateProcessor.php` — command Telegram, katalog, cart, checkout manual, instruksi pembayaran, dan notifikasi admin manual order.
- `src/app/Services/Telegram/TelegramCheckoutIntents.php` — adapter yang mengubah Payment rail/outcome menjadi instruksi Telegram; tidak memiliki ownership transition Order.
- `src/app/Services/Checkout/PaymentProviderResolver.php` — resolusi active provider + payment intent untuk checkout baru.
- `src/app/Services/Admin/PaymentProviderSwitcher.php` — owner-only provider switch, readiness, version conflict, dan audit.
- `src/app/Services/KlikQris/KlikQrisNativeQrisService.php` — lifecycle KlikQRIS Native QRIS dan safe outcomes.
- `src/app/Services/KlikQris/KlikQrisCallbackProcessor.php` — callback signal + authoritative status verification.
- `src/app/Services/Pakasir/PakasirNativeQrisService.php` — legacy Pakasir native QRIS create/recovery.
- `src/app/Services/Binance/BinancePaymentVerification.php` — Binance verification workflow.
- `src/app/Services/Bsc/BscPaymentVerification.php` — authoritative BSC Payment verification and atomic claim/manual-review workflow.
- `src/app/Services/Bsc/BscReadiness.php` — DB activation, RPC/token metadata, and active receiver readiness.
- `src/app/Console/Commands/CheckBscReadiness.php` — command `bsc:check-readiness --json` for deployment validation.
- `src/app/Console/Commands/ReconcileBscPayments.php` — command `bsc:reconcile {--chunk=100}` using the shared Binance verification queue.
- `src/app/Storefront/StorefrontShopCheckout.php` — guest storefront checkout dan Recovery Key issuance.
- `src/app/Storefront/StorefrontShopPaymentStatusRefresh.php` — signed invoice status refresh/recovery adapter.
- `src/app/Services/Admin/AdminOrderOperations.php` — operasi admin untuk complete manual order dan resend receipt, termasuk audit log.
- `src/app/Services/OrderStateMachine.php` — transisi status order/payment, release reservasi, sale stock movement, refund, cancel, dan expiry.
- `src/app/Console/Commands/HealthCheck.php` — command `ops:health {--db} {--queue-schema} {--supervisor} {--bsc} {--sakurupiah}`.
- `src/app/Console/Commands/CheckFailedJobs.php` — command `ops:check-failed-jobs {--max=0}` untuk alert sederhana jumlah failed jobs.
- `src/app/Console/Commands/ExpirePendingOrders.php` — command `orders:expire-pending {--chunk=100}`.
- `src/app/Console/Commands/ReconcileKlikQrisPayments.php` — command `klikqris:reconcile {--chunk=100}`.
- `src/app/Console/Commands/ReconcileSakurupiahPayments.php` — command `sakurupiah:reconcile {--chunk=100}` dengan bounded scan, eager loading, dan single-flight lock per Payment.
- `src/app/Console/Commands/CheckSakurupiahReadiness.php` — command `sakurupiah:check-readiness {--json}` untuk laporan readiness/backlog redacted.
- `src/app/Console/Commands/ReconcilePakasirPayments.php` — command `pakasir:reconcile {--chunk=100}`.
- `src/app/Console/Commands/ReconcileBinancePayments.php` — command `binance:reconcile {--chunk=100}`.
- `src/routes/console.php` — jadwal scheduler untuk expire pending orders dan reconciliation payment.
- `src/config/orders.php` — konfigurasi TTL pending payment.
- `scripts/concurrency-deploy-gate.sh` — deploy gate PostgreSQL untuk race test inventory/cart/checkout/payment/admin stock.
- `src/phpunit.concurrency.xml` — konfigurasi PHPUnit khusus concurrency gate tanpa override SQLite default.
- `src/tests/Feature/Telegram/` — coverage flow customer Telegram, checkout manual, receipt, callback paid, dan rate limit.
- `src/tests/Feature/AdminOrderPaymentOperationsTest.php` — coverage operasi admin, resend receipt, complete manual order, reconcile payment, dan health check.
- `src/tests/Feature/ProductionReleaseHardeningTest.php` — coverage hardening operasional/security untuk release production.
- `src/tests/Feature/OrderCartDomainTest.php` — coverage cart/order state machine, expiry, cancel, paid/refund transitions, dan stok.
- `src/tests/Feature/CoreSchemaAndModelCastsTest.php` — coverage skema tabel inti, primary key UUID, dan cast enum/JSON model.
- `src/tests/Feature/AdminCatalogPolicyAndStockAuditTest.php` — coverage policy katalog per role, penyesuaian stok, dan audit log.
