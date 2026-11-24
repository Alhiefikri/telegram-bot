# Catatan diskusi redesign storefront

Status: masukan pemilik untuk dibahas, belum disetujui untuk implementasi.

Hasil pembahasan dan pemecahan tiket: [Master Plan](../plans/2026-11-19-storefront-catalog-first-plan.md), [Master Spec](2026-11-19-storefront-catalog-first-spec.md), dan [tracker #731](https://github.com/Alhiefikri/telegram-bot/issues/731).

## Checkout QR

- [Contoh checkout produk](https://lhiestore.web.id/shop/checkout/products/28?from=https%3A%2F%2Flhiestore.web.id%2Fpilihan%2Fchatgpt&quantity=1). Target pemilik: informasi utama dan aksi bayar terlihat dalam satu layar, dengan lebih sedikit scroll dan tanpa konten yang tidak dibutuhkan.
- Pendekkan instruksi pembayaran. Teks yang ditandai: “Bayar dengan QRIS pada invoice, bayar tepat sesuai total produk, lalu hubungi admin dengan bukti pembayaran dan nomor invoice ini.” Bedakan instruksi QR Manual dari QRIS otomatis agar pelanggan tidak salah mengikuti langkah.
- Gunakan ikon yang relevan dengan fungsi atau status, bukan dekorasi.

## Home langsung katalog

- Ubah home menjadi halaman toko, dengan katalog langsung terlihat.
- Gunakan sidebar yang mengelompokkan Product Family. Pemilik ingin navigasi antar Family yang ringkas dan tidak mengharuskan scroll panjang.
- Situs pembanding yang kemudian diberikan: [NyStore](https://nystoreofficial.com/#products). Referensinya adalah filter Family yang mengubah daftar di tempat, bukan hero, wallet, atau login wajib.

## Tema gelap dan bahasa

- Perbaiki teks/instruksi pembayaran yang menyatu dengan latar di mode gelap. Jangan mengorbankan keterbacaan atau ukuran kontrol demi target satu layar.
- Perluas dukungan bahasa. Daftar bahasa tambahan belum ditentukan. Cakupan pembahasan: katalog, checkout, invoice/status, dan bantuan, bukan hanya label menu.

## Konteks migrasi

Prioritas diskusi: alur beli, bayar, akses pesanan, dan bantuan di website. Redesign ini tidak menyetujui perubahan aturan pembayaran, approval admin, login, ataupun perilaku bot.
