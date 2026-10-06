# H0: kebijakan operasional saldo dan staffing approval

Status: **Disetujui sebagai kebijakan operasional H0-v1**, 2026-10-04. Issue [#538](https://github.com/Alhiefikri/telegram-bot/issues/538), bagian dari [#536](https://github.com/Alhiefikri/telegram-bot/issues/536). Prerequisite #537 CLOSED saat diperiksa. Kebijakan ini belum implementasi runtime atau izin deploy/menerima dana produksi.

Owner keputusan: **Owner toko, pengguna yang memberi mandat dan menjawab interview #538 dalam thread ini**. Tidak ada Admin/Finance/second approver. Jangan mengarang application User ID, Telegram ID Owner, nama approver kedua, password atau credential. Implementer memetakan actor Owner terautentikasi dan recipient Telegram existing dari sumber server yang sah; mapping dan uji authorization wajib sebelum aktivasi.

## Bukti persetujuan

Owner menyatakan hanya mengoperasikan toko sendiri; meminta pengiriman IDR manual tanpa batas bisnis, dengan password, preview exact Telegram ID/username, full reversal, history dan notifikasi. Setiap pilihan dijawab dalam interview tertulis. Owner menjawab **“setuju”** setelah review [ADR-0020](adr/0020-single-owner-balance-adjustments.md) dan residual risk tanpa pemeriksa kedua, tanpa batas nominal, serta tanpa mandatory payment proof.

Pada review paket akhir H0-v1, Owner memberikan persetujuan dan mandat: **“oke silahkan ga usah ada intervie wlagi kamu aja yang bikin planningh intinya pastikan tidak ribet flownya dan aman dari race condtion. performance leak dan security issue udah itu aja a.”** Ini persetujuan paket operasional dan pendelegasian rincian planning teknis; bukan bukti provider/legal capability, implementasi atau izin produksi. Tanggal keputusan/persetujuan: 2026-10-04. Sumber bukti: jawaban user dalam thread implementasi #538; ringkasan ini harus disertakan pada PR/issue tanpa secrets.

## Decision record per field

Semua row memiliki owner dan tanggal eksplisit. “Interview + H0-v1” berarti persetujuan tertulis individual serta mandat final di atas; “H0-v1” berarti usulan pelengkap paket akhir diterima oleh mandat itu. Gate implementasi/provider yang disebut bukan placeholder nilai bisnis H0 dan tidak boleh diubah menjadi approval palsu.

| ID | Field | Nilai/keputusan | Owner | Tanggal | Bukti approval |
| --- | --- | --- | --- | --- | --- |
| S1 | Staffing | Single-Owner exception ADR-0020; tidak ada global Owner bypass | Owner toko | 2026-10-04 | Interview + linked ADR + H0-v1 |
| S2 | Maker/operator | Owner saja; actor dari session server, tanpa Admin/Finance/backup | Owner toko | 2026-10-04 | Interview + H0-v1 |
| S3 | Approver | Tidak ada approver kedua; per-action Owner password menggantikan distinct actor hanya dalam scope exception yang diuji | Owner toko | 2026-10-04 | Interview + linked ADR + H0-v1 |
| S4 | Confirmation/security | Password setiap kirim/full reversal; 5 gagal dalam 15 menit → lockout konfirmasi actor 15 menit, satu alert per episode; tidak reuse global password-confirmed session | Owner toko | 2026-10-04 | Interview + H0-v1 |
| S5 | Authority | Owner view/request/confirm/reverse/policy/pause/resume/review; tanpa fallback operator; custody qualification tetap H1 | Owner toko | 2026-10-04 | H0-v1 |
| I1 | IDR manual amount/quota | Integer IDR positif, tanpa maksimum atau daily business quota tambahan; representasi/overflow dan technical anti-abuse tetap dibatasi | Owner toko | 2026-10-04 | Interview + H0-v1 |
| I2 | Purpose/reason/evidence | Dana masuk dan refund/manual case; alasan wajib, proof/reference tidak wajib untuk unrecorded case; bonus/promosi tidak diminta; Owner-attested adjustment bukan verified provider funding | Owner toko | 2026-10-04 | Interview + H0-v1 |
| I3 | Preview/expiry | 5 menit sejak preview dibuat; binding actor/Customer/amount/reason/source/nonce/version; perubahan input atau expiry perlu preview baru | Owner toko | 2026-10-04 | Interview + H0-v1 |
| I4 | Reversal | Seluruh nominal original sekali; password baru, linked immutable debit, sufficient available funds; insufficient → review tanpa saldo negatif/partial debit | Owner toko | 2026-10-04 | Interview + H0-v1 |
| I5 | Funding IDR existing | Pertahankan policy/preset/provider/deadline existing; tidak mengganti IDR ledger atau mengubah provider/history lama | Owner toko | 2026-10-04 | H0-v1 |
| U1 | USDT amount/preset | Input manual tanpa preset dan tanpa business min/max; positif, maksimal 6 desimal, exact integer micro-USDT, tanpa float/rounding/overflow | Owner toko | 2026-10-04 | Interview + H0-v1 |
| U2 | Per-Customer exposure | Tanpa business cap; available liability dan pending/ambiguous funds tetap dicatat/reconcile, bukan integer tak terbatas | Owner toko | 2026-10-04 | Interview + H0-v1 |
| U3 | Aggregate exposure | Tanpa business cap/preallocated beta quota; supersede proposed cap policy saja, bukan receipt/atomicity/backing constraints; risiko liability tidak berbatas bisnis diterima melalui H0-v1 | Owner toko | 2026-10-04 | Interview + H0-v1 |
| U4 | Open funding/anti-abuse | Satu active USDT funding target per Customer lintas Binance/BSC; unresolved payment/create/finality tetap held; technical request limits/budgets reuse bounded infrastructure | Owner toko | 2026-10-04 | Interview + H0-v1 |
| U5 | TTL/deadline | Konfirmasi nominal 10 menit; deadline transaksi snapshot durasi Binance/BSC dari setting dashboard existing; expiry tidak membuktikan tidak ada uang | Owner toko | 2026-10-04 | Interview + H0-v1 |
| B1 | Audience | Semua Customer bot terdaftar, tanpa allowlist; unknown Telegram ID tidak auto-create Customer; rail tetap melewati provider/security gates | Owner toko | 2026-10-04 | Interview + H0-v1 |
| F1 | Fee aplikasi | 0 USDT untuk Binance Pay ID dan USDT BEP20 di BSC; tampilkan requested credit dan exact amount yang harus diterima | Owner toko | 2026-10-04 | Interview + H0-v1 |
| F2 | Fee payer/provider | Biaya transfer Binance/jaringan BSC ditanggung Customer; tidak menambah fee aplikasi. Custody/treasury/return cost dan capability dikualifikasi H1/A1, bukan diasumsikan gratis/tersedia | Owner toko | 2026-10-04 | Interview + H0-v1 |
| A1 | Accounting responsibility | Owner accountable, tanpa Finance fiktif; typed funding/refund/Owner-attested adjustment punya counterpart per currency; chart/asset evidence dibuktikan U1/U2/H1, tanpa synthetic provider receipt | Owner toko | 2026-10-04 | H0-v1 |
| R1 | Money/history retention | Riwayat kirim/full reversal selama toko beroperasi tanpa auto-delete; USDT mengikuti activity IDR existing. Ledger/receipt claims/resolutions minimum tetap retained untuk accounting dan dedup | Owner toko | 2026-10-04 | Interview + H0-v1 |
| R2 | Evidence/privacy retention | Tidak ada proof-upload wajib; simpan minimal sanitized verifier/accounting evidence, tanpa password/full Telegram payload; existing log/security retention dipertahankan; kebutuhan custody/provider khusus H1 sebelum aktivasi | Owner toko | 2026-10-04 | H0-v1 |
| T1 | Terms | H0-v1: saldo belanja IDR/USDT terpisah, tanpa withdrawal/transfer antar-user/implicit FX; disclosure fee/network/exact amount sebelum confirmation | Owner toko | 2026-10-04 | Interview + H0-v1 |
| T2 | Exceptions/support | Mismatch masuk support existing, Customer kirim bukti, Owner settle sesuai verified received funds/source; tanpa automatic credit/FX/refund atau fixed response SLA baru. External return yang sah tetap qualified review H1 | Owner toko | 2026-10-04 | Interview + H0-v1 |
| O1 | Pause/incident/resume | Account drift → freeze mutation + Owner alert; aggregate/systemic drift → pause new funding; in-flight work tetap direconcile. Owner resume setelah konsistensi terbukti dan diaudit; dedup event-driven alerts | Owner toko | 2026-10-04 | Interview + H0-v1 |

Technical throttle/batch/query limits dipilih dan dibuktikan implementer dalam ruang lingkup mandat keamanan/performa, bukan dijadikan ENV business knobs. Semua nilai policy runtime baru berada di DB/Admin. Application password/actor/Telegram mapping harus diverifikasi server; tidak menambah label identitas fiktif ke record.

## Flow yang disetujui

**Pengiriman manual IDR:** exact Telegram ID → Customer ditemukan → nominal dan alasan → preview ID/username jika tersedia/nominal → password Owner → satu effect ledger + history + notifikasi. Unknown ID berhenti di not-found. Username tersimpan adalah tampilan dan dapat berubah; bukan ownership/lookup key. Tidak ada form set-balance, daftar second-approver, mandatory proof upload, atau kategori accounting teknis dalam UI.

Jika tindakan menyelesaikan Top-up/refund yang sudah tercatat, kaitkan ke source original dan gunakan satu resolution bersama callback/poll/refund otomatis. Jangan credit independent adjustment dan meninggalkan target original creditable. Kasus tanpa record tetap manual Owner-attested; idempotency mencegah replay request yang sama, tetapi tanpa reference tidak dapat membuktikan dua request berbeda bukan pembayaran fisik yang sama. Risiko ini diterima dalam ADR-0020.

**Pembatalan:** pilih pengiriman original di history → preview full reversal → password Owner → debit tertaut sekali jika saldo cukup. Entry original tidak diedit/dihapus. Bila saldo kurang, tampilkan review; linked recovery tidak dibuka lagi agar callback terlambat tidak recredit.

**Customer USDT funding:** nominal manual → konfirmasi nominal/terms (10 menit) → pilih Binance Pay ID atau USDT BEP20 BSC yang qualified → instruksi exact token/network/receiver/received amount/deadline → verification → satu credit + notification. Pilihan rail boleh terjadi sebelum konfirmasi jika seluruh pilihan dibinding dalam preview; tidak boleh provider invoice/ledger effect sebelum confirmation. Satu active target lintas rail; membuka top-up lagi kembali ke target yang sama.

Input menerima satu separator koma atau titik sebagai desimal, tanpa grouping ribuan/mixed separators/scientific notation/tanda negatif. Contoh 1,5 dan 1.5 → 1.5 USDT. Reject >6 fractional digits atau format ambiguous, tidak round/truncate. Normalized amount selalu muncul di preview. Raw token units konversi exact ke micro-USDT dengan remainder=0; simbol USDT saja tidak membuktikan token.

**Tracking:** dashboard Owner default hanya Customer dengan available IDR atau USDT >0, identitas Telegram dan dua amount terpisah. Filter tidak menghapus zero account/history. Paginate/query indexed dan bounded. Riwayat USDT meniru activity IDR existing; baseline StoreBalanceActivityService memakai lima item per halaman dengan cursor/limited columns. Jangan load seluruh Customer/ledger ke browser atau worker.

## Terms customer H0-v1

Saldo Rupiah dan USDT merupakan saldo belanja toko yang terpisah. Saldo tidak dapat ditarik atau ditransfer ke user lain dan tidak dikonversi otomatis. Top-up USDT tidak dikenai fee aplikasi toko; biaya transfer Binance/jaringan BSC ditanggung pengirim. Gunakan metode yang dipilih, token USDT yang benar dan nominal pembayaran persis seperti instruksi. Pembayaran tidak cocok tidak dikreditkan otomatis; hubungi support dengan bukti untuk pemeriksaan Owner. Masa konfirmasi nominal 10 menit; deadline pembayaran mengikuti instruksi transaksi. Pembayaran yang masih diperiksa belum menjadi saldo tersedia. Pemberitahuan saldo masuk/pembatalan dikirim melalui bot.

Teks ini adalah approved product terms H0-v1; bukan legal/provider qualification H1. Ketiadaan fitur withdrawal tidak dianggap menghapus penanganan external return yang sah. Snapshot terms version/policy dilakukan downstream.

## Syarat teknis dan residual risk

- Customer/actor/target/currency/amount/version/fingerprint direvalidasi di service owner di bawah row locks; unique source/receipt/request identity melindungi money. PostgreSQL authoritative, Redis bukan ledger/receipt authority.
- Atomic posting meliputi ledger/projection/source resolution/accounting/audit/durable notification obligations. Tidak ada Telegram/HTTP/RPC/custody side effect dalam retried transaction. Identity dan lock graph mengikuti A0; jangan lock Customer setelah Order/account secara ad hoc.
- Customer wajib diberi notifikasi credit/reversal; Owner menerima setiap manual credit/reversal dan lockout. Reuse Telegram Outbound Effect, recovery dan ambiguity/manual review existing. Failed notification tidak undo/repeat money.
- Alert sekali per transition/episode, attempts dihitung pada request dan expiry timestamp; tanpa scheduler per attempt/Customer. Recovery existing memakai indexed bounded batches/backoff; buktikan query/worker/memory overhead.
- Receiver ownership/finality dan stable economic receipt global harus qualified. Checkout amount-unique/shared-address matching tidak menjadi attribution funding. Binance C2C/history tidak diasumsikan Merchant invoice binding. Salah token/network/receiver/Customer, mismatch amount, duplicate receipt/hash atau stale/fake callback tidak auto-credit.
- Single Owner/password/audit tidak mengganti independent review jika session/password Owner diambil alih. Uncapped nominal/exposure menambah potensi liability; unlinked manual requests tidak memiliki dedup physical receipt tanpa reference. Owner menerima desain dan risikonya, sedangkan security/restore/accounting proof tetap mandatory.
- H0 policy secara eksplisit menggantikan default draft dual-approval dan bounded-cap/beta policy dari #536/ADR-0019. Semua invariants lain tetap: no float/FX, immutable IDR history, global receipts, customer ownership, exact currency, source idempotency, durable work, original-currency refund entitlement, no keys in app.

## Handoff dan verification

[Implementation plan H0-v1](superpowers/plans/2026-10-04-balance-h0-implementation-plan.md) memetakan tahap kecil, gates, tests dan bukti performa. [ADR-0020](adr/0020-single-owner-balance-adjustments.md) menetapkan exception dan acceptance tambahan. H0 menyelesaikan keputusan/control specification; I1/I2/I3/U3/O2/V1/V2 membuktikan implementation acceptance sebelum UI/rail aktif. H1/A1/H2 tetap provider/custody/production gates; tidak ditutup oleh interview atau scaffold.

Context7: [Laravel 13 transactions](https://github.com/laravel/docs/blob/13.x/database.md), [pessimistic locking](https://github.com/laravel/docs/blob/13.x/queries.md), [authorization](https://github.com/laravel/docs/blob/13.x/authorization.md), [password confirmation](https://github.com/laravel/docs/blob/13.x/authentication.md), [hash verification](https://github.com/laravel/docs/blob/13.x/hashing.md); [PostgreSQL 16 conflict handling](https://www.postgresql.org/docs/16/sql-insert.html) dan [isolation](https://www.postgresql.org/docs/16/transaction-iso.html). Docs Brick Math main diverge dari installed 0.18.0; plan mengacu installed composer.lock/vendor signatures untuk exact arithmetic, bukan menerima main API sebagai bukti kompatibilitas.

H0 checks: seluruh 24 fields mempunyai nilai/owner/date/approval evidence; approved exception/control specification ada; exact-ID/no-auto-create disetujui; runtime DB/Admin boundary terdokumentasi; source/local links dan diff whitespace diverifikasi. Tidak menjalankan runtime/concurrency/benchmark untuk docs-only patch dan tidak mengklaim tests tersebut lulus. PR harus memuat checks/limitasi ini. Application mapping/provider/storage/runtime performance adalah bukti downstream sebelum aktivasi, bukan claim H0 telah terimplementasi.
