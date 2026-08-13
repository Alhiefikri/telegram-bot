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
- **Payment Provider Switch**: owner memilih `Manual`, `Pakasir`, atau `KlikQRIS` untuk checkout baru. Payment yang sudah dibuat tetap memakai provider asal.
- **Payment rails**: Manual QR, Pakasir hosted/native QRIS, KlikQRIS Native QRIS, dan Binance Pay. KlikQRIS tidak melakukan automatic fallback ke Manual; outcome create yang tidak pasti dipulihkan pada Payment yang sama.
- **Admin operations**: operasi manual oleh admin/support/finance, seperti validasi pembayaran manual, resend receipt, dan rekonsiliasi payment legacy.
- **Queue**: memproses update Telegram, instruksi checkout, notification, receipt, Sales Proof Post, broadcast, dan verifikasi Binance pada queue terpisah.
- **Scheduler**: menjalankan expiry order, reconciliation KlikQRIS/Pakasir/Binance, recovery Telegram, cleanup, dan statistik harian.

Batasan dokumen: fokus pada pemahaman sistem dan operasional production. Detail implementasi, schema database, dan API internal sebaiknya dibaca langsung dari codebase/test.

## 2. User / Customer Flow

Alur customer berjalan dari Telegram:

1. Customer mengirim `/start`.
2. Bot membuat atau menggunakan ulang data customer Telegram, lalu menampilkan katalog kategori.
3. Customer juga bisa membuka katalog dengan `/katalog`, atau mencari produk langsung dengan `/cari keyword`.
4. Customer memilih kategori dari tombol inline, atau memilih hasil pencarian.
5. Bot menampilkan produk aktif dengan stok tersedia pada kategori tersebut atau dari hasil pencarian.
6. Customer memilih produk.
7. Bot menampilkan pilihan quantity berdasarkan stok tersedia. Customer dapat memilih tombol quantity, atau mengetik angka ketika bot sedang menunggu input jumlah.
8. Produk dimasukkan ke cart aktif.
9. Bot menampilkan tombol checkout/pembayaran.
10. Saat checkout, sistem membuat order dan payment dengan status awal pending.
11. Customer menerima instruksi pembayaran.
12. Customer dapat membatalkan cart aktif dengan `/batal`.
13. Customer dapat mengecek status order terakhir dengan `/status` atau `/pesanan`, melihat riwayat dengan `/riwayat`, dan membuka detail invoice dengan `/order NOMOR_INVOICE`.

Diagram sederhana:

```text
/start, /katalog, atau /cari keyword
        ↓
Pilih kategori / hasil pencarian
        ↓
Pilih produk
        ↓
Pilih/ketik quantity
        ↓
Produk masuk cart
        ↓
Checkout
        ↓
Order + payment pending dibuat
        ↓
Instruksi pembayaran dikirim
        ↓
Payment paid / expired / failed / refunded
```

Catatan:

- `/batal` membatalkan cart aktif, bukan membatalkan semua histori order.
- `/cari keyword` mencari produk aktif dengan stok tersedia. Jika hasil tidak ditemukan, customer diarahkan kembali ke `/katalog`.
- `/status` dan `/pesanan` menampilkan order terakhir customer dengan kombinasi status order dan status payment.
- `/riwayat` menampilkan hingga 5 order terakhir customer Telegram tersebut dengan tombol detail; `/order NOMOR_INVOICE` menampilkan detail order milik customer yang sama.

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
3. Bot mengirim QR manual LHIE STORE beserta invoice, total, batas pembayaran, detail item, dan instruksi kirim bukti foto dengan caption `/payment`.
4. Admin memvalidasi pembayaran di luar sistem, misalnya dari bukti transfer customer.
5. Admin menjalankan operasi “complete manual order” dari dashboard/admin operation.
6. Sistem menandai payment sebagai `paid`, mengurangi stok dari reservasi menjadi penjualan, mengubah order menjadi `completed`, lalu mengirim receipt lewat queue.

Manual payment dianggap pending sejak checkout sampai admin menyelesaikan order atau order kedaluwarsa/dibatalkan oleh proses lain.

### 3.4 Pakasir payment

Pakasir adalah provider legacy otomatis yang dapat dipilih owner dari Admin Dashboard. Ia memiliki dua flow: `hosted` dan `api_qris`. Hosted mengirim customer ke hosted-link checkout Pakasir. Native QRIS membuat transaksi QRIS via API dan mengirim QR `payment_number` langsung di Telegram; adapter Telegram tetap menyediakan hosted URL sebagai fallback tampilan untuk Payment yang sama.

