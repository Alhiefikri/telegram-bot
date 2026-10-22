# KlikQRIS reconcile candidate classes (#650)

Perubahan ini memisahkan pemilihan kandidat `klikqris:reconcile` menjadi kelas
pembayaran aktif dan recovery history. Verifikasi provider, settlement,
inventory, receipt, late-payment review dan scheduler interval tidak berubah.

Status: implementasi dan bukti lokal. Tidak ada CI, deploy, production probe,
pembayaran nyata, atau pesan pelanggan dalam perubahan ini.

## Masalah awal

Satu cursor dan satu kuota `--max-payments` dipakai bersama oleh Payment
`pending`, Payment terminal baru, dan `outcome_unknown`. Dengan urutan
`created_at, id`, Payment expired yang lebih lama menghabiskan kuota sebelum
Payment aktif diperiksa. Baseline lokal pada issue #648 memperlihatkan satu
Payment pending yang provider-nya sudah `SUCCESS` baru diverifikasi pada sweep
ketiga setelah 20 history eligible.

## Kontrak seleksi

| Kelas | Predicate | Tujuan |
| --- | --- | --- |
| `active` | `provider=klikqris`, `merchant_ref` terisi, `status=pending`, `provider_create_state != outcome_unknown` | Payment yang masih aktif dan sensitif terhadap latency. |
| `outcome_unknown` | `provider=klikqris`, `merchant_ref` terisi, `provider_create_state=outcome_unknown` (status apa pun) | Create ambigu, termasuk Payment yang sudah dibayar lokal tetapi masih butuh signature authoritative. |
| `history` | `provider=klikqris`, `merchant_ref` terisi, `status in (expired, failed)`, `updated_at >= now-72h`, `provider_create_state != outcome_unknown` | Late payment dan recovery terminal yang masih dalam lookback. |

Predicate ketiga kelas saling lepas, sehingga satu Payment tidak dihitung dua
kali dalam satu sweep. Jika state berubah di tengah sweep dan Payment masuk ke
kelas berikutnya, ID yang sudah diproses dilewati sehingga tidak ada dua
percobaan dalam satu sweep.

Alokasi kuota:

1. Setiap kelas non-kosong mendapat minimum satu Payment.
2. History mendapat jatah `max(1, floor(max-payments / 4))`, dibatasi agar
   `active` dan `outcome_unknown` tetap mendapat minimum satu.
3. Sisa kuota dipakai dengan prioritas `active`, lalu `outcome_unknown`, lalu
   `history`. Kuota yang tidak terpakai karena suatu kelas sudah habis
   dialihkan ke kelas berikutnya, sehingga sweep tidak berhenti di bawah
   `--max-payments` bila masih ada kandidat.
4. Bila `--max-payments` lebih kecil dari jumlah kelas non-kosong, rotasi
   durable di `klikqris:reconcile:rotation:{environment}` memilih kelas yang
   didanai pada sweep tersebut. Rotasi bergerak maju setiap sweep sehingga
   semua kelas mendapat giliran dalam beberapa sweep.

Setiap kelas memiliki cursor keyset `(created_at, id)` sendiri di
`klikqris:reconcile:{class}:cursor:{environment}`. Cursor hanya berputar
(`Cache::forget`) setelah seluruh kandidat kelas itu sudah mendapat giliran;
kelas lain tidak terpengaruh. Cache loss mengulang kelas dari kandidat tertua,
yang aman karena verifikasi provider dan settlement tetap idempotent. Key lama
`klikqris:reconcile:cursor:{environment}` tidak dibaca lagi, sehingga sweep
pertama setelah deploy mulai dari kandidat tertua di setiap kelas.
`--after=<timestamp>` menyemai ketiga cursor dan mereset rotasi.

Batas keras tetap sama:

- maksimum 100 Payment per sweep (`--max-payments`, default 10);
- maksimum 1000 baris per halaman (`--chunk`, default 100);
- execution deadline monotonik 45 detik
  (`services.klikqris.reconcile_max_execution_seconds`);
- scan cap halaman `services.klikqris.reconcile_cursor_scan_cap` (default 500).

Stdout `klikqris:reconcile classes: ...` dan log
`klikqris.reconciliation.completed` melaporkan budget, selected, processed,
skipped, failed dan wrapped per kelas, ditambah `rotation_used`, `pages_fetched`,
`budget_exhausted`, `selected`, `max_payments`, `max_execution_seconds`,
`cursor_scan_cap`, dan durasi. Jumlah percobaan HTTP mentah tetap dibatasi oleh
retry/timeout client provider yang sudah ada; batas command adalah jumlah
Payment dan deadline.

## Dataset dan hasil before/after

Harness sementara menjalankan command aplikasi sebenarnya
(`klikqris:reconcile --chunk=100 --max-payments=10`) dengan SQLite in-memory,
HTTP fake, queue fake, dan waktu dibekukan pada `2026-07-28 07:05:00`.
Harness dihapus setelah pengukuran; regresi permanen berada di
`src/tests/Feature/KlikQrisReconciliationTest.php`.

"Request" di bawah adalah panggilan status provider yang tercatat HTTP fake.
Tidak ada create provider pada dataset ini.

