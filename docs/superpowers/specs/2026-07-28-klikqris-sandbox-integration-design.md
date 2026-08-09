# Desain Integrasi KlikQRIS Sandbox Paralel

**Tanggal:** 2026-07-28
**Status:** Disetujui untuk direncanakan
**Ruang lingkup:** Integrasi KlikQRIS sandbox pada Store Bot testing dan Web Storefront lokal tanpa mengubah perilaku Pakasir atau mengaktifkan KlikQRIS produksi

## Tujuan

Membuktikan bahwa KlikQRIS dapat menggantikan Pakasir pada masa depan melalui pengujian end-to-end yang aman. Pelanggan testing harus dapat menerima QRIS langsung di Telegram, membayar nominal final yang diberikan KlikQRIS, dan memperoleh receipt setelah pembayaran diverifikasi. Seluruh pekerjaan berlangsung paralel terhadap Pakasir dan tidak boleh mengubah provider produksi tanpa persetujuan eksplisit pemilik.

## Keputusan utama

- Implementasi dilakukan pada worktree dan branch `feature/klikqris-sandbox`.
- Target Telegram adalah LhieStore Testing Bot dengan database lokal atau database testing yang terpisah dari produksi.
- Fase ini hanya mendukung endpoint KlikQRIS sandbox.
- Pakasir tetap menjadi perilaku default di `main` dan produksi.
- QRIS ditampilkan langsung di Telegram. Pelanggan tidak diarahkan ke hosted checkout, browser, redirect, atau Popup Snap Payment.
- Web Storefront lokal menampilkan invoice QRIS sebagai fallback dan menyediakan observasi transaksi untuk pengujian, tetapi pelanggan Telegram tidak dipaksa membukanya.
- Satu QRIS berlaku 15 menit. Setelah kedaluwarsa, QRIS lama tidak dipakai ulang.
- Webhook mempercepat deteksi pembayaran, sedangkan status API KlikQRIS menjadi sumber verifikasi utama.
- Tidak ada aktivasi, deployment, atau cutover produksi otomatis. Keputusan produksi memerlukan persetujuan baru dan eksplisit dari pemilik.

## Batas isolasi

### Git dan runtime

Pekerjaan kode dilakukan dalam worktree terpisah agar workspace `main` tetap bersih. Branch sandbox dapat mengubah seam checkout bersama hanya sejauh diperlukan untuk memilih provider secara konfigurabel, tetapi tidak boleh menghapus, mengganti, atau mengubah hasil jalur Pakasir.

Runtime pengujian menggunakan:

- LhieStore Testing Bot dan token Telegram testing.
- Database lokal/testing yang terpisah.
- API key dan merchant ID KlikQRIS sandbox.
- URL publik sementara menuju aplikasi lokal untuk callback. Validasi awal memakai
  ngrok yang sudah tersedia pada host dan meneruskan HTTPS publik ke port web
  Docker lokal `8000`; Cloudflare Tunnel tetap dapat dipakai sebagai alternatif.
- Callback URL sandbox khusus: `https://<tunnel>/api/webhook/klikqris/sandbox`.

Nilai credential tidak boleh dimasukkan ke Git, fixture, dokumentasi, log, payload audit, atau UI admin. Konfigurasi memakai nama environment khusus KlikQRIS seperti `KLIKQRIS_SANDBOX_API_KEY`, `KLIKQRIS_SANDBOX_MERCHANT_ID`, dan pengaturan timeout. Fase sandbox tidak menyediakan konfigurasi endpoint atau credential produksi.

### Gerbang aktivasi

KlikQRIS hanya dapat dipilih ketika seluruh kondisi berikut benar:

1. Aplikasi berjalan di lingkungan non-production yang diizinkan.
2. Flag sandbox KlikQRIS aktif.
3. Readiness check menemukan base URL sandbox, API key sandbox, merchant ID, callback route, queue, dan scheduler yang diperlukan.
4. Payment provider testing secara eksplisit dipilih sebagai `klikqris`.