Kedua flow membuat Payment pending lokal lebih dulu. Hosted checkout tidak membutuhkan transaksi native. Native QRIS memanggil Pakasir setelah commit lokal; jika create gagal, order tetap pending dan customer dapat diarahkan ke hosted URL pada Payment yang sama. Perilaku ini adalah legacy Pakasir, bukan pola yang boleh disalin ke KlikQRIS.

Callback/webhook Pakasir adalah untrusted intake. Sistem hanya menandai paid setelah Transaction Detail API dengan API-key verification cocok terhadap `order_id`/`merchant_ref`, nominal, project/slug, status `completed`, dan payment method bila tersedia. Payload tanpa bentuk callback Pakasir tidak disimpan sebagai `payment_events`; replay callback Pakasir yang identik di-coalesce ke satu event dengan `idempotency_key`, `received_count`, dan `last_received_at` karena Pakasir tidak menyediakan ID event webhook terpisah.

- `PAID`: payment menjadi `paid`, order Pakasir masuk `completed`, stok final dikurangi, dan receipt dikirim lewat queue jika transisi berhasil.
- `EXPIRED`: order pending menjadi `expired`, payment menjadi `expired`, dan reservasi stok dilepas.
- `FAILED`: order pending menjadi `cancelled`, payment menjadi `failed`, dan reservasi stok dilepas.
- `REFUND`: payment menjadi `refunded`; order menjadi `cancelled` kecuali order sudah `completed`.

### 3.5 KlikQRIS Native QRIS

KlikQRIS dipilih melalui Payment Provider Switch dan hanya berlaku untuk checkout baru. Lifecycle terpusat di `App\Services\KlikQris\KlikQrisNativeQrisService`, yang mengurus create, recovery, persistence, expiry, payability, safe outcome, dan single-flight lock per Payment.

Outcome yang dapat diterima caller adalah `Payable`, `Creating`, `RecoveryPending`, `ProviderTotalPending`, `InvalidProviderResult`, dan `Expired`. Store Bot maupun Web Storefront hanya boleh menampilkan QR ketika outcome `Payable`; outcome lain tidak boleh menampilkan QR, total final, atau raw provider data.

Jika hasil create tidak pasti, sistem melakukan status recovery pada Payment/invoice yang sama. Tidak ada Payment kedua dan tidak ada automatic fallback ke Manual. Callback KlikQRIS hanya signal; `KlikQrisCallbackProcessor` melakukan status lookup authoritative sebelum memanggil `OrderLifecycle`. Scheduler menjalankan `klikqris:reconcile` ketika environment aktif. Detail keputusan ada di [ADR-0006](../docs/adr/0006-klikqris-native-qris-and-pakasir-retirement.md) dan runbook ada di [KlikQRIS sandbox validation](../docs/klikqris-sandbox-validation.md).

### 3.6 Binance Pay

Binance disimpan sebagai `provider=manual` dengan `method=BINANCE_PAY_ID`, sehingga caller harus memeriksa `method` dan tidak boleh menyimpulkan rail hanya dari provider. Verifikasi otomatis dikelola `BinancePaymentVerification` dalam mode `amount_unique` atau `order_id`; mismatch dan bukti terlambat tidak auto-claim dan dapat masuk manual review.

## 4. Admin Flow

Admin flow berfokus pada operasi support/finance, bukan percakapan Telegram customer.

Kemampuan admin yang terlihat di codebase:

- melihat dan mengelola data order/payment melalui admin panel;
- menyelesaikan manual order setelah pembayaran diverifikasi;
- mengirim ulang receipt untuk order paid yang memiliki customer Telegram;
- mengelola setting pembayaran manual seperti instruksi QR manual, kontak admin, dan mode Pakasir;
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

The production Docker image runs pending Laravel migrations before Supervisor starts PHP-FPM and the queue worker. This keeps first boot from failing healthchecks because tables such as `cache` or `jobs` do not exist yet. Set `APP_AUTO_MIGRATE=false` if an environment requires manual migration orchestration.

