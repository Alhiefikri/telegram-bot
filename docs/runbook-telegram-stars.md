# Telegram Stars: verifikasi dan operasi

Terkait [#740](https://github.com/Alhiefikri/telegram-bot/issues/740), [#746](https://github.com/Alhiefikri/telegram-bot/issues/746) dan [#747](https://github.com/Alhiefikri/telegram-bot/issues/747). **Default OFF.** Runbook ini bukan otorisasi deploy, aktivasi publik, transaksi nyata, atau payout.

## Persiapan oleh Owner

1. Di **Admin → Pengaturan → Harga Telegram Stars**, biarkan **Izin penerbitan** nonaktif. Masukkan kurs IDR/USD, estimasi reward dalam micro-USD per XTR dan cadangan basis points; uji preview sebelum menyimpan. Contoh: Rp10.000, kurs Rp18.300, reward 13.000 micro-USD, cadangan 1.500 bp → 50 XTR. Nilai ini bukan rekomendasi kurs atau yield. Versi lama tetap melekat pada invoice yang sudah diterbitkan; jangan mengubah snapshot.
2. Dengan bot terpisah di test environment Telegram, verifikasi webhook secret, update `message` dan `pre_checkout_query`, worker checkout dan `payments`, dan scheduler recovery. Pada salinan lokal terisolasi (`APP_ENV=local`), set `services.telegram.test_network` ke `true` di `src/config/services.php` dan gunakan kredensial test bot; Bot API memakai rute `/test/`. Jangan gunakan token produksi, katalog sungguhan, atau data pelanggan. Pisahkan database, backup, cache dan queue dari produksi. Izin penerbitan Owner tidak membuka Stars di bot publik, termasuk bila database berisi kebijakan aktif.
3. Atur kontak privat Support di dashboard dan uji tautannya dari `/paysupport`. Pastikan operator dapat menerima dan menangani sengketa. Gunakan nomor invoice, bukan username sebagai identitas pembayaran; jangan meminta OTP, password, atau screenshot sebagai bukti uang. Aktifkan 2-step verification akun Telegram yang mengendalikan bot. Bila ikon premium `payment.stars` kosong atau ditolak, tampilannya harus kembali ke ⭐.
4. Tinjau kelayakan katalog dan syarat pembelian di Direct/Cart. MVP hanya untuk pembayaran tunggal yang seluruh kredensial lokalnya bisa direservasi. Telegram memperingatkan risiko barang digital yang tidak dapat ditarik kembali setelah chargeback; keputusan kategori dan penerimaan risiko tetap milik Owner.

## Pause, monitoring, dan rollback

- Matikan **Izin penerbitan** di halaman harga dan simpan versi terbaru. Pause menutup admission dan claim untuk invoice/approval baru; kiriman yang sudah di-claim bisa in flight, dan replay query yang telah disetujui dapat mengembalikan keputusan tersimpan dalam jendela yang sama. Uang yang sudah berjalan tetap harus diproses. Approval bukan status lunas.
- Biarkan webhook, worker dan scheduler aktif. Pantau latensi `dogfood.telegram.stars_pre_checkout` (bila instrumentation aktif; target aplikasi ≤2 detik, batas Telegram 10 detik), invoice outbound `pending`/`unknown`, dan **Admin → Pengaturan → Refund Telegram Stars**. Tinjau `telegram.stars_payment_review`, `telegram.stars_refund_review`, `telegram.stars_refund_uncertain` dan `telegram.stars_unresolved_work`. Pakai timestamp, internal payment ID/inbox ID, hitungan atau offset; jangan salin token, charge mentah, payload, Telegram ID, atau alasan berisi PII ke log/tiket umum.
- Dari `src/`, `php artisan telegram:report-stars-operations` membaca backlog, review, klaim berumur >5 menit, drift antara claim dan status Payment/Order, serta total settlement dan refund terkonfirmasi **dalam XTR**. Hitungan review bersifat historis, bukan antrian yang otomatis turun. Basis harga IDR pada Order bukan kas; jangan gabungkan XTR dengan kas IDR, USDT, atau saldo spendable Telegram.
- `php artisan telegram:recover-stars-payments --limit=25` memproses ulang inbox durable, termasuk refund; `php artisan telegram:recover-outbound` membantu wake-up yang hilang. Jangan kirim ulang invoice `unknown` secara otomatis; tinjau bukti terlebih dahulu dan gunakan alur resend operator untuk *target yang sama* jika memang disetujui. Jangan menciptakan order/payload pengganti hanya karena timeout.
- Rollback operasional berarti **pause**, bukan downgrade schema, mematikan worker atau menghapus inflight work. Pertahankan identitas bot dan namespace; rotasi secret dari bot yang sama tidak mengganti identitas. Jangan mengganti bot untuk menyelesaikan klaim lama.

### Restore dan pending work

Cadangkan database secara terenkripsi (termasuk inbox, snapshot, approval, receipt, settlement/review, refund claim, audit, inventory, dan outbox); simpan kunci terpisah. Latih restore pada database dan queue terisolasi tanpa koneksi outbound ke produksi. Sebelum menerima order setelah restore nyata, tetap pause, jangan lakukan refund baru, dan bandingkan window yang hilang dengan backup/WAL, bukti Telegram, serta produk yang mungkin sudah dikirim. Restore DB lama tidak mengembalikan saldo Telegram atau kredensial yang sudah dikirim. Jangan memulihkan record dengan menebak dari screenshot, menghapus claim yang statusnya tidak diketahui, atau membuka pause dari hitungan nol saja. Jalankan recovery bila bukti lokal yang benar kembali tersedia, lalu pemeriksaan drift. Jika ada event tidak pernah tiba atau bukti tidak cukup, tetap review manual; history tidak otomatis melunasi order. Owner memberi persetujuan terpisah sebelum membuka admission kembali.

## Support, refund, dan dispute

1. Pelanggan membuka `/paysupport` di privat, lalu menghubungi Support dengan nomor invoice. Tanggung jawab penanganan ada pada merchant, bukan Telegram support.
2. Owner membuka detail charge di **Refund Telegram Stars**. Cocokkan invoice, pembeli Telegram asli, receipt internal/label charge tersamarkan dan total XTR. Isi alasan audit, konfirmasi refund penuh dan masukkan kembali password **dashboard**. Tidak ada perubahan buyer/amount, refund parsial, split tender, atau kredit alternatif.
3. Aplikasi menyimpan claim sebelum satu panggilan `refundStarPayment`. Hanya ACK sukses dengan `ok: true` dan `result: true` yang mencatat refund Owner sebagai `confirmed`. Timeout, crash, ACK aneh dan error provider **bukan** izin kirim ulang, termasuk ketika claim tampaknya belum sempat dikirim. Retry hanya pembacaan/pemrosesan bukti.
4. `php artisan telegram:report-stars-history --offset=0 --pages=1` melakukan scan read-only (100 baris/halaman, maks. 5 halaman sekali jalan). Lanjutkan dari `next_offset` sampai window yang ditinjau tercakup; offset bukan cursor permanen. `--reconcile-refunds` mencatat bukti `receiver` dari invoice ke antrean review tanpa panggilan refund. Arah `source`, dua arah, partner non-invoice, dan payload/akun/nominal/waktu yang tidak cocok tidak membuktikan reversal. Batasi scan dan ulangi window jika history bergerak.
5. `external_reversal_review` berarti reversal di luar claim Owner (mungkin chargeback). `reversal_attribution_unknown` berarti webhook atau debit yang cocok membuktikan reversal, tetapi bukan bahwa permintaan Owner menyebabkannya. Pertahankan review, jangan menandainya sebagai refund Owner, jangan kirim refund atau barang lagi, dan eskalasi bila sumbernya tidak bisa dipastikan. Charge tambahan, order/charge tidak dikenal, atau bukti yang tidak lengkap juga tetap di review. Refund Owner yang dikonfirmasi memperbarui riwayat pembayaran asli tanpa restock atau mutasi saldo.

## Bukti teknis (#747)

| Tahap | Bukti fixture lokal |
| --- | --- |
| T1 ([#741](https://github.com/Alhiefikri/telegram-bot/issues/741)) | `StarsQuoteCalculatorTest`, `StarsPricingPolicyTest`: formula, pembulatan, preview, role, audit dan versi. |
| T2 ([#742](https://github.com/Alhiefikri/telegram-bot/issues/742)) | `TelegramStarsCheckoutTest` dan `TelegramStarsCheckoutPostgresConcurrencyTest`: Direct/Cart, binding quote, replay, snapshot, stok terakhir dan regresi checkout. |
| T3 ([#743](https://github.com/Alhiefikri/telegram-bot/issues/743)) | `TelegramStarsWebhookTest` dan `TelegramStarsTransportPostgresConcurrencyTest`: autentikasi, minimization, invoice ACK/timeout, race approval/expiry dan deadline di fixture. |
| T4 ([#744](https://github.com/Alhiefikri/telegram-bot/issues/744)) | `TelegramStarsSettlementTest` dan `TelegramStarsSettlementPostgresConcurrencyTest`: durable inbox, replay, charge conflict, rollback atomik, late payment, history yang tidak melakukan settlement. |
| T5 ([#745](https://github.com/Alhiefikri/telegram-bot/issues/745)) | `TelegramStarsCheckoutUxTest`: persetujuan, locale, fallback ikon dan petunjuk isi saldo, history/struk XTR. |
| T6 (#746) | `TelegramStarsRefundPostgresConcurrencyTest` dan `/paysupport` HTTP fixture: Owner/password/preview, race satu call, ACK ketat, timeout, debit salah arah/mismatch, external reversal, refund webhook, lost wake-up, histori, dan tanpa kredit/restock. |
| Drill pause (#747) | Fixture PostgreSQL menahan invoice baru, menolak approval baru pada invoice ber-ACK, tetap memproses success yang sebelumnya disetujui, dan akhirnya mengonfirmasi refund saat pause; tes checkout memverifikasi quote baru diblokir dan replay tidak melakukan repricing. |

Suite PostgreSQL harus memakai instance disposable dan nama database berlabel `test`: `RUN_CONCURRENCY_TESTS=1 vendor/bin/phpunit -c phpunit.concurrency.xml --filter 'TelegramStars.*PostgresConcurrencyTest'`. Suite HTTP/SQLite lokal: `vendor/bin/phpunit --exclude-group concurrency --filter 'Stars|PakasirWebhookTest|KlikQrisWebhookTest|UsdtBalanceCheckoutTest|StorefrontShop(Checkout|Cart|OrderInvoice)Test|StorefrontCheckoutAdmissionTest|StorefrontBinanceOrderIdTest|HistoricalOrderRefundTest|ManualFulfillmentResolutionTest'`. Jalankan dari `src/` dengan `APP_KEY` fixture non-produksi. PHP memerlukan `pdo_pgsql` dan `pcntl` untuk race; SQLite tidak membuktikan row locking.

Hasil lokal: **16/16 PostgreSQL 16 disposable**, **293/293 fixture dan regresi HTTP/SQLite**, serta **32/32 regresi rail tambahan** (`BinanceCheckoutPaymentPreparationTest`, `BscUsdtTransferIngestionTest`, `StoreBalanceReconciliationTest`, `StoreBalanceTopUpReconciliationTest`). Build aset lokal dibutuhkan untuk tes HTTP Storefront. Tidak ada GitHub Actions manual, deploy, transaksi/payout nyata, atau perubahan terhadap konfigurasi produksi. Drill integrasi juga menemukan pemanggilan scope stok yang tidak ada pada baseline; pemeriksaan reservasi sekarang memakai filter stok lokal yang sama dengan allocator (`supplier_purchase_id IS NULL`) dan tetap menolak stok retired.

### Gap: Telegram test environment

**Belum diuji di Telegram test environment.** Tidak ada bot/test account dengan Stars uji yang disediakan. Hasil fixture tidak membuktikan tampilan native, saldo kurang atau konfirmasi Telegram live. Untuk sesi terpisah gunakan [test environment resmi](https://core.telegram.org/bots/features#testing-your-bot), bot dan akun uji, kredensial dummy, tanpa uang nyata:

- Mobile dan desktop: Direct/Cart eligible → terms → invoice privat → approve → bayar dengan test Stars → buka invoice yang menjadi struk → lihat satu fulfillment dan histori XTR.
- Gunakan pembeli dengan test Stars tidak cukup; catat ada/tidaknya opsi resmi isi saldo dan petunjuk **Settings → My Stars**, lalu kembali ke invoice yang sama. Periksa icon premium jika didukung dan fallback ⭐. Jangan membeli Stars sungguhan.
- Owner refund penuh; bandingkan struk/status aplikasi/riwayat Telegram. Ulang drill pause dan uji `/paysupport` di private dan group. Catat versi client, tanggal, hasil, dan kemampuan yang tidak tersedia; jangan klaim pass jika tidak bisa diuji.

## Keputusan aktivasi publik: belum disetujui

[Telegram Bot Developer Terms §6.2](https://telegram.org/tos/bot-developers#6-2-digital-goods-and-services) mewajibkan Stars secara eksklusif untuk barang/jasa digital di bot dan Mini App; §6.2.1 mewajibkan jalur sengketa termasuk `/paysupport`. Mode checkout digital tiga rail bukan mode rilis yang sesuai. Web Storefront independen bukan fallback checkout bot. Jangan menambahkan link/tombol bypass. Jika Owner memilih cutover Stars-only pada tiket terpisah:

| Titik cutover | Keputusan atau kontrol yang dibutuhkan |
| --- | --- |
| Direct, Cart, deep link dan callback lama | Blokir intent pembayaran digital non-Stars di server, bukan hanya tombol; uji callback terlambat dan Cart campuran/noneligible. |
| QRIS (termasuk mode/manual lama) dan kripto (Binance/BSC) | Owner memutuskan scope/admission; tetap tangani settlement, expiry, refund dan histori transaksi lama. |
| Top-up IDR/USDT dan saldo lama | Owner memutuskan apakah/di mana funding dan spending boleh tetap ada tanpa membayar digital di bot. Pertahankan histori dan kewajiban saldo lama, jangan mengonversi otomatis ke XTR. |
| Checkout eksternal/API | Tidak ada perubahan dari tiket ini; jangan jadikan rute eksternal sebagai bypass dari bot/Mini App. |

Buka gate publik hanya setelah keputusan scope, kebijakan saldo/katalog, support/terms, pemulihan dan bukti native disetujui. Tidak ada klaim kepatuhan, eligibility payout atau persetujuan aktivasi dari fixture teknis.

## Pengukuran payout (hanya jika Owner menyetujui eksperimen lain)

Cek eligibility akun/wilayah, aturan Fragment, periode hold dan syarat verifikasi yang berlaku saat itu ([§6.2.4](https://telegram.org/tos/bot-developers#6-2-4-rewards-for-stars)). Catat batch ID privat, tanggal, XTR yang benar-benar didebit untuk reward, TON bruto dan biaya Fragment/jaringan, TON yang sampai ke jalur konversi, kurs eksekusi, biaya platform/bank/pajak yang benar-benar dibayar, serta IDR bersih yang diterima. Jangan kurangi biaya yang sudah termasuk dalam jumlah net dua kali. Hitung **IDR bersih diterima ÷ XTR yang didebit untuk batch reward**, bukan dari estimasi harga katalog, Telegram Ads, harga pembelian Stars pelanggan, atau struk gift. Jika berhenti di TON, catat TON net dan tandai IDR belum direalisasi. Jangan menyimpan token, seed phrase, atau data KYC di runbook. Tidak ada eksperimen payout dalam #747.

Rujukan implementasi (Context7: `/websites/core_telegram_bots_api`, detail diverifikasi di dokumentasi resmi): [Payments in Stars](https://core.telegram.org/bots/payments-stars), [`refundStarPayment`](https://core.telegram.org/bots/api#refundstarpayment), [`RefundedPayment`](https://core.telegram.org/bots/api#refundedpayment), [`StarTransaction`](https://core.telegram.org/bots/api#startransaction), [`TransactionPartnerUser`](https://core.telegram.org/bots/api#transactionpartneruser).