Jika salah satu kondisi gagal, aplikasi harus gagal tertutup dan tidak diam-diam berpindah ke KlikQRIS produksi. Produksi tetap memakai konfigurasi yang sudah ada sampai ada desain dan persetujuan cutover terpisah.

## Arsitektur

Modul `App\Services\KlikQris` berdiri sendiri dan mengikuti batas tanggung jawab berikut:

- **KlikQrisProviderAdapter** mendefinisikan operasi create transaction dan transaction status.
- **KlikQrisClient** menangani HTTP, header autentikasi, timeout, retry terbatas, dan validasi bentuk response.
- **KlikQrisTransaction** menormalisasi field create/status menjadi nilai domain yang terketik.
- **KlikQrisNativeQrisService** memastikan create bersifat idempotent, menyimpan hasil yang valid, dan memulihkan outcome yang tidak diketahui.
- **KlikQrisCallbackProcessor** menerima callback sebagai sinyal, melakukan status check otoritatif, dan menerapkan pembayaran melalui `OrderLifecycle`.
- **KlikQrisPaymentEventLogger** menyimpan event tersanitasi untuk audit tanpa credential, signature, atau base64 QR.
- **ReconcileKlikQrisPayments** memeriksa payment pending atau outcome-unknown setiap satu menit.
- **KlikQrisProviderReadiness** menolak penggunaan provider jika konfigurasi sandbox tidak lengkap atau lingkungan tidak aman.

Integrasi memakai field provider generik pada `Payment` sejauh semantiknya sesuai. Perubahan skema tambahan harus bersifat aditif. Field atau helper Pakasir tidak diubah untuk dipakai KlikQRIS jika hal itu mengaburkan makna atau berisiko mengubah perilaku Pakasir.

## Model data

Untuk payment KlikQRIS:

- `provider`: `klikqris`.
- `merchant_ref`: `order_id` unik yang dikirim ke KlikQRIS.
- `amount`: nominal dasar order dalam rupiah.
- `provider_total_payment`: nominal final yang wajib dibayar setelah `amount_uniq` atau komponen provider diterapkan.
- `provider_fee`: selisih tervalidasi antara nominal final dan nominal dasar bila non-negatif.
- `provider_payment_method`: `qris`.
- `provider_created_at` dan `provider_expires_at`: waktu dari provider yang sudah dinormalisasi ke zona waktu aplikasi.
- `provider_payload`: metadata tersanitasi yang diperlukan untuk audit, seperti status, nama toko, URL QRIS, dan representasi asli nominal untuk display. API key, merchant credential, signature, dan base64 QR tidak boleh masuk ke field ini.
- Signature transaksi KlikQRIS tidak dipersistenkan. Signature provider hanya berada di memori selama pemrosesan response; recovery status otoritatif memakai endpoint status provider dan signature tidak pernah dianggap sebagai bukti pembayaran.
- State create mengikuti pola `creating`, `created`, `outcome_unknown`, dan keadaan gagal yang dapat dipulihkan.

KlikQRIS mengembalikan nilai uang sebagai campuran integer dan string desimal. Parser harus menormalisasi tanpa floating point. Karena order internal memakai rupiah bulat, respons hanya diterima jika nilai desimal mewakili rupiah bulat secara tepat, misalnya `1016.00`. Nilai pecahan non-nol ditolak secara fail-closed sampai model uang mendukungnya secara eksplisit. String asli `total_amount` yang tervalidasi dipertahankan untuk menampilkan seluruh digit desimal secara persis.

## Aliran transaksi

### Membuat QRIS

