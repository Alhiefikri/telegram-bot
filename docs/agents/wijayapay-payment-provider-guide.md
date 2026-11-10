# Panduan Agent: Payment Provider WijayaPay

Status: **implemented** for checkout QRIS and Store Balance top-ups.

This guide records the WijayaPay provider contract, the integrity rules that
are intentionally stricter than the published examples, and the operational
limits that keep the provider from leaking money, credentials, or request
capacity.

## Sumber dan batas kontrak

- Dokumentasi resmi: <https://docs.wijayapay.com/>
- Gateway: `https://gateway.wijayapay.com`
- Satu environment saja; tidak ada sandbox yang dipublikasikan. Kredensial
  produksi adalah `WIJAYAPAY_CODE_MERCHANT` + `WIJAYAPAY_API_KEY`. Base URL,
  callback/return route, allowlist host, timeout, dan budget retry dipin di
  `src/config/services.php`, bukan ENV.
- Channel yang diaktifkan untuk rilis ini: `QRIS` saja. Virtual Account dan
  retail tidak dipakai karena toko hanya menjual rail QRIS.

### Endpoint

| Operasi | Method | Endpoint | Catatan |
| --- | --- | --- | --- |
| Daftar channel | `GET` | `/api/get-payment?code_merchant=&api_key=` | Dipakai readiness; rate limit header contoh `5/menit`. |
| Buat transaksi | `POST` | `/api/transaction/create` | JSON body + header `X-Signature`. |
| Cek status | `GET` | `/api/get-status?code_merchant=&api_key=&ref_id=` | Sumber status authoritative. |
| Callback | merchant URL | `POST /api/webhook/wijayapay` | Provider mengirim JSON + `X-Signature`. |

### Signature

```text
signature = md5(code_merchant + api_key + ref_id)
```

Ini **bukan HMAC** dan tidak mencakup amount/status. Signature hanya
mengautentikasi klaim `ref_id`; setiap transisi `paid`/`expired` wajib
diverifikasi ulang ke `/api/get-status`. Implementasi ada di
`WijayaPaySignature`; perbandingan memakai `hash_equals`.

### Create response

`success: true` dengan `data` berisi `payment_method`, `total_bayar`,
`total_fee`, `total_diterima`, `ref_id`, `trx_reference`, `expired`,
`qr_string`, dan/atau `qr_image`. Provider mendokumentasikan bahwa `ref_id`
yang sudah dipakai akan mengembalikan response pengecekan status, bukan
membuat transaksi kedua. Parser menerima kedua bentuk tersebut.

Aturan integritas:

- `ref_id` harus sama dengan `Payment.merchant_ref`.
- `payment_method` harus `QRIS`.
- `total_bayar` harus sama dengan nominal lokal (fee ditanggung toko) atau
  nominal + `total_fee` (fee ditanggung pembeli).
- `total_fee` tidak boleh melebihi `qris_contract.max_fee_bps` (default 500
  bps) sebagai guard terhadap response yang tidak masuk akal.
- `total_diterima` harus konsisten dengan `total_bayar - total_fee` bila
  dikirim.
- `expired` wajib valid dan future sebelum invoice dianggap payable.
- QR hanya dipresentasikan bila `qr_string` adalah payload EMVCo valid dengan
  nominal tag 54 sama dengan total, atau `qr_image` HTTPS pada host allowlist
  (`wijayapay.com`, `www.wijayapay.com`).

### Status dan callback

Status yang diterima: `pending`, `paid`, `expired`. Nilai lain fail closed.

Callback contoh:

```json
{
  "data": {
    "updated_at": "2026-11-07 03:52:51",
    "payment_methode": "QRIS",
    "total_dibayar": 83302,
    "total_fee": 679,
    "amount_received": 82623,
    "trx_reference": "WP750872",
    "ref_id": "ORD-Q7SH8NXHLX"
  },
  "status": "paid"
}
```

Endpoint merchant wajib membalas `{"status": true}`. Callback duplicate dan
unknown di-acknowledge dengan bentuk yang sama; acknowledgement bukan bukti
pembayaran. Body dibatasi 64 KiB, JSON depth 32, dan duplicate key di objek
mana pun ditolak.

Verifikasi callback selalu:

1. parse body dengan schema ketat;
2. verifikasi `X-Signature` terhadap `ref_id` dari body;
3. cari `Payment` berdasarkan `provider = wijayapay` + `merchant_ref`;
4. baca status authoritative dari `/api/get-status`;
5. cocokkan `ref_id`, `trx_reference`, total, fee, dan amount evidence;
6. baru delegasikan `paid`/`expired` ke `OrderLifecycle`.

Callback yang `ref_id`-nya bukan Payment tetapi cocok dengan
`StoreBalanceTopUp` dirutekan ke rail top-up. Callback hanya menjadi wakeup dan
klaim reference tersegel; rail tetap membaca `/api/get-status` sebelum settle.
Top-up `expired` masih boleh late settle, sedangkan `invalid_provider_result`
tetap manual review.

