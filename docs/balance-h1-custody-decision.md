# Keputusan H1: custody dan Customer USDT Balance (#539)

Tanggal: 2026-10-04. Pemilih pendekatan: agent, atas instruksi Owner untuk memutuskan #539. Status: **pendekatan desain dipilih; qualification H1 belum lulus; aktivasi no-go**. Ini bukan tanda tangan counsel, kontrak provider, atau bukti penguasaan wallet.

## Keputusan

Pilih **verified operator-controlled public receivers eksklusif per Customer**, dengan penandatanganan transaksi di perangkat/wallet eksternal yang dikendalikan Owner. Aplikasi hanya menyimpan alamat publik dan bukti verifikasi; seed, private key, signing capability dan backup key tidak masuk aplikasi, database, log, CI atau VPS. Jalur desain pertama adalah BSC karena verifier sudah ada, tetapi token/bridge dan RPC tetap harus qualified sebelum adapter funding nyata diintegrasikan atau diaktifkan.

Receiver funding terikat permanen ke satu Customer; tidak pernah direcycle, dialihkan ke Customer lain, atau dibagi dengan checkout. Rotasi menambah receiver baru untuk Customer yang sama dan mempertahankan pemantauan receiver lama. Unique registry lintas checkout/funding harus menolak overlap termasuk receiver historis. Satu alamat deposit bersama dengan nominal unik tidak dipilih: itu tidak memenuhi atribusi receiver eksklusif H1. Bukti kontrol receiver mengidentifikasi pengendali wallet; pembayaran ke receiver mengatribusikan target Customer, bukan otomatis membuktikan identitas manusia pengirim.

**Binance Pay ID tetap tidak qualified untuk funding.** Keputusan ini tidak menghapus pilihan produk H0-v1; kemampuan itu tetap menunggu bukti resmi binding Customer/invoice, izin menerima dan menyimpan dana pihak ketiga, serta kontrak verifikasi A1. Tidak mengganti C2C/history dengan asumsi Merchant API. Custody provider terkelola hanya dipertimbangkan kembali jika kontrak, negara, kapasitas dan segregasi funds benar-benar terbukti; tidak memilih nama vendor dengan menyamakan keberadaan API dengan izin custody.

Alasan: pendekatan ini memakai seam verifikasi BSC yang ada, menghindari kunci dalam aplikasi, dan memberikan atribusi funding yang dapat dibuktikan tanpa memaksakan model akun exchange biasa. Trade-off nyata adalah provisioning alamat, backup/signing eksternal, gas tiap receiver, rekonsiliasi treasury dan return manual. Kapasitas belum terbukti; keuntungan desain bukan bukti kesiapan operasi.

## Model funds dan treasury yang dipilih

Customer USDT Balance adalah kewajiban toko dalam micro-USDT, terpisah dari Store Balance IDR. Custody oleh operator tetap custody dana Customer, walaupun aplikasi hanya watch-only. H0-v1 tetap berlaku: input manual, fee aplikasi 0, tidak ada business amount/exposure cap, withdrawal, transfer antar-user atau FX implicit. Tidak menambahkan cap terselubung atau mempersempit audience H0-v1.

Pilih backing **1:1 dalam aset/token yang sama**, dipisahkan secara operasional dari dana belanja Supplier dan dana operasi. Accounting memisahkan available liability, unresolved/pending receipts dan external-return obligations; seluruh kewajiban harus tercakup dalam rekonsiliasi. Tidak lending, staking, bridging atau konversi otomatis atas backing. Nilai pasar USD saja tidak membuktikan penggantian token setara. Kekurangan backing menahan funding/spend untuk review; bukan kredit atau debit sintetis.

Sweep dan external return ditandatangani di luar aplikasi. Catat transaksi keluar sebagai treasury movement, bukan top-up Customer baru. Biaya gas/sweep/return disediakan Owner sebagai biaya operasi; fee transfer masuk pengirim mengikuti H0-v1. Ini pemilihan penanggung biaya, bukan klaim gas gratis atau dana tersedia. Kekurangan gas/kunci/alamat tidak boleh memunculkan instruksi payable baru. Rekonsiliasi dan recovery dana yang sudah masuk tetap berjalan ketika funding paused.

External return yang sah adalah penyelesaian case, bukan fitur withdrawal umum: bind original receipt/Customer/currency/amount, verifikasi tujuan return melalui proses privat (sender on-chain bisa hot wallet exchange), konfirmasi Owner per tindakan dan simpan bukti final serta satu resolution identity. Jangan auto-return ke `from`, meminta private key pengirim, atau credit sekaligus refund. SLA, gas reserve dan kemampuan signer harus dibuktikan sebelum activation; tidak mengarang operator cadangan.

## Evidence resmi dan batas kesimpulan