| Dataset | Sebelum | Sesudah |
| --- | --- | --- |
| 20 expired eligible + 1 pending, provider target `SUCCESS` | Request per sweep `10, 10, 1`; target paid pada sweep 3 | Request sweep 1 `10` (1 active + 9 history); target paid pada sweep 1 |
| 25 expired eligible + 3 pending, semua target `SUCCESS` | Request per sweep `10, 10, 8`; 3 target paid pada sweep 3 | Request sweep 1 `10` (3 active + 7 history); 3 target paid pada sweep 1 |
| 20 expired eligible + 20 pending yang tetap `PENDING` | 10 request per sweep, tanpa pemisahan kelas yang terlihat | Sweep 1–6: active/history `8/2`, `8/2`, `4/6`, `8/2`, `8/2`, `4/6`; 20 baris history selesai pada sweep 6, sweep 7 mengonfirmasi wrap cursor history (0 history) sementara active memakai seluruh kuota, lalu siklus history berulang |

Wall-clock total dari harness sementara tidak dipakai untuk mengklaim
perbaikan latency karena jumlah request dan overhead harness berbeda antar
skenario. Bukti yang dipakai adalah deterministik: urutan kelas, request per
sweep, sweep saat paid, dan batas kuota.

## Verifikasi yang dijalankan

SQLite (seluruh kelas test terkait):

```text
php artisan test tests/Feature/KlikQrisReconciliationTest.php
28 passed / 231 assertions

php artisan test \
  tests/Feature/KlikQrisReconciliationTest.php \
  tests/Feature/KlikQrisWebhookTest.php \
  tests/Feature/KlikQrisNativeQrisTest.php \
  tests/Feature/Telegram/KlikQrisCheckoutTest.php \
  tests/Feature/KlikQrisCustomerSurfacesIntegrationTest.php \
  tests/Feature/TelegramLatencyBaselineCommandTest.php
108 passed / 793 assertions
```

Seluruh 12 file fitur KlikQRIS di SQLite (tanpa dua file
`*PostgresConcurrencyTest`): 150 passed / 958 assertions. Termasuk owner alert,
admin observability, gateway, reporting, readiness, webhook, checkout Telegram,
storefront, dan expired notifier.

PostgreSQL 16.15 disposable (container `postgres:16-alpine`, database terpisah):

```text
DB_HOST=<alamat-container> DB_PORT=5432 DB_USERNAME=baseline \
DB_PASSWORD=baseline DB_DATABASE=telegram_bot_issue650 \
php vendor/bin/phpunit -c phpunit.postgres.xml tests/Feature/KlikQrisReconciliationTest.php
28 passed / 231 assertions
```

Pint lulus untuk command dan test yang diubah. Test Postgres mencakup cursor
UUID pada `created_at` yang sama, query kelas, wrap cursor, cache loss, dan
late-payment review; lane ini memakai database disposable, bukan production.

Kompatibilitas runner #649: `TelegramLatencyBaseline` memakai
`ReconcileKlikQrisPayments::forgetCursors()` agar setiap observasi memulai
ketiga cursor kelas dari posisi tertua. `php artisan test
tests/Feature/TelegramLatencyBaselineCommandTest.php` lulus 3 test / 8
assertions. Full `telegram:latency-baseline` tidak dijalankan ulang pada tiket
ini; eksekusi before/after akhir tetap milik #656.

Regresi permanen yang ditambahkan:

- aktif diverifikasi pada sweep pertama sementara history tetap maju;
- dataset 25 history + 3 aktif (> kuota) tetap membayar semua target pada sweep
  pertama;
- backlog aktif kontinu tidak membuat history starvation;
- `outcome_unknown` dan history mendapat jatah minimum saat ada active;
- cap di bawah jumlah kelas non-kosong memakai rotasi sehingga setiap kelas tetap maju;
- tie `created_at` berjalan satu per satu lalu cursor wrap;
- cache loss mengulang kelas active dari kandidat tertua;
- sweep berulang setelah sukses tidak menggandakan receipt atau payment event;
- timeout provider tidak mengubah status atau membuat reconciliation event;
- late success pada Order terminal tetap membuat Manual Fulfillment Review
  dengan alasan `late_payment` dan tidak mengirim receipt otomatis.

## Batas bukti

Pemeriksaan framework melalui Context7: Laravel 13
[logical query grouping](https://github.com/laravel/docs/blob/13.x/queries.md),
[scheduling `withoutOverlapping`](https://github.com/laravel/docs/blob/13.x/scheduling.md),
dan [cache locks](https://github.com/laravel/docs/blob/13.x/cache.md). Perubahan
ini hanya memakai query builder, cache, dan scheduler mutex yang sudah ada;
tidak ada mekanisme locking atau observability baru.

- Bukti before/after berasal dari SQLite in-memory + HTTP/queue fake + waktu
  test yang dibekukan, bukan wall-clock production.
- Postgres hanya membuktikan query/cursor/transaksi pada database disposable;
  tidak ada Redis worker, scheduler nyata, provider live, Telegram live, atau
  beban konkuren production dalam bukti ini.
- Tidak ada klaim bahwa polling adalah satu-satunya jalur pembayaran; webhook
  sukses tetap memakai jalur verifikasi authoritative yang sudah ada.
- Interval scheduler, mutex tanpa overlap, queue worker, pin, dan auto-pin
  tidak diubah.
- Full suite dan CI tidak dijalankan sebagai bagian dari perubahan ini.
