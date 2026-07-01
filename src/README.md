# Telegram Bot Commerce — Panduan Operasional High-Level

Dokumen ini menjelaskan gambaran besar cara kerja project Telegram bot commerce ini: alur customer, pembayaran, operasi admin, dan hal-hal penting untuk menjalankan aplikasi di production.

Dokumen ini **bukan** API reference detail dan tidak membahas setiap class/method. Untuk detail teknis, lihat file referensi di bagian akhir.

## 1. Overview Project

Bot ini digunakan sebagai channel penjualan lewat Telegram. Customer membuka katalog, memilih produk, memasukkan jumlah, checkout, lalu menerima instruksi pembayaran. Admin mengelola katalog/order dari backend Laravel dan menjalankan operasi seperti menyelesaikan order manual, mengirim ulang receipt, dan rekonsiliasi pembayaran.

Komponen utama:

- **Telegram**: antarmuka utama untuk customer. Update dari Telegram diterima melalui webhook `POST /api/webhook/telegram`.
- **Laravel backend**: menyimpan customer, catalog, cart, order, payment, audit log, dan menjalankan business flow.
- **Payment mode**: default production sementara memakai pembayaran manual `/payment` selama KYC Pakasir. Pakasir tetap tersedia sebagai hosted-link checkout dengan API-key verification dan bisa diaktifkan dari admin dashboard setelah siap.
- **Admin operations**: operasi manual oleh admin/support/finance, seperti validasi pembayaran manual, resend receipt, dan rekonsiliasi payment legacy.
- **Queue**: memproses pekerjaan background, termasuk pemrosesan update Telegram dan pengiriman receipt.
- **Scheduler**: menjalankan expiry order pending dan rekonsiliasi pembayaran berkala.

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

Pada jalur Pakasir aktif, checkout membuat payment pending, mengirim customer ke hosted-link checkout Pakasir, lalu sistem melakukan API-key verification untuk memastikan status pembayaran sebelum update order/payment.



4. Callback harus lolos validasi signature dan event header.
5. Payload dicocokkan dengan payment internal menggunakan `merchant_ref`, `reference`, nominal, dan payment method.


- `PAID`: payment menjadi `paid`, order masuk `processing`, stok final dikurangi, dan receipt dikirim lewat queue jika transisi berhasil.
- `EXPIRED`: order pending menjadi `expired`, payment menjadi `expired`, dan reservasi stok dilepas.
- `FAILED`: order pending menjadi `cancelled`, payment menjadi `failed`, dan reservasi stok dilepas.
- `REFUND`: payment menjadi `refunded`; order menjadi `cancelled` kecuali order sudah `completed`.




Ada dua cara yang terlihat di codebase:



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

Production-like local dogfooding should target Docker/Nginx/PostgreSQL, not `php artisan serve` with SQLite:

```bash
POSTGRES_PASSWORD=dogfood_password \
APP_KEY=base64:replace-with-generated-key \
TELEGRAM_BOT_TOKEN=dogfood-token \
TELEGRAM_WEBHOOK_SECRET=dogfood-secret \
WEB_PORT=18080 \
docker compose -p telegram-bot-dogfood up -d --build

BASE_URL=http://127.0.0.1:18080 ./scripts/smoke-production.sh

POSTGRES_PASSWORD=dogfood_password WEB_PORT=18080 \
docker compose -p telegram-bot-dogfood exec app \
php artisan admin:bootstrap-owner owner@example.test

PLAYWRIGHT_BASE_URL=http://127.0.0.1:18080 \
PLAYWRIGHT_ADMIN_EMAIL=owner@example.test \
PLAYWRIGHT_ADMIN_PASSWORD='replace-with-password' \
PLAYWRIGHT_TELEGRAM_WEBHOOK_SECRET='replace-with-secret' \
npm run test:browser:docker
```

The Docker browser smoke validates the deployed HTTP surface, admin login/dashboard, mobile overflow, unauthenticated admin redirect, and Telegram webhook fail-closed behavior. It does **not** prove end-to-end Telegram delivery through Telegram's real network unless the environment is configured with a real bot token, a public HTTPS webhook URL, and a controlled Telegram test account.

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


Endpoint aplikasi:

```text
```

Hal penting:

- gunakan HTTPS di production;
- callback harus berisi event `payment_status` dan signature valid;


### 5.3 Queue worker

Queue diperlukan untuk pekerjaan background seperti pemrosesan update Telegram dan pengiriman receipt.

Checklist operasional:

- pastikan `QUEUE_CONNECTION` sesuai kebutuhan production; default project menggunakan database queue;
- jalankan queue worker di process manager seperti Supervisor;
- monitor tabel/job failed jobs;
- pastikan queue `notifications` ikut diproses karena receipt dikirim melalui queue tersebut.

