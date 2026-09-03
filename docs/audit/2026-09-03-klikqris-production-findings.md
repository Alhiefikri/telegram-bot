# Temuan Audit Read-Only Production KlikQRIS

**Tanggal audit:** 3 September 2026  
**Invoice yang menjadi pemicu audit:** `INV-20260903-01M1JY3NF9AMF8D9P44WA46HVN`  
**Waktu alert:** 3 September 2026 13:17:58 WIB  
**Production commit yang diamati:** `613653e`  
**Metode:** audit production read-only melalui SSH, observasi log, inspeksi source code, probe endpoint publik, dan focused test lokal.

Dokumen ini hanya mencatat masalah, bukti, dampak, tingkat keyakinan, dan batasan verifikasi. Keputusan operasional dan rencana perbaikan tidak dicantumkan.

---

## 1. Ringkasan temuan

Temuan utama terdiri dari dua kelompok:

1. **Defect internal pada reconciliation KlikQRIS** yang terkonfirmasi dari source code dan berkorelasi dengan durasi command production yang sangat lama.
2. **Kegagalan intermiten pada boundary integrasi KlikQRIS** yang terlihat dari callback HTTP 500 dan kondisi status provider yang tidak dapat dipastikan.

Flow aplikasi ketika status provider tidak dapat dipastikan memang masuk ke kondisi aman `RecoveryPending`. Flow tersebut tidak membuat payment kedua dan setelah tiga observasi recovery mengirimkan alert owner. Perilaku ini menjelaskan mengapa alert muncul, tetapi tidak menjelaskan penyebab awal status provider menjadi tidak pasti.

Berdasarkan bukti yang tersedia, provider tidak terbukti mengalami outage total. Penyebab tunggal untuk invoice target belum dapat dibuktikan karena detail payment database, exception aplikasi lengkap, payload response terautentikasi, dan dashboard KlikQRIS tidak tersedia dalam audit ini.

### Status remediasi

- **F-01** (terminal lama tanpa batas): sebagian sudah diperbaiki lewat cutoff `updated_at` 72 jam untuk status `expired`/`failed`; fairness backlog ditindaklanjuti di issue #417.
- **F-02** (starvation + overlap): diperbaiki di issue #417 dengan cursor rotasi durable per environment dan execution budget per sweep, serta mutex scheduler yang menutupi budget tersebut. Lihat `ReconcileKlikQrisPayments` dan `docs/agents/codebase.md` §5.4.

---

## 2. Temuan F-01: reconciliation memproses transaksi terminal lama tanpa batas waktu

**Severity:** High  
**Confidence defect:** Verified  
**Confidence sebagai penyebab tunggal invoice target:** Validated, belum conclusive

### Lokasi dan perilaku

Command `klikqris:reconcile` mengambil payment KlikQRIS yang memiliki `merchant_ref` dan status:

```text
pending
expired
failed
```

Query tersebut tidak memiliki batas waktu untuk status `expired` dan `failed`. Dengan demikian, payment terminal lama tetap masuk ke reconciliation pada setiap siklus.

Secara konseptual, query production yang diamati memiliki bentuk:

```php
Payment::query()
    ->where('provider', 'klikqris')
    ->whereNotNull('merchant_ref')
    ->whereIn('status', [
        'pending',
        'expired',
        'failed',
    ])
    ->where(function ($query) {
        $query
            ->whereIn('status', [
                'pending',
                'expired',
                'failed',
            ])
            ->orWhere('provider_create_state', 'outcome_unknown');
    });
```

### Masalah logika

Kondisi `whereIn('status', ...)` di bagian luar membuat payment hanya dapat dipilih jika statusnya sudah berada di daftar `pending`, `expired`, atau `failed`.

Kondisi `orWhere('provider_create_state', 'outcome_unknown')` di bagian dalam tidak memperluas query secara efektif karena masih dibatasi oleh `whereIn` bagian luar. Secara logika query dapat disederhanakan menjadi:

```text
status IN (pending, expired, failed)
```

Jika intent-nya adalah memasukkan semua payment dengan `provider_create_state = outcome_unknown`, kondisi outer `whereIn` dapat mengecualikan sebagian payment tersebut.

### Bukti production

Durasi reconciliation yang diamati berada pada kisaran:

- 54 detik;
- 1 menit 6 detik;
- 1 menit 8 detik;
- 1 menit 26 detik;
- sampai 1 menit 43 detik.

Pada window waktu yang berdekatan dengan alert invoice target, reconciliation berjalan sekitar 1 menit 6 detik.

### Dampak

- Transaksi lama tetap memicu request status ke provider.
- Jumlah request provider bertambah seiring pertumbuhan histori payment.
- Command yang berjalan lebih lama dari interval satu menit dapat menyebabkan eksekusi scheduler berikutnya tertunda atau dilewati karena `withoutOverlapping`.
- Recovery payment aktif dapat menunggu siklus scheduler berikutnya.
- Beban database, scheduler, dan koneksi provider meningkat.
- Request berulang terhadap provider meningkatkan kemungkinan timeout, rate limit, atau status tidak tersedia.

### Hubungan dengan invoice target

Invoice target berada dalam flow recovery setelah status QR belum dapat dipastikan. Reconciliation yang berlangsung lama pada waktu yang sama merupakan faktor internal yang dapat memperpanjang waktu recovery. Bukti yang tersedia belum menunjukkan row payment target dan belum membuktikan bahwa request target sendiri tertunda oleh payment lama tertentu.

---

## 3. Temuan F-02: jadwal reconciliation dan pemanggilan provider memperbesar efek defect

**Severity:** High  
**Confidence:** Verified

Reconciliation KlikQRIS dijadwalkan setiap menit dan menggunakan `withoutOverlapping`. Command memproses chunk payment dan melakukan verifikasi terhadap provider.

Ketika jumlah payment terminal lama bertambah, durasi command dapat melampaui interval scheduler. Karena overlap dicegah, siklus berikutnya tidak berjalan paralel untuk mengejar keterlambatan. Ini membuat backlog recovery menjadi lebih panjang, bukan lebih cepat.

Kombinasi berikut memperbesar masalah:

```text
semua histori terminal ikut diproses
        +
reconciliation setiap menit
        +
provider call per payment
        +
withoutOverlapping
        =
reconcile lambat dan recovery aktif tertunda
```

Tidak ditemukan bukti app container mengalami restart atau OOM pada waktu audit. Perlambatan lebih konsisten dengan volume pekerjaan reconciliation, aktivitas database, dan pemanggilan provider daripada crash aplikasi.

---

## 4. Temuan F-03: callback KlikQRIS menghasilkan HTTP 500 secara intermiten

**Severity:** High  
**Confidence adanya kegagalan boundary:** Verified  
**Confidence sebagai penyebab invoice target:** Unknown

### Bukti nginx

Dalam log access nginx yang tersimpan untuk sekitar 24 jam:

- 29 request `POST /api/webhook/klikqris` menghasilkan HTTP 200;
- 4 request `POST /api/webhook/klikqris` menghasilkan HTTP 500.

Timestamp request HTTP 500 yang terlihat dalam log UTC:

```text
2026-09-02 12:23:25Z
2026-09-02 17:48:15Z
2026-09-02 18:24:39Z
2026-09-03 00:51:51Z
```

Tidak ada HTTP 500 callback yang teridentifikasi tepat pada menit alert invoice target, sehingga hubungan langsung dengan invoice target belum dapat dipastikan.

### Masalah boundary exception

Webhook controller saat ini menangani `KlikQrisStatusUnavailableException`, tetapi exception lain pada proses verifikasi dapat keluar menjadi HTTP 500. Kategori yang berpotensi masuk ke jalur tersebut meliputi:

- HTTP 401 atau 403 dari provider;
- HTTP 404;
- response schema provider yang berubah atau tidak lengkap;
- payload invalid;
- `RuntimeException` lain dari client atau parser;
- kegagalan konfigurasi atau validasi response.

### Dampak

- Provider dapat menganggap callback gagal.
- Provider dapat melakukan retry.
- Status payment dapat terlambat menjadi authoritative.
- Error transient dan permanent tidak terlihat berbeda pada access log.
- Payment dapat bertahan di `outcome_unknown` atau `recovery_unconfirmed`.
- Retry provider dan reconciliation aplikasi dapat saling memperbesar jumlah request.