1. Pelanggan menyelesaikan checkout melalui LhieStore Testing Bot atau Web Storefront lokal.
2. Sistem membuat payment `klikqris` dengan `merchant_ref` unik dan status pending.
3. `KlikQrisNativeQrisService` mengambil lock per payment dan menandai create sebagai `creating` sebelum request keluar.
4. Backend memanggil `POST https://klikqris.com/api/sandbox/qris/create` satu kali dengan `order_id`, `id_merchant`, `amount`, dan keterangan.
5. Response harus memiliki status sukses, `order_id` yang cocok, nominal dasar yang cocok, `total_amount` valid, status `PENDING`, QRIS, dan `expired_at` yang dapat diparse.
6. Sistem menyimpan metadata transaksi, nominal final, waktu kedaluwarsa, serta referensi QRIS dalam transaksi database.
7. Bot mengirim gambar QRIS, nominal final, waktu kedaluwarsa, dan tombol cek status.

Jika `qris_url` valid dan dapat diunduh, backend menggunakannya untuk mengirim gambar. Jika URL gagal tetapi response create menyediakan `qris_image`, backend mendekode base64 secara sementara sebagai fallback. Base64 tidak dipersistenkan dan file sementara dihapus setelah pengiriman.

### Instruksi Telegram

Instruksi pembayaran menggunakan bahasa Inggris yang singkat dan profesional. Nominal final harus ditampilkan persis, termasuk digit desimal yang diberikan provider. Contoh:

```text
⚠️ Pay exactly IDR 1,016.00. Do not round or change any digit.
This QRIS expires in 15 minutes.
```

Pesan menyediakan aksi:

- Cek status pembayaran.
- Buka fallback invoice jika gambar Telegram gagal atau pelanggan memilihnya.
- Buat checkout/QRIS baru setelah transaksi lama kedaluwarsa.

Pelanggan tidak diarahkan otomatis ke website atau Popup Snap Payment.

### Konfirmasi pembayaran

1. KlikQRIS mengirim webhook sandbox ketika status berubah.
2. Endpoint mencatat event tersanitasi dan mencari payment berdasarkan provider `klikqris` serta `order_id`.
3. Callback tidak langsung mengubah status order karena dokumentasi tidak menjelaskan algoritma kriptografis untuk menghitung ulang signature webhook.
4. Backend memanggil `GET /api/sandbox/qris/status/{order_id}` memakai credential server.
5. Payment hanya dianggap paid jika response otoritatif cocok pada provider, `order_id`, nominal dasar, nominal final, dan status provider `SUCCESS`.
6. `OrderLifecycle` menerapkan transisi paid di bawah lock database.
7. Receipt, inventory fulfillment, dan efek Telegram dikirim tepat satu kali melalui mekanisme idempotensi yang sudah ada.
8. Callback dengan JSON dan skema yang dapat diterima, termasuk callback duplikat atau order yang tidak dikenal, membalas `200 OK` setelah pencatatan aman agar provider tidak mengulang tanpa batas. Callback malformed dapat ditolak dengan `400`; tidak satu pun callback mismatch mengubah state atau membocorkan detail payment.

Status provider dinormalisasi sebagai berikut:

- `PENDING` menjadi payment pending.
- `SUCCESS` dari status API menjadi payment paid.
- `PAID` dari webhook hanya menjadi sinyal untuk menjalankan verifikasi status.
- `EXPIRED` menjadi payment expired jika status otoritatif dan deadline cocok.
- Status yang tidak dikenal tidak mengubah payment dan dicatat untuk observasi.

### Rekonsiliasi

Scheduler menjalankan rekonsiliasi setiap satu menit terhadap payment KlikQRIS yang masih pending, expired lokal tetapi mungkin terlambat terkonfirmasi, atau memiliki state `outcome_unknown`. Rekonsiliasi memakai endpoint status yang sama dan aturan matching yang sama dengan callback. Ini memastikan pembayaran tetap ditemukan ketika webhook terlambat atau gagal.

Retry menggunakan backoff terbatas hanya untuk connection error, timeout, HTTP 408, 429, dan 5xx. Error autentikasi atau response mismatch tidak diretry membabi buta.

