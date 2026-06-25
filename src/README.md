# Telegram Bot Commerce — Panduan Operasional High-Level

Dokumen ini menjelaskan gambaran besar cara kerja project Telegram bot commerce ini: alur customer, pembayaran, operasi admin, dan hal-hal penting untuk menjalankan aplikasi di production.

Dokumen ini **bukan** API reference detail dan tidak membahas setiap class/method. Untuk detail teknis, lihat file referensi di bagian akhir.

## 1. Overview Project

Bot ini digunakan sebagai channel penjualan lewat Telegram. Customer membuka katalog, memilih produk, memasukkan jumlah, checkout, lalu menerima instruksi pembayaran. Admin mengelola katalog/order dari backend Laravel dan menjalankan operasi seperti menyelesaikan order manual, mengirim ulang receipt, dan rekonsiliasi pembayaran Tripay.

Komponen utama:

- **Telegram**: antarmuka utama untuk customer. Update dari Telegram diterima melalui webhook `POST /api/webhook/telegram`.
- **Laravel backend**: menyimpan customer, catalog, cart, order, payment, audit log, dan menjalankan business flow.
- **Tripay**: payment provider untuk jalur pembayaran otomatis. Callback Tripay diterima melalui `POST /api/webhook/tripay`.
- **Admin operations**: operasi manual oleh admin/support/finance, seperti validasi pembayaran manual, resend receipt, dan rekonsiliasi Tripay.
- **Queue**: memproses pekerjaan background, termasuk pemrosesan update Telegram dan pengiriman receipt.
- **Scheduler**: menjalankan expiry order pending dan rekonsiliasi Tripay berkala.

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
- Flow Telegram yang terlihat saat ini melakukan checkout manual DANA dari tombol checkout. Codebase juga memiliki dukungan service untuk payment provider Tripay.

## 3. Payment Flow

Codebase memperlihatkan dua jalur pembayaran: **manual payment** dan **Tripay payment/callback**.

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
3. Bot mengirim instruksi pembayaran manual, termasuk invoice, total, batas pembayaran, informasi DANA/admin jika tersedia, dan detail item.
4. Admin memvalidasi pembayaran di luar sistem, misalnya dari bukti transfer customer.
5. Admin menjalankan operasi “complete manual order” dari dashboard/admin operation.
6. Sistem menandai payment sebagai `paid`, mengurangi stok dari reservasi menjadi penjualan, mengubah order menjadi `completed`, lalu mengirim receipt lewat queue.

Manual payment dianggap pending sejak checkout sampai admin menyelesaikan order atau order kedaluwarsa/dibatalkan oleh proses lain.

### 3.4 Tripay payment dan callback

Pada jalur Tripay:

1. Checkout membuat payment provider `tripay` dengan status `pending`.
2. Sistem membuat transaksi ke Tripay dan menyimpan referensi provider jika berhasil.
3. Tripay mengirim callback ke `POST /api/webhook/tripay` ketika status pembayaran berubah.
4. Callback harus lolos validasi signature dan event header.
5. Payload dicocokkan dengan payment internal menggunakan `merchant_ref`, `reference`, nominal, dan payment method.
6. Status dari Tripay diterapkan ke order/payment.

Mapping status callback Tripay:

- `PAID`: payment menjadi `paid`, order masuk `processing`, stok final dikurangi, dan receipt dikirim lewat queue jika transisi berhasil.
- `EXPIRED`: order pending menjadi `expired`, payment menjadi `expired`, dan reservasi stok dilepas.
- `FAILED`: order pending menjadi `cancelled`, payment menjadi `failed`, dan reservasi stok dilepas.
- `REFUND`: payment menjadi `refunded`; order menjadi `cancelled` kecuali order sudah `completed`.

Callback Tripay juga dicatat sebagai payment event agar bisa diaudit.

### 3.5 Rekonsiliasi pembayaran Tripay

