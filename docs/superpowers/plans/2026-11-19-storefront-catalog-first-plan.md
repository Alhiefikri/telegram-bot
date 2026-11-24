# Master Plan: Storefront langsung katalog dan checkout ringkas

Status: disetujui untuk perencanaan dan pemecahan tiket. Dokumen ini tidak mengotorisasi deploy, CI, atau perubahan produksi.

Tracker: [Master Spec #731](https://github.com/Alhiefikri/telegram-bot/issues/731). GitHub menyimpan dependensi native; tiket berlabel `ready-for-agent` dapat dikerjakan setelah blocker tertutup.

## Tujuan dan urutan

1. **Pulihkan keterbacaan checkout.** Rapikan review QRIS direct/cart: produk, kuantitas, `Subtotal produk`, metode dan aksi membuat invoice. Tampilkan hanya nominal rail terpilih; jangan menebak nominal QRIS final. Di invoice, tampilkan QR, `Total bayar`, tenggat dan tindakan berikutnya menurut status. Perbaiki kontras bersamaan dengan kedua perubahan, bukan nanti sebagai polish.
2. **Jadikan `/` halaman toko.** Ambil pola filter Family di tempat dari [katalog NyStore](https://nystoreofficial.com/#products), bukan hero, carousel, layout kartu besar, kewajiban login, atau wallet. Produk bisa ditemukan langsung dari home; pencarian dan Family tidak menumpuk semua Plan di layar.
3. **Perluas cakupan bahasa.** Ubah tiga locale yang sudah ada (Indonesia, Melayu, Inggris) dari pilihan global menjadi default toko *plus* pilihan pengunjung. Lengkapi seluruh funnel katalog → checkout → invoice/status → akses pesanan/bantuan sebelum menambah locale keempat. Terjemahan tidak boleh membuat klaim pembayaran atau garansi yang berbeda.
4. **Integrasikan dan uji pembelian tanpa Telegram.** Buktikan pembelian guest, invoice yang dapat dibuka kembali, verifikasi otomatis, akses produk yang berhak, dan jalan ke bantuan di luar Telegram. QR Manual tetap mengikuti proses operator sekarang; kemandirian approval manual adalah pekerjaan pembayaran terpisah, bukan efek samping redesign.

## Paket kerja

| ID | Deliverable | Mulai setelah |
|---|---|---|
| S1 ([#732](https://github.com/Alhiefikri/telegram-bot/issues/732)) | Review checkout direct/cart ringkas, termasuk kontras dan copy | Mandiri |
| S2 ([#733](https://github.com/Alhiefikri/telegram-bot/issues/733)) | Invoice QRIS/QR Manual ringkas dan bantuan dari invoice | Mandiri |
| S3 ([#734](https://github.com/Alhiefikri/telegram-bot/issues/734)) | Home katalog-first, sidebar Family, navigasi mobile, dan URL filter | Mandiri |
| S4 ([#735](https://github.com/Alhiefikri/telegram-bot/issues/735)) | Preferensi bahasa request-scoped, switcher, isolasi cache | S3; gunakan shell katalog baru |
| S5 ([#736](https://github.com/Alhiefikri/telegram-bot/issues/736)) | Terjemahan UI, error, status, dan bantuan di funnel | S1–S4 |
| S6 ([#737](https://github.com/Alhiefikri/telegram-bot/issues/737)) | Integrasi lintas alur dan bukti browser untuk cutover | S5 |

S1 dan S2 menjaga style di permukaan masing-masing; S3 memiliki shell katalog. Hindari dua tiket mengubah token tema global atau file locale yang sama secara bersamaan. Bila token bersama perlu berubah, S6 mengintegrasikan satu perubahan dengan bukti semua konsumen, sebelum cutover.

## Keputusan bisnis yang dipertahankan

- Tidak ada channel pembayaran baru, perubahan pilihan provider, harga, stok, verifikasi settlement, Customer, maupun Recovery Key. QRIS otomatis tidak beralih diam-diam ke manual. QR Manual tidak tampak otomatis dan tidak menjanjikan upload atau approval web yang belum ada.
- Login Telegram OIDC, dashboard approval QR Manual, penambahan rail Stars, pemindahan data, dan perubahan Store Bot tidak termasuk. Prinsipnya: kurangi ketergantungan pada Telegram di alur web yang sudah ada, tanpa memecahkan ulang checkout.
- Pertahankan URL Family dan detail produk, guest checkout, keranjang, price tier, waitlist, invoice bertanda tangan dan bantuan yang dikonfigurasi. Tidak ada ENV baru untuk konten atau tampilan.

## Bukti selesai dan pelepasan

Ukur jalur direct purchase baseline pada 1366×768 dan 390×844. Bandingkan jarak scroll hingga produk pertama (home), tombol membuat invoice (review), QR/nominal (invoice). Uji tambahan di 320×568 dan pembesaran teks 200% tanpa menyembunyikan aksi, error, syarat wajib, atau informasi harga. Kontras AA dari warna terhitung browser di light/dark; bandingkan area kritis yang sama sebelum dan sesudah. Jangan buat transaksi produksi; gunakan aplikasi dan data disposable. Bukti integrasi, risiko, serta instruksi rollback kode/aset dicatat di S6. Operator menyetujui merge/deploy secara terpisah.

Kontrak implementasi: [Master Spec](../specs/2026-11-19-storefront-catalog-first-spec.md). Masukan awal: [catatan diskusi](../specs/2026-11-19-storefront-redesign-discussion.md).
