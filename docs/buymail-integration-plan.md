# Rencana integrasi BuyMail: pembelian setelah buyer bayar dan klaim Gmail

Tanggal: 2026-10-03. Status: bahan planning/brainstorming; belum merupakan keputusan produk yang disetujui atau implementasi. Dokumentasi publik dibaca, tanpa API key, transaksi, deposit, atau deployment.

Update: user kemudian mengotorisasi implementasi. Rencana berikut dipertahankan sebagai konteks desain; hasil aktual, penyesuaian dan bukti tes berada di [catatan implementasi](buymail-implementation-progress.md), [ADR-0017](adr/0017-per-account-warranty-and-partial-refunds.md) dan [runbook BuyMail](runbook-buymail.md). Aktivasi produksi dan transaksi live tetap terpisah.

Sumber kontrak: [BuyMail Reseller API](https://buymail.simuru-store.com/api/reseller/docs). Detail fakta dan kontrak yang belum jelas: [riset API](agents/buymail-api-research.md). Fondasi repo: [ADR-0016](adr/0016-private-supplier-fulfillment-and-capacity.md), [rencana supplier existing](supplier-api-reseller-implementation-plan.md), dan [runbook](runbook-supplier-api-reseller.md).

## 1. Hasil yang dituju

Buyer membeli Product Gmail milik toko melalui Store Bot atau Web Storefront. Toko baru mengeluarkan Supplier Balance ketika pembayaran buyer benar-benar terverifikasi. Akun disimpan aman, lalu dikirim ke buyer. Buyer dapat mengajukan klaim pada akun tertentu sebelum deadline; toko mengirim bukti ke BuyMail, memantau keputusan, dan menjalankan penyelesaian buyer yang terpisah dari refund supplier.

**Batas penting:** BuyMail mendukung otomatisasi pembelian dan pengajuan/pemantauan refund, tetapi tidak menjamin semua Gmail bermasalah otomatis memperoleh refund. Keputusan tetap mengikuti bukti, tenggat, dan pemeriksaan supplier. Refund approved pun dapat reversed. Jangan menjanjikan refund pasti untuk semua jenis masalah.

## 2. Fakta provider yang menentukan desain

- Base URL tetap `https://buymail.simuru-store.com/api/reseller`; autentikasi `Authorization: Bearer`. Uang IDR integer, waktu UTC.
- API terbuka setelah total deposit mencapai Rp100.000 dan diaktifkan di portal reseller. Key dapat dibatasi ke IP server; regenerasi langsung mematikan key lama.
- Batas 120 request/menit/key. Profil memberi saldo, harga akun, stok, limit quantity, refund window dan apakah refund aktif.
- Order memerlukan `Idempotency-Key`, tersimpan 7 hari. Body sama/key sama mengembalikan hasil original. `reference` unik maksimal 64 karakter dan dapat dicari melalui GET.
- Satu request membeli 1–50 akun, tanpa SKU. Pemeriksaan akun biasanya 10–30 detik; docs meminta HTTP timeout minimal 90 detik.
- `partial` berarti hanya sebagian quantity lolos. Biaya hanya untuk `quantity_delivered`, bukan quantity yang diminta.
- Refund per account ID; multipart JPEG/PNG maksimal 4 MB, note maksimal 500 karakter, dalam 2 jam delivery. Gunakan `refund_deadline` individual sebagai batas otoritatif, bukan waktu buyer membuka pesan.
- Screenshot wajib dari HP saat login lewat aplikasi Google, dengan status bar. Wrong-password dengan perubahan lebih dari 1 jam dan Family Link disebut eligible; lock setelah perubahan password, device/location baru, dan bulk sending tidak ditanggung.
- `confirm` menutup garansi lebih awal. Jangan panggil otomatis setelah delivery, pesan dibaca, atau Order Completed.
- Webhook untuk deposit dan keputusan refund; tidak ada event order-completed yang didokumentasikan. Retry webhook tidak dijamin melewati restart supplier; GET tetap sumber kebenaran.

Semua butir di atas adalah kontrak yang tertulis, bukan hasil pengujian akun produksi. [Sumber](https://buymail.simuru-store.com/api/reseller/docs).

## 3. Kondisi repo dan gap nyata

| Area | Existing | Tambahan BuyMail |
| --- | --- | --- |
| Koneksi | `SupplierManager`: Admin, encrypted key, version/guard | Provider BuyMail, Bearer auth, account identity, webhook secret/IP info |
| Katalog | Lima provider melalui `/me` atau `/balance` + `/products` | `/profile` saja; satu sumber Gmail internal yang tidak dianggap SKU dari API |
| Mata uang | `SupplierProvider`, `SupplierClient::ready`, dan PostgreSQL constraint hanya USD/USDT | Tambah IDR, conversion rate 1; pertahankan unit internal existing secara eksplisit |
| Pembelian | HTTP timeout 12 detik, generic result berupa teks, exact quantity | Kontrak khusus BuyMail, timeout >=90 detik, structured account IDs/deadlines, partial delivery |
| Recovery | Durable purchase, idempotency/replay konservatif, GET known order | Lookup exact reference, retention 7 hari, `in_progress` dan `reference_already_used` |
| Runtime | Worker payments/default 60 detik; Redis `retry_after` default 90 detik; lease purchase 2 menit | Queue/connection supplier dengan timeout dan lease yang konsisten |
| Refund buyer | `OrderStateMachine::applyPaymentRefunded`, full Order; StoreBalanceLedger satu refund per Order, sesuai debit original | Per-unit/partial settlement baru, cap kumulatif, tidak menandai seluruh Payment Refunded untuk satu akun |
| Klaim pasca-delivery | Product punya copy `warranty_policy`, belum ada workflow klaim akun supplier | Form bukti, case per account, submit/poll/decision/reversal, penyelesaian buyer |
| Deposit supplier | Runbook existing top-up di luar aplikasi | Jika ingin seluruh kapabilitas API: Admin create/list/status/cancel deposit BuyMail |

Jangan membangun checkout kedua. Reuse ownership Product, OrderCheckoutService, SupplierFulfillmentService, credential presentation, payment verification, dan Telegram outbound ledger. Tidak menganggap fondasi existing sudah deployed atau BuyMail sudah aktif.

## 4. Cakupan semua kapabilitas dokumentasi

Path berikut relatif terhadap base URL BuyMail. Endpoint Admin/customer lokal dipisahkan dari endpoint supplier ini.

| Kontrak | Fitur toko | Perlindungan utama |
| --- | --- | --- |
| GET `/profile` | Saldo, harga modal, kapasitas, limit, refund enabled | Freshness, private Admin, margin dan commitments |
| POST `/orders` | Beli hanya untuk Order buyer Paid | Persist intent; stable idempotency key/reference; quantity <= limit |
| GET `/orders/{id}` | Recovery exact order dan histori | Validasi account connection, ID, reference, amount, jumlah akun |
| GET `/orders?limit=20&reference=…` | Recovery kehilangan respons/ID, histori bounded | Exact reference; pagination belum jelas, bukan fuzzy matching |
| GET `/accounts/{id}` | Status akun/keputusan refund | ID terikat unit milik buyer, bukan ID input arbitrer |
| POST `/accounts/{id}/confirm` | Konfirmasi eksplisit jika buyer yakin | Peringatan garansi berakhir; jangan otomatis |
| POST `/accounts/{id}/refund` | Pengajuan klaim + bukti | Deadline, file, ownership, single active case; timeout dibaca ulang |
| POST `/deposits` | Admin membuat instruksi top-up supplier | Explicit amount/confirmation, key stabil, satu unpaid deposit |
| GET `/deposits/{id}` | Rekonsiliasi deposit | GET mengotorisasi saldo; paid sekali saja |
| GET `/deposits?limit=20` | Histori/top-up yang masih pending | Bounded reads; contract pagination belum lengkap |
| POST `/deposits/{id}/cancel` | Admin membatalkan unpaid deposit | Paid-vs-cancel race dibaca ulang; bukan refund deposit paid |
| Webhook `deposit.paid` | Percepatan rekonsiliasi saldo supplier | Signed durable inbox; bukan Customer Store Balance top-up |
| Webhook `refund.approved/rejected/reversed/cancelled` | Percepatan update case dan alert | Dedupe, GET authoritative, dukung perubahan keputusan |
| Webhook `ping` | Health check koneksi | Valid signature, tidak memindahkan uang |
| Portal reseller | Generate/rotate key, webhook URL/secret, IP allowlist | Docs tidak memberi endpoint untuk konfigurasi ini; setup tetap melalui portal |

Deposit API merupakan kelengkapan Admin, bukan prasyarat auto-buy jika Supplier Balance telah didanai. Tidak membuat pembayaran deposit secara otomatis; API hanya memberi payment URL. Hindari auto-top-up tanpa limit dan otorisasi eksplisit.

## 5. Alur pembelian dan recovery

1. Admin menghubungkan BuyMail, sync profil dan map Gmail ke Product yang benar-benar setara. Nama/harga retail/icon/garansi/guide adalah milik toko dan diedit di database/Admin.
2. Katalog memakai kapasitas supplier yang fresh, dibatasi stok, saldo, commitment yang belum selesai dan margin. Browsing/cart/checkout belum memanggil POST order BuyMail.
3. Checkout snapshot quantity, harga retail, kebijakan garansi, source/account identity dan biaya yang diketahui. Reserve kapasitas internal; ini bukan jaminan reservasi stok di BuyMail.
4. Hanya authoritative payment settlement menghasilkan durable fulfillment. QR screenshot, redirect sukses, status frontend, atau callback tanpa verifikasi tidak cukup. Late Payment mengikuti Manual Fulfillment Review existing.
5. Worker lock/claim intent lalu commit. Network berjalan di luar lock. Satu purchase attempt memakai reference <=64 dan key yang stabil; request body tidak boleh berubah ketika retry.
6. Respons divalidasi: order ID/reference tepat, IDR, requested/delivered quantity, account IDs unik dan order_id cocok, email/password lengkap, actual charge diketahui, deadline valid. Simpan evidence encrypted sebelum delivery.
7. Debit supplier dan unit allocation diterapkan tepat sekali. Toko menyelesaikan Order setelah seluruh quantity atau penyelesaian kekurangannya sudah jelas; receipt ulang tidak pernah membeli ulang.
8. Timeout/crash: cari GET exact reference/order terlebih dahulu. Jika perlu replay, gunakan key/body yang sama dalam retention terdokumentasi; backoff dan `Retry-After`. Setelah 7 hari tanpa outcome, tidak POST ulang otomatis. Reference menjadi identitas tambahan, bukan alasan mengasumsikan dedupe tanpa batas waktu.
9. `idempotency_in_progress`, `order_in_progress`, dan `reference_already_used` berarti recovery original, bukan fallback supplier baru. Body berubah/key sama adalah bug yang perlu review.
10. Hanya structured error resmi yang menjamin tidak ada purchase/debit dapat mengizinkan safe retry/fallback. Raw 500/502 HTML proxy, timeout dan payload rusak tetap ambiguous. Error `purchase_failed` 500 di docs berbeda dari HTTP 500 generik.

**Runtime proposal:** dedicated supplier queue/connection; contoh HTTP 95 detik, worker/job 120 detik, retry_after 180 detik, claim lease 240 detik untuk satu HTTP attempt/job. Tetapkan ulang bila satu job melakukan beberapa request. Persist intent tetap pengaman crash; lease bukan idempotency provider. Scheduler existing jangan ditahan request 95 detik: discovery bounded hanya membangunkan pekerjaan, dengan durable due-work agar Redis loss tetap dapat dipulihkan. Urutan HTTP timeout < job timeout < retry_after, lease melewati durasi claim yang sah. [Laravel queue docs](https://github.com/laravel/docs/blob/13.x/queues.md), [HTTP client docs](https://github.com/laravel/docs/blob/13.x/http-client.md), diambil melalui Context7 Laravel 13.x.

**Harga:** API tidak mendokumentasikan quote lock atau `max_price`. Fresh profile dan buffer menurunkan risiko, tetapi tidak menjamin biaya sebelum POST. Jika actual price naik, biaya sudah terlanjur didebit; jangan berpura-pura guard setelah response mencegah spend. Kebutuhan strict cost cap harus dikonfirmasi ke supplier sebelum aktivasi.

**Limit harian:** docs menyebut reset 00:00 GMT+7, berbeda dengan Asia/Makassar. Simpan waktu UTC dan tampilkan WITA; cooldown limit menggunakan zona provider. Jangan mengarang angka daily allowance yang tidak ada di profil.

## 6. Partial delivery: wajib diselesaikan secara eksplisit

Contoh buyer membayar 3 akun @Rp8.000; BuyMail mengirim 2 @Rp4.800 dan charge Rp9.600. Dua akun itu valid, owned oleh Order dan tidak boleh dibeli ulang. Kekurangan buyer tetap 1 akun/Rp8.000, bukan Rp4.800 biaya supplier.

- Catat requested, acquired, allocated, outstanding dan refunded per unit; settle biaya aktual serta release commitment unused satu kali.
- Boleh attempt baru untuk **sisa terbukti belum dikirim/didebit** setelah original partial terminal tervalidasi. Reference/key baru khusus child attempt sisa, bukan seluruh quantity.
- Retry sisa dibatasi waktu/attempt dan margin; jika tidak tersedia, buyer memilih penyelesaian yang tersedia menurut kebijakan toko. Jangan tahan seluruh Order tanpa batas.
- Jika sebagian unit sudah dikirim, countdown garansinya sudah berjalan. Tampilkan delivery dan klaim tiap akun segera, walaupun Order keseluruhan masih Processing. Evaluasi perluasan credential presentation existing yang menunggu fulfillment penuh.
- Akun refund/replacement tetap Retired Credential, tidak kembali ke Available pool. Akun lain pada invoice yang sama tetap dapat digunakan.
- Partial refund tidak memakai full-order `applyPaymentRefunded` untuk satu unit. Full-order fallback berarti toko memilih mengembalikan seluruh harga retail dan menanggung unit yang sudah diterima; itu keputusan bisnis berbeda.

## 7. Klaim Gmail bermasalah dan dua proses refund

### Pengajuan buyer

Store Bot/Web Storefront menawarkan **Laporkan akun bermasalah** pada unit milik buyer dengan deadline/countdown, alasan yang ditanggung dan contoh bukti sah. Telegram memakai upload dokumen atau foto resolusi memadai; backend memvalidasi hasil upload. Web memakai session/order ownership atau Recovery Key existing, bukan nomor invoice saja.

Buyer memilih unit, alasan dan mengunggah bukti. Simpan case + file private, lalu segera jadwalkan submit BuyMail bila valid agar approval Admin lokal tidak menghabiskan dua jam. Buyer menerima **klaim diajukan/menunggu pemeriksaan**, bukan **refund berhasil**. Target submit segera; alert sebelum deadline dan jangan menunda polling profile untuk pengajuan urgent.

Validasi file melalui decoder/MIME asli, JPEG/PNG <=4 MB, batasi dimensi, upload dan akses authenticated. Simpan bukti original untuk review; jangan crop status bar atau mengedit substansi. Note maksimal 500 karakter. Screenshot/key/password/raw response tidak masuk log publik, audit payload, analytics atau issue.

Screenshot autentik adalah bukti awal, bukan otomatis membuktikan password telah berubah atau keluarga supervisi. Aplikasi tidak login ke Gmail, mengubah password, mencoba account checking dengan browser automation, atau menilai kelayakan berdasarkan OCR saja.

### Supplier claim

State lokal usulan: `received → submitting → pending → approved/rejected/cancelled/expired`; `approved → reversed` harus tetap dapat diproses. `submission_unknown` dipakai jika upload timeout/crash.

Refund endpoint tidak memiliki kontrak Idempotency-Key eksplisit. Single active case/unit, durable dispatch token, GET account setelah timeout dan cek `refund_pending`/refund object sebelum mencoba ulang. Jangan menganggap `refund_already_requested` sebagai rejected; temukan case original. Contract retry jika GET masih active perlu dikualifikasi; tanpa bukti aman, review sebelum POST ulang. Jangan mengharapkan upload ulang setelah deadline diterima.

Polling durable due-cases menjadi safety net; webhook hanyalah wakeup. Rejected/cancelled/reversed menyimpan riwayat dan decision evidence. Private Admin dapat membuka dispute/chat URL HTTPS yang tervalidasi; customer copy tetap store-branded. No API untuk diskusi dispute didokumentasikan, jadi resolusi dispute membutuhkan tindakan melalui chat supplier.

### Refund atau replacement buyer

Keputusan user pada 2026-10-03: **buyer memilih refund atau penggantian akun setelah klaim diverifikasi**. Buyer tidak dikenai biaya tambahan untuk penggantian. Simpan pilihan sebagai snapshot case dan jalankan satu penyelesaian saja. Untuk otomatisasi, supplier-approved yang sudah dikonfirmasi GET menjadi bukti verifikasi default; Admin dapat memverifikasi dan menyetujui kompensasi toko secara terpisah. Istilah verified tidak berarti screenshot diterima lokal atau POST refund menghasilkan 202.

Supplier-approved mengembalikan **modal ke Supplier Balance**, bukan uang buyer. Refund buyer menghitung **harga retail unit snapshot**, bukan harga modal atau harga Product saat ini. Contoh modal Rp4.800 dan retail Rp8.000: supplier recovery Rp4.800; customer refund Rp8.000.

- Buyer membayar Store Balance: canonical ledger perlu diperluas untuk refund per-unit/partial, immutable identity per settlement, total credit <= debit original, cumulative cap locked. Jangan mengganti key saja: existing unique source/kind dan pemeriksaan full debit memang menghalangi partial refund.
- Buyer membayar QRIS/transfer/USDT: pengembalian melalui rail atau bukti transfer yang sesuai. Existing confirmed external-refund path tetap wajib. Partial settlement baru memerlukan pencatatan confirmed amount/identity/currency; customer payment tidak otomatis menjadi full Refunded.
- Replacement: retire akun asal, buat obligation pengganti dengan identity sendiri, validasi saldo/margin dan batas kompensasi. Key pembelian original tidak digunakan untuk membeli pengganti. Tidak mengenakan harga lagi ke buyer. Account replacement memiliki deadline supplier sendiri.
- Customer settlement state terpisah, misalnya `unresolved → refund_pending → refunded` atau `replacement_pending → replaced`; timeout transfer ditandai uncertain, bukan dikirim ulang tanpa bukti.
- Mencegah klik ganda, webhook ganda atau keputusan paralel refund+replacement melalui unique identity/case dan lock yang sama. Total kompensasi buyer tidak melebihi entitlement kebijakan.
- Bila supplier rejected tetapi toko memilih goodwill refund, catat keputusan Admin/loss terpisah. Supplier rejection tidak menghapus tanggung jawab garansi toko yang sudah dijanjikan.
- Bila supplier reversed setelah buyer refunded, jangan mendebit balik Store Balance buyer otomatis. Catat supplier loss/reversal, sync saldo, review fraud/limit exposure dan alert operator. Reversal tidak menghidupkan akun retired atau mengizinkan refund kedua.

**Ketegangan dokumentasi:** guide menyarankan first login komputer, sementara bukti refund wajib login HP. Minta supplier menjelaskan cara menghasilkan bukti eligible tanpa menimbulkan device flag. Jangan menjanjikan alur klaim aman yang belum jelas.

## 8. Pembagian modul dan data usulan

- `BuyMailAdapter` di boundary Suppliers: profile, purchase/recovery, account, confirm, refund multipart, deposit; normalisasi typed DTO. Hindari menambah condition BuyMail ke parser generic yang tidak memahami partial atau account metadata.
- Existing SupplierFulfillmentService: hanya pemilik acquisition setelah Paid, commitments dan recovery. Extend untuk terminal partial dan outstanding child attempts sambil mempertahankan provider lain.
- Supplier account-unit mapping: `(supplier_connection_id, external_account_id)` unik, purchase/OrderItem/ProductStockItem, provider status, acquired/delivered time, refund_deadline. Private IDs dipakai melalui opaque local unit ID di UI.
- Warranty claim owner: case per unit, reason, private proof reference/digest, snapshots deadline/policy/retail amount, provider outcome history dan due-work; buyer dan operator adapters tidak memindahkan uang langsung.
- Customer compensation owner: refund/replacement obligation dan append-only settlement identities; bersama OrderLifecycle/StoreBalanceLedger untuk actual money. Per-unit settlement mengubah kontrak whole-order refund, perlu ADR baru, bukan patch controller.
- Signed webhook inbox: event ID scoped connection, raw payload encrypted/bounded, received_at, verification/version/status, durable due processing. Historical keputusan jangan ditimpa tanpa audit.
- Supplier deposits: private intent/idempotency/reference/provider ID/status, requested amount/payment URL/deadline, due reconciliation. Supplier account-change guards mencakup pending deposits/claims/reversal recovery, bukan hanya active purchase.
- Semua kebijakan garansi, retail pricing, quantity limit toko, polling/freshness policy dan UI copy disimpan DB/Admin sesuai policy repo. Key dan secret encrypted; tidak menambah ENV katalog. Queue/runtime configuration mengikuti kebijakan deployment existing.

Schema/nama di atas usulan, belum vocabulary atau ADR final. Pisahkan provider account status, claim decision, buyer settlement, OrderStatus dan PaymentStatus; tidak menyatukannya menjadi satu enum refund.

## 9. Webhook, operasi dan keamanan

- Verifikasi HMAC-SHA256 atas `timestamp + '.' + raw_body`, constant-time compare, toleransi absolute 5 menit; validasi body schema/size, event-body ID/type vs header. Dedupe event per connection, gunakan clock server yang tersinkronisasi.
- Persist inbox sebelum memberi 2xx, dalam 10 detik; jika persist gagal gunakan non-2xx agar provider retry. Parsing finansial dan GET dilakukan async di luar webhook transaction.
- Signed event tidak selalu event terbaru. GET account/deposit sebelum menerapkan state ekonomi; simpan event trail dan cegah stale approved menimpa reversed. Bila GET gagal, pending reconciliation, bukan asumsi approved.
- Setelah refund/deposit update, sync authoritative profile balance. Jangan menambah kredit pada snapshot yang sudah berisi refund; commitments tetap dihitung terpisah dan dedupe economic effect.
- Prioritaskan urgent claim submit/purchase/recovery terhadap profile/history sync dalam budget 120/key. Hormati Retry-After dengan jitter/backoff dan bounded attempts; tidak loop setiap akun pada setiap page load.
- Fixed HTTPS host, redirect disabled saat mengirim Bearer key; bounded payload/IDs. Jangan server-fetch URL arbitrary dari webhook, chat atau payment payload.
- Monitor oldest paid-undelivered, ambiguous attempts, partial outstanding, near-deadline claims, webhook verification/reconciliation failures, pending deposits, low supplier balance, reversal loss. Pesan buyer tidak memuat modal, key, supplier raw error atau internal IDs.
- Pembaruan key/secret mencakup account identity confirmation dan unresolved claims/deposits; key rotation bukan pindah akun. Portal mencabut key lama langsung, maka koordinasikan drain/pemulihan dan jangan menganggap grace period ada.

## 10. Tahap implementasi dan acceptance

1. **Kontrak dan keputusan produk.** Konfirmasi missing guarantees di bawah, policy refund/replacement, multi-account/partial settlement serta guide klaim. ADR perluasan partial refund, bukan revisi diam-diam ADR-0016.
2. **Adapter dan Admin.** Tambah BuyMail/IDR/constraints, fixed host/Bearer, profile mapping, account identity, key/webhook secret. Setup katalog private dan price/capacity preview. HTTP fixtures dari contoh docs dengan credentials sintetis.
3. **Auto-buy dan partial fulfillment.** Perbaiki runtime supplier queue; paid gate, recovery reference/idempotency, allocation account IDs/deadlines, outstanding-only attempts, early unit delivery, no auto confirm.
4. **Klaim dan kompensasi wajib sebelum aktivasi penjualan.** Upload customer/Admin, urgent submission, GET poll, signed inbox, decision/reversal; refund partial ledger/external settlement, replacement, per-unit retirement dan buyer notifications.
5. **Kelengkapan API Admin.** Account explicit confirm, deposit create/list/read/cancel, private history/dispute link, runbook/monitoring/guard rotasi. Jika belum dibuat, tandai deferred secara eksplisit; jangan menyebut seluruh fitur API selesai.
6. **Verifikasi dan qualification.** Fake-HTTP application integration + PostgreSQL races; real Admin/Store Bot/Web behavior. Controlled real purchase/refund perlu otorisasi spend terpisah dan bukti sandbox/akun operator; belum dilakukan pada planning ini. Deploy mengikuti GitHub Actions/VPS repo, bukan Railway.

Acceptance minimum:

- Browse, cart, pending/unverified/expired/cancelled Orders tidak membeli supplier. Late Payment tidak membypass review.
- Satu buyer payment + callback/job/webhook duplicate/crash menghasilkan satu economic purchase untuk quantity itu, termasuk recovery original tanpa ID.
- `partial` 2/3 settle hanya 2, tidak repurchase 3; 2 akun dapat menerima klaim segera dan kekurangan 1 diselesaikan tepat sekali.
- GET metadata hilang/conflicting IDs/duplicate accounts/wrong reference/currency/cost diperlakukan sebagai evidence review, bukan safe repurchase.
- Timeout 95 detik tidak dimatikan worker 60 detik dan tidak diproses ulang lease premature. Redis hilang tidak menghapus kewajiban Paid atau klaim yang due.
- Window berdasarkan deadline supplier UTC, boundary timezone WITA/GMT+7 benar; local upload belum berarti claim accepted supplier. Alert sebelum deadline.
- Ownership/Recovery Key, valid/invalid MIME/file besar/status-bar guidance, key/credential redaction dan no auto-confirm terbukti.
- Claim timeout, duplicate upload, refund_already_requested, out-of-order duplicate/invalid-signature webhook, restart/poll recovery dan approved→reversed ditangani.
- Akun 1 rusak dari 3 tidak meretire 2 akun lain atau menandai seluruh Payment Refunded; refund retail benar setelah tier discount dan snapshot.
- Store Balance partial credits capped oleh original debit; full refund berikutnya hanya sisa; refund vs replacement vs whole-order refund race tidak overcompensate.
- Supplier reversal tidak otomatis mencabut uang buyer; supplier balance resync tidak double credit. Partial external refund memerlukan actual transfer evidence.
- Deposit paid vs cancel race, duplicate paid event, pending deposit error dan account rotation dengan unresolved liabilities ditangani.

## 11. Hal yang belum dijamin dan perlu ditanyakan ke BuyMail

1. Apakah price dapat dikunci/max-price dikirim? Apa arti stok/profile saat daily limit tercapai? Ada remaining daily allowance yang dapat dibaca?
2. Apa schema list envelopes, pagination, exact reference filtering, zero-delivery partial, history retention dan durable recovery setelah idempotency 7 hari?
3. Scope idempotency per account atau API key? Key rotation mempertahankan purchase reference/dedupe? Apakah in-flight/idempotency survive server crash dan concurrent requests?
4. Refund upload crash/timeout: kapan GET pasti memperlihatkan pending? Apakah request identik boleh diulang aman dan timestamp supplier receipt menentukan deadline?
5. Deadline diukur dari provider delivery mana? Ada grace period untuk provider outage/upload in progress? ETA keputusan dan dispute SLA?
6. Apakah `refund.cancelled` ada state di GET? Status account setelah reversed, penanda decision version/time/refund ID dan economics reversal apa? Bisa saldo menjadi negatif?
7. Apakah signature setiap webhook retry memakai timestamp baru? Secret rotation/replay history didukung? Event ID unik lintas key/account?
8. Cara bukti HP yang eligible bila first login disarankan komputer, dan ketentuan resale/retail warranty yang boleh dijanjikan.

Absennya jawaban tidak membatalkan planning. Hal yang memengaruhi keselamatan pembelian/refund membatasi tingkat otomatisasi dan menjadi qualification gate sebelum production.