After migrations, the Docker entrypoint runs `php artisan optimize` by default. This creates Laravel's production cache artifacts for the live container environment: cached config, routes, events, and compiled Blade views. The command is intentionally run at container startup rather than during `docker build`, because the cached config file contains runtime values such as `APP_KEY`, database credentials, Telegram credentials, and Pakasir credentials. Do not bake `bootstrap/cache/config.php` into the image.

Set `APP_OPTIMIZE=false` to skip startup optimization for one-off debugging. When runtime environment variables change, recreate the app and scheduler containers so the entrypoint rebuilds the cache from the new environment. Use `php artisan optimize` after manual runtime config changes, and use targeted clears such as `php artisan config:clear`, `php artisan route:clear`, `php artisan event:clear`, or `php artisan view:clear` when intentionally invalidating one artifact. Avoid routine `php artisan optimize:clear` on production boot because Laravel also clears keys from the default cache driver.

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

Use `php scripts/perf-dogfood.php` when you need the broader scenario matrix and per-scenario timing JSON. With `DOGFOOD_FAKE_TELEGRAM_OUTBOUND=true`, the broad harness runs in `synthetic` mode: Telegram outbound goes through `FakeTelegramBotClient`, and fixture-dependent real-external scenarios are reported under `blocked_scenarios` when their fixtures are absent. With fake outbound disabled, the harness runs in `real-external` mode and only real disposable Telegram, Store Channel, callback, photo, and Pakasir fixtures should be supplied.

External dogfood scenarios require real disposable fixtures. Leave missing values blank; `php scripts/perf-dogfood.php` records those scenarios as `blocked` before queue work starts instead of using fake Telegram or Pakasir data. Its JSON output includes `run_mode`, `blocked_scenarios`, and `final_queue_state`; after a synthetic run, `final_queue_state.jobs_depth` should be `0` and `final_queue_state.passes_ops_check_failed_jobs_max_0` should be `true`.

Dogfood timing logs should use `DOGFOOD_TIMING_LOG_CHANNEL=stderr` for production-like Docker runs, and the broad perf script should be executed with `docker compose exec --user laravel app`. The perf script also forces its own Laravel logger to `stderr`. This keeps benchmark instrumentation in Docker logs instead of writing additional timing records to `storage/logs`, so a CLI timing run cannot make PHP-FPM or the Supervisor queue worker lose write access to Laravel's file logs.

- `DOGFOOD_TELEGRAM_PHOTO_FILE_ID`: real Telegram `photo.file_id` from the dogfood bot chat for `/payment` proof forwarding.
- `DOGFOOD_ADMIN_EMAIL`, `DOGFOOD_ADMIN_PASSWORD`: safe dogfood admin account used by the performance script to log in through the real admin form and submit CSRF-protected admin mutations. If unset, the script falls back to `PLAYWRIGHT_ADMIN_EMAIL` and `PLAYWRIGHT_ADMIN_PASSWORD`.
- `DOGFOOD_STORE_CHANNEL_CHAT_ID`, `DOGFOOD_STORE_CHANNEL_USERNAME`, `DOGFOOD_STORE_CHANNEL_LINK`: disposable Store Channel target where the bot can post.
- `DOGFOOD_STORE_CHANNEL_MEMBER_USER_ID`, `DOGFOOD_STORE_CHANNEL_NON_MEMBER_USER_ID`: Telegram identities used to measure Community Gate member/non-member paths.
- `DOGFOOD_TELEGRAM_CALLBACK_QUERY_ID`: fresh callback query ID from a real Telegram tap; it expires quickly, so set it only for that run.
- `DOGFOOD_PAKASIR_PENDING_ORDER_ID`, `DOGFOOD_PAKASIR_PENDING_AMOUNT`: pending Pakasir transaction fixture that matches the configured `PAKASIR_SLUG` and `PAKASIR_API_KEY`.

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

Run the PostgreSQL concurrency deploy gate before shipping changes that affect inventory, cart, checkout, payment, order expiry, or admin stock operations:

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

The gate creates a one-off PostgreSQL database named like `telegram_bot_concurrency_test_*`, runs the `concurrency` PHPUnit group with `RUN_CONCURRENCY_TESTS=1`, fails if PHPUnit reports any skipped concurrency test, and drops the database during cleanup. It writes `phpunit.log`, `junit.xml`, and `report.md` under `artifacts/concurrency-gate/<timestamp>/`. Attach the latest `report.md` to the issue or PR so reviewers can see the test count, skipped count, failures, and exit code.

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

