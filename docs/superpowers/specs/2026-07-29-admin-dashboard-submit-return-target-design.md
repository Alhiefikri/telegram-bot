# Desain Return Target Setelah Submit Dashboard Admin

**Tanggal:** 2026-07-29
**Status:** Disetujui untuk implementation planning
**Ruang lingkup:** Seluruh form mutasi pada dashboard Blade `/admin`, kecuali login dan halaman detail order terpisah
**Batas operasional:** Dokumen ini tidak mengizinkan deployment production

## Tujuan

Setelah admin menyimpan perubahan, dashboard harus kembali ke item atau bagian yang baru diproses, bukan ke bagian paling atas halaman. Hasil perubahan harus mudah ditemukan, terlihat di viewport, dan disertai umpan balik yang dekat dengan konteks kerja admin.

Solusi harus mempertahankan arsitektur Blade, Alpine.js, dan pola POST/redirect/GET yang sudah digunakan. Perubahan ini tidak mengubah seluruh dashboard menjadi aplikasi AJAX.

## Masalah Saat Ini

- Semua mutasi dashboard melakukan full-page redirect.
- Redirect umumnya hanya membawa query `tab`, misalnya `/admin?tab=produk`.
- Browser memuat halaman dari posisi paling atas setelah redirect.
- Admin harus mencari kembali kartu produk atau form yang baru digunakan.
- Masalah paling terasa saat mengedit produk atau stok yang berada jauh di bawah daftar.
- Flash status berada di bagian atas shell, sehingga dapat tidak terlihat ketika posisi kerja dipulihkan ke item.

## Keputusan Desain

Gunakan return target berbasis elemen sebagai perilaku utama:

1. Setiap form dashboard menyatakan target aman yang harus ditampilkan setelah submit.
2. Server menentukan tab dan target akhir yang diizinkan, lalu redirect ke URL dashboard dengan fragment elemen.
3. Setelah halaman dimuat, browser menampilkan target tersebut dan JavaScript melakukan penyempurnaan fokus serta highlight.
4. Flash sukses ditampilkan dekat target terkait atau dipindahkan secara aksesibel ke viewport target.
5. Jika target tidak tersedia, dashboard kembali ke bagian utama tab terkait.

Contoh konseptual:

```text
POST /admin/products/42/stock
-> 302 /admin?tab=produk#product-42
```

Koordinat `scrollY` tidak menjadi sumber kebenaran karena tinggi dan urutan konten dapat berubah setelah mutasi. Penyimpanan koordinat boleh dipakai hanya sebagai fallback tambahan jika kelak diperlukan, bukan bagian dari scope awal.

## Kontrak Return Target

### Target stabil

Elemen tujuan memakai ID stabil yang berasal dari nama target dan identifier server-side, bukan input bebas pengguna:

```text
admin-section-stats
admin-section-products
admin-section-sales
admin-section-broadcast
admin-section-store-channel
admin-payment-settings
admin-support-settings
admin-category-create
product-{product_id}
sales-proof-{order_id}
```

ID target harus unik pada halaman dan memiliki `scroll-margin` yang cukup untuk navigasi atau header yang tetap terlihat.

### Kepemilikan target

- Server tetap menjadi sumber kebenaran untuk target redirect.
- Client boleh mengirim konteks terbatas, tetapi server tidak boleh memasukkan fragment arbitrer dari request ke header `Location`.
- Controller atau service redirect memilih target dari daftar target yang dikenal.
- Product target selalu dibangun dari ID model yang telah ditemukan atau dibuat server.

### Matriks perilaku

| Aksi | Tab | Target berhasil | Perilaku validasi gagal |
| --- | --- | --- | --- |
| Tambah produk | `produk` | Kartu produk baru | Buka modal tambah produk dan fokus error pertama |
| Edit produk | `produk` | Kartu produk terkait | Buka modal edit produk dan fokus error pertama |
| Tambah stok | `produk` | Kartu produk terkait | Buka modal tambah stok dan fokus error pertama |
| Edit akun stok | `produk` | Kartu produk terkait | Buka kembali modal kelola stok dan tampilkan akun error |
| Hapus akun stok | `produk` | Kartu produk terkait | Kembali ke kartu produk jika operasi ditolak |
| Batalkan order dari stok | `produk` | Kartu produk terkait | Kembali ke kartu produk jika operasi ditolak |
| Ubah visibilitas produk | `produk` | Kartu produk terkait | Kembali ke kartu produk |
| Arsipkan produk | `produk` | Bagian daftar produk | Kembali ke daftar karena kartu sudah tidak ada |
| Tambah kategori | `produk` | Form tambah kategori | Pertahankan nilai lama dan fokus error pertama |
| Ubah mode atau batas pembayaran | `stats` | Pengaturan pembayaran | Fokus error pertama pada form terkait |
| Ubah support WhatsApp | `stats` | Pengaturan support | Fokus error pertama pada form terkait |
| Kirim broadcast | `broadcast` | Form broadcast | Pertahankan input dan fokus error pertama |
| Simpan Telegram Channel | `telegram-channel` | Form pengaturan channel | Pertahankan input dan fokus error pertama |
| Test Telegram Channel | `telegram-channel` | Bagian test channel | Kembali ke bagian test channel |
| Kirim fake sales proof | `telegram-channel` | Bagian fake sales proof | Kembali ke bagian fake sales proof |
| Retry sales proof | `penjualan` | `sales-proof-{order_id}`; fallback ke daftar penjualan jika order tidak ada pada halaman aktif | Kembali ke target yang sama |