`callback_enforce_ip_allowlist` default `false` karena origin dapat berada di
belakang reverse proxy yang tidak tercakup trusted-proxy. Bila dinyalakan,
`callback_allowed_ips` berisi IP publik provider (`172.235.245.77`).

## Race, recovery, dan performance

- **Single-flight per Payment**: cache lock `wijayapay:payment:{id}` dipakai
  bersama oleh create, refresh pelanggan, verifikasi callback, dan sweep.
  Tidak ada HTTP di dalam transaksi database.
- **Create idempotent**: satu `Payment` hanya punya satu `merchant_ref`.
  Kegagalan transport/schema menjadi `outcome_unknown`; tidak pernah membuat
  Payment atau reference kedua. Retry create memakai reference yang sama
  karena provider mendokumentasikan deduplikasi `ref_id`.
- **Ambiguous create recovery**: jawaban `transaksi tidak ditemukan` dari
  `/api/get-status` membuktikan belum ada transaksi dan boleh memicu create
  ulang pada reference yang sama. HTTP/transport failure, error kredensial,
  dan response rusak tidak pernah dianggap not-found. Retry dibatasi interval
  `create_retry_min_interval_seconds` dan window
  `create_recovery_window_seconds`; sweep Payment maupun top-up memakai jalur
  ini, sehingga customer tidak perlu menekan retry manual.
- **Callback/reconciliation race**: `OrderLifecycle` tetap pemilik transisi.
  Receipt global (`ExternalMoneyReceiptWriter`) mencegah satu pembayaran
  diklaim dua kali.
- **Expiry/paid race**: status `expired` tidak boleh mengalahkan indikasi
  sukses yang sudah tercatat; Payment tetap `verification_pending` sampai
  status authoritative terbaru tersedia.
- **Request gate**: `WijayaPayRequestGate` menyerialkan dispatch per merchant
  dan menyimpan cooldown 60 detik setelah HTTP 429. Lookup di-retry maksimal
  3 kali dengan backoff; create tidak pernah di-retry buta.
- **Readiness cache**: `WijayaPayProviderReadiness` meng-cache observasi QRIS
  selama 60 detik dengan single-flight lock. `get-payment` tidak dipanggil per
  checkout atau per request admin.
- **No N+1 on read paths**: status lookup dilakukan per Payment, bukan per
  Order Item. Daftar channel hanya diambil saat readiness cache miss.
- **Late top-up payment**: deadline lokal hanya menutup presentasi payable,
  bukan bukti bahwa pelanggan belum membayar. Sweep tetap membaca status
  authoritative untuk top-up `expired` dalam
  `reconcile_terminal_lookback_hours` (default 72 jam), dan callback selalu
  memverifikasi ulang. Hanya provider `paid` yang cocok dengan
  amount/fee/total/reference yang boleh late settle; evidence yang tidak cocok
  tetap manual review.
- **Fair top-up sweep**: kursor `created_at`+`id` yang persisten berputar
  melalui seluruh target unresolved, sehingga target lama yang terus pending
  tidak memblokir target berikutnya. `chunk`, `max-top-ups`, cooldown 429, dan
  execution budget tetap menjadi batas per sweep.
- **Active poll**: `PollActiveWijayaPayPayment` berjalan tiap ~20 detik untuk
  checkout aktif; sweep menit tetap menjadi jalur pemulihan jika wakeup
  hilang.

## Local mapping

| Local field | WijayaPay | Aturan |
| --- | --- | --- |
| `provider` | `wijayapay` | Immutable provenance. |
| `merchant_ref` | `ref_id` | Satu-satunya idempotency/correlation key. |
| `provider_reference` | `trx_reference` | Wajib sebelum paid. |
| `method` / `provider_payment_method` | `QRIS` | Rail tetap QRIS. |
| `amount` | nominal lokal | IDR integer. |
| `provider_total_payment` | `total_bayar` | Bisa sama dengan amount atau amount + fee. |
| `provider_fee` | `total_fee` | Nonnegatif, dibatasi `max_fee_bps`. |
| `provider_payload` | sanitized projection | Tidak pernah raw response/kredensial. |
| `provider_presentation` | encrypted QR payload/image URL | Hanya QR tervalidasi. |
| `provider_create_state` | state lifecycle | `creating`, `create_failed`, `outcome_unknown`, `recovery_unconfirmed`, `created`, `provider_total_pending`, `provider_presentation_pending`, `provider_expired`, `provider_paid`, `provider_invalid_result`. |

Tidak ada migration baru: kolom generik `payments` dan
`store_balance_top_ups` sudah cukup.

## Operasional

- Aktifkan provider lewat Admin Payment Provider Switch; readiness gate
  memeriksa kredensial, base URL, callback route, QRIS channel aktif, dan
  kontrak IDR/expiry.
- Scheduler: `wijayapay:reconcile --chunk=100` dan
  `wijayapay:reconcile-balance-top-ups --chunk=100 --max-top-ups=10` setiap
  menit bila konfigurasi siap.
