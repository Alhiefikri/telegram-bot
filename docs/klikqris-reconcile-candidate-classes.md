# KlikQRIS candidate classes and scheduler isolation (#650, #654)

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
| `active` | `provider=klikqris`, `merchant_ref` terisi, `status=pending`, state bukan `outcome_unknown`/`recovery_unconfirmed` | Payment yang masih aktif dan sensitif terhadap latency. |
| `outcome_unknown` | `provider=klikqris`, `merchant_ref` terisi, state `outcome_unknown` atau `recovery_unconfirmed` (status apa pun) | Create ambigu, termasuk Payment yang sudah dibayar lokal tetapi masih butuh signature authoritative. |
| `history` | `provider=klikqris`, `merchant_ref` terisi, `status in (expired, failed)`, masih dalam lookback 72 jam **atau** memiliki status lookup 404 yang belum terverifikasi, state bukan kedua state ambigu | Late payment dan recovery terminal; uncertainty 404 tidak hilang ketika melewati lookback. |

Predicate ketiga kelas saling lepas, sehingga satu Payment tidak dihitung dua
kali dalam satu sweep. Jika state berubah di tengah sweep dan Payment masuk ke
kelas berikutnya, ID yang sudah diproses dilewati sehingga tidak ada dua
percobaan dalam satu sweep.

