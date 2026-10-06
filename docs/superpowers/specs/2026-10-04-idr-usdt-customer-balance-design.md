# Spec besar: penambahan saldo Rupiah dan Customer USDT Balance

Issue canonical: [#536](https://github.com/Alhiefikri/telegram-bot/issues/536).

[Tiket implementasi dan dependency](../plans/2026-10-04-idr-usdt-customer-balance-tickets.md) — 26 child tickets; keputusan operasional/custody dan aktivasi tetap gated.

Tanggal: 2026-10-04. Status: **Draft untuk review produk/arsitektur; belum izin implementasi atau aktivasi produksi**.

Dokumen pendamping: [rencana implementasi](../plans/2026-10-04-idr-usdt-customer-balance-plan.md) dan [riset sumber primer](../../research/2026-10-04-idr-usdt-wallet-sources.md). Issues/PRD canonical dicatat di GitHub; dokumen ini merupakan lampiran versionable. Semua nama tabel/API baru di bawah adalah usulan. Angka SLO adalah target desain yang harus dibuktikan, bukan hasil benchmark.

## 1. Tujuan dan batas produk

Customer dapat menyimpan dua saldo terpisah, menambah saldo IDR melalui rail yang sudah tersedia, menerima penambahan IDR yang diotorisasi operator, serta top-up dan menyimpan USDT tanpa otomatis mengubahnya menjadi Rupiah. Saldo dapat dipakai untuk pembelian dalam currency yang sesuai dan dikembalikan dalam currency serta unit nominal debit asli. UI menunjukkan saldo, riwayat, status pembayaran, fee, dan instruksi yang tidak ambigu.

Scope inti:

- IDR: pertahankan Store Balance existing; tambah alur Admin penambahan saldo yang beralasan, terotorisasi, idempotent, dan diaudit.
- USDT: account dan ledger terpisah, funding intent, invoice/deposit, bukti pembayaran authoritative, settlement, riwayat, rekonsiliasi, operator review, dan checkout/refund USDT.
- Telegram private chat menjadi channel utama. Admin Dashboard mengelola kebijakan, review, dan laporan. Customer web terautentikasi mendapatkan read-only saldo/riwayat miliknya; write/funding/checkout web baru dibuka setelah ownership dan account-linking gate lulus. Guest invoice/recovery key order tidak memberikan akses wallet.
- Penambahan manual USDT tersedia hanya sebagai penyelesaian deposit terverifikasi yang masuk review; arbitrary USDT grant bukan bagian default.
- Currency conversion, transfer antar-customer, bunga, trading, withdrawal otomatis, chain lain, serta private-key custody di aplikasi tidak termasuk release ini. Saldo USDT adalah kewajiban toko yang didukung aset settlement, bukan private wallet non-custodial user. Ketentuan penggunaan wajib menjelaskan bahwa saldo dipakai belanja, risiko token/provider, dan proses penanganan pengembalian eksternal yang sah.

Keputusan yang diusulkan: mulai dengan **USDT BEP20 di BNB Smart Chain** karena verifier/readiness sudah ada. Binance Pay dapat menjadi rail kedua hanya jika merchant reference dan beneficiary/ownership attribution terbukti. Pemilihan custody/provider dan penerimaan dana customer harus diselesaikan sebelum rail menerima uang; tahap ledger dan UX dapat dikerjakan sebelumnya.

## 2. Baseline repo yang wajib dipertahankan

| Area | Bukti saat ini | Implikasi desain |
| --- | --- | --- |
| Stack | `src/composer.json`: PHP ^8.3, Laravel ^13.8, Brick Math ^0.18; PostgreSQL 16 di Compose | Exact arithmetic dan transaksi PostgreSQL, bukan float/Redis sebagai money authority |
| IDR | `StoreBalanceLedger`, `customer_balance_accounts`, `store_balance_entries`, ADR 0008 | Jangan membuat IDR wallet kedua atau mengubah entry lama |
| Funding IDR | `StoreBalanceFundingIntentService`, `StoreBalanceTopUpService`, lifecycle manual/KlikQRIS/Sakurupiah; ADR 0010 | Reuse owner, policy version, provider snapshot, fee separation |
| Late money | ADR 0011 dan `StoreBalanceOwnerCompensationService` | Settlement vs compensation bersaing pada identitas ekonomi yang sama |
| Checkout/refund | `OrderCheckoutService`, `OrderLifecycle`, partial refund/warranty | Debit dan Order/Payment/stock harus atomik; refund total tidak melampaui debit |
| Crypto external checkout | `BinancePaymentVerification`, `BscPaymentVerification`, `BscTokenAmount`, `BinanceUsdtAmount` | Reuse pure parser/inspection; verifier sekarang Order/Payment-bound, bukan top-up-ready |
| Payment ownership | Binance mengandalkan unique `payments.provider_reference`; BSC evidence masih Payment-bound | Constraint satu tabel tidak melindungi top-up di tabel berbeda; perlu claim global |
| Durability | Redis volatile; Telegram Outbound Effect durable di DB | Persist workflow/outbound work sebelum dispatch, recovery dari DB |
| Security identity | Customer Telegram dan customer web tanpa Telegram sama-sama mungkin | Customer ID dari actor terautentikasi, bukan parameter client |

Istilah **Store Balance** tetap IDR sesuai `CONTEXT.md`. Istilah baru **Customer USDT Balance** berarti saldo belanja USDT milik Customer; **USDT Funding Intent**, **USDT Top-up**, **External Money Receipt**, dan **Balance Adjustment Request** harus masuk glossary saat implementasi. Usulan ini memperluas ADR 0010 melalui ADR baru; tidak menulis ulang keputusan historis sebagai sudah diterima.

## 3. User flows dan aturan nominal

### 3.1 IDR

Menu Saldo menampilkan dua baris: `Saldo Rupiah Rp...` dan `Saldo USDT ... USDT`; setiap currency punya Top-up dan Riwayat sendiri. IDR menggunakan minimum/preset/custom, konfirmasi intent, fee dan final payable dari rail existing. Hanya requested credit yang masuk saldo. Pergantian payment provider tidak mengubah top-up lama.

Admin mencari Customer terdaftar melalui Telegram ID numerik exact pada Admin Dashboard, melihat ID immutable dan identitas penerima untuk verifikasi, lalu memilih `Tambah saldo Rupiah`. Telegram ID yang tidak ditemukan menampilkan not-found dan tidak membuat Customer otomatis; username bukan identitas canonical. Form memerlukan amount positif, alasan terstruktur + catatan, kategori dana (`funded_transfer`, `promotional_grant`, atau `correction`), external receipt bila relevan, dan idempotency nonce server. Preview menyebut customer, amount, sumber dana dan saldo sebelum. Tidak ada form “set balance”.

Maker dapat Owner/Admin/Finance dengan permission `balance.adjustment.request`; approver wajib Owner dengan `balance.adjustment.approve`, berbeda actor dari maker. Semua nominal perlu approval, bukan hanya di atas threshold. Owner tunggal tidak mendapat bypass default: fitur adjustment tetap tidak siap sampai second approver tersedia atau ADR secara eksplisit menetapkan policy single-operator beserta kompensasi kontrol. Bukti transfer dan receipt claim diverifikasi server; screenshot saja tidak membuktikan dana masuk. Grant/correction bukan top-up yang mengklaim provider sukses dan mempunyai counterpart accounting sendiri.

Request `pending -> approved/applied` atomik, atau `rejected/cancelled/expired`; satu application ledger untuk satu request. Editing amount/customer/reason setelah submit dilarang: cancel dan buat request baru. Replay approval menampilkan hasil yang sama. Koreksi entry memakai reversal baru, original entry reference, dual approval, dan available funds guard; jika uang sudah terpakai, tahan untuk review, jangan saldo negatif atau debit diam-diam.

### 3.2 USDT top-up

1. Customer private chat memilih Top-up USDT, network BSC, amount decimal dan menerima preview: requested credit, fee, total dikirim, receiver, token/network, minimum, deadline, serta warning network pada layar pembayaran.
2. Konfirmasi server membuat satu Top-up dari intent; tidak ada payment provider/ledger effect sebelum konfirmasi. Intent mengikat actor, currency, amount, policy version, chat/root, expiry, nonce. Rate limit dan batas open intents/invoices berlaku.
3. Adapter menyediakan reference/invoice/receiver yang unik dan dapat dikaitkan authoritative ke Customer. Timeout create menjadi `creation_unknown`; query reference yang sama sebelum create ulang, tanpa fallback diam-diam.
4. Customer membayar dan dapat mengirim tx hash sebagai petunjuk lookup. Server mengecek chain/contract/receiver, receipt sukses, Transfer event, amount exact, canonical block/finality, serta ownership invoice. Hash/screenshot/amount saja tidak boleh memberi kredit.
5. Tampilan `menunggu konfirmasi -> memeriksa pembayaran -> saldo bertambah` dengan receipt internal dan nominal yang benar. Pengiriman receipt gagal tidak membatalkan kredit. Retry customer tidak membuat invoice atau kredit kedua.

**Ownership attribution adalah gate keras.** Untuk BSC, receiver deposit ditetapkan eksklusif pada satu Customer dan tidak pernah dialihkan ke Customer lain, termasuk setelah intent expire. Receiver top-up tidak boleh masuk pool receiver checkout bersama. Global receiver-ownership registry membatasi `(chain_id, normalized_address)` lintas tabel receiver checkout dan top-up; unique index pada tabel top-up saja tidak cukup untuk melarang pemakaian silang. Alamat berbeda per intent/customer boleh disediakan custody provider, atau alamat publik operator yang diverifikasi kepemilikannya dapat dipetakan permanen dengan uniqueness DB; app tidak memegang private key. Jika kapasitas alamat tidak ada, rail menolak pembuatan invoice. Sender address tidak dianggap bukti ownership customer karena exchange dapat memakai hot wallet bersama.

Satu open intent per Customer/rail/receiver; event baru dikaitkan ke intent dengan expected amount dan payment window yang cocok. Deposit tanpa intent, kurang/lebih, multiple transfers, amount tidak representable, atau beberapa intent cocok masuk review pada **customer receiver yang sama**, tanpa otomatis assign ke customer yang mengirim hash. Tidak ada alamat bersama + nominal unik sebagai pengganti ownership. Address reuse lintas customer merupakan no-go.

Untuk Binance Pay, aktivasi hanya bila provider authenticated order/reference atau verified payer identity mengikat pembayaran ke intent/customer. API history sukses dengan nominal cocok saja belum cukup. Jika kontrak provider tidak menyediakan binding ini, rail tetap disabled dan BSC tetap menjadi scope USDT utama.

### 3.3 Penyimpanan, belanja, refund

Saldo USDT tersedia untuk checkout full payment berdasarkan pricing snapshot USDT existing dan quantity tiers. Tidak ada konversi dari total IDR atau partial tender. Payment memakai provider/method internal tersendiri (`usdt_balance` / `USDT_BALANCE` usulan), currency USDT, dan amount micro; tidak masquerade sebagai Binance external checkout. Order reporting membedakan IDR display total legacy dari payment USDT; admin/invoice/profit/refund tidak boleh menjumlahkan kedua currency. Item tanpa harga USDT valid tidak eligible.

Account lock dan debit terjadi di checkout transaction yang sama dengan Order, Payment, inventory reservation/lifecycle dan outbound intent. Supplier I/O dan Telegram berada di luar money transaction. Fulfillment failure mengikuti owner existing: refund entitlement terverifikasi menghasilkan credit currency asli, termasuk refund parsial; jumlah kumulatif refund <= original debit dan entitlement per unit. Refund tidak menghitung ulang harga/kurs.

## 4. Invariants dan model data

**I1** Satu IDR account existing dan maksimal satu USDT account per Customer; currency immutable. **I2** Semua money mutation melalui ledger owner; client tidak menulis balance. **I3** Amount positif, integer exact; available >= 0 dan <= cap. **I4** Ledger append-only dan projection commit bersama. **I5** Satu source/idempotency menghasilkan tepat satu effect; replay berbeda payload konflik. **I6** Satu external receipt tidak mendanai checkout dan top-up atau dua customer. **I7** Snapshot pembayaran tidak berubah setelah konfirmasi. **I8** Refund/reversal tertaut original effect dan cap. **I9** Status/UI/notification bukan otoritas uang. **I10** Projection mismatch membekukan mutation akun terkait. **I11** IDR dan USDT tidak saling mengubah balance. **I12** Bukti dana ambigu tidak mengkredit available balance.

IDR memakai tabel existing tanpa pemindahan saldo. USDT memakai tabel additive yang currency-specific pada release pertama; shared parser/protocol dapat diekstrak, tetapi tidak memigrasikan dua ledger sekaligus atau dual-write IDR. Satu façade read-only menggabungkan tampilan. Pilihan generalisasi ledger baru memerlukan ADR terpisah.

| Tabel usulan | Field/constraint utama |
| --- | --- |
| `customer_usdt_balance_accounts` | unique customer FK RESTRICT; balance_micro BIGINT CHECK >= 0; currency CHECK USDT; version, last_entry_id, frozen_reason |
| `customer_usdt_balance_entries` | account FK RESTRICT; account_sequence; kind; amount_micro BIGINT CHECK > 0; balance_after_micro CHECK >= 0; source_type/id; idempotency_key; request_fingerprint; original_entry/refund_order_id nullable; actor/reference; created_at |
| `customer_usdt_funding_intents` | opaque UUID, customer, amount_micro, policy_version, chat/root binding, hashed nonce, expires_at, state; claim identity unique |
| `customer_usdt_top_ups` | unique intent FK; immutable customer/currency/amount/fee/payable/rail/provider-account/reference/network/token/decimals/receiver/finality policy snapshot; status, attempt_version, provider create lease, next_check_at, settled_entry_id |
| `customer_usdt_deposit_receivers` | globally unique normalized chain+address (plus custody namespace where needed); verified operator custody; customer immutable FK; token/network; retired_at; never reassign |
| `external_money_receipts` | unique canonical identity; rail/provider account namespace; raw amount exact string; currency/token/network; tx/log/block evidence; accepted beneficiary; status; owner_type/id; fingerprint; immutable consumption record |
| `balance_adjustment_requests` | customer/currency; positive amount, effect direction/type; reason/funding category; receipt/original_entry; maker/approver; nonce/version; state; applied_entry_id; expiry |
| `customer_balance_policies` | per currency versioned limits, presets, exposure caps, expiry, rail readiness, pause controls; managed via Admin, not new ENV |
| durable workflow/review records | DB inbox/outbox, attempt version, lease expiry, next attempt, sanitized error, unique effect identity; reuse Telegram Outbound Effect seam |

USDT entry uniqueness: `(account_id, account_sequence)`, `(account_id, idempotency_key)`, `(source_type, source_id, kind)`; projection cursor/account linkage checked transactionally. Index `(account_id, id DESC)` for latest/history; `(state, next_check_at, id)` on work; unique reference scoped provider account. Refund original debit/source lookup indexed. FK deletion RESTRICT preserves financial history. PostgreSQL DB triggers/least-privilege writer roles reject ledger UPDATE/DELETE; model guards alone tidak cukup. Projection writes restricted to owner application path, constraints and reconciliation remain defense in depth. Separate migration/admin credentials from runtime role; document restore privileges.

Canonical receipt identity:

- Binance Pay: provider + stable receiving account namespace + authoritative transaction ID, normalized without truncation; account namespace survives credential rotation.
- BSC: chain_id + normalized tx_hash + log_index; contract/receiver/value are validated immutable evidence fields, **bukan namespace untuk membuat log yang sama bisa dipakai ulang**.
- Existing checkout may claim an entire BSC tx. Add unique **parent transaction claim** `(chain_id, tx_hash)` with owner target for legacy compatibility; all logs in a tx can belong only to the same target in this release. Different targets for two legitimate logs are held for review. Exact one-log settlement remains default; never sum unrelated logs.
- QRIS: provider + merchant account namespace + canonical transaction/reference sesuai kontrak adapter; webhook delivery ID bukan economic receipt ID.

Claim registry menjadi canonical lintas checkout/top-up/verified manual adjustment. Jika custody deposit API dan chain reader melihat transfer yang sama, simpan alias canonical ke receipt yang sama sebelum consumption; bila alias tidak dapat dibuktikan, hanya satu adapter boleh settle rail tersebut. Jangan namespace adapter sehingga satu dana fisik dapat dikredit dua kali. Backfill settled dan reserved legacy evidence serta compensated funding sources sebelum USDT activation; konflik/historical ambiguity menghentikan gate. Pending targets yang dapat menerima bukti selama cutover juga harus memakai registry. Tidak boleh membuka top-up ketika checkout masih menulis claim hanya pada tabel lama.

## 5. Presisi, fee, dan accounting

IDR = rupiah integer; USDT = 1.000.000 micro per USDT. UI/API money berupa string decimal canonical atau string unit integer + explicit currency, bukan JSON floating point. Input grammar ketat, panjang dibatasi; reject negatif/nol, NaN/Infinity, exponent, separator ambigu dan excess fractional nonzero. Parser menerima nilai representable exact; overflow sebelum PHP int/DB write dicegah dengan Brick Math dan cap bisnis. Chain raw uint256 disimpan BigInteger/string; snapshot token decimals diverifikasi server. BSC token konfigurasi repo mengharapkan 18 decimals, sehingga 1 USDT micro internal tidak sama dengan 1 raw token unit.

Model fee: `payable = requested_credit + separately_disclosed_fee` dalam currency yang sama bila provider mendukungnya. Untuk BSC fase awal, fee aplikasi 0 dan network gas dibayar pengirim di luar deposit; nominal Transfer exact = requested credit. Tidak membuat nominal tambahan unik. Fee/provider/network bukan saldo user; perubahan biaya tidak mengubah invoice existing. Fee deduction/overpayment bukan implicit rounding. Di review, settlement revised amount memerlukan customer-visible resolution, frozen original snapshot, unique receipt dan approved economic source baru; partial/multiple deposits tidak auto-credit release pertama.

Customer ledger adalah subledger kewajiban toko. Buat append-only accounting event per posting: top-up menaikkan aset settlement + liability, pembelian mengurangi liability dan mengakui sale sesuai existing accounting, refund membalik sale/liability, promo grant menaikkan expense + liability; correction mengikuti original event. Totals per currency memakai exact arithmetic. Tidak mengunci satu clearing-account global di setiap checkout; counterpart event dan subledger commit bersama, aggregate reporting diperbarui asinkron idempotent.

Rekonsiliasi terpisah: (a) projection vs signed ledger sum, (b) receipt/settlement vs ledger, (c) total customer liability + unresolved deposits vs aset custody/provider dan fee/movement operator. Aset settlement yang berpindah venue bukan hilangnya liability; treasury movement tercatat. Defisit/unknown custody evidence mempause funding/spend sesuai insiden, memicu alert, tanpa auto-adjust ledger.

## 6. State machine dan recovery

Funding intent: `awaiting_confirmation -> claimed` atau `cancelled/expired`; claim replay kembali ke top-up yang sama. Policy/nonce/root stale memerlukan intent baru tanpa provider call.

Top-up: `created -> creating -> awaiting_payment -> verifying -> settled`. Cabang: `creation_unknown`, `provider_unavailable`, `expired`, `manual_review`, `rejected`, `externally_refunded`, `resolved_by_compensation`. Settlement tidak ditentukan oleh order enum existing. Evidence dapat `observed -> confirmed/finalized -> claimed`; wrong/missing/unfinal evidence belum spendable. Inbox valid diterima durable lalu diproses; invalid auth ditolak sebelum perubahan ekonomi.

Expired adalah deadline invoice, bukan bukti tidak ada dana. Late exact receipt yang attributable dan final dapat settle source sama, seperti IDR ADR 0011. Wrong amount/uncertain ownership masuk review. Kalau sebelumnya compensated atau externally refunded, receipt late masuk accounting/review dan **tidak** kredit kedua. Compensation/resolution dan settlement memakai satu unique top-up resolution identity ditambah claim receipt; setelah resolve, tidak reopen.

Reorg sebelum finality menahan kredit; perubahan canonical evidence setelah settled membekukan akun/exposure terkait dan membuka insiden. Jangan otomatis menghapus entry/menjadikan saldo negatif. Owner menentukan recovery/reversal terotorisasi jika funds tersedia; kerugian sementara dicatat treasury accounting. Finality policy harus menyebut kemampuan RPC, confirmation/finalized checks, cutoff dan fallback; RPC tanpa policy yang dibuktikan = rail unavailable.

## 7. Transaction protocol dan race controls

Read provider/RPC di luar transaction, simpan evidence normalized + version. Dalam transaction pendek: acquire locks sesuai flow, re-read target dan policy snapshot/status/version, verifikasi projection cursor, claim economic receipt/resolution, reject replay payload berbeda, cek saldo/overflow/refund cap, append entry + accounting event, update projection/cursor, update target settled/applied, append audit dan durable outbound work. Commit; dispatch sesudah commit hanya sebagai latency optimization. Crash recovery membaca pending work dari PostgreSQL.

Lock order harus mempertahankan existing flow, bukan menambah global account lock secara membabi buta:

- Checkout: Cart -> selected Customer currency account -> Checkout Maintenance -> CartItems/Products/supplier resources dalam existing canonical order -> ManualPaymentSetting -> Payment/lifecycle resources. Supplier lock graph existing diperiksa sebelum USDT adapter diintegrasikan.
- Funding existing: Customer -> currency account -> Top-up -> related resolution/request -> receipt claim -> entry/outbound. USDT funding/adjustment memakai urutan kompatibel; tidak mengunci Customer setelah account.
- Refund: selected currency account -> Order -> Payment -> entitlement/refund resources -> inventory sesuai lifecycle existing.
- Operasi tanpa account (verifikasi/network) tidak kemudian mengunci Cart atau Customer setelah mengunci Order/Payment. Jika menemukan debit/refund operation, keluar dari verification transaction dan masuk lifecycle dengan canonical order.

Receipt insertion unique conflict ditangani melalui savepoint/upsert atau rollback dan fresh transaction; jangan catch SQLSTATE 23505 lalu lanjut transaksi PostgreSQL yang sudah aborted. Empty-account race memakai INSERT ON CONFLICT lalu SELECT FOR UPDATE; SELECT lock pada row yang belum ada bukan proteksi.

Retry deadlock/serialization maksimal 5 attempts sesuai pola repo, backoff/jitter terbatas di boundary luar, deadline request; tidak retry insufficient balance/conflict/auth. Closure bebas HTTP, Telegram, signing custody transfer atau side effects nontransactional. Atomic conditional debit `WHERE balance >= amount` + check affected row dan DB nonnegative guard; successful concurrent credits tidak saling overwrite. Redis lease boleh mengurangi duplicate work; uniqueness/version DB tetap authority ketika Redis restart.

| Race | Hasil wajib |
| --- | --- |
| Dua debit saldo terakhir | Satu sukses, satu insufficient; tidak overspend |
| Callback + poll + operator review receipt sama | Satu resolution dan kredit |
| Dua customer submit hash sama | Kredit hanya receiver owner yang terikat intent, atau review; claimant hash tidak mengambil ownership |
| Checkout + top-up pakai external tx sama | Satu global claim; loser conflict, tidak ada kredit/debit semu |
| Intent confirm dua kali/create timeout | Satu target/reference; unknown create direconcile |
| Expiry + late settlement | Satu settled credit atau unresolved review; uang tidak dibuang |
| Compensation + verified settlement | Satu economic resolution; late evidence tidak double credit |
| Full + partial refund | Total <= debit dan entitlement, satu effect/source |
| Approval bersamaan/stale form | Satu application; berbeda payload/version konflik |
| Worker crash/Redis flush | Durable DB work ditemukan ulang; effect tidak double |

## 8. Security acceptance

Customer authorization setiap read/mutate dibuktikan dari actor Telegram/session server; private-chat rule dan bot webhook secret tetap enforced. Nonce opaque, expiring, terikat actor/chat/root/target/policy; jangan mengekspos ID sequential sebagai capability. Callback ownership selalu dicek walau signature benar. Web memakai CSRF/session protections dan account authentication existing; order Recovery Key tidak menjadi wallet login. Customer tidak bisa browse customer/receiver/receipt/entry milik orang lain.

Operator permissions dibedakan view/request/approve/policy/custody; approval memakai fresh authentication, audit actor dari session, dual control, expiry dan state recheck. Permission removal antara submit/approve dievaluasi ulang. Secrets encrypted server, hidden serialization, least-privilege provider key, rotation menyisakan snapshot; private keys/seed phrase/signing key tidak disimpan di app/log/queue. Settings harga/preset/fee/network label/exposure cap disimpan DB dan Admin; ENV baru hanya credentials/secrets sesuai policy repo.

Webhook mengikuti adapter auth nyata, raw body signature + timestamp/nonce bila protokol mendukung, request size/content limits, durable inbox dedup, authoritative recheck bila kontrak memerlukannya. Binance Merchant webhook rules tidak ditempel ke Binance history API existing seolah integrasi sama. BSC RPC URL allowlist dan response budgets; hanya chain/token/network approved, bukan URL RPC/explorer dari user. TLS/timeout/backoff dan circuit breaker; provider unavailable tidak memakai alternate receiver/provider tanpa intent baru.

Tx hash dan reason/proof adalah input tak tepercaya: format/length validation, HTML/Markdown escaping, proof attachment MIME/size checks, privacy/retention policy, jangan fetch arbitrary proof URL. Audit/log tidak menyimpan API keys, raw signed request, QR/payment secrets, full chat payload, recovery credentials. Admin proof access terotorisasi dan pagination scoped. Rate limits per customer/actor/target/IP serta server-side open intent/exposure caps mengurangi spam, enumeration dan provider-cost DoS; throttle di Redis bukan pengganti persistent cap. Per-customer cap memeriksa available liability + pending funding reservations di bawah customer/account lock. Exposure cap keseluruhan memakai quota/reservation durable yang dipartisi dan dialokasikan konservatif: jumlah quota tidak melebihi cap; allocation/reallocation terserialisasi, hot posting mengunci bucket/account terkait saja. Intent reservasi menjadi liability saat settle dan baru dilepas pada effect yang menurunkan liability; expiry tidak melepas reservasi dana ambigu. Audit allocation dan reconciliation mendeteksi oversubscription; jika mekanisme cap belum terbukti, batasi beta melalui kuota customer preallocated, bukan check aggregate SUM yang race-prone.

Penambahan saldo custodial dan USDT membutuhkan penilaian penggunaan dana pihak ketiga, izin/terms provider dan kewajiban yurisdiksi oleh pihak yang kompeten sebelum aktivasi. Spec ini tidak menyatakan ada izin/regulatory exemption. Legal/provider readiness dicatat sebagai gate produk yang spesifik, bukan dianggap selesai oleh test teknis.

## 9. Performance dan operasional

Hot saldo read satu consistent SQL snapshot (projection + newest entry/cursor), indexed point query; global Telegram metrics cache tidak menyimpan saldo. Riwayat keyset cursor server-signed/scoped, 25 default/100 maksimum; no N+1 dan no ledger SUM setiap tampilan. Reconciliation full history offline bounded chunk/checkpoint. IDR mutation existing masih memiliki full-history validation pada sejumlah jalur; ukur dahulu dan ubah ke cursor hanya jika immutability DB + full offline reconciliation dibuktikan, jangan melemahkan guard demi latency.

Polling terpusat per provider-account/network dengan pagination lengkap, watermarks + overlap dedup, progress durable, request budget dan fairness. Tidak memindai seluruh history untuk tiap customer/top-up. Incomplete provider page tidak dianggap “belum bayar definitif”. BSC ingestion memakai checkpoint block hash/log dan recovery reorg window. Durable scheduled work diklaim chunk 100 usulan dengan SKIP LOCKED + lease DB lalu HTTP di luar transaction; retry exponential bounded dan next_check_at index. Outbox dan review backlog tidak tumbuh tanpa alarm. Tidak memakai satu mutable global clearing balance sebagai lock hotspot.

Target staging awal: p95 saldo read <=100 ms, p95 posting internal <=250 ms tanpa waktu provider, p99 account lock wait <=500 ms untuk akun berbeda, tidak ada pertumbuhan backlog setelah burst berhenti. Beban: 10.000 customer / 1.000.000 entries, 50 read/s + 10 postings/s selama 30 menit dan burst 5x selama 60 detik; ada skenario satu hot account dan provider outage. Target disesuaikan dengan VPS capacity hasil ukur, correctness selalu wajib. Provider latency/finality dilaporkan terpisah; jangan menjanjikan settlement blockchain dalam SLA posting DB.

Metric: posted/replayed/conflict, lock wait/deadlock/retry, insufficient funds, mismatch, provider latency/errors/history incomplete, age unsettled/review/outbox, treasury coverage per currency, receipt collision, blocked unsafe deposits. Jangan label metric dengan raw customer/hash tak terbatas. Mismatch atau receipt ownership conflict kritis segera alert; stuck work >2 interval polling dan oldest outbox >5 menit usulan alert. Funding pause, spending pause, rail pause, operator review dan receipt delivery pause terpisah; menutup funding tidak menghentikan rekonsiliasi uang yang sudah masuk.

Backup/PITR restore drill PostgreSQL mencakup ledger, claims, invoice, audit dan durable effects. Setelah restore, freeze mutations; query provider/chain periode hilang, reconcile sebelum reopen; side effect eksternal tidak ikut rollback DB. Secrets/custody berada pada recovery procedure terpisah. Rollback kode dengan additive schema, jangan drop ledger/claims atau refund otomatis. Production melalui GitHub Actions main/VPS existing.

## 10. Acceptance dan keputusan terbuka

Release lulus hanya jika seluruh invariants I1–I12 dibuktikan dengan feature tests, PostgreSQL multiprocess races, provider/RPC contract scenarios, fault injection dan staging performance report. SQLite/sync queue tidak membuktikan locking/durability. Rencana pendamping memetakan deliverables/test/gate.

Keputusan yang memerlukan review sebelum aktivasi: custody provider atau kapasitas public receiver eksklusif; finality policy dan RPC capabilities; merchant eligibility/legal handling customer funds; minimum/maksimum dan exposure per currency; identity assurance/allowlist beta; operator approval staffing; fee final; terms/non-withdrawal policy; biaya treasury/gas dan settlement backing. Jangan invent provider credential/capability. Default safe: disabled USDT funding sampai gate terpenuhi, IDR existing tetap sesuai contract.

## 11. Dasar sumber dan batas klaim

Context7 diperiksa pada 2026-10-04 untuk `/laravel/docs/__branch__13.x`, `/websites/postgresql_16`, `/brick/math`. Laravel mendokumentasikan transaction rollback/commit, lockForUpdate dan deadlock attempts; PostgreSQL menjelaskan row locks/consistent lock ordering dan transaction failure; Brick Math mendukung exact arithmetic dan exception saat rounding diperlukan. [Laravel database](https://github.com/laravel/docs/blob/13.x/database.md), [Laravel query locking](https://github.com/laravel/docs/blob/13.x/queries.md), [PostgreSQL locking](https://www.postgresql.org/docs/16/explicit-locking.html), [PostgreSQL isolation](https://www.postgresql.org/docs/16/transaction-iso.html), [Brick Math](https://github.com/brick/math). Dokumen Brick main dapat berbeda dari ^0.18; implementasi harus memastikan installed signature/version dan repo tests sebelum memakai API.

Desain claim registry, custody mapping, maker-checker, outbox, caps dan SLO adalah rekomendasi untuk risiko repo ini, bukan jaminan library. Finality/ERC-20/provider/OWASP sumber primer dan keterbatasan integrasi ada di [catatan riset](../../research/2026-10-04-idr-usdt-wallet-sources.md). Tidak ada klaim “bebas semua security/race/performance issue”; ada kontrol, test dan no-go gates yang dapat diperiksa.
