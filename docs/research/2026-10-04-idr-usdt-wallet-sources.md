# Riset sumber wallet IDR dan USDT

Tanggal pemeriksaan: 2026-10-04. Ini masukan untuk plan/spec, bukan implementasi atau bukti bahwa layanan sudah aman. Klaim dokumentasi dibedakan dari rekomendasi desain.

## Basis versi dan metode

Repo menyatakan Laravel `^13.8`, PHP `^8.3`, dan Brick Math `^0.18` di `src/composer.json`; `docker-compose.yml` memakai `postgres:16-alpine`. Rail checkout yang sudah ada mencakup Binance Pay ID dan USDT BEP20/BSC menurut `docs/agents/codebase.md`. Ini fakta konfigurasi repo; bukan audit versi server produksi.

Context7 dipakai dengan urutan resolve-library-id → query-docs. Resolver Laravel menghasilkan `/laravel/docs` dengan branch `__branch__13.x`; query memakai `/laravel/docs/__branch__13.x`. Resolver PostgreSQL menghasilkan dokumentasi versi 16 dan 17; query awal 17 diperiksa ulang menggunakan `/websites/postgresql_16` untuk mencocokkan repo. Sumber di bawah adalah dokumentasi primer hasil Context7 atau halaman resmi yang dibaca langsung.

## Transaksi, locking, dan retry