Detail exception untuk empat HTTP 500 tersebut tidak dapat diambil karena log Laravel internal tidak tersedia pada lokasi yang dapat dibaca dan output scheduler diarahkan ke `/dev/null`.

---

## 5. Temuan F-04: recovery customer bergantung pada `provider_total_payment`

**Severity:** Medium-High  
**Confidence:** Validated dari source flow

Flow `TelegramOrderIntents::refreshEligibleKlikQrisPayment` mensyaratkan readiness tertentu, termasuk `provider_total_payment`, sebelum melakukan refresh atau reconcile dari jalur customer.

Pada skenario create request yang sudah dikirim tetapi response tidak dapat dipastikan:

- `merchant_ref` dapat sudah tersedia;
- nominal lokal payment dapat tersedia;
- `provider_total_payment` dapat masih kosong;
- payment tetap sebenarnya dapat diverifikasi melalui GET status provider;
- jalur check/retry customer dapat tidak melakukan recovery karena readiness belum terpenuhi.

Dalam kondisi tersebut, recovery lebih banyak bergantung pada scheduler. Ketika scheduler sedang lambat akibat reconciliation histori, customer dapat mencoba kembali tanpa benar-benar memicu verifikasi status provider.

### Dampak

- Tombol atau flow check customer tidak selalu menjadi recovery path yang efektif.
- Payment dengan merchant reference valid dapat tetap menunggu scheduler.
- Kondisi `outcome_unknown` lebih mudah berlanjut sampai batas alert tercapai.
- QR tetap tidak tersedia walaupun provider mungkin sebenarnya sudah memiliki transaksi yang dapat diambil melalui status endpoint.

Flow ini tidak melakukan POST create kedua secara otomatis. Itu mencegah duplicate payment, tetapi juga berarti status recovery harus berhasil melalui jalur yang tersedia.

---

## 6. Temuan F-05: status queue `DONE` tidak merepresentasikan QR berhasil tampil

**Severity:** Medium  
**Confidence:** Verified dari source flow dan log job

`TelegramCheckoutIntents::sendKlikQrisPaymentInstructions` menjalankan lifecycle KlikQRIS dan selalu memproses notifikasi melalui jalur `finally`.

Jika lifecycle tidak menghasilkan payable QR, flow dapat mengirim pesan retry atau recovery pending. Pipeline delivery kemudian dapat menyelesaikan outbound effect dan queue job menjadi `DONE`, walaupun gambar QR belum terkirim.

### Dampak

- Monitoring queue dapat terlihat sehat karena job berstatus `DONE`.
- Customer dapat hanya menerima pesan teks tanpa QR.
- Tidak ada perbedaan jelas antara QR berhasil dikirim dan retry notice berhasil dikirim.
- Root cause menjadi sulit dibaca dari status job saja.
- Owner alert menjadi indikator terpisah dari status queue.

Job `SendKlikQrisCheckoutInstructions` di sekitar waktu audit memang banyak yang berstatus `DONE` dengan durasi 1 sampai 4 detik. Status tersebut tidak dapat digunakan sebagai bukti bahwa QR image berhasil diterima customer.

---

## 7. Temuan F-06: validasi QR image dapat menolak response provider yang sah

**Severity:** Medium, latent compatibility risk  
**Confidence:** Possible, tidak terbukti pada invoice target

`KlikQrisQrisImage` melakukan validasi ketat terhadap `qris_url` dan hasil download. Syarat yang diamati meliputi:

- URL menggunakan HTTPS;
- host berada dalam allowlist production;
- URL tidak memiliki query atau fragment;
- URL tidak memiliki port, user info, atau struktur yang ditolak;
- path berakhiran `.png`;
- response HTTP 200;
- content type `image/png`;
- body merupakan PNG yang valid.

Dokumentasi KlikQRIS yang diperiksa memberikan contoh URL `.png`, sehingga tidak ada bukti mismatch pada kontrak dokumentasi saat audit.

Namun, jika response aktual provider menggunakan:

- query parameter pada URL;
- redirect;
- ekstensi atau path selain `.png`;
- content type berbeda;
- host CDN resmi yang belum ada di allowlist;
- body image yang valid tetapi dideklarasikan dengan content type berbeda;

maka aplikasi dapat menolak QR dan masuk ke recovery atau 404. Response aktual untuk invoice target tidak dapat diperiksa karena detail payment dan payload terautentikasi tidak tersedia.

