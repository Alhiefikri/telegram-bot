# Testing supplier lokal — CSxStore

## Lingkungan yang disiapkan

Server: `http://127.0.0.1:18082`, Admin: `/admin`, buyer: `/shop`.
Konfigurasi privat: `src/.env.supplier-local`. Database terpisah:
`src/database/supplier-local.sqlite`. Keduanya diabaikan Git.
APP_KEY tersendiri menjaga credential supplier terenkripsi; jangan regenerasi
key setelah memasukkan API key. Lingkungan ini tidak membawa token Telegram
atau credential payment dari `.env` yang sudah ada.

Seluruh migration sudah dijalankan. Akun owner lokal dibuat; login tersimpan
dalam `src/storage/app/private/supplier-local-login.txt` (permission 0600).
Password itu hanya untuk lingkungan lokal ini.

Untuk menjalankan ulang, buka terminal terpisah dari `src/`:

```bash
php artisan serve --env=supplier-local --host=127.0.0.1 --port=18082 --no-reload
php artisan queue:work supplier --env=supplier-local --queue=suppliers --timeout=120 --tries=1 --sleep=2
php artisan queue:work database --env=supplier-local --queue=default,notifications,telegram-outbound,telegram-stock --timeout=60 --tries=1 --sleep=2
APP_ENV=supplier-local php artisan schedule:work --env=supplier-local
```

Jalankan hanya baris untuk proses yang belum berjalan. Jangan memakai
`composer dev` untuk pengujian ini: command tersebut tidak memilih environment
terpisah ataupun worker supplier. Jangan `config:cache` saat kedua environment
dipakai dalam satu checkout; file cache konfigurasi Laravel dibagi.

Untuk pengujian terkendali, refresh lewat tombol sinkron sebelum checkout
dan setelah perubahan harga/saldo. Observasi supplier kedaluwarsa dalam lima
menit. Bila perlu menjalankan recovery, pilih environment yang sama:

```bash
php artisan suppliers:sync --env=supplier-local --supplier=ID_SUPPLIER --limit=1
php artisan suppliers:recover --env=supplier-local --limit=25
```

SQLite ini cukup untuk walkthrough UI dan satu order terkendali. Bukti race,
locking, dan kesiapan production memerlukan PostgreSQL/concurrency gate;
SQLite tidak membuktikan perilaku tersebut.

### Koneksi Canboso

Canboso sudah tersimpan sebagai supplier **ID 5** di database supplier-local, dengan API key terenkripsi dan kurs awal Rp18.000/USD mengikuti koneksi lokal lain. Kurs ini perlu disesuaikan dengan biaya funding aktual sebelum penjualan.

Smoke GET melalui `SupplierCatalogSync` membaca wallet USD bersaldo **0**, 203 SKU, dan 123 SKU orderable pada saat pengecekan. Belum ada mapping Product publik, pembelian supplier, atau top-up. Produk slot/upgrade tidak eligible untuk pengiriman otomatis. Angka katalog dapat berubah pada sinkron berikutnya.

Gunakan halaman Admin lokal yang sama untuk membuka katalog Canboso, mengimpor draft atau memetakan SKU ekuivalen. Top-up wallet di Canboso lalu sinkronkan saldo sebelum mengaktifkan penjualan. Koneksi lokal ini bukan penambahan supplier di VPS production.

Pada 14 Oktober 2026, key dari permintaan terbaru berbeda dari key yang tersimpan. Endpoint `/balance` memverifikasi key baru dengan HTTP 200, wallet USD, dan saldo 0. `SupplierManager` memperbarui koneksi ID 5 dengan optimistic version dan audit; pemeriksaan persisted-state memastikan key cocok dan tersimpan sebagai ciphertext. Kurs Rp18.000/USD tetap dipertahankan, bukan dianggap sebagai biaya funding yang sudah diverifikasi.

Endpoint `/products` mengembalikan HTTP 503. `SupplierCatalogSync` mencatat `last_error = upstream` dan backoff; 203 SKU lama tetap tersimpan tetapi freshness koneksi tidak berlaku. Angka 123 SKU orderable di atas berasal dari smoke sebelumnya, bukan hasil key baru. Belum ada pembelian atau debit wallet dalam pengecekan ini. Tunggu backoff dan pemulihan endpoint sebelum sinkron ulang; jangan menganggap katalog lama sebagai kapasitas yang siap dijual.