## Arsitektur

### Redirect server-side

Pusatkan pembentukan redirect dashboard pada service kecil `AdminDashboardRedirector` agar controller tidak menyusun query dan fragment secara berbeda-beda. Antarmuka konseptual:

```php
dashboardRedirect(tab: string, target: string): RedirectResponse
productRedirect(product: Product): RedirectResponse
```

Helper bertanggung jawab untuk:

- Memilih tab yang valid.
- Menghasilkan target dari identifier server-side.
- Membawa flash `status` atau error bag yang sudah ada.
- Menghasilkan URL dashboard dengan query tab dan fragment target.
- Menyediakan fallback target tingkat tab.

`AdminProductDashboardFormProtocol` yang sudah mengelola redirect produk memakai `AdminDashboardRedirector` sebagai dependency. Controller dashboard lain memakai service yang sama agar kontraknya konsisten.

### Markup target

- Tambahkan ID stabil pada section dashboard, kartu produk, dan blok pengaturan yang menjadi target.
- Tambahkan atribut penanda seperti `data-admin-return-target` untuk perilaku enhancement JavaScript.
- Target dapat menerima state visual sementara melalui class atau atribut, tanpa mengubah data domain.
- Elemen target yang bukan kontrol interaktif dapat memakai `tabindex="-1"` agar dapat menerima fokus secara programatis tanpa masuk urutan tab normal.

### Enhancement JavaScript

Saat halaman selesai diinisialisasi:

1. Baca fragment URL.
2. Pastikan elemen cocok dengan target dashboard yang tersedia pada DOM.
3. Tampilkan target dengan `scrollIntoView({ block: 'center' })` atau posisi terdekat yang tidak tertutup navigasi.
4. Fokuskan target atau kontrol status terkait tanpa smooth scroll yang panjang.
5. Berikan highlight singkat dan hormati `prefers-reduced-motion`.
6. Pastikan flash sukses diumumkan melalui region `aria-live`.

Native fragment navigation tetap menjadi fallback bila JavaScript tidak tersedia. Enhancement tidak boleh menyebabkan lompatan scroll kedua yang mengganggu.

### Flash status kontekstual

Flash global tetap dipertahankan sebagai fallback dan sumber pesan server. Pada load dengan return target:

- Pesan harus terlihat di sekitar target atau melalui notice kontekstual yang terhubung dengan target.
- Pesan sukses diumumkan satu kali kepada screen reader.
- Pesan tidak boleh menutupi tombol atau input pada viewport kecil.
- Tidak perlu membuat sistem toast global baru.

## Aliran Data

### Submit berhasil

1. Admin membuka form atau modal dari suatu item.
2. Form melakukan submit normal.
3. Server memvalidasi dan menjalankan mutasi.
4. Server menentukan tab dan target dari hasil mutasi.
5. Server mengembalikan redirect dengan flash status.
6. Dashboard dirender dengan data terbaru.
7. Fragment dan enhancement membawa admin ke target.
8. Target diberi highlight singkat dan pesan sukses diumumkan.

### Validasi gagal

1. Server redirect dengan named error bag dan old input seperti saat ini.
2. Dashboard state membuka kembali modal atau form terkait.
3. Return target membawa konteks ke section atau kartu asal.
4. Setelah modal terbuka, fokus berpindah ke field pertama yang invalid.
5. Nilai input lama tetap tersedia; data rahasia mengikuti kebijakan existing dan tidak ditambahkan ke URL atau storage browser.

### Target hilang

Target dapat hilang setelah produk diarsipkan, filter berubah, atau data tidak lagi tersedia. Dalam kondisi ini:

- Gunakan target fallback milik tab.
- Jangan kembali ke tab yang salah.
- Jangan melempar error JavaScript.
- Flash status tetap dapat dibaca.