Untuk validasi staging Pakasir native QRIS sebelum production cutover, siapkan environment disposable dengan credential Pakasir staging/sandbox dan bot Telegram test, lalu pilih `QR Native Pakasir` dari Admin Dashboard. Buat checkout dari Store Bot staging, ambil UUID payment atau `merchant_ref` dari admin/database, lalu jalankan:

```bash
php artisan pakasir:validate-native-qris <payment-uuid-or-merchant-ref> --create --reconcile
```

Command ini menghasilkan report redacted: dashboard sudah memilih native QRIS, create QRIS idempotent, QR source berupa `payment_number` non-URL, fee/`total_payment`/expiry ada dan konsisten, hosted fallback terbentuk untuk payment yang sama, Transaction Detail cocok dengan amount original, reconciliation bisa menandai paid saat detail `completed`, dan payload tersimpan tidak berisi raw `payment_number`. Gunakan `--json` jika report perlu dilampirkan sebagai artefak internal; audit dulu agar tidak ada secret.

Pertanyaan provider yang sengaja menguji duplicate endpoint harus dijalankan hanya di staging disposable:

```bash
php artisan pakasir:validate-native-qris <payment-uuid-or-merchant-ref> --create --duplicate-provider-create
```

Option `--duplicate-provider-create` secara eksplisit memanggil `transactioncreate/qris` lagi untuk payment yang sama. Jangan gunakan option itu di production.

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
- atur kapasitas Docker/Supervisor dengan `LARAVEL_UPDATE_WORKER_PROCESSES`, `LARAVEL_OUTBOUND_WORKER_PROCESSES`, `LARAVEL_NOTIFICATION_WORKER_PROCESSES`, dan `LARAVEL_BROADCAST_WORKER_PROCESSES` tanpa mengubah kode aplikasi.

Production-like Docker runs separate Supervisor-managed workers: `laravel-update-worker` handles `default`, `laravel-outbound-worker` handles `telegram-outbound`, `laravel-notification-worker` handles `notifications`, and `laravel-broadcast-worker` handles `telegram-broadcast`. Manual checkout creates the pending order on the update worker, then dispatches customer payment instructions and pending-order admin notification to `telegram-outbound` so slow checkout `sendPhoto`/`sendMessage` calls do not occupy the update worker. Payment receipts, paid/expired admin notifications, Sales Proof Post publishing, and checkout-instruction cleanup run on `notifications`, so a burst of checkout instructions does not delay critical payment-facing work. Safe defaults are one process per pool; raise only the saturated pool after checking `php artisan ops:health --queue-depth` and container CPU/memory. Roll back safely by setting the changed `LARAVEL_*_WORKER_PROCESSES` value back to `1` and redeploying/restarting Supervisor; do not merge `notifications` back into `telegram-outbound` because that removes the isolation guarantee. Before changing worker counts again, run the dogfood benchmark from clean dogfood volumes and compare queue wait, update-worker handler time, manual outbound job time, notification job time, Telegram outbound time, failed jobs, and memory. The controlled run 2 benchmark in `docs/docker-dogfood-benchmark-run-2.md` measured queue wait at 17.865 ms average, 27 ms p95, and 43 ms max with zero failed jobs before the later notification split; use newer clean runs for current queue behavior.

Manual checkout notification retry behavior:

- the job is unique per order while queued/running, so duplicate checkout callbacks cannot enqueue concurrent sends for the same order;
- successful customer QR/photo delivery sets `orders.manual_checkout_customer_notified_at`;
- successful admin notification delivery, or a configured no-op because no admin chat exists, sets `orders.manual_checkout_admin_notified_at`;
- if the customer send succeeds and the admin send fails, retry sends only the admin notification;
- if the customer send fails before the timestamp is written, retry sends the customer instructions again because Telegram delivery was not confirmed durably.

Admin Broadcast memakai queue telegram-broadcast dengan batch maksimal 25 recipient dan shared cache limiter `TELEGRAM_BROADCAST_RATE_LIMIT_PER_SECOND` (default `25` pesan/detik), sehingga laju tetap di bawah batas broadcast Telegram 30 pesan/detik walaupun worker ditambah. Respons HTTP 429 memakai nilai retry_after yang dibatasi; recipient yang belum due tetap pending dan ditemukan kembali oleh command telegram:recover-outbound setelah window retry aman.

Contoh command umum Laravel:

```bash
php artisan queue:work
```