Verifikasi permintaan key terbaru: `CanbosoSupplierTest`, `CanbosoQueryBudgetTest`, dan `CanbosoSecurityTest` lulus 149 test dengan 7.300 assertion. `CanbosoPostgresConcurrencyTest` lulus delapan race multi-process pada PostgreSQL 16 disposable, 205 assertion, tanpa skip. Smoke persisted-state menunjukkan nol mapping publik, nol purchase, nol reserved balance, key tidak muncul dalam serialisasi model maupun audit, dan katalog tidak fresh setelah kegagalan upstream. Test memakai HTTP sintetis; GET saldo real tidak membuktikan debit atau delivery provider.


## API key dan produk test

1. Login Admin → Supplier Reseller. Isi form dan klik **Buat supplier**;
   popup password muncul otomatis bila diperlukan. Setelah password benar,
   supplier langsung disimpan. Password salah atau Batal tidak menghapus
   API key/isian; secret tetap hanya di form browser sampai penyimpanan berhasil.
2. Tambah supplier; pilih **CSxStore** (`csxstore`). Nama otomatis mengikuti
   provider. Mata uang CSxStore otomatis USD sesuai dokumentasi API. Key dimasukkan pada form
   **API key**, bukan ENV/chat. Nama supplier hanya untuk Admin.
3. Isi kurs IDR per USD sesuai biaya funding aktual; mata uang ditetapkan otomatis.
   Aktifkan koneksi. Sinkron katalog dan saldo hanya GET ke supplier.
4. Buka katalog privat. Cari produk **test API**; pastikan SKU, biaya, stok,
   dan jenis pengirimannya dari respons real. Nama “test” belum membuktikan
   produk gratis. Produk yang membutuhkan input/manual tidak bisa dijadikan
   sumber pengiriman instan.
5. Impor sebagai draft dengan nama/deskripsi milik toko dan harga retail.
   Pengulangan impor SKU yang sama tidak membuat produk duplikat.
6. Buka sumber supplier produk, konfirmasi ekuivalensi dan aktifkan mapping.
   Di tab Produk, edit nama/deskripsi/panduan dan aktifkan produk untuk buyer.
   Gunakan stok lokal **0** supaya pembelian benar-benar menguji supplier.
7. Isi kebijakan harga pada bagian **Harga jual**, pilih SKU, lalu klik
   **Hitung simulasi**. Hasil muncul di halaman yang sama dalam rupiah:
   harga beli supplier, harga jual, dan keuntungan per unit. Angka yang kamu
   isi tetap ada agar bisa disimpan setelah diperiksa. Pengecekan tidak
   menyimpan pengaturan atau membeli produk. Permintaan JSON eksplisit
   tetap tersedia untuk pemanggil programatis; browser biasa mendapatkan
   halaman HTML. Cadangan biaya tidak lagi digunakan. Fee pembayaran/funding
   tambahan dicatat sebagai biaya aktual, bukan dianggap sudah termasuk
   dalam keuntungan per unit.

## Margin dan kenaikan harga

Contoh: biaya $0.10, kurs Rp16.000/USD → biaya Rp1.600. Retail Rp3.000,
minimum profit Rp1.000 → batas biaya pembelian Rp2.000/unit.
Biaya yang telah diketahui di atas batas itu tidak eligible untuk supplier
checkout. Manual mempertahankan retail; fixed/percent/hybrid menghitung ulang
harga dasar saat sync, sementara snapshot pesanan lama tetap.

Uji UI tanpa membeli: naikkan minimum profit sampai biaya melampaui batas
yang diizinkan, sinkron, dan pastikan kapasitas supplier menjadi nol. Turunkan
kembali, sinkron, dan cek kapasitas pulih. Pastikan stok lokal nol agar tidak
menutupi hasil guard. Ini menguji batas margin, bukan mengubah harga provider.

Uji otomatis respons harga berubah berada pada suite Supplier/BuyMail. Provider
CSxStore tidak mendokumentasikan field max-price/quote lock pada POST order.
Precheck dan freshness tidak menjamin harga tidak berubah saat request
sedang berlangsung. Biaya aktual di atas ceiling perlu review, bukan klaim
bahwa debit supplier pasti dapat dicegah atau dibatalkan.

Dokumentasi CSxStore menyebut idempotency tetapi tidak menjelaskan identifier
yang harus dikirim. Adapter memperlakukan POST yang ambigu sebagai one-shot;
jangan mencoba membeli ulang dengan reference baru setelah timeout. Pulihkan
order supplier yang sama lewat monitor privat apabila identitasnya diketahui.