## Kedaluwarsa dan QRIS baru

Satu QRIS berlaku 15 menit berdasarkan expiry provider. Expiry lokal tidak boleh melampaui expiry provider.

Ketika QRIS kedaluwarsa:

- Payment lama berubah menjadi expired dan tidak diaktifkan kembali.
- QRIS, signature, dan `order_id` lama tidak digunakan ulang.
- Tombol `Buat QRIS Baru` memulai checkout baru dengan referensi baru.
- Stok dan harga diperiksa ulang. Jika order lama sudah kedaluwarsa dan reservasi dilepas, sistem tidak memperpanjang reservasi tersebut secara diam-diam.
- Payment baru hanya dibuat jika produk masih tersedia dan harga masih valid.

Perilaku ini mencegah reservasi stok tanpa batas dan memisahkan audit setiap upaya pembayaran.

## Outcome create yang tidak diketahui

Jika request create timeout atau koneksi terputus setelah request mungkin diterima provider:

1. Payment ditandai `outcome_unknown`.
2. Sistem tidak mengirim request create kedua dengan referensi berbeda.
3. Sistem memanggil status endpoint untuk `order_id` pertama.
4. Jika transaksi ditemukan dan cocok, hasilnya dipersistenkan serta QRIS dapat dipulihkan.
5. Jika provider belum dapat memastikan hasil, pelanggan diberi pesan untuk membuka invoice atau mengecek lagi. Scheduler mencoba status check kemudian.
6. Pembuatan transaksi baru hanya diizinkan setelah transaksi pertama terbukti tidak ada atau sudah kedaluwarsa sesuai aturan yang aman.

## Web Storefront lokal dan observasi

Web Storefront lokal mendukung invoice KlikQRIS agar alur backend yang sama dapat diuji tanpa Telegram dan menjadi fallback sukarela. Halaman menampilkan:

- QRIS.
- Nominal final persis.
- Countdown expiry.
- Status pembayaran.
- Tombol refresh yang rate-limited dan single-flight.

Tampilan admin/testing dapat menunjukkan provider, merchant reference, nominal dasar, nominal final, state create, status provider, expiry, waktu callback terakhir, dan hasil rekonsiliasi. Credential, signature, QRIS base64, payload mentah sensitif, dan detail internal exception tidak ditampilkan.

## Penanganan kegagalan dan keamanan

- Semua request provider memiliki connect timeout dan total timeout.
- Create tidak diretry otomatis tanpa recovery status karena dapat menghasilkan transaksi ganda.
- Status check boleh diretry secara terbatas untuk gangguan sementara.
- Webhook dibatasi rate limit, ukuran payload, content type, dan validasi skema.
- Webhook dianggap tidak tepercaya sampai status API mengonfirmasi transaksi.
- Payment lookup selalu membatasi `provider = klikqris` agar `order_id` provider lain tidak dapat cocok.
- Perbandingan nominal memakai representasi integer/decimal terkanonisasi, bukan floating point.
- Event callback dan response provider disanitasi sebelum log atau persistensi.
- Proses callback dan rekonsiliasi aman terhadap duplikasi dan race condition.
- Error ke pelanggan tidak menyertakan credential, signature, response mentah, atau detail jaringan.
- Signature diperlakukan sebagai opaque transaction token, bukan bukti kriptografis, sampai KlikQRIS mendokumentasikan algoritma validasinya.

## Strategi pengujian

Implementasi dilakukan test-first. Pengujian otomatis memakai fake HTTP provider dan fake Telegram outbound, sedangkan validasi akhir menggunakan KlikQRIS sandbox nyata pada LhieStore Testing Bot.

### Unit dan feature tests

Pengujian wajib mencakup:

