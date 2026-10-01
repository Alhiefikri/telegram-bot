# Desain Statistik Pertumbuhan Telegram

**Tanggal:** 2026-07-27
**Status:** Diimplementasikan; tampilan empat periode diperbarui 2026-10-02
**Ruang lingkup:** Tombol statistik admin Telegram dan respons statistik interaktif

## Tujuan

Mengubah tombol `📊 Statistik Harian` menjadi `📊 Statistik` dan membuat hasilnya membantu admin menilai apakah performa store meningkat, bukan hanya melihat angka satu hari tanpa pembanding.

## Keputusan desain

Satu kali menekan tombol akan mengirim satu ringkasan yang memuat empat periode:

1. **Hari Ini**, dibandingkan durasi yang sama kemarin.
2. **Minggu Ini**, dibandingkan durasi yang sama pada minggu sebelumnya. Minggu dimulai Senin.
3. **Bulan Ini**, dibandingkan tanggal dan waktu setara pada bulan sebelumnya. Jika bulan sebelumnya lebih pendek, ujung periode pembanding dibatasi pada akhir bulan tersebut.
4. **Bulan Kemarin**, satu bulan kalender penuh dibandingkan satu bulan kalender penuh sebelumnya. Ujung rentang eksklusif di awal bulan berikutnya, sehingga transaksi tepat pada awal bulan tidak dihitung dua kali.

Seluruh rentang menggunakan zona waktu aplikasi, yaitu `Asia/Jakarta`, dan dihitung dari satu timestamp akhir laporan yang ditangkap sekali. Ini mencegah perbedaan batas waktu antarbagiannya.

## Bentuk respons

Respons tetap berupa satu pesan agar cepat dipindai dan tidak memenuhi chat. Judul periode tebal, keterangan pembanding miring, dan metrik dikelompokkan dalam blockquote Telegram. Bulan ditulis dalam bahasa Indonesia; bagian minggu mencantumkan tanggal awal dan tanggal laporan. Setiap bagian menampilkan:

- Order baru.
- Order dibayar.
- Produk terjual.
- Nilai penjualan dalam rupiah.
- Pembayaran bersih yang diterima, dikelompokkan menurut mata uang.
- Perubahan terhadap periode pembanding dengan indikator `📈`, `📉`, atau `➖` dan persentase.

Contoh dari smoke lokal dengan data fixture (bukan angka production):

```text
📊 Pertumbuhan Store
Diperbarui 02 Okt 2026 · 00:36 WIB

☀️ Hari Ini · 02 Okt
vs kemarin · hingga 00:36 WIB
Order Baru: 1 (📈 Baru)
Order Dibayar: 1 (📈 Baru)
Produk Terjual: 1 (📈 Baru)
Nilai Penjualan: Rp10.000 (📈 Baru)
Pembayaran Diterima:
• Rupiah: Rp10.000 (📈 Baru)

🗓 Minggu Ini · 28 Sep–02 Okt
vs minggu lalu · durasi setara
Order Baru: 3 (📈 Baru)
Order Dibayar: 3 (📈 Baru)
Produk Terjual: 4 (📈 Baru)
Nilai Penjualan: Rp70.000 (📈 Baru)
Pembayaran Diterima:
• Rupiah: Rp19.000 (📈 Baru)
• USDT: 2 USDT (📈 Baru)

📅 Bulan Ini · Oktober 2026
vs September 2026 · durasi setara
Order Baru: 2 (📈 +100%)
Order Dibayar: 2 (📈 +100%)
Produk Terjual: 2 (📈 +100%)
Nilai Penjualan: Rp20.000 (📉 -33,3%)
Pembayaran Diterima:
• Rupiah: Rp19.000 (📉 -35,6%)

🗓 Bulan Kemarin · September 2026
vs Agustus 2026 · bulan penuh
Order Baru: 2 (➖ 0%)
Order Dibayar: 2 (➖ 0%)
Produk Terjual: 3 (📈 +50%)
Nilai Penjualan: Rp80.000 (📈 +77,8%)
Pembayaran Diterima:
• Rupiah: Rp29.500 (📈 +18%)
• USDT: 2 USDT (📈 +100%)
```

Perubahan pembayaran dihitung secara terpisah untuk setiap mata uang. Nilai IDR dan USDT tidak pernah dijumlahkan atau dibandingkan silang.

### Aturan baseline nol

- Nilai sekarang dan pembanding sama-sama nol: `➖ 0%`.
- Nilai sekarang positif sementara pembanding nol: `📈 Baru`.
- Nilai sekarang lebih rendah: `📉` dengan persentase negatif.
- Nilai sama dan positif: `➖ 0%`.