Contoh command umum Laravel:

```bash
php artisan queue:work
```

Sesuaikan opsi worker, queue, timeout, dan supervisor config dengan environment production.

### 5.4 Scheduler / cron

Scheduler menjalankan command periodik dari `routes/console.php`:

```text
orders:expire-pending          setiap menit
```

Pastikan cron Laravel aktif, biasanya:

```bash
* * * * * cd /path/to/src && php artisan schedule:run >> /dev/null 2>&1
```

Sesuaikan path dengan lokasi deploy.

### 5.5 Health check

Endpoint HTTP health Laravel:

```text
GET /up
```

Command operasional project:

```bash
php artisan ops:health --db --queue-schema --supervisor
```

Opsi tersedia:

- `--db`: cek koneksi database;
- `--queue-schema`: cek tabel penting untuk database queue/session/cache;
- `--supervisor`: cek proses yang dikelola Supervisor sesuai konfigurasi command.

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


Command manual:

```bash
```

Fungsi:

- menerapkan status valid ke sistem internal.

### 5.8 Logging dan monitoring

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
- [ ] `TELEGRAM_PAYMENT_MODE=manual` selama KYC Pakasir; owner/admin bisa mengganti mode lewat panel "Mode pembayaran" di `/admin`.
- [ ] Credential Pakasir terisi jika mode Pakasir akan diaktifkan: `PAKASIR_SLUG`, `PAKASIR_API_KEY`, `PAKASIR_BASE_URL`.
- [ ] TTL order pending disesuaikan melalui `ORDERS_PENDING_PAYMENT_TTL_MINUTES` bila diperlukan.
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
- jalankan `php artisan ops:health --db --queue-schema --supervisor`.

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


Kemungkinan penyebab:

- signature/private key salah;
- event header bukan `payment_status`;
- provider reference atau merchant reference tidak cocok;
- nominal atau payment method tidak cocok;
- callback ditolak oleh rate limit atau size guard;

Langkah cek:

- cek payment event yang masuk;
- cek log untuk pesan validasi callback.

### Order pending tidak expired

Kemungkinan penyebab:

- scheduler tidak berjalan;
- command `orders:expire-pending` gagal;
- `expires_at` belum lewat;
- order bukan status `pending_payment`;
- ada error saat release stok/reservasi.

Langkah cek:

- pastikan cron menjalankan `php artisan schedule:run` setiap menit;
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

Langkah cek:

- jalankan `php artisan ops:health --queue-schema --supervisor`;
- jalankan `php artisan ops:check-failed-jobs --max=0` atau sesuaikan threshold operasional;
- cek proses Supervisor/systemd/container;
- cek tabel `jobs` dan `failed_jobs`;
- cek log exception job;
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

- `src/app/Http/Controllers/TelegramWebhookController.php` — validasi secret Telegram, filter payload, simpan update, dispatch job pemrosesan update.
- `src/app/Services/Telegram/TelegramUpdateProcessor.php` — command Telegram, katalog, cart, checkout manual, instruksi pembayaran, dan notifikasi admin manual order.
- `src/app/Services/Admin/AdminOrderOperations.php` — operasi admin untuk complete manual order dan resend receipt, termasuk audit log.
- `src/app/Services/OrderStateMachine.php` — transisi status order/payment, release reservasi, sale stock movement, refund, cancel, dan expiry.
- `src/app/Console/Commands/HealthCheck.php` — command `ops:health {--db} {--queue-schema} {--supervisor}`.
- `src/app/Console/Commands/CheckFailedJobs.php` — command `ops:check-failed-jobs {--max=0}` untuk alert sederhana jumlah failed jobs.
- `src/app/Console/Commands/ExpirePendingOrders.php` — command `orders:expire-pending {--chunk=100}`.
- `src/routes/console.php` — jadwal scheduler untuk expire pending orders dan reconciliation payment.
- `src/config/orders.php` — konfigurasi TTL pending payment.
- `src/tests/Feature/Phase6TelegramBotTest.php` — coverage flow customer Telegram, checkout manual, receipt, callback paid, dan rate limit.
- `src/tests/Feature/Phase7AdminOperationsTest.php` — coverage operasi admin, resend receipt, complete manual order, reconcile payment, dan health check.
- `src/tests/Feature/Phase8HardeningTest.php` — coverage hardening operasional/security sesuai fase project.
- `src/tests/Feature/Phase4OrderCartDomainTest.php` — coverage cart/order state machine, expiry, cancel, paid/refund transitions, dan stok.