## Buyer dengan API supplier real

1. Setelah key/sinkron valid, catat saldo awal, SKU test, quantity **1**, biaya
   estimasi, retail, dan batas belanja supplier sebelum melakukan checkout.
2. Buka `/shop` dari jendela privat buyer; pilih produk test dan checkout.
   Katalog, cart, dan invoice pending belum boleh membuat order supplier.
3. Tentukan cara settlement buyer. Environment ini belum mempunyai credential
   payment gateway. Pengujian supplier saja dapat memakai settlement lokal
   terkontrol melalui seam OrderLifecycle; itu simulasi pembayaran buyer,
   tetapi POST pembelian supplier tetap real dan dapat mengurangi saldo.
   Pengujian pembayaran buyer real memerlukan rail/payment provider yang
   dikonfigurasi dan pembayaran benar-benar diverifikasi. Jangan menandai paid
   dengan update database langsung atau menganggap invoice pending sudah paid.
4. Setelah settlement yang dipilih dan batas belanja dikonfirmasi, worker
   `suppliers` memproses acquisition. Pantau Admin → Monitor pembelian /
   pemulihan, status payment/order, jumlah akun/credential yang diberikan,
   total biaya aktual dan saldo supplier akhir.
5. Verifikasi buyer menerima tepat quantity yang dibeli, nama supplier/API key/
   biaya modal tidak tampil, dan refresh invoice tidak membuat pembelian kedua.
   Cocokkan debit dengan order supplier asli melalui portal/detail order.
6. Apabila hasil ambigu, partial, atau biaya berlebih, simpan obligation yang
   sama dan gunakan monitor/recovery. Jangan hapus purchase/hold atau membuat
   checkout kedua untuk “mencoba ulang” outcome yang belum diketahui.

## Bukti saat penyiapan

Catatan berikut merekam penyiapan terdahulu. Nilai cadangan biaya di catatan historis bukan pengaturan yang masih tersedia; fitur tersebut sudah dihapus.

- Migration isolated berhasil; tabel supplier tersedia dan belum ada koneksi.
- HTTP login dan halaman Admin Supplier Reseller berhasil (200) menggunakan
  akun owner dan cookie lokal.
- Worker supplier dan default dijalankan pada environment yang sama.
- `php artisan test --compact --filter='Supplier|BuyMail'`: 216 passed,
  15 skipped, 1.366 assertions. Skipped bukan bukti concurrency PostgreSQL.
- Operator sudah memasukkan key CSxStore lewat Dashboard. Sinkron GET real
  berhasil: 29 produk. Produk `Api test`, SKU `48`, biaya USD 0,01
  (Rp180 pada kurs Rp18.000), stok 2, mendukung pengiriman otomatis.
- Product lokal #1 sudah aktif, stok lokal 0, mapping CSxStore aktif, mode
  manual, retail Rp1.000, minimum profit Rp800, cadangan biaya 0. Setelah
  sinkron ulang, kapasitas buyer 2. Minimum profit Rp1.000 sebelumnya membuat
  kapasitas 0 karena selisih Rp820: guard margin menolak sumber tersebut.
- GET `/orders` CSxStore terautentikasi berhasil. Envelope mencakup `orders`;
  row mencakup `order_id`, `service_id`, `quantity`, `unit_price`, `total_cost`,
  `products`, dan `status`. Hanya nama field diperiksa/dicatat, tanpa credential.
- Buyer Web checkout telah diperiksa: produk terlihat, tetapi halaman menyatakan
  pembayaran belum aktif. Database isolated belum mempunyai payment settings.
  Karena operator mengizinkan pembayaran buyer simulasi dan saldo CSxStore real,
  test dilanjutkan melalui Telegram dengan saldo toko lokal Rp1.000.

## Hasil test Telegram dan API real — 3 Oktober 2026

- Bot identity `getMe` dikonfirmasi **@LhieStoreTestBot**, terpisah dari token
  `@lhiestore_bot` di `src/.env`. Hanya credential bot test dari root `.env`
  disalin ke `.env.supplier-local`. APP_KEY dan database isolated dipertahankan.
- Ngrok agent yang sudah berjalan diarahkan ulang dari port 18081 ke 18082.
  URL HTTPS: `https://substandard-miguelina-soberingly.ngrok-free.dev`.
  Request inspection tunnel dimatikan. Public `/up` mengembalikan 200.
  `telegram:webhook:set` berhasil; `ops:check-telegram-webhook` membuktikan
  URL sesuai, secret dikonfigurasi, pending 0 dan tidak ada error webhook.