Sejak #663, semua kelas juga mensyaratkan `klikqris_status_next_check_at`
kosong atau sudah due. Deferred row tidak ikut `exists()`, alokasi kelas,
page, maupun kuota Payment. Detail retry dan tindakan operator ada di
[Legacy HTTP 404](#legacy-http-404-and-durable-discovery-663).

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
`cursor_scan_cap`, dan durasi. Issue #654 menambahkan `http_attempt_count`,
`stop_reason`, dan `carry_over`, serta meneruskan sisa deadline ke setiap
attempt HTTP dan jeda retry. Jumlah Payment, pembagian kelas, cursor dan
interval dari #650 tidak berubah.

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

## Batas bukti #650

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

## Scheduler isolation and deadlines (#654)

Prasyarat [#650](https://github.com/Alhiefikri/telegram-bot/issues/650) sudah
ditutup; kontrak kelas di atas tetap menjadi input, bukan implementasi kedua.
[#648](https://github.com/Alhiefikri/telegram-bot/issues/648) tetap memiliki
integrasi latency, dan #656 memiliki validasi end-to-end sesudah seluruh tuning.

`src/routes/console.php` menjalankan tujuh command provider di background:
Payment dan balance top-up KlikQRIS/Sakurupiah, legacy Pakasir, Binance dan BSC.
`ops:schedule-run` kini membatasi seluruh task background menjadi dua child
bersamaan. Parent memanggil `Event::finish()` dan melepas mutex setelah child
selesai, tanpa proses `schedule:finish` terpisah. Tidak ada queue baru,
perubahan interval, worker baru, atau perubahan otoritas settlement.
Binance/BSC mempertahankan batas kandidat yang sudah ada.

`ProviderVerificationProfiles::until()` membatasi konteks sinkron dan selalu
memulihkan konteks sebelumnya melalui `finally`. KlikQRIS, Pakasir dan
Sakurupiah menggunakan deadline sweep yang sama pada setiap attempt HTTP.
Middleware memeriksa deadline saat dispatch, lalu mengecilkan `timeout` dan
`connect_timeout` ke sisa milidetik. Sisa kurang dari satu milidetik berhenti,
bukan dibulatkan menjadi timeout nol yang berarti tanpa batas bagi cURL.
Jeda retry yang tidak muat menghentikan sweep, bukan memulai retry budget baru.
Provider timeout/budget exhaustion tidak membuktikan unpaid dan tidak memberi
otoritas fulfillment. DB bookkeeping/finalization dapat selesai sedikit
sesudah deadline I/O; perubahan ini bukan hard process-kill timeout.

### Legacy Pakasir

```text
php artisan pakasir:reconcile --chunk=100 --max-payments=10 --max-requests=30
```

- `--chunk`: 1..1000, hanya page size.
- `--max-payments`: 1..100, default 10.
- `--max-requests`: 1..300, default 30; menghitung attempt transport, termasuk retry.
- `services.pakasir.reconcile_max_execution_seconds`: default 45 detik.
- Eligibility tetap pending atau expired/failed dalam lookback 72 jam dengan
  `merchant_ref`. Riwayat di luar eligibility lama tidak diperluas/dihapus.

Cache key `pakasir:reconcile:cursor` menyimpan `{id, end}` tanpa TTL. `end`
adalah ID maksimum pada awal pass sehingga penambahan UUID baru tidak
memperpanjang pass tanpa batas. Posisi disimpan sebelum I/O: worker mati atau
row yang selalu gagal tidak menahan seluruh row sesudahnya. Row tersebut
kembali diperiksa setelah wrap. Row baru di belakang cursor juga mendapat
giliran setelah wrap. Restart command melanjutkan cursor; kehilangan Redis
mengulang pass dari awal dengan settlement yang tetap idempotent.

Completion stdout dan `pakasir.reconciliation.completed` mencatat selected,
processed/skipped/failed, `http_attempt_count`, pages, cursor, wrapped,
`stop_reason`, `carry_over`, durasi dan peak alokasi PHP. Stopping reason adalah
`deadline`, `request_limit`, `payment_limit`, atau `exhausted`. Mutex default
dua menit, dihitung di atas execution budget dengan margin 60 detik.
Jangan force-unlock worker aktif; setelah process-tree interruption, tunggu
lease berakhir. Tidak ada perubahan pada verifikasi authoritative, receipt,
stock allocation atau late-payment manual review.

### Output dan health

Setiap event menimpa output sweep terakhirnya, bukan append tanpa retensi:

```text
storage/logs/klikqris-reconciliation.log
storage/logs/klikqris-top-up-reconciliation.log
storage/logs/sakurupiah-reconciliation.log
storage/logs/sakurupiah-top-up-reconciliation.log
storage/logs/pakasir-reconciliation.log
storage/logs/binance-reconciliation.log
storage/logs/bsc-reconciliation.log
```

Heartbeat Docker scheduler menandakan seluruh cycle `ops:schedule-run`,
termasuk child background, selesai dengan bukti token cycle yang cocok dan tanpa
OOM baru. Exit satu yang normal tetap menggagalkan cycle dan mempertahankan
alert kritis, tetapi tidak menyatakan loop mati. Hanya cycle yang seluruh task-nya
sukses dapat memulihkan alert. Callback gagal, exit abnormal, interupsi, atau token
cycle lain tidak dapat menyegarkan heartbeat setelah exit non-zero.
Expiry mendapat slot prioritas pada pool yang sama, bukan fase foreground
eksklusif; verifikasi BSC/notifikasi expiry yang lambat tidak menahan semua
polling. Recovery mail/update dan provider berbagi pool. Exit zero tetap
bukan bukti provider sudah paid atau semua carry-over habis: periksa
output/completion per rail. Shell mempertahankan running marker,
interruption/OOM alert, heartbeat 180 detik, dan log durasi cycle.
Validasi resource pool yang menggantikan fan-out #654 dicatat di
[runtime supervision](runbook-telegram-outbound-effect-contract.md#runtime-supervision-and-alerts).

Framework diperiksa melalui Context7 terhadap Laravel 13
[`runInBackground` dan `withoutOverlapping`](https://github.com/laravel/docs/blob/13.x/scheduling.md)
serta [HTTP timeout/retry](https://github.com/laravel/docs/blob/13.x/http-client.md).
Tidak ada outbox, financial writer atau delivery recovery kedua.

### Bukti runtime disposable #654

Lingkungan: Linux x64, PHP CLI 8.4.27 dalam image
`telegram-bot:php-security-8.4.27` dengan source worktree dimount,
PostgreSQL 16.15 dan Redis 7.4.11. Network Docker `issue654-test` memakai
`--internal`. HTTP provider sungguhan memakai server PHP lokal dengan empat
worker; Guzzle hanya diizinkan menuju fixture tersebut. Endpoint KlikQRIS
canonical dialihkan oleh middleware harness ke loopback, tanpa mengganti
readiness/authority produksi. Queue fake menahan seluruh handoff Telegram/mail.
PostgreSQL dan Redis/cache mutex nyata, bukan SQLite atau cache array.

Dataset tiap reset: 25 Pakasir pending dengan amount 1000, satu Payment
KlikQRIS pending, serta satu Order manual yang sudah melewati expiry.
Provider mengembalikan pending; mode `slow` menunda Pakasir tiga detik,
mode `outage` mengembalikan HTTP 503, mode backlog menunda 80 ms.
Lookup KlikQRIS berjalan terpisah dan tetap pending; fixture QR belum membuktikan
payability (`invalid_provider_result`), sehingga tidak ada klaim settlement atau
pengiriman kredensial dari smoke ini. Mail/update recovery memakai dataset
kosong; bukti itu membuktikan command tidak tertahan secara serial, bukan
throughput delivery atau recovery backlog kedua channel tersebut.

Harness sementara membangun aplikasi/Console Kernel sebenarnya,
memuat `src/routes/console.php`, mempertahankan event KlikQRIS, Pakasir,
`orders:expire-pending`, `orders:recover-credential-mails`, dan
`telegram:recover-updates`, lalu memanggil `schedule:run`. `ARTISAN_BINARY`
diarahkan ke bootstrap harness yang sama agar child dan `schedule:finish`
memakai database/transport disposable. Callback event merekam start/finish;
`docker stats --no-stream` disampling selama cycle. Kontrol serial hanya
mengubah flag `runInBackground` menjadi false pada event harness, bukan
mengembalikan source lama. Ini bukan baseline immutable #649 atau gate #656.

Command yang dijalankan:

```bash
docker exec issue654-app php /tmp/issue654.php smoke:seed
docker exec -u laravel -e SERIAL_CONTROL=1 -e SMOKE_DEADLINE=10 \
  issue654-app php /tmp/issue654.php smoke:cycle
docker exec -u laravel -e SERIAL_CONTROL=0 -e SMOKE_DEADLINE=10 \
  issue654-app php /tmp/issue654.php smoke:cycle
docker exec -u laravel -e SMOKE_DEADLINE=5 \
  issue654-app php /tmp/issue654.php pakasir:reconcile
```

`smoke:*` dan `SMOKE_DEADLINE` hanya milik bootstrap sementara yang sudah
dihapus, bukan command atau ENV aplikasi baru. Default aplikasi tetap 45 detik.
Hanya wrapper lokal yang memilih mode fixture dan override config untuk run ini.

| Skenario | Batas CPU | Cycle ms | Payment / HTTP attempt Pakasir | Sweep Pakasir ms | Stop reason | Maksimum sampel memori container MiB |
| --- | ---: | ---: | ---: | ---: | --- | ---: |
| Kontrol serial, slow, budget 10 s | 0.35 | 43530.47 | 4 / 4 | 10011.90 | deadline | 165.9 |
| Background, slow, budget 10 s | 0.35 | 63569.85 | 3 / 3 | 10103.51 | deadline | 277.8 |
| Background, outage, budget 5 s | 0.35 | 72732.68 | 1 / 2 | 4997.28 | deadline | 259.7 |
| Final backlog, sweep 1 via scheduler | 1.00 | 9057.97 | 10 / 10 | 1488.62 | payment_limit | tidak disampling selama cycle |
| Final backlog, sweep 2 via command baru | 1.00 | tidak dijalankan | 10 / 10 | 1081.80 | payment_limit | tidak disampling |
| Final backlog, sweep 3 via command baru | 1.00 | tidak dijalankan | 5 / 5 | 631.23 | exhausted | tidak disampling |

Setiap baris adalah satu observasi, bukan distribusi latency. Semua container
aplikasi dibatasi 384 MiB. Pada tiga run 0.35 CPU, maksimum sampel CPU
37.32% / 37.69% / 38.60% dan PID 32 / 36 / 38; angka CPU adalah pembacaan Docker
sesaat, bukan core allocation. Fixture dan helper proses ikut dihitung.
Harness memakai `sleep` sebagai PID 1, bukan init yang mereap semua orphan
helper, sehingga PID antar-run tidak membuktikan steady-state produksi.
Pada final backlog, alokasi PHP maksimum per sweep 16 / 16 / 14 MiB;
sampel container setelah cycle idle 37.65 MiB, bukan peak selama cycle.
Cgroup `oom` dan `oom_kill` tetap nol, dan `OOMKilled=false`.

Kontrol serial baru memulai expiry pada +25578.82 ms setelah event pertama.
Background slow memulai expiry pada +106.84 ms dan menyelesaikan expiry serta
mail recovery sebelum callback selesai Pakasir. Update recovery juga selesai
dengan exit 0. Total cycle background tidak selalu lebih cepat: CPU/bootstrapping
dan kompetisi resource tetap berpengaruh, bahkan cycle outage melewati satu
interval menit. Tidak ada interval yang diturunkan atau klaim SLA eksternal.
Topologi semua tujuh rail aktif sekaligus dan steady-state produksi belum diukur.

Final backlog meninggalkan tepat 25 Payment Pakasir pending, satu KlikQRIS
pending, dan satu Payment manual expired. Batch terakhir menghapus cursor
setelah wrap. Pengulangan HTTP 503 lain mencapai batas 30 attempt untuk sepuluh
Payment lalu mencatat `request_limit`; regression test mempertahankan penghitungan retry dan request cap.
Run slow final dengan budget 5 s membuat dua attempt, satu pending dan satu
timeout, selesai pada 5005.50 ms dengan `deadline` dan carry-over. Selisih
5.50 ms adalah bookkeeping lokal, bukan full retry budget tambahan.

Overlap/interruption smoke:

- Dua pemanggilan event yang sama memberi `mutex_held=true` dan
  `second_run_skipped=true`; hanya satu event start tercatat.
- Worker dan shell parent dihentikan setelah fixture menerima request pertama.
  Cursor tetap tersimpan, sementara mutex tetap held. TTL key lock pada koneksi
  Redis `default` dipercepat hanya di disposable runtime; setelah expiry,
  `expired_lock_held=false` dan event baru dapat berjalan. Command berikutnya
  melanjutkan sesudah ID tersimpan, bukan mengulang stuck row pertama.
- Shell `docker-scheduler.sh` sebenarnya dijalankan dengan running marker
  sisa. Episode `scheduler_runtime_failure` terbuka lalu menjadi `resolved`
  setelah cycle bersih; cycle tercatat 26 s. `ops:health
  --scheduler-heartbeat=180` melaporkan heartbeat berumur 9 s, dan running
  marker sudah hilang. Ini liveness foreground, bukan provider-success health.
- Final event memakai file output per rail yang ditimpa dan mempertahankan
  `http_attempt_count`, stopping reason serta cursor di output aktual.

Transport guard diuji dengan request middleware yang menunda dispatch melewati
deadline: tidak ada HTTP yang terkirim. Status lookup dan recovery create
KlikQRIS juga ditolak sebelum transport ketika deadline sudah lewat.
Regresi mencakup context restoration, retry cap, progress setelah row gagal,
wrap/cache-loss, settlement idempotency dan late-payment manual review.

Verifikasi lokal:

```bash
cd src
DB_HOST=172.19.0.2 DB_PORT=5432 DB_DATABASE=issue654_test \
DB_USERNAME=baseline DB_PASSWORD=baseline \
php vendor/bin/phpunit -c phpunit.postgres.xml \
  tests/Feature/PakasirReconciliationTest.php \
  tests/Feature/KlikQrisReconciliationTest.php \
  tests/Feature/PakasirWebhookTest.php \
  tests/Feature/KlikQrisGatewayTest.php \
  tests/Feature/StoreBalanceTopUpReconciliationTest.php \
  tests/Feature/SakurupiahReconciliationTest.php \
  tests/Feature/SakurupiahGatewayTest.php \
  tests/Feature/FoundationInfrastructureTest.php
# 141 tests / 976 assertions passed.
```

Sesudah audit caller, dua run PostgreSQL fokus juga lulus:

```bash
DB_HOST=172.19.0.2 DB_PORT=5432 DB_DATABASE=issue654_test \
DB_USERNAME=baseline DB_PASSWORD=baseline \
php vendor/bin/phpunit -c phpunit.postgres.xml \
  tests/Feature/SakurupiahReconciliationTest.php \
  tests/Feature/SakurupiahGatewayTest.php \
  tests/Feature/Telegram/StoreBalanceTopUpSakurupiahRailTest.php
# 40 tests / 187 assertions passed; memakai DB disposable yang sama.

DB_HOST=172.19.0.2 DB_PORT=5432 DB_DATABASE=issue654_test \
DB_USERNAME=baseline DB_PASSWORD=baseline \
php vendor/bin/phpunit -c phpunit.postgres.xml \
  tests/Feature/KlikQrisReconciliationTest.php \
  tests/Feature/StoreBalanceTopUpReconciliationTest.php
# 41 tests / 328 assertions passed setelah membuang parameter deadline lokal yang tidak dipakai.
```

IP di atas adalah alamat container disposable saat run. Pint pada file PHP
yang berubah dan `sh -n docker-scheduler.sh` lulus. Dua regression Pakasir gagal
sebelum implementasi karena opsi cap belum ada. Tidak ada CI/CD, deploy,
production probe, pembayaran nyata, pesan pelanggan atau klaim full-suite.


## Legacy HTTP 404 and durable discovery (#663)

### Evidence and attribution

Audit read-only 2026-10-28 terhadap release `1a46d9e7` memakai bounded logs
dan SELECT dalam transaksi `READ ONLY`, `statement_timeout=4s`,
`lock_timeout=1s`, lalu `ROLLBACK`. Tidak ada provider probe atau mutation sweep.

- Ada 12 terminal `outcome_unknown`: 3 expired, 9 failed, dibuat
  2026-09-18 sampai 2026-10-08. Lima memiliki signature dan event authoritative;
  tujuh tidak memiliki payload, signature, maupun event authoritative.
- Output terakhir memilih 8 unknown dan 2 history, membuat 10 request dan
  mencatat 4 HTTP 404. Fingerprint MD5 12 karakter dari Payment ID yang gagal:
  `193641f090fc`, `8e763bd0159e`, `62ec11a7e0ca`, `5d6c49031e51`.
  Keempatnya tidak memiliki payload/signature/event authoritative.
  Fingerprint hanya untuk korelasi audit, bukan identifier pembayaran provider.
- Ada pula 71 terminal `recovery_unconfirmed` tanpa payload/signature yang
  dibuat 2026-08-31 sampai 2026-10-24. Predicate lama membuangnya setelah
  lookback 72 jam; #663 menjaga keduanya dalam kelas create ambigu yang sama.
- Deployed source merutekan production ke
  `GET https://klikqris.com/api/qris/status/{merchant_ref}` dengan kredensial
  production **saat ini**, bukan snapshot kredensial saat create. Database
  tidak merekam endpoint/environment/credential version historis.
  Nilai effective config cache dan credential rotation tidak diaudit.
- HTTP 404 membuktikan lookup gagal dalam konteks yang dipakai, bukan bahwa
  provider tidak pernah menerima uang. Transaction tidak pernah tercipta,
  retention/provider state, dan perubahan merchant/credentials tetap tidak
  dapat dibedakan dari evidence ini. Operator harus memverifikasi konteks
  asli dengan provider; jangan menyimpulkan unpaid dari tidak adanya snapshot.

Penyebab kandidat berulang terbukti secara lokal: cursor bergerak dan wrap,
tetapi bukan retry delay. `outcome_unknown` tidak memiliki batas umur;
terminal lookup 404 melempar exception sebelum callback dapat memperbarui
evidence. Row tetap eligible dan kembali setelah wrap. Tidak ada next-check
durable sebelumnya. Repro command sebelum fix membuat dua request untuk satu
row setelah cache dibersihkan; regression sekarang membatasi request kedua.

Scheduler production yang dibaca tetap healthy; bounded log menunjukkan cycle
76 dan 41 detik, KlikQRIS exit 1, sementara Sakurupiah, BSC, Pakasir dan expiry
exit 0. Ini bukti progress task lain, bukan jaminan latency production.

### Discovery policy and failure signal

`KlikQrisNativeQrisService::reconcile()` memiliki policy ini di bawah lock
Payment existing, bukan lifecycle/queue kedua:

| HTTP 404 berturut dalam episode | Delay sebelum discovery berikut |
| --- | --- |
| 1 | 5 menit |
| 2 | 30 menit |
| 3 dan seterusnya | 24 jam |

Migration additive menyimpan `klikqris_status_failure_count` (tier jenuh di 3),
`klikqris_status_next_check_at`, dan `klikqris_status_last_error` pada Payment.
Metadata tidak memperbarui `updated_at`, sehingga tidak memperpanjang history
lookback sebagai efek samping. Cache loss, cursor wrap dan `--after` tidak
mereset delay. Setelah dua retry cepat, discovery dibatasi satu lookup per
hari per row, masih tunduk pada kuota kelas/deadline global; ini bukan
pernyataan bahwa unresolved money selesai.

Order, Payment status, inventory, signature, payload dan event historis tidak
diubah oleh HTTP 404. History yang pernah gagal tetap dapat ditemukan setelah
72 jam. Callback tetap dapat melakukan lookup authoritative selama cooldown.
Hanya evidence identity/amount yang lolos boundary processor existing
menghapus metadata 404. Late success tetap masuk Manual Fulfillment Review;
callback/sweep duplikat tidak menggandakan fulfillment atau receipt.

Stdout `verification: unresolved_status_404=N` dan completion log menyimpan
jumlah unresolved. `operator_action=verify_original_provider_context` berarti
operator harus memeriksa konteks provider asli. Selama count ini positif,
command tetap exit 1, termasuk tick dengan `requests=0`. `failed` per kelas
hanya menghitung exception pada tick itu; `carry_over`/`exhausted` berbicara
tentang kandidat **due**, bukan menyatakan deferred money sudah selesai.
Managed-task alert tetap failed; liveness heartbeat tidak diubah.

### Operator remediation

1. Pisahkan scheduler heartbeat dari `klikqris:reconcile` task failure dan
   unresolved Payment. Jangan menghapus alert, cursor, row atau credential
   history untuk membuat badge hijau.
2. Baca metadata berikut dengan akses database terotorisasi, dalam transaksi
   read-only yang bounded. Jangan mengekspor merchant reference, signature,
   API key atau provider payload:

   ```sql
   BEGIN READ ONLY;
   SET LOCAL statement_timeout = '4s';
   SET LOCAL lock_timeout = '1s';
   SELECT left(md5(id::text), 12) AS payment_fingerprint,
          status, provider_create_state, created_at::date,
          klikqris_status_failure_count,
          klikqris_status_next_check_at, klikqris_status_last_error
   FROM payments
   WHERE provider = 'klikqris'
     AND klikqris_status_last_error IS NOT NULL
   ORDER BY created_at, id
   LIMIT 25;
   ROLLBACK;
   ```

3. Cocokkan tanggal create, event authoritative existing dan konteks
   merchant/environment dengan catatan provider asli melalui kanal aman.
   Credential rotation, penggantian endpoint, provider probe, dan sweep manual
   memerlukan otorisasi terpisah. Jangan mencoba create kedua untuk reference
   yang outcome-nya masih unknown.
4. Simpan kasus operator dan evidence otoritatif. Tanpa bukti tersebut,
   pertahankan row unresolved dan discovery harian. Jangan mengubahnya menjadi
   paid/unpaid, me-release stock, menghapus histori atau menganggap invoice
   provider tidak pernah ada. Provider recovery/callback yang valid tetap
   memakai processor dan Manual Fulfillment Review existing.

### Local verification

Regression deterministik mencakup cache loss/wrap, delay 5m/30m/24h,
history melewati 72 jam, fairness kuota dua Payment terhadap lima old ambiguous
rows dan satu active, recovery pending, late paid, serta callback saat deferred
dan replay tanpa receipt kedua.

Smoke memakai actual Console Kernel, command provider, dan
`ops:schedule-run` pada SQLite WAL/file cache disposable, dengan HTTP nyata
hanya ke fixture loopback dan semua queue fake. Tidak ada Telegram/provider
production. Lima terminal unknown + satu Sakurupiah pending + satu Order due:

- Tiga sweep KlikQRIS `--max-payments=2` membuat request `2, 2, 1`, lalu
  sweep keempat membuat `0`, tetap exit 1 dan menyimpan lima unresolved.
- Pool tetap maksimum dua child. Expiry memproses satu Order. Sakurupiah
  membuat satu HTTP request dan melaporkan `pending=1`, exit 0.
  Sakurupiah mulai pada +1366.56 ms, sebelum KlikQRIS selesai pada +2778.46 ms;
  angka ini satu observasi lokal, bukan distribusi/SLA.
- Setelah fixture mengembalikan success dan next-check dibuat due hanya
  dalam disposable DB, command menemukan lima late Payment, membuat lima
  Manual Fulfillment Review dan menghapus lima metadata 404. Sweep berikut
  membuat nol request dan tidak menambah review.

Focused SQLite suite: 151 tests / 1041 assertions lulus, mencakup
reconciliation, webhook, native lifecycle, gateway, Telegram checkout/status
verification, bounded scheduler dan isolation. Pint dijalankan pada PHP yang
diubah. Smoke scaffolding dihapus setelah pengukuran. Tidak ada klaim CI,
deploy, pembayaran nyata, provider qualification atau fix production.

PostgreSQL 16.15 disposable (`issue663_test`, loopback port 18663):

```sh
DB_HOST=127.0.0.1 DB_PORT=18663 DB_USERNAME=baseline \
DB_PASSWORD=baseline DB_DATABASE=issue663_test \
php vendor/bin/phpunit -c phpunit.postgres.xml \
  tests/Feature/KlikQrisReconciliationTest.php \
  tests/Feature/KlikQrisWebhookTest.php \
  tests/Feature/KlikQrisNativeQrisTest.php
# 96 tests / 700 assertions passed.
```

Dua assertion lama yang bergantung pada urutan key JSON dihapus dari test
unknown callback. PostgreSQL JSONB tidak menjamin urutan itu; test tetap
memeriksa status unknown, tidak adanya mutasi/HTTP/receipt, coalescing replay,
merchant-reference hashing dan tidak tersimpannya secret fixture.
Final SQLite tetap 151 / 1041, dan final Pint `--test` lulus.
Smoke actual command diulang pada source final dan database disposable baru:
lima unresolved tetap fail closed saat deferred, lalu lima late reviews setelah
recovery. Tidak ada layanan atau file smoke yang dipertahankan.