1. [Binance Capital API](https://developers.binance.com/en/docs/catalog/core-trading-wallet/api/rest-api/capital) mendokumentasikan pembacaan deposit address/list/history akun. Context7 resolve `Binance Wallet API` lalu query `/binance/binance-api-postman` juga menunjukkan lookup alamat berdasarkan coin/network. Tidak ditemukan pada kontrak endpoint tersebut jaminan provisioning permanen satu receiver per Customer atau izin third-party stored funds. Ini batas evidence yang diperiksa, bukan klaim semua produk Binance tidak mungkin memenuhi kebutuhan.
2. [Tether supported protocols](https://tether.to/en/supported-protocols/) pada pemeriksaan ini menampilkan XAUt di bagian BNB Smart Chain; tidak memberi sertifikasi native USDt untuk kontrak checkout `0x55d398326f99059ff775485246999027b3197955`. [BNB Chain Bridge V2](https://www.bnbchain.org/en/blog/binance-bridge-v2-release) menjelaskan native asset versus pegged token; [Binance Proof of Collateral](https://www.binance.com/en/proof-of-collateral) membedakan collateral bridge dari reserve exchange. Token BSC existing harus diperlakukan sebagai kandidat dengan risiko peg/bridge tambahan, bukan diasumsikan native Tether hanya karena simbol USDT. Provenance kontrak spesifik, decimals RPC, redemption path, depeg/freeze/bridge failure dan penerimaan risiko tertulis masih missing.
3. [Pernyataan BI mengenai virtual currency](https://www.bi.go.id/id/publikasi/ruang-media/news-release/Pages/sp_200418.aspx) menyatakan virtual currency bukan alat pembayaran sah di Indonesia. [PBI 23/6/PBI/2021](https://www.bi.go.id/id/publikasi/peraturan/Pages/PBI_230621.aspx) menjelaskan larangan PJP mengaitkan virtual currency dengan pembayaran. Keduanya adalah konteks resmi historis, bukan legal opinion mengenai penerapan aturan sekarang pada toko ini.
4. [PBI 10 Tahun 2025](https://www.bi.go.id/id/publikasi/peraturan/Pages/PBI_102025.aspx) adalah kerangka baru yang berlaku 31 Maret 2026. Ringkasan resmi berhasil dibaca; naskah PDF resmi gagal diambil pada pemeriksaan ini. Karena itu tidak menyatakan pasal lama tetap berlaku atau menyimpulkan pengecualian bagi closed-loop balance. Counsel harus memeriksa naskah lengkap/aturan pelaksanaan terkini, yurisdiksi operator/Customer dan model penyimpanan USDT untuk belanja. Persetujuan H0, rekening exchange, alamat hardware wallet, atau tidak adanya withdrawal tidak menggantikan penilaian ini.

Kesimpulan desain: tidak ada evidence yang cukup untuk menyatakan penerimaan/penyimpanan/spend USDT di produksi qualified. Keputusan operasional saat ini adalah **no-go real funding/spend**. Ledger scaffolding, fake adapter dan test disposable dapat dilanjutkan sesuai dependency asli; tidak mengubah scope #536 menjadi IDR-only, tidak menutup H1 dengan fake tests, dan tidak mengubah checkout existing melalui dokumen ini.

## Acceptance register H1

| Requirement #539 | Keputusan / evidence yang diperlukan | Status saat ini |
| --- | --- | --- |
| Custody model | Public receiver per Customer; signer eksternal; alternatif provider tidak dipilih tanpa agreement | Desain dipilih |
| Receiver eksklusif, tidak dibagi checkout | Inventory publik receiver; proof-of-control dengan challenge domain/purpose-bound; registry global lintas receiver aktif/historis; rotasi tidak recycle | Belum dibuktikan |
| Izin provider / third-party funds | Untuk self-managed receiver: assessment model custody dan terms tiap layanan pendukung; untuk Binance rail: agreement/account eligibility resmi | Missing |
| Yurisdiksi/legal handling | Assessment tertulis pihak kompeten untuk entity, negara Customer, stored USDT, checkout, AML/KYC/Travel Rule bila relevan, freeze dan return; rujuk aturan terkini | Missing; activation no-go |
| Token issuer/bridge/redemption | Provenance resmi kontrak spesifik, decimals/finality, collateral/redemption path dan penerimaan risiko tertulis | Missing |
| Kapasitas alamat | Provisioning eksternal, kapasitas seluruh audience H0-v1, backup/recovery signer, exhausted inventory fail-closed; tanpa reassignment | Missing |
| Treasury backing/gas | Accounting dan reconciliation 1:1, aset custody terverifikasi, biaya/gas reserve, sweep/restore drill dan penanggung jawab Owner | Model dipilih; bukti missing |
| External refund/return | Verifikasi tujuan, exact amount/currency, original receipt global resolution, signing eksternal, cost/SLA dan recovery drill | Model dipilih; bukti missing |
| Owner review evidence | Review paket qualification tanpa secret/live transfer di lampiran publik; sign-off autentik Owner dan pihak kompeten | Belum tersedia |

#537 sudah CLOSED pada pemeriksaan. #539 tetap OPEN/needs-info karena acceptance qualification di atas belum terpenuhi. Keputusan pendekatan yang diminta Owner selesai; penyelesaian operasional ticket H1 belum selesai. Agent tidak mengarang agreement, receiver, transfer, saldo treasury, legal clearance atau persetujuan pihak lain.