---

## 8. Temuan F-07: konfigurasi scheduler membuang detail hasil command

**Severity:** Medium  
**Confidence:** Verified

Output command scheduler diarahkan ke `/dev/null`. Akibatnya, audit production hanya dapat melihat bahwa command mulai, selesai, atau gagal pada level supervisor/container log, tetapi tidak dapat melihat detail per payment seperti:

- merchant reference yang diproses;
- response status provider;
- alasan parser gagal;
- jenis timeout;
- hasil recovery setiap invoice;
- jumlah payment yang diproses dalam satu siklus;
- payment mana yang menghabiskan waktu paling lama.

Kondisi ini secara langsung membatasi kemampuan untuk menghubungkan durasi reconcile dengan invoice target.

---

## 9. Temuan F-08: worker dan database memiliki masalah terpisah yang menambah noise production

**Severity:** Medium  
**Confidence:** Verified sebagai masalah production, tidak terbukti sebagai akar QRIS langsung

### Broadcast worker

Log app menunjukkan `laravel-broadcast-worker` beberapa kali menerima `SIGKILL` dan kemudian di-respawn oleh supervisor.

Worker ini bukan jalur inti pembuatan QRIS, tetapi menunjukkan terdapat instabilitas proses worker di production. Kondisi ini dapat mengurangi kapasitas dan memperumit pembacaan log ketika sistem sedang melakukan recovery payment.

### Cache lock PostgreSQL

Log PostgreSQL mencatat banyak error:

```text
duplicate key value violates unique constraint "cache_locks_pkey"
```

Key yang terlihat terkait dengan:

```text
lhie-store-cache-laravel_unique_job:App\Jobs\SendProductPriceChangeNotifications:54
```

Error ini berkaitan dengan notification job, bukan KlikQRIS secara langsung. Namun, konflik lock dapat menambah retry, error, dan aktivitas database pada waktu yang berdekatan dengan operasi payment.

### Memory dan swap

Snapshot resource production menunjukkan:

- total memory sekitar 1.9 GiB;
- swap terpakai sekitar 912 MiB dari 2 GiB;
- memory available sekitar 609 MiB;
- root filesystem terpakai sekitar 69%;
- tidak ada OOM kill pada app container yang diamati.

Penggunaan swap dan instabilitas worker merupakan kondisi resource yang perlu dikorelasikan dengan SIGKILL, tetapi tidak ada bukti langsung bahwa keduanya menyebabkan invoice target kehilangan QR.

---

## 10. Observasi admin payment mode di sekitar waktu alert

Nginx mencatat aktivitas pada Admin Dashboard sebelum alert:

```text
2026-09-03 06:16:18Z  GET  /admin?tab=stats       200
2026-09-03 06:16:22Z  POST /admin/payment-mode   302
2026-09-03 06:16:23Z  GET  /admin?tab=stats       200
```

Observasi tersebut membuktikan ada request ke endpoint payment mode, tetapi tidak membuktikan:

- nilai provider sebelum request;
- nilai provider sesudah request;
- apakah perubahan berhasil;
- apakah request ditolak validasi;
- apakah request tersebut berkaitan dengan invoice target.

Dari source code, payment provider pada payment yang sudah dibuat tidak berubah hanya karena payment mode baru berubah. Nilai payment target dan nilai switch saat ini tidak dapat dikonfirmasi melalui bukti yang tersedia dalam audit ini.

---

## 11. Hal yang tidak ditemukan sebagai masalah utama

Observasi berikut tidak menunjukkan outage total atau kerusakan umum pada web production:

- container app, scheduler, web, dan database aktif;
- tidak ditemukan OOM atau restart abnormal app pada waktu kejadian;
- endpoint health production `/up` merespons HTTP 200;
- storefront root merespons HTTP 200;
- job instruksi KlikQRIS lain berhasil dalam sekitar 1 sampai 4 detik;
- endpoint `GET /api/qris/status/{order_id}` KlikQRIS tanpa credential merespons HTTP 401, yang menunjukkan edge dan route provider dapat dijangkau;
- endpoint `GET /api/qris/create` merespons HTTP 405 dengan method POST, sesuai kontrak method;
- format endpoint dan header yang digunakan client sesuai dokumentasi KlikQRIS yang diperiksa;
- tidak ditemukan logic auto-switch provider;
- tidak ditemukan logic pembuatan payment manual otomatis;
- tidak ditemukan bukti aplikasi sengaja membuat duplicate payment pada recovery.