Rekonsiliasi digunakan ketika callback terlambat, gagal diterima, atau status internal perlu disamakan dengan status di Tripay.

Ada dua cara yang terlihat di codebase:

- command scheduled: `php artisan tripay:reconcile --chunk=100`;
- operasi admin manual untuk payment Tripay pending yang sudah memiliki provider reference.

Rekonsiliasi mengambil detail transaksi dari Tripay, memvalidasi kesesuaian referensi dan nominal, lalu menerapkan status seperti callback.

## 4. Admin Flow

Admin flow berfokus pada operasi support/finance, bukan percakapan Telegram customer.

Kemampuan admin yang terlihat di codebase:

- melihat dan mengelola data order/payment melalui admin panel;
- menyelesaikan manual order setelah pembayaran diverifikasi;
- mengirim ulang receipt untuk order paid yang memiliki customer Telegram;
- melakukan rekonsiliasi manual payment Tripay;
- mengelola setting pembayaran manual seperti instruksi, data DANA, QR, dan kontak admin;
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

Untuk Tripay:

```text
Payment Tripay pending
        ↓
Callback masuk otomatis atau status belum sinkron
        ↓
Admin dapat trigger reconcile
        ↓
Sistem ambil detail dari Tripay
        ↓
Status internal diperbarui bila payload valid
        ↓
Audit log manual reconciliation tersimpan
```

Receipt hanya bisa dikirim ulang untuk order yang sudah paid dan masih dalam status operasional yang sesuai (`processing` atau `completed`) serta memiliki customer Telegram.

## 5. Operational Guide

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

### 5.2 Webhook Tripay

Endpoint aplikasi:

```text
POST /api/webhook/tripay
```

Hal penting:

- gunakan HTTPS di production;
- set callback URL di Tripay ke endpoint public yang benar;
- pastikan signature key/private key Tripay sesuai;
- callback harus berisi event `payment_status` dan signature valid;
- endpoint memiliki size guard dan rate limiter `tripay`.

Catatan: `.env.example` harus disesuaikan dengan route production yang benar. Route aplikasi untuk Tripay adalah `/api/webhook/tripay`.

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
tripay:reconcile --chunk=100   setiap 5 menit
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
`smoke-admin-rendering.sh` menjalankan build, membuat database SQLite sementara, login ke admin lewat browser, lalu memastikan Dashboard dan Categories tidak memiliki icon Filament berukuran abnormal atau scroll height kosong yang berlebihan.

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

### 5.7 Reconcile Tripay payments

Command manual:

```bash
php artisan tripay:reconcile --chunk=100
```

Fungsi:

- mencari payment Tripay dengan status `pending`, `expired`, atau `failed` yang memiliki provider reference;
- mengambil detail transaksi dari Tripay;
- menerapkan status valid ke sistem internal.

### 5.8 Logging dan monitoring

Hal yang perlu dimonitor:

- error log Laravel;
- failed jobs;
- queue length/backlog;
- response webhook Telegram dan Tripay;
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
- [ ] Setting payment manual/admin sudah benar: `DANA_QR_IMAGE_URL`, `DANA_ACCOUNT_NAME`, `DANA_ACCOUNT_NUMBER`, `TELEGRAM_ADMIN_CONTACT_URL`, `TELEGRAM_ADMIN_USERNAME`, `TELEGRAM_ADMIN_CHAT_ID`, atau sesuaikan dari admin payment settings project.
- [ ] `TELEGRAM_PAYMENT_MODE` dan `TELEGRAM_DEFAULT_PAYMENT_METHOD` sesuai mode pembayaran yang digunakan project.
- [ ] Credential Tripay terisi jika jalur Tripay dipakai: `TRIPAY_API_KEY`, `TRIPAY_PRIVATE_KEY`, `TRIPAY_MERCHANT_CODE`.
- [ ] `TRIPAY_BASE_URL` mengarah ke sandbox/production sesuai kebutuhan.
- [ ] `TRIPAY_CALLBACK_URL` dan `TRIPAY_RETURN_URL` memakai URL production yang benar.
- [ ] `TRIPAY_WEBHOOK_RATE_LIMIT_PER_MINUTE` dan `TRIPAY_WEBHOOK_MAX_BODY_BYTES` sesuai kebutuhan.
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