- Health check: `php artisan ops:health --wijayapay`.
- Jangan pernah menaruh `api_key` di log, Payment payload, event, screenshot,
  atau test fixture. URL `get-payment`/`get-status` membawa `api_key` sebagai
  query parameter sesuai kontrak provider; transport error tidak boleh
  dilaporkan dengan URL mentah.

## Entry point test

- `WijayaPayGatewayTest` — signature, create header/body, duplicate ref,
  lifecycle snapshot, callback verification, signature rejection, duplicate
  callback, status outage, storefront invoice presentation, scheduler
  ambiguous-create recovery vs provider outage, bounded recovery window.
- `WijayaPayTopUpRailTest` — payable top-up, QR attachment, trusted paid
  evidence, expired status, scheduler late settlement after local expiry,
  signed top-up callback, no-credit contradiction/manual review, and fair
  cursor rotation beyond one sweep.
- `ProductionConfigurationValidatorTest` — production config safety.
- `Telegram/CheckoutManualAlternativeTest` dan
  `Telegram/StoreBalanceTopUpManualAlternativeTest` menguji pilihan manual
  eksplisit, ownership pesan, status tidak pasti, retirement QR, replay,
  pembayaran dua jalur, serta larangan menghapus provenance lewat rollback.

## Alternatif QR manual di Store Bot

Checkout dan top-up IDR menawarkan **Gunakan QR Manual** tanpa mengubah
Payment Provider Switch toko. Pelanggan mengonfirmasi bahwa ia belum
membayar; status provider dibaca ulang sebelum perpindahan. Status tidak
pasti menahan perpindahan, bukan dianggap belum bayar. QR otomatis harus
terbukti sudah dihapus dari Telegram sebelum instruksi manual dikirim.
Provider dan Telegram HTTP berjalan di luar transaksi keuangan; token
retirement/switch membatasi worker yang boleh menyelesaikan perpindahan.

Checkout mempertahankan Order dan invoice provider asli, lalu mencatat
Payment manual yang tertaut secara unik ke Payment asli. Payment provider
yang telah dialihkan tidak mengotorisasi fulfillment lagi: dana masuk
berikutnya dicatat sebagai review `payment_after_manual_alternative`.
Top-up mempertahankan target serta invoice provider, menggunakan
`manual_alternative_at` untuk presentasi efektif dan `settled_provider`
untuk sumber kredit sebenarnya. Pembayaran kedua dari jalur lain menjadi
review tanpa kredit saldo kedua. Kedua migrasi menolak rollback yang
menghapus riwayat perpindahan.

Teks tersedia dalam Indonesia, Melayu, dan Inggris. Nominal final memakai
entity Telegram `code`; hanya angkanya yang disalin, bukan subtotal,
invoice, atau label mata uang. Nominal manual tidak mewarisi biaya invoice
otomatis. Dukungan aplikasi Malaysia tetap bergantung pada QR merchant;
fitur ini tidak menjamin semua aplikasi lintas negara dapat membayar.

Verifikasi lokal: suite Telegram/provider/renderer menjalankan 1.490 tes
(1.484 lulus, 6 dilewati), 13.707 assertions. Suite review, refund, top-up
dan lifecycle tambahan lulus 126 tes / 939 assertions. Audit katalog bahasa
lulus untuk `id`, `en`, dan `ms`. Smoke runtime dengan provider fixture
membuktikan nominal otomatis 10.125 dan manual 10.000 pada ketiga bahasa,
Order sama dan default toko tetap WijayaPay. Webhook ngrok serta `/start`
ke `LhieStoreTestBot` berhasil. Koneksi Telegram berikutnya timeout dari PHP
dan Python, sehingga E2E live pembayaran penuh belum terverifikasi.
Backend fixture dan harness dihentikan/dibersihkan. Tidak ada transfer
sungguhan atau perubahan produksi.


## Framework references

The implementation decisions were checked against the Laravel 13 documentation
through Context7:

- HTTP client timeouts, connection exceptions and fake testing:
  <https://github.com/laravel/docs/blob/13.x/http-client.md>
- Database transactions, deadlock retries and `lockForUpdate`:
  <https://github.com/laravel/docs/blob/13.x/database.md> and
  <https://github.com/laravel/docs/blob/13.x/queries.md>
- Cache atomic locks (`Cache::lock()->block()`) for single-flight provider
  work: <https://github.com/laravel/docs/blob/13.x/cache.md>

## Known provider unknowns

- Apakah `success` selalu ada pada response duplicate `ref_id`.
- Apakah `get-status` selalu mengembalikan `total_bayar`/`total_dibayar` pada
  status `paid`; bila tidak, settlement tetap `verification_pending`.
- Rate limit selain `get-payment` dan perilaku `Retry-After`.
- Timezone/format `expired` di luar contoh `Asia/Jakarta`.
- Perilaku callback bila `ref_id` sudah kedaluwarsa atau transaksi berstatus
  `expired` (docs hanya mencontohkan `paid`).

Semua unknown di atas ditangani dengan fail-closed. Alternatif manual
memerlukan pilihan eksplisit pelanggan dan bukti aman seperti dijelaskan
di atas; kegagalan provider tidak pernah memindahkan metode secara diam-diam.