- **Fakta Laravel:** `DB::transaction` commit ketika closure sukses dan rollback ketika exception. Argumen `attempts` memungkinkan retry deadlock. `lockForUpdate()` dianjurkan di dalam transaction. [Database Laravel 13.x](https://github.com/laravel/docs/blob/13.x/database.md), [Query builder Laravel 13.x](https://github.com/laravel/docs/blob/13.x/queries.md).
- **Fakta PostgreSQL 16:** Read Committed adalah default; `SELECT` biasa tidak melindungi read-modify-write. UPDATE dan SELECT FOR UPDATE menunggu perubahan konkuren dan menggunakan versi terbaru setelah commit; predicate WHERE diperiksa ulang. Repeatable Read/Serializable bisa memerlukan retry seluruh transaction. [Transaction isolation](https://www.postgresql.org/docs/16/transaction-iso.html).
- **Fakta PostgreSQL 16:** FOR UPDATE menahan writer/locker pada baris yang sama sampai transaction selesai. Deadlock dapat terjadi bahkan melalui row lock; PostgreSQL membatalkan salah satu transaction. Dokumentasi menganjurkan urutan lock konsisten dan transaksi pendek. [Explicit locking](https://www.postgresql.org/docs/16/explicit-locking.html).
- **Rekomendasi desain:** ledger insert, saldo tersedia, status topup/debit, claim pembayaran, dan outbox harus commit atomik. Pakai lock per akun/customer-currency dan unique idempotency key di database. Pemeriksaan saldo dan debit berada dalam transaction yang sama; tambahkan guard UPDATE `balance >= debit` dan CHECK saldo nonnegatif. Jika operasi melibatkan order/stock, tetapkan satu urutan lock lintas modul. Retry terbatas hanya error konkuren yang diidentifikasi; closure harus bebas side effect jaringan. Constraint unik tetap diperlukan karena lock baris yang belum ada tidak melindungi concurrent insert.

## Queue dan durability

**Fakta Laravel:** job dapat berjalan sebelum parent transaction commit. `after_commit=true` atau `dispatch(...)->afterCommit()` menunda dispatch; job dibuang jika transaction rollback. [Jobs and database transactions](https://laravel.com/framework/docs/13.x/queues#jobs-and-database-transactions).

**Rekomendasi desain:** afterCommit membantu visibility, tetapi bukan bukti atomic durability antara commit database dan pengiriman Redis. Simpan event outbox bersama posting ledger; worker mengirim notifikasi/fulfillment dengan delivery idempotent. Jika worker mati setelah commit sebelum ACK, redelivery tidak boleh menggandakan kredit. Poll/reconcile dibatasi chunk, waktu, retry/backoff, dan checkpoint; panggilan provider/RPC berada di luar lock saldo.

## Presisi nominal dan chain

**Fakta PostgreSQL:** integer/bigint exact; numeric exact, sedangkan floating point inexact. Numeric dengan scale dapat membulatkan input. [Numeric types](https://www.postgresql.org/docs/16/datatype-numeric.html). **Fakta ERC-20:** `decimals()` bersifat optional dan menjelaskan pembagian nilai unit integer token; event Transfer memuat sender, recipient, dan value. [ERC-20 standard](https://eips.ethereum.org/EIPS/eip-20).

**Rekomendasi desain:** simpan IDR sebagai rupiah integer dan USDT sebagai micro-USDT integer (1 USDT = 1.000.000 unit internal), cocok dengan `BinanceUsdtAmount`. Parse string decimal dengan aritmetika exact dan batas overflow; larang float di jalur uang. Decimal internal 6 bukan asumsi semua kontrak chain berdecimal 6. Repo BSC memeriksa metadata token dan default expected decimals 18 melalui `BscReadiness`; `BscTokenAmount` mengonversi micro-USDT. Snapshot chain ID, contract, decimals, receiver, expected amount; raw chain uint256 tetap string/BigInteger. Nominal dengan presisi tak terwakili jangan dibulatkan diam-diam: tahan untuk review atau reject sebelum pembayaran.

## Binance dan finality: batas penggunaan

**Fakta Binance Wallet API:** deposit history tersedia di `/sapi/v1/capital/deposit/hisrec` dan mengembalikan ID deposit, string amount, coin, network, address/tag, txId, status, confirmTimes, unlockConfirm, serta travelRuleStatus. Status 1 berarti sukses; status 6 adalah credited tetapi belum bisa withdraw. Network config menyediakan depositEnable, contractAddress, minConfirm, unLockConfirm, depositDust, dan batas withdrawal. Deposit address tanpa network memakai network default. Endpoint riwayat memiliki pagination dan batas window. [Capital API](https://developers.binance.com/en/docs/catalog/core-trading-wallet/api/rest-api/capital).

**Batas:** ini data wallet akun Binance, bukan bukti dukungan wallet kustodian per customer. Jangan menganggap adapter ini sama dengan Binance Pay yang dipakai repo. Kelayakan akun, negara, penggunaan dana pihak ketiga, dan layanan merchant perlu bukti provider sebelum aktivasi. Tidak ada nilai minimum, fee, decimals, atau confirmations USDT yang disimpulkan dari contoh API.

**Fakta Binance Pay Merchant:** webhook wajib diverifikasi dengan public key Binance Pay; signature memakai timestamp, nonce, body mentah, LF, RSA/SHA256. [Webhook common rules](https://developers.binance.com/en/docs/products/binance-pay-merchant/webhook-common). **Rekomendasi:** ini hanya berlaku bila integrasi memang merchant webhook tersebut. Tambahkan replay protection, unique event claim, dan server-side reconciliation; screenshot/hash customer bukan otoritas kredit.

**Fakta repo, bukan jaminan kontrak provider:** `src/app/Services/Binance/BinancePayClient.php` mengambil histori C2C melalui `/sapi/v1/pay/transactions`, menandatangani request HMAC-SHA256. `BinancePayTransaction` membedakan `orderId` dan `transactionId`; identifier yang dimasukkan customer dicocokkan, sedangkan posting memakai transaction ID provider. Kontrak C2C tidak boleh dianggap identik dengan Merchant API atau Capital deposit API. Upaya membaca halaman resmi C2C transaction-history pada pemeriksaan ini tidak berhasil; status akhir, direction, receiver/payer identity, cakupan uniqueness transaction ID, pagination, retensi, dan kelayakan akun untuk topup wallet perlu validasi resmi provider sebelum adapter diaktifkan. Hasil pencarian SDK pihak ketiga tidak digunakan sebagai bukti kontrak.

**Rekomendasi identitas pembayaran:** claim canonical memakai namespace provider + identitas akun penerima + transaction ID untuk C2C, dan chain ID + tx hash + log index untuk on-chain. Tambahkan alias claim bila satu deposit bisa muncul melalui dua adapter agar tidak dikreditkan ganda. Canonical claim tidak membuktikan kepemilikan customer: customer mengetahui hash/order ID publik belum tentu pengirim. Attribution memerlukan invoice/reference/alamat unik yang dibind ke customer, atau metode verifikasi kepemilikan pengirim yang didukung provider. Ambiguity masuk review; jangan menganggap sender address blockchain adalah identitas manusia atau ID akun exchange.

**Fakta BNB Chain:** RPC mendefinisikan tag `safe` dan `finalized` serta finality API; tersedia mekanisme fallback probabilistik. [BSC API list](https://docs.bnbchain.org/bnb-smart-chain/developers/json_rpc/bsc-api-list/). **Rekomendasi:** pastikan RPC yang dipilih mendukung finality yang ditetapkan, cocokkan canonical block hash dan receipt Transfer log. Reorg/RPC ambiguity menahan kredit; policy confirmation harus eksplisit dan diuji, bukan diasumsikan dari tampilan explorer. Satu transaksi bisa berisi beberapa log: identitas bukti on-chain perlu chain ID + tx hash + log index, dengan claim yang mencegah pemakaian silang checkout/topup.

Tidak ada token contract USDT BSC atau angka decimals yang disertifikasi oleh riset ini. Nilai expected decimals 18 adalah konfigurasi/verifikasi repo, bukan bukti semua aset bernama USDT mempunyai issuer, hak redemption, atau unit yang sama. Sebelum rollout, rekam sumber resmi asal token/issuer atau bridge, chain ID, allowlisted contract, `decimals()` aktual dari RPC, dan persetujuan provider custody; symbol/name token atau explorer berlabel USDT tidak cukup. Jangan menyalin waktu blok/angka confirmations dari contoh dokumentasi sebagai policy permanen.

## Authorization dan integritas transaksi

**Fakta rekomendasi OWASP:** authorization dianjurkan dengan least privilege, default deny, dan pemeriksaan permission pada setiap request; UUID/identifier yang sulit ditebak tidak menggantikan kontrol akses. [Authorization Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html).

**Fakta rekomendasi OWASP:** transaction authorization harus ditegakkan di server; data signifikan perlu dibind ke otorisasi, transisi tidak boleh dilompati, perubahan data menginvalidasi authorization, dan kredensial/challenge dibatasi waktu serta unik per operasi. [Transaction Authorization Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Transaction_Authorization_Cheat_Sheet.html).

**Penerapan desain, bukan klaim keamanan yang sudah teruji:** callback Telegram mengacu pada intent milik actor server-side, dengan nonce sekali pakai, TTL, customer/chat ownership, dan snapshot currency/amount/destination. Konfirmasi user menampilkan total nominal, currency, serta biaya sebelum debit. Admin adjustment dan review topup memakai permission terpisah, alasan, reauthentication/MFA untuk aksi berisiko, dan dual approval sesuai policy yang eksplisit; spec ini mengusulkan dual approval untuk semua penambahan manual IDR. Approval membind request immutable; perubahan nominal/customer membatalkan approval sebelumnya. Screenshot hanya lampiran dan diakses privat; validasi tipe/ukuran, scanning, signed download URL berumur pendek, serta masking PII diperlukan. Limit percobaan/order-ID/tap/reconcile membatasi penyalahgunaan, tetapi database claim/transaction tetap sumber kebenaran.

## Bukti yang belum tersedia dan keputusan terbuka

- Dokumen provider yang secara eksplisit mengizinkan penerimaan dan penyimpanan dana pihak ketiga, cakupan negara/customer, penanganan travel rule, pembekuan akun, dan refund; keberadaan API saja tidak menjawab ini.
- Penilaian hukum dari sumber regulator terkini dan counsel untuk model usaha Indonesia: store credit IDR, custody/kewajiban USDT, penggunaan saldo USDT untuk checkout, transfer, dan redemption mempunyai implikasi berbeda. Riset teknis ini tidak menyatakan salah satunya sudah mendapat izin. Aktivasi setiap kemampuan harus mengikuti keputusan tertulis per kemampuan; rancangan teknis dapat dilanjutkan tanpa mengaktifkan layanan.
- Provider custody dan payment rail IDR, support finality RPC pada endpoint produksi, token contract/issuer, biaya/limit topup, bukti attribution customer, retention audit/PII, serta target throughput/SLO yang akan dipakai sebagai gate rollout. Default rekomendasi spec harus disebut sebagai default usulan, bukan keputusan bisnis yang sudah disetujui.

## Gate sebelum implementasi dan aktivasi

Rekomendasi berikut berasal dari analisis risiko, bukan janji dokumentasi bahwa semua issue akan hilang:

1. Test PostgreSQL nyata dengan koneksi/proses terpisah: dua debit saldo terakhir, callback ganda + reconcile, approval bersamaan, payment diklaim checkout/topup, deadlock/retry, rollback, dan worker crash.
2. Pastikan authorization customer berdasarkan actor Telegram/session server; Admin adjustment memerlukan permission, alasan, audit append-only, dan idempotency. Endpoint tidak menerima saldo hasil hitungan client. Reversal adalah posting baru, bukan edit histori.
3. Test overflow, negative/zero, notasi ilmiah, excess precision, chain/token/network salah, replay, invalid signature, status belum final, dan perubahan setting setelah intent dibuat.
4. Ukur lock wait, throughput, panjang backlog, p95/p99, serta waktu reconcile pada dataset produksi representatif. Jangan lock satu akun clearing global pada semua transaksi; itu berpotensi menjadi bottleneck. Pelaporan aggregate dibaca dari ledger/projection terpisah dan bukan scan penuh setiap tampilan saldo.
5. Bukti custody/settlement dan policy refund harus jelas. Tidak ada pencampuran IDR/USDT atau kurs otomatis; ledger tiap currency berdiri sendiri. Peluncuran terbatas memerlukan rekonsiliasi saldo-ledger dan kewajiban customer terhadap aset settlement.