### Payment Tripay tidak berubah status

Kemungkinan penyebab:

- callback URL Tripay salah;
- signature/private key salah;
- event header bukan `payment_status`;
- provider reference atau merchant reference tidak cocok;
- nominal atau payment method tidak cocok;
- callback ditolak oleh rate limit atau size guard;
- Tripay belum mengirim callback.

Langkah cek:

- pastikan URL callback mengarah ke `/api/webhook/tripay`;
- cek credential Tripay dan signature key;
- cek payment event yang masuk;
- jalankan `php artisan tripay:reconcile --chunk=100`;
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
- job error berulang karena config Telegram/Tripay salah;
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

- `src/routes/api.php` — mendefinisikan endpoint webhook Telegram dan Tripay beserta middleware rate limit/size guard.
- `src/app/Http/Controllers/TelegramWebhookController.php` — validasi secret Telegram, filter payload, simpan update, dispatch job pemrosesan update.
- `src/app/Http/Controllers/TripayWebhookController.php` — menerima raw callback Tripay dan mendelegasikan proses ke callback processor.
- `src/app/Services/Telegram/TelegramUpdateProcessor.php` — command Telegram, katalog, cart, checkout manual, instruksi pembayaran, dan notifikasi admin manual order.
- `src/app/Services/Tripay/TripayCallbackProcessor.php` — validasi callback/rekonsiliasi Tripay, pencatatan payment event, dan penerapan status payment/order.
- `src/app/Services/Admin/AdminOrderOperations.php` — operasi admin untuk complete manual order dan resend receipt, termasuk audit log.
- `src/app/Services/Admin/AdminPaymentOperations.php` — operasi admin untuk rekonsiliasi manual payment Tripay, termasuk audit log.
- `src/app/Services/OrderCheckoutService.php` — pembuatan order/payment dari cart aktif, reservasi stok, dan inisiasi transaksi Tripay bila provider Tripay digunakan.
- `src/app/Services/OrderStateMachine.php` — transisi status order/payment, release reservasi, sale stock movement, refund, cancel, dan expiry.
- `src/app/Console/Commands/HealthCheck.php` — command `ops:health {--db} {--queue-schema} {--supervisor}`.
- `src/app/Console/Commands/CheckFailedJobs.php` — command `ops:check-failed-jobs {--max=0}` untuk alert sederhana jumlah failed jobs.
- `src/app/Console/Commands/ExpirePendingOrders.php` — command `orders:expire-pending {--chunk=100}`.
- `src/app/Console/Commands/ReconcileTripayPayments.php` — command `tripay:reconcile {--chunk=100}`.
- `src/routes/console.php` — jadwal scheduler untuk expire pending orders dan Tripay reconciliation.
- `src/config/services.php` — konfigurasi Telegram, Tripay, payment manual/admin contact, dan webhook rate limit.
- `src/config/orders.php` — konfigurasi TTL pending payment.
- `src/tests/Feature/Phase6TelegramBotTest.php` — coverage flow customer Telegram, checkout manual, receipt, callback paid, dan rate limit.
- `src/tests/Feature/Phase7AdminOperationsTest.php` — coverage operasi admin, resend receipt, complete manual order, reconcile payment, dan health check.
- `src/tests/Feature/Phase8HardeningTest.php` — coverage hardening operasional/security sesuai fase project.
- `src/tests/Feature/Phase5TripaySandboxTest.php` — coverage sandbox Tripay, callback signature, reconciliation, refund, dan payload guard.
- `src/tests/Feature/Phase4OrderCartDomainTest.php` — coverage cart/order state machine, expiry, cancel, paid/refund transitions, dan stok.