Probe route QR production menggunakan invoice reference tanpa signed URL merespons HTTP 404. Route tersebut memang menggunakan middleware `storefront.signed` dan membutuhkan order state yang valid, sehingga probe ini tidak dapat digunakan untuk menyimpulkan status QR invoice target.

---

## 12. Rangkaian masalah yang paling konsisten dengan bukti

Rangkaian berikut adalah hipotesis teknis paling konsisten dengan observasi, tetapi bukan bukti final untuk setiap langkah invoice target:

```text
Histori expired/failed terus direconcile
        ↓
Command klikqris:reconcile berjalan puluhan detik sampai lebih dari satu menit
        ↓
Siklus scheduler berikutnya tertunda atau tidak berjalan karena withoutOverlapping
        ↓
Recovery invoice aktif menunggu lebih lama
        ↓
Status create/status provider atau callback mengalami kegagalan intermiten
        ↓
Aplikasi tidak dapat memastikan status authoritative
        ↓
Lifecycle mengembalikan RecoveryPending
        ↓
Customer menerima retry text-only atau tidak menerima QR image
        ↓
Tiga observasi recovery tercapai
        ↓
Alert owner dikirim
```

Jalur alternatif yang juga konsisten dengan source code:

```text
Response QR provider tidak memenuhi validasi image
        ↓
Download atau parse QR image gagal
        ↓
Recovery presentation image dicoba
        ↓
Jika tetap gagal, QR tidak dapat disajikan
        ↓
Customer melihat retry atau 404
```

Jalur alternatif ini belum dapat dikonfirmasi untuk invoice target karena response QR aktual tidak tersedia.

---

## 13. Penilaian tingkat keyakinan

| Temuan | Tingkat keyakinan | Status bukti |
|---|---|---|
| Reconciliation memproses payment terminal lama tanpa cutoff | Tinggi | Tervalidasi dari source code dan durasi production |
| Kondisi query `outcome_unknown` redundant/terbatas oleh outer status filter | Tinggi | Tervalidasi dari struktur query |
| Reconciliation memperlambat recovery aktif | Sedang-tinggi | Didukung durasi command dan waktu alert, invoice row belum tersedia |
| Callback KlikQRIS mengalami kegagalan intermiten | Tinggi | 4 HTTP 500 teramati dari 33 callback |
| Salah satu callback 500 adalah invoice target | Rendah | Tidak ada korelasi langsung pada log yang tersedia |
| KlikQRIS mengalami outage total | Rendah | Job lain berhasil dan endpoint provider reachable |
| Recovery customer dapat terblokir karena `provider_total_payment` kosong | Sedang-tinggi | Didukung source flow |
| Job `DONE` dapat terjadi tanpa QR image terkirim | Tinggi | Didukung source flow dan pola job log |
| QR target ditolak karena format image atau URL | Rendah | Kemungkinan dari validator, response target tidak tersedia |
| SIGKILL broadcast worker dan cache lock conflict adalah akar QRIS | Rendah | Masalah production teramati, tetapi tidak ada hubungan langsung |

---

## 14. Batasan audit

Hal-hal berikut tidak dapat diverifikasi dalam audit read-only ini:

1. Row database lengkap untuk invoice `INV-20260903-01M1JY3NF9AMF8D9P44WA46HVN`.
2. Nilai `provider_create_state`, `provider_total_payment`, `qris_url`, dan `qris_image` aktual invoice target.
3. Exception Laravel lengkap untuk callback HTTP 500.
4. Response authenticated KlikQRIS untuk status invoice target.
5. Status invoice target pada dashboard KlikQRIS.
6. Nilai payment provider mode yang tersimpan setelah request Admin Dashboard.
7. Acceptance QR end-to-end melalui signed URL order yang valid.
8. Detail per payment dari scheduler karena output command diarahkan ke `/dev/null`.

Tidak ada perubahan production yang dilakukan selama audit. Tidak ada switch provider yang diubah dan tidak ada payment manual yang dibuat.