Persentase ditampilkan ringkas, tanpa desimal jika bulat dan maksimal satu angka desimal bila diperlukan.

## Arsitektur dan aliran data

### Menu dan routing

- Keyboard admin menampilkan `📊 Statistik`.
- Pesan teks baru `📊 Statistik` membuka ringkasan pertumbuhan.
- Pesan lama `📊 Statistik Harian` dan callback lama `statistik_harian` tetap diterima untuk kompatibilitas dengan keyboard atau pesan Telegram yang masih tersimpan.
- Seluruh jalur tetap melalui pemeriksaan admin dan private-chat yang sudah ada.

### Perhitungan periode

`TelegramStoreStatsReport::growth()` menangkap satu `reportEndAt`, kemudian membangun pasangan rentang saat ini dan sebelumnya untuk hari, minggu, bulan aktif, dan bulan kalender yang sudah selesai. Rentang aktif memakai awal inklusif sampai timestamp laporan yang sama, dengan ujung pembanding pada durasi setara. Bulan Kemarin memakai dua interval kalender dengan awal inklusif dan akhir eksklusif: September dibanding Agustus pada laporan Oktober, termasuk seluruh transaksi di akhir bulan tetapi tidak termasuk transaksi tepat pada 1 Oktober.

Perhitungan statistik dasar tetap memakai sumber kebenaran yang sudah ada:

- Order baru berdasarkan `orders.created_at`.
- Order dibayar berdasarkan pembayaran lunas kanonis dari `PaymentReceiptReport`.
- Satu order hanya dihitung sekali walaupun memiliki baris pembayaran duplikat.
- Produk terjual dan nilai penjualan hanya berasal dari order dengan pembayaran kanonis pada rentang tersebut.
- Pembayaran bersih tetap dikelompokkan per mata uang setelah biaya provider.

Rekap otomatis harian `📊 Rekap Kemarin` tidak berubah.

### Pemisahan tanggung jawab

- Pembentuk rentang bertanggung jawab hanya menentukan batas periode.
- Kalkulator statistik bertanggung jawab menghasilkan metrik satu rentang.
- Kalkulator perubahan bertanggung jawab membandingkan dua nilai dan menentukan indikator.
- Formatter bertanggung jawab menyusun satu pesan Telegram yang mudah dipindai.

Tidak ada cache atau state baru. Setiap penekanan tombol menghasilkan snapshot read-only sehingga aman terhadap callback duplikat dan tidak menimbulkan race condition penulisan.

## Keamanan dan performa

- Hanya admin yang dapat mengakses statistik.
- Statistik hanya dikirim di private chat agar data bisnis tidak bocor ke grup.
- Callback yang dipalsukan oleh non-admin tetap ditolak.
- Nilai mata uang tidak digabungkan lintas mata uang.
- Query tetap dibatasi rentang waktu dan menggunakan indeks pembayaran yang sudah ditambahkan.
- Implementasi tidak melakukan query per order. Agregasi item dan order dilakukan per rentang.
- Pesan harus tetap di bawah batas Telegram. Formatter hanya menampilkan mata uang yang muncul pada periode kini atau pembanding.

## Strategi pengujian

Implementasi dilakukan test-first. Regression test harus terlebih dahulu gagal karena source lama masih hanya mendukung statistik harian.

Pengujian wajib mencakup:

1. Keyboard menampilkan `📊 Statistik` dan tidak menampilkan label lama.
2. Statistik interaktif menyertakan metrik empat periode.
3. Label dan callback lama masih berfungsi.
4. Non-admin dan group chat tidak dapat memperoleh data statistik.
5. Record hari ini, awal minggu, awal bulan, dan bulan sebelumnya masuk hanya ke rentang yang benar.
6. Pembanding menggunakan durasi setara, minggu dimulai Senin, dan zona waktu Asia/Jakarta.
   Batas Bulan Kemarin mencakup pergantian tahun, Februari kabisat, dan pengecualian transaksi tepat pada awal bulan berikutnya.
7. Baseline nol menghasilkan `Baru` atau `0%`, bukan pembagian nol.
8. Pembayaran duplikat tetap memakai pembayaran kanonis.
9. IDR, USDT, dan mata uang lain tetap terpisah.
10. Rekap otomatis kemarin tetap memiliki perilaku lama.
11. Seluruh targeted test, full test suite, formatter, dependency audit, dan PostgreSQL concurrency gate lulus sebelum rilis.

## Di luar ruang lingkup

- Grafik visual atau dashboard baru.
- Penyimpanan snapshot statistik.
- Pemilihan rentang tanggal khusus.
- Perubahan metrik pada Mini App Dashboard.
- Perubahan jadwal rekap harian otomatis.