Sesuaikan opsi worker, queue, timeout, dan supervisor config dengan environment production.

### 5.4 Scheduler / cron

Scheduler menjalankan command periodik dari `routes/console.php`:

```text
klikqris:reconcile --chunk=100 setiap menit jika environment aktif
pakasir:reconcile --chunk=100  setiap menit
binance:reconcile --chunk=100  setiap menit
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

Sebelum production, cek minimal:

- [ ] `APP_ENV=production`.
- [ ] `APP_DEBUG=false`.
- [ ] `APP_URL` memakai HTTPS dan domain production yang benar.
- [ ] `APP_KEY` sudah dibuat.
- [ ] Database production (`DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`) benar dan migrasi sudah dijalankan.
- [ ] `QUEUE_CONNECTION` sesuai; default project menggunakan `database`.
- [ ] Tabel queue/cache/session/failed jobs tersedia.
- [ ] `TELEGRAM_BOT_TOKEN` terisi.
- [ ] `TELEGRAM_WEBHOOK_SECRET` terisi dan sama dengan secret webhook Telegram.
- [ ] `TELEGRAM_API_BASE_URL` sesuai, biasanya `https://api.telegram.org`.
- [ ] `TELEGRAM_WEBHOOK_RATE_LIMIT_PER_MINUTE` disesuaikan dengan trafik.
- [ ] Setting payment manual/admin sudah benar: asset QR manual `public/qr-lhie.jpg`, nama QR `LHIE STORE`, `TELEGRAM_ADMIN_USERNAME`, `TELEGRAM_ADMIN_CHAT_ID`, atau sesuaikan dari admin payment settings project.
- [ ] Provider checkout baru dipilih oleh owner melalui panel "Mode pembayaran" di `/admin`: QR Manual, Pakasir hosted/native QRIS, atau KlikQRIS. Payment lama tidak ikut berpindah provider.
- [ ] Credential Pakasir terisi jika mode Pakasir akan diaktifkan: `PAKASIR_SLUG`, `PAKASIR_API_KEY`, `PAKASIR_BASE_URL`; dashboard menolak aktivasi Pakasir tanpa slug/API key.
- [ ] Gunakan `QR Native Pakasir` hanya setelah staging membuktikan native QRIS dengan `php artisan pakasir:validate-native-qris <payment> --create --reconcile`; rollback cepat dari dashboard ke `Hosted Pakasir` atau `QR Manual`.
- [ ] Credential KlikQRIS sandbox/production dipisahkan; aktifkan environment sesuai readiness dan approval rollout. KlikQRIS tidak fallback otomatis ke Manual.
- [ ] `PAKASIR_REDIRECT_URL` opsional; kosongkan jika tidak butuh return URL khusus.
- [ ] Batas waktu checkout QRIS dan Binance Pay disesuaikan melalui dashboard admin bila diperlukan.
- [ ] HTTPS aktif untuk semua webhook.
- [ ] Queue worker berjalan di Supervisor/systemd/container process manager.
- [ ] Scheduler Laravel aktif via cron atau scheduler runner platform deploy.

Jika ada setting production yang dikelola di luar env, sesuaikan dengan config/env project dan panel admin yang tersedia.

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
- Pilihan dashboard `pakasir_checkout_flow` tidak sesuai rencana rollout (`hosted` atau `api_qris`).
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
- `src/app/Storefront/StorefrontShopCheckout.php` — guest storefront checkout dan Recovery Key issuance.
- `src/app/Storefront/StorefrontShopPaymentStatusRefresh.php` — signed invoice status refresh/recovery adapter.
- `src/app/Services/Admin/AdminOrderOperations.php` — operasi admin untuk complete manual order dan resend receipt, termasuk audit log.
- `src/app/Services/OrderStateMachine.php` — transisi status order/payment, release reservasi, sale stock movement, refund, cancel, dan expiry.
- `src/app/Console/Commands/HealthCheck.php` — command `ops:health {--db} {--queue-schema} {--supervisor}`.
- `src/app/Console/Commands/CheckFailedJobs.php` — command `ops:check-failed-jobs {--max=0}` untuk alert sederhana jumlah failed jobs.
- `src/app/Console/Commands/ExpirePendingOrders.php` — command `orders:expire-pending {--chunk=100}`.
- `src/app/Console/Commands/ReconcileKlikQrisPayments.php` — command `klikqris:reconcile {--chunk=100}`.
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