- Operator mengizinkan agent mensimulasikan tindakan buyer pada private chat
  sendiri. Saldo toko Rp1.000 dikreditkan sekali melalui StoreBalanceLedger
  dengan source `local_supplier_test`; ini tidak mewakili deposit uang buyer
  real. Private harness berada di `src/storage/app/private/supplier-telegram-test.php`,
  diabaikan Git dan memiliki pemeriksaan database serta identity bot test.
  Ia menggunakan synthetic `/start`, detail produk, dan checkout saldo melalui
  webhook HTTPS dengan secret yang benar; Telegram mengirim menu/pesan real.
- GET real order lama CSxStore menunjukkan `success: true`, `status: completed`.
  Adapter sebelumnya menolaknya dengan `invalid_order_status`. Perbaikan hanya
  menerima `completed` untuk CSxStore dengan boolean success benar; exact
  identity/quantity/cost dan delivery validation tetap berlaku. Dua regression
  tests membuktikan kontrak berhasil dan menolak success/identity yang salah.
  Suite SupplierClient/Fulfillment/IndependentRegression: 90 passed,
  612 assertions. GET real setelah perbaikan memberi trusted delivered.
- Checkout membeli **1 unit SKU 48**, stok lokal 0, harga toko Rp1.000.
  Order test menjadi Paid/Completed;
  Payment memakai STORE_BALANCE. Ledger buyer menyisakan saldo 0.
- Tepat satu SupplierPurchase berstatus delivered, quantity 1, actual cost
  10.000 micro-USD = **USD 0,01 / Rp180**. Satu ProductStockItem supplier
  order-owned dialokasikan. Exact GET order supplier membuktikan quantity 1,
  cost USD 0,01, trusted identity, dan satu hasil pengiriman.
- Telegram outbound `receipt.credentials`, `receipt.order_actions`, dan
  `admin.order_paid_notification` semuanya Sent dengan message ID terkonfirmasi.
  Tidak ada failed jobs; ini membuktikan Telegram menerima pengiriman, bukan
  bahwa operator telah membacanya. Isi credential tidak dicatat di runbook.
- Callback checkout identik diputar ulang melalui webhook: jumlah Order dan
  SupplierPurchase tetap 1, saldo buyer tetap 0. Tidak ada pembelian kedua.
- Sync real sesudah pembelian: saldo supplier dikonfirmasi ulang, stok test tersisa 1,
  held funds 0. Harga USD 0,01 eligible; evaluasi clone catalog dengan harga
  USD 0,02 (Rp360) ditolak oleh guard minimum Rp800. Clone tidak disimpan,
  tidak mengubah harga provider, dan tidak membeli produk lagi. Risiko harga
  berubah saat POST tanpa quote lock tetap sebagaimana dijelaskan di atas.
- App server, default/notifications worker, Telegram outbound worker, supplier
  worker, scheduler, dan ngrok dibiarkan berjalan untuk pengamatan operator.
  Scheduler diluncurkan dengan OS `APP_ENV=supplier-local` agar child commands
  tetap memilih database isolated. Web buyer payment gateway tetap belum
  dikonfigurasi; bukti ini khusus buyer Telegram dengan saldo simulasi dan
  supplier API real, bukan pembayaran gateway real atau qualification produksi.

## ProdSeller: sinkron real — 3 Oktober 2026

Operator menambahkan koneksi ProdSeller melalui Dashboard. API key valid;
GET balance dan katalog masing-masing sekitar 1,7–1,8 detik. Seluruh 31 produk
di list tidak mempunyai field stock, sehingga pembacaan detail serial melewati
deadline snapshot 40 detik. Retry scheduler juga menghabiskan budget lokal
240 read per 15 menit; budget dibiarkan pulih tanpa direset/dilewati.

Detail reader diperbaiki menjadi kelompok maksimal lima GET bersamaan,
tetap dengan deadline, limit detail, fixed-origin, response bounds, dan
penolakan seluruh snapshot bila salah satu detail gagal. Sinkron real setelah
perbaikan berhasil dalam **15,85 detik**, menyimpan **31 produk**, dan
membersihkan error. Tidak ada POST pembelian ProdSeller yang dilakukan.
Server web lokal kemudian dimuat ulang dan tombol sinkron diuji lewat
Dashboard HTTPS ngrok: berhasil dalam **25,34 detik**, dengan pesan sukses.
Scheduler isolated dinyalakan kembali. Suite regression supplier: 134 passed,
994 assertions; tes tambahan memastikan detail yang gagal menolak seluruh
snapshot dan tidak mengirim kelompok berikutnya.

