# Desain Statistik Pertumbuhan Telegram

**Tanggal:** 2026-07-27  
**Status:** Disetujui untuk direncanakan  
**Ruang lingkup:** Tombol statistik admin Telegram dan respons statistik interaktif

## Tujuan

Mengubah tombol `📊 Statistik Harian` menjadi `📊 Statistik` dan membuat hasilnya membantu admin menilai apakah performa store meningkat, bukan hanya melihat angka satu hari tanpa pembanding.

## Keputusan desain

Satu kali menekan tombol akan mengirim satu ringkasan yang memuat tiga periode:

1. **Hari Ini**, dibandingkan durasi yang sama kemarin.
2. **Minggu Ini**, dibandingkan durasi yang sama pada minggu sebelumnya. Minggu dimulai Senin.
3. **Bulan Ini**, dibandingkan tanggal dan waktu setara pada bulan sebelumnya. Jika bulan sebelumnya lebih pendek, ujung periode pembanding dibatasi pada akhir bulan tersebut.

Seluruh rentang menggunakan zona waktu aplikasi, yaitu `Asia/Jakarta`, dan dihitung dari satu timestamp akhir laporan yang ditangkap sekali. Ini mencegah perbedaan batas waktu antarbagiannya.

## Bentuk respons

Respons tetap berupa satu pesan agar cepat dipindai dan tidak memenuhi chat. Setiap bagian menampilkan:

- Order baru.
- Order dibayar.
- Produk terjual.
- Nilai penjualan dalam rupiah.
- Pembayaran bersih yang diterima, dikelompokkan menurut mata uang.
- Perubahan terhadap periode pembanding dengan indikator `📈`, `📉`, atau `➖` dan persentase.

Contoh struktur:

```text
📊 Pertumbuhan Store
Diperbarui 27 Jul 2026 19:30 WIB
━━━━━━━━━━━━━━
☀️ Hari Ini
Order Baru: 6  📈 +20%
Order Dibayar: 4  📈 +33,3%
Produk Terjual: 7  📈 +40%
Nilai Penjualan: Rp350.000  📈 +25%
Diterima: Rp332.500  📈 +24%

🗓 Minggu Ini
Order Dibayar: 18  📈 +12,5%
Nilai Penjualan: Rp1.450.000  📈 +8,2%

📅 Bulan Ini
Order Dibayar: 61  📉 -4,7%
Nilai Penjualan: Rp5.275.000  📈 +6,1%
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

`TelegramAdminStatsIntents` menangkap satu `reportEndAt`, kemudian membangun pasangan rentang saat ini dan sebelumnya untuk hari, minggu, dan bulan. Rentang aktif menggunakan interval awal inklusif sampai `reportEndAt` yang sama. Ujung setiap rentang pembanding digeser ke waktu setara pada hari, minggu, atau bulan sebelumnya sehingga durasi yang dibandingkan adil.

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
2. Label baru membuka ringkasan tiga periode.
3. Label dan callback lama masih berfungsi.
4. Non-admin dan group chat tidak dapat memperoleh data statistik.
5. Record hari ini, awal minggu, awal bulan, dan bulan sebelumnya masuk hanya ke rentang yang benar.
6. Pembanding menggunakan durasi setara, minggu dimulai Senin, dan zona waktu Asia/Jakarta.
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