1. Readiness menolak lingkungan production, credential kosong, base URL non-sandbox, atau konfigurasi tidak lengkap.
2. Create mengirim header dan payload yang tepat tanpa membocorkan credential ke log.
3. Response create dinormalisasi dan nominal desimal diparse tanpa floating point.
4. `order_id`, nominal dasar, nominal final, status, atau expiry yang mismatch ditolak.
5. QRIS URL dipakai sebagai jalur utama dan base64 sebagai fallback sementara.
6. Create konkuren menghasilkan tepat satu request provider.
7. Timeout create menghasilkan `outcome_unknown` dan status recovery, bukan create kedua.
8. Webhook `PAID` tidak langsung menandai paid sebelum status API `SUCCESS`.
9. Webhook palsu, order asing, nominal mismatch, dan status tidak dikenal tidak mengubah order.
10. Callback duplikat tidak menggandakan receipt, inventory fulfillment, sales proof, atau efek outbound lain.
11. Rekonsiliasi menemukan transaksi paid ketika webhook dilewati.
12. Rekonsiliasi aman untuk payment yang sudah paid atau expired.
13. Payment dan order kedaluwarsa pada deadline provider.
14. Tombol QRIS baru tidak menggunakan kembali payment, `order_id`, atau reservasi yang kedaluwarsa.
15. Instruksi Telegram menampilkan nominal final secara persis dan tidak berisi hosted checkout atau Popup Snap.
16. Web Storefront lokal menampilkan invoice dan status tanpa data sensitif.
17. Jalur Pakasir yang ada tetap lulus tanpa perubahan hasil.

### Validasi sandbox nyata

Sandbox dianggap berhasil hanya setelah seluruh skenario berikut dibuktikan dan dicatat:

1. QRIS berhasil dibuat dari checkout LhieStore Testing Bot.
2. QRIS tampil di chat dan dapat dibaca.
3. Nominal yang ditampilkan sama persis dengan `total_amount` create response.
4. Simulasi pembayaran melalui dashboard mengirim webhook.
5. Status API berubah menjadi `SUCCESS` dan order menjadi paid tepat satu kali.
6. Receipt Telegram terkirim tepat satu kali.
7. Webhook yang dikirim ulang tetap idempotent.
8. Pembayaran ditemukan oleh rekonsiliasi ketika webhook sengaja dilewati.
9. QRIS menjadi expired setelah 15 menit dan checkout baru menghasilkan QRIS serta referensi baru.
10. Gangguan create/status yang disimulasikan tidak menghasilkan transaksi ganda.
11. Callback palsu dan nominal mismatch tidak dapat menandai order paid.
12. Invoice Web Storefront lokal dan observasi admin menampilkan state yang benar tanpa rahasia.

## Gerbang produksi

Kelulusan sandbox hanya menghasilkan laporan kesiapan. Langkah berikut tidak boleh dilakukan sebagai bagian dari fase ini:

- Menambahkan atau mengaktifkan credential KlikQRIS produksi.
- Mengarahkan callback KlikQRIS produksi.
- Mengubah default provider produksi.
- Menonaktifkan atau menghapus Pakasir.
- Memigrasikan transaksi Pakasir yang masih pending.
- Push atau deployment produksi.

Setelah seluruh bukti sandbox tersedia, pemilik meninjau laporan dan memberikan keputusan eksplisit apakah desain produksi boleh dimulai. Cutover produksi akan memiliki spesifikasi terpisah yang mencakup coexistence provider, transaksi lama, rollback, monitoring, dan waktu penghentian Pakasir.

## Di luar ruang lingkup

- Popup Snap Payment.
- Hosted checkout atau redirect wajib ke website.
- Perubahan kontrak atau perilaku Pakasir.
- Aktivasi KlikQRIS produksi.
- Penghapusan kode, route, scheduler, callback, atau credential Pakasir.
- Migrasi transaksi provider lama.
- Perubahan desain pembayaran manual atau Binance Pay yang tidak diperlukan.