## Katalog review supplier — 4 Oktober 2026

Empat Product baru diimpor melalui `ProductSupplierManager` pada database
`supplier-local.sqlite`, bukan database produksi. Harga manual mengikuti
katalog publik produksi: Gemini 18 bulan Rp13.000, Adobe Express 1 tahun
Rp10.000, Super Duolingo 12 bulan Rp13.000, dan Capcut 1 bulan Rp20.000.
Product Capcut 7 hari yang sudah ada tetap Rp5.000. Semua menggunakan stok
lokal nol dan mapping supplier aktif; Product Family GEMINI, ADOBE, DUOLINGO,
dan CAPCUT menyediakan label paket. CAPCUT mempunyai dua pilihan Plan.
Deskripsi, durasi, ikon, catatan pengiriman/support, dan batas garansi diisi
sebagai konten toko. Gambar Duolingo diambil dari aset publik produksi.

Harga produksi bukan bukti kelayakan supplier: pada observasi review,
saldo CSxStore nol dan ProdSeller 0,169999 USDT. Capcut 7 hari memiliki kapasitas
satu; produk lain nol karena saldo tidak cukup. Capcut 1 bulan juga bermodal
Rp22.500, lebih besar daripada retail Rp20.000. Stok API tidak disalin ke stok
lokal dan saldo tidak direkayasa. Garansi Adobe produksi tujuh hari tidak
ditiru: SKU yang dipilih tidak memberi garansi setelah aktivasi.

Checkout Maintenance diaktifkan dengan catatan review test. Jangan membuka
checkout tanpa otorisasi spending supplier terpisah. Tidak ada Order atau
SupplierPurchase baru selama smoke katalog. Bot `LhieStoreTestBot` terverifikasi;
ngrok menunjuk ke port 18082 dengan request inspection dimatikan.

Terminal interaktif ditemukan mewarisi APP_KEY yang berbeda. Launcher privat
`src/storage/app/private/supplier-review-runtime.sh` membersihkan environment
aplikasi warisan sebelum memuat `.env.supplier-local`; APP_KEY database tidak
diganti. Gunakan launcher ini untuk seluruh proses review, dari `src/`:

```bash
sh storage/app/private/supplier-review-runtime.sh php artisan serve --env=supplier-local --host=127.0.0.1 --port=18082 --no-reload
sh storage/app/private/supplier-review-runtime.sh php artisan queue:work database --env=supplier-local --queue=default,notifications,telegram-outbound,telegram-stock --timeout=60 --tries=1 --sleep=2
sh storage/app/private/supplier-review-runtime.sh php artisan queue:work supplier --env=supplier-local --queue=suppliers --timeout=120 --tries=1 --sleep=2
sh storage/app/private/supplier-review-runtime.sh php artisan schedule:work --env=supplier-local
```

Verifikasi review: build frontend berhasil; login Owner, daftar produk supplier,
foto Duolingo, serta pergantian Plan CAPCUT diuji pada browser. Viewport 390 px
tidak overflow. Browse sintetis `/products` diterima melalui webhook publik,
diproses worker, lalu empat family dan detail Duolingo dikirim melalui service
katalog ke private chat operator pada Telegram nyata. Ini bukan bukti pembeli
telah menekan seluruh tombol. Tidak ada pembelian atau deposit supplier.

Tambahan review: Product #7 `HOTMAIL ACCOUNT` dari CSxStore SKU `10` masuk
family HOTMAIL dengan label `1 akun · Email / Password / Code`, ikon email,
retail review Rp1.000, minimum profit Rp500, dan buffer Rp100. Paket berisi
akun `@hotmail.com`; masa garansi tidak diasumsikan. Observasi API menunjukkan
163.195 unit, tetapi kapasitas buyer nol karena saldo CSxStore kosong.
Halaman publik `/pilihan/hotmail` menampilkan harga dan deskripsi yang benar;
preview family dikirim melalui service katalog ke Telegram test nyata.
Jumlah Order dan SupplierPurchase tidak berubah; checkout tetap ditutup.

Referensi: [dokumentasi API CSxStore](https://api.cssx.store/docs),
[supplier runbook](runbook-supplier-api-reseller.md),
[Laravel queue workers](https://laravel.com/docs/13.x/queues#running-the-queue-worker).