## Keamanan dan Privasi

- Fragment hanya berisi nama section atau ID model internal yang sudah tampil pada dashboard admin.
- Jangan memasukkan nama produk, isi stok, credential, old input, pesan error, atau URL eksternal ke fragment.
- Jangan menyimpan isi form atau stok rahasia di `localStorage` atau `sessionStorage`.
- Target request yang tidak dikenal harus diabaikan atau dipetakan ke fallback server-side.
- Authorization dan CSRF yang ada tidak berubah.

## Aksesibilitas dan UX

- Target akhir harus terlihat di viewport desktop dan Telegram Mini App/mobile.
- Fokus harus mengikuti konteks visual agar pengguna keyboard tidak tertinggal di awal dokumen.
- Highlight berlangsung singkat, tidak berkedip, dan tetap jelas tanpa hanya mengandalkan warna.
- `prefers-reduced-motion: reduce` menonaktifkan animasi scroll dan transisi highlight yang tidak perlu.
- Modal validasi gagal harus memiliki fokus pada field invalid pertama; jika tidak ada field yang dapat difokuskan, fokus pada heading modal.
- Tombol submit mempertahankan state `Menyimpan...` dan pencegahan double submit yang sudah ada.

## Non-goals

- Mengubah semua form menjadi AJAX atau SPA.
- Menghapus full-page refresh.
- Mempertahankan modal terbuka setelah submit berhasil.
- Menyimpan draft form di browser.
- Mengubah desain visual dashboard secara luas.
- Mengubah business rule produk, stok, pembayaran, broadcast, atau Telegram Channel.
- Mengubah perilaku login atau halaman detail order terpisah.
- Melakukan deployment production.

## Strategi Pengujian

### Feature tests

- Setiap endpoint dashboard yang berhasil redirect ke tab dan fragment yang benar.
- Tambah produk memakai ID produk yang baru dibuat sebagai target.
- Edit dan tambah stok memakai ID produk terkait.
- Arsip produk memakai fallback daftar produk, bukan ID kartu yang sudah hilang.
- Named error bag, old input, dan state pembukaan modal tetap bekerja.
- Target dari input arbitrer tidak dipantulkan ke redirect.

### Browser tests

- Dari produk jauh di bawah halaman, edit produk lalu buktikan kartu yang sama terlihat di viewport setelah refresh.
- Dari produk jauh di bawah halaman, tambah stok lalu buktikan kartu yang sama terlihat dan jumlah stok terbaru tampil.
- Tambah produk lalu buktikan kartu baru menjadi target.
- Ubah visibilitas lalu buktikan kartu terkait menjadi target.
- Arsip produk lalu buktikan bagian daftar produk menjadi target tanpa error.
- Submit kategori, pengaturan pembayaran, support, broadcast, dan Telegram Channel lalu buktikan section asal terlihat.
- Validasi produk dan stok membuka kembali modal, mempertahankan input yang diizinkan, dan memfokuskan error pertama.
- Target yang tidak ditemukan jatuh ke section tab tanpa error JavaScript.
- Perilaku diuji pada viewport desktop dan lebar Telegram Mini App.
- Mode reduced motion tidak menjalankan smooth scroll atau animasi yang tidak diperlukan.

### Regression checks

- Tab aktif tetap berasal dari server state/query yang benar.
- Back/forward browser tetap dapat digunakan.
- Tanpa JavaScript, fragment native tetap membawa pengguna mendekati target.
- Tidak ada data stok rahasia pada URL atau browser storage.
- Existing `AdminDashboardTest` dan `admin-dashboard-responsive.spec.js` tetap lulus.

## Acceptance Criteria

Desain dianggap terimplementasi dengan benar ketika:

- Tidak ada submit dashboard dalam scope yang sukses lalu meninggalkan admin di bagian paling atas tanpa konteks.
- Edit produk dan tambah stok kembali ke kartu produk terkait.
- Tambah produk kembali ke kartu yang baru dibuat.
- Submit pada tab lain kembali ke section form terkait.
- Validasi gagal membuka konteks form yang benar dan memfokuskan error pertama.
- Flash sukses terlihat atau diumumkan dekat target.
- Target yang hilang memiliki fallback tab yang aman.
- Solusi tetap memakai POST/redirect/GET dan tidak memperkenalkan AJAX dashboard secara menyeluruh.
- Desktop, mobile, keyboard, reduced motion, dan fallback tanpa JavaScript tercakup pengujian.
- Data produk sensitif dan isi stok tidak bocor ke URL atau browser storage.
- Tidak ada deployment production tanpa instruksi eksplisit pemilik.
