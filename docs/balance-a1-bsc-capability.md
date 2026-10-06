# Laporan kapabilitas rail USDT BSC (A1 #540)

Tanggal capability report: 2026-10-04; pembaruan implementasi U5: 2026-10-06. Status: **real funding/spend no-go**. A1 memenuhi work package [#540](https://github.com/Alhiefikri/telegram-bot/issues/540) tanpa mengaktifkan funding atau mengubah checkout existing. Owner menyetujui pemisahan technical completion U5 dari qualification H1, kemudian mengizinkan merge dan penutupan [#550](https://github.com/Alhiefikri/telegram-bot/issues/550) untuk scope watch-only teknis. Qualification asli, independent actual staging evidence dan aktivasi downstream tetap belum selesai dan berada di gate [#539](https://github.com/Alhiefikri/telegram-bot/issues/539) serta sign-off/release terkait; penutupan #550 bukan approval funding.

Konteks: [plan/spec balance](superpowers/specs/2026-10-04-idr-usdt-customer-balance-design.md) · [keputusan custody H1](balance-h1-custody-decision.md) · [riset sumber wallet](research/2026-10-04-idr-usdt-wallet-sources.md) · [ADR-0021](adr/0021-usdt-exclusive-external-receivers.md).

## 1. Metode dan batas bukti

- Dokumentasi primer diperiksa 2026-10-04 memakai Context7 bila tersedia, lalu situs resmi provider. Context7 tidak memiliki library JSON-RPC BSC; resolver hanya menemukan library TSS/Greenfield yang tidak relevan. Context7 `binance/binance-api-postman` tidak memuat `/sapi/v1/pay/transactions`, sehingga klaim C2C memakai halaman katalog resmi Binance dan kode repo.
- Probe live read-only dijalankan 2026-10-04 22:28 +07:00 melalui `php artisan bsc:check-capabilities --json` terhadap pinned endpoints (`BSC_RPC_URL` bila diset, lalu `bsc-dataseed.binance.org`, `bsc-rpc.publicnode.com`). Tidak ada secret, transfer, atau dana nyata. Output sanitized yang direkam:

```json
{"rpc_qualified":true,"required":["chain_id","token_contract_code","token_decimals","finalized_block","canonical_receipt","transfer_logs"],"capabilities":{"chain_id":{"status":"verified","detail":"RPC melaporkan chain ID 56."},"token_contract_code":{"status":"verified","detail":"Alamat token memiliki contract code."},"token_decimals":{"status":"verified","detail":"Token decimals sesuai (18)."},"finalized_block":{"status":"verified","detail":"Tag finalized mengembalikan block lengkap di belakang head."},"canonical_receipt":{"status":"verified","detail":"Receipt canonical konsisten dengan block finalized."},"transfer_logs":{"status":"verified","detail":"Bounded eth_getLogs transfer filter diterima."}},"rpc_chain_id":56,"token_decimals":18,"checked_at":"2026-10-04T22:28:14+07:00"}
```

- Contract test `tests/Unit/Services/Bsc/BscCapabilityContractTest.php` memakai fixture JSON-RPC sandbox/testnet yang dilabeli; fixture itu bukan production proof. Test verifier menambahkan receipt gagal/tidak lengkap/log malformed sebagai bukti fail-closed.
- Hasil live adalah observasi titik-waktu endpoint publik best-effort, bukan jaminan kontrak provider produksi.

## 2. Ringkasan gate

| Area | Status | Konsekuensi |
| --- | --- | --- |
| RPC BSC (chain, token code, decimals, finalized, canonical receipt, transfer log) | **verified** pada probe live 2026-10-04 | Capability minimum funding rail terbukti di endpoint yang dicoba |
| Provenance token/issuer kontrak `0x55d3…7955` | **unknown** (Tether tidak mencantumkan kontrak USD₮ BSC di halaman resminya) | Aktivasi funding no-go |
| Receiver dan recover by reference | U4 memiliki binding terverifikasi; U5 memiliki invoice lokal idempotent dan adapter RPC watch-only; automatic provisioning belum qualified | Implementasi teknis tersedia dalam environment disposable; funding produksi disabled |
| Custody/capacity/legal/treasury | **belum terbukti** (H1 acceptance register) | Aktivasi funding no-go |
| Binance C2C sebagai funding | **unsupported** (tanpa binding beneficiary/invoice) | Binance funding disabled |
| Checkout BSC existing | tidak berubah | Tetap mengikuti readiness/release gate existing |

Kesimpulan: **NO-GO untuk real funding/spend**. Kualifikasi ini cukup untuk melanjutkan ledger/adapter contract dan test disposable, bukan untuk menerima dana. `rpc_qualified` hanya menyatakan permukaan RPC lolos; issuer/provenance, provisioning, custody/capacity, legal dan treasury adalah gate aktivasi terpisah yang saat ini `unknown` → no-go.

## 3. Chain, kontrak token, dan decimals

| Item | Nilai/evidence | Status |
| --- | --- | --- |
| Chain | BNB Smart Chain Mainnet, chain ID 56; RPC live melaporkan 56 dan konfigurasi aplikasi wajib 56 (config non-mainnet = `unsupported`) | verified |
| Kontrak token | `0x55d398326f99059ff775485246999027b3197955` (approved repo); `eth_getCode` live mengembalikan bytecode | verified |
| Decimals | RPC live `decimals()` = 18, sama dengan `expected_token_decimals`; mismatch memicu `unsupported`/readiness invalid | verified pada snapshot ini |
| Issuer/bridge/redemption | [Tether Supported Protocols](https://tether.to/en/supported-protocols/) (diambil 2026-10-04) hanya mencantumkan XAU₮ `0x21cAef8A43163Eea865baeE23b9C2E327696A3bf` untuk BNB Smart Chain; tidak ada sertifikasi USD₮ untuk kontrak checkout. Symbol/explorer berlabel USDT tidak cukup | unknown → fail closed |
| Sandbox/testnet | Fixture chain 97 di contract test dilabeli sandbox; diklasifikasi `unsupported` dan tidak pernah dianggap bukti mainnet | fixtures only |

Desimal kontrak bersifat OPTIONAL pada [EIP-20](https://eips.ethereum.org/EIPS/eip-20): nilai 18 tidak boleh diasumsikan dari simbol. Snapshot menyimpan decimals hasil verifikasi server, dan nominal micro-USDT dikonversi eksak tanpa float (`BscTokenAmount`, test boundary `BscTokenAmountTest`).

## 4. RPC capability dan policy finality

`App\Services\Bsc\BscCapabilityProbe` memeriksa capability yang dibutuhkan rail dan mengklasifikasikan setiap hasil sebagai `verified`, `unsupported` (mismatch definitif), atau `unknown` (tidak lengkap/provider gagal). `BscCapabilityReport::isQualified()` hanya true bila **semua** capability wajib `verified`; command `bsc:check-capabilities` keluar `FAILURE` untuk mismatch/unknown (fail closed).

| Capability | Aturan acceptance | Fixture/evidence |
| --- | --- | --- |
| `chain_id` | `eth_chainId` = 56; nilai lain `unsupported`, error `unknown` | happy path; testnet 0x61; HTTP 500; JSON malformed |
| `token_contract_code` | `eth_getCode` non-empty | code valid; `0x` kosong |
| `token_decimals` | `decimals()` = expected (18) | happy path; mismatch 6 |
| `finalized_block` | `eth_getBlockByNumber('finalized')` mengembalikan number+hash lengkap **dan berada di belakang head** (`number < latest`); tag yang mengabaikan kedalaman `unknown`, method-not-found `unsupported`, payload tidak lengkap `unknown` | happy path; error -32601; block tanpa hash; tag yang tidak bergerak |
| `canonical_receipt` | Receipt transaksi di block finalized terbaca; status `0x0`/`0x1`, transactionHash cocok, blockNumber/blockHash konsisten dengan block | happy path; receipt tanpa status; blockHash mismatch; block tanpa transaksi |
| `transfer_logs` | Bounded `eth_getLogs` filter contract+receiver diterima | happy path; error limit provider → tidak pernah `verified` |

Policy finality runtime:

- [BEP-126 Fast Finality](https://github.com/bnb-chain/BEPs/blob/master/BEPs/BEP126.md) (Status: Enabled): block finalized tidak di-revert; finality tercapai dalam dua block pada mayoritas kasus. Tag `safe`/`finalized` tersedia pada `eth_getBlockByNumber` menurut [BSC JSON-RPC API list](https://docs.bnbchain.org/bnb-smart-chain/developers/json_rpc/bsc-api-list/); halaman itu menolak fetch otomatis pada pemeriksaan ini, sehingga dukungan tag juga dibuktikan langsung oleh probe live.
- Verifier existing tetap otoritatif: confirmation threshold + grace + optional `requires_finalized_block` per snapshot, konsistensi `blockHash`, dan review manual untuk reorg/mismatch. Capability `unsupported`/`unknown` tidak boleh menghasilkan kredit.
- `bsc:check-readiness` tetap menjadi gate checkout; `bsc:check-capabilities` adalah gate kualifikasi provider/rail. Rail funding tidak boleh menerima dana saat capability belum `verified`.

Pagination dan rate budget (config, bukan ENV baru):

| Parameter | Nilai | Alasan |
| --- | --- | --- |
| `log_scan_chunk_blocks` | 1.000 | bound aman untuk `eth_getLogs` provider publik |
| `log_scan_max_blocks` | 250.000 | batas safety window scan amount-unique |
| `log_scan_blocks_per_second` + clock skew + reorg buffer | 3 bps, 120 s, 12 block | estimasi window + konservatisme reorg |
| `rpc_timeout_seconds` / connect / attempts / delay | 5 s / 2 s / 2 / 150 ms | retry terbatas pada transport |
| Riwayat/reconcile | `bsc:reconcile --chunk=100` per menit, hanya state eligible | pagination chunk + fairness |

## 5. Provisioning dan recover by reference

`UsdtTopUpRail` kini terikat ke `BscUsdtTopUpRail`, memakai receiver operator-controlled U4 yang sudah diregistrasi dan diverifikasi untuk Customer tersebut. `createOrRecover` menyimpan satu reference/fingerprint lokal dan immutable receiver/token/network/amount/fee/finality/window snapshot. Karena tidak ada external create pada jalur ini, tidak ada remote creation-unknown yang perlu ditebak: timeout sebelum commit tidak membuat invoice, lost response setelah commit direcover dengan reference yang sama tanpa RPC atau receiver fallback. Customer tanpa receiver terverifikasi tidak mendapat invoice. Ownership berdasarkan beneficiary receiver permanen, bukan hot-wallet sender atau pengirim hash. Kapasitas inventory nyata, signer, legal dan treasury tetap qualification H1; adapter provisioning approved tetap tidak dipalsukan.

## 6. Fee/network/custody snapshot immutability

Snapshot immutable yang berlaku untuk rail funding nanti, mengikuti pola checkout existing (`payment_bsc_details`):

| Field | Sumber | Aturan |
| --- | --- | --- |
| chain_id, token_contract, token_decimals | konfigurasi approved + verifikasi RPC | Dibekukan saat snapshot; perubahan setting tidak mengubah target lama |
| receiver_address (dan custody namespace/ownership proof bila ada) | registry penerima eksklusif | Tidak pernah reassign; rotasi = receiver baru |
| requested credit, application fee, payable, expected token units | exact micro-USDT + allowance decimals | Fee aplikasi 0; gas network dibayar pengirim; tidak ada pembulatan tersembunyi |
| network identity/label | DB/Admin (bukan ENV) | Final untuk invoice; tidak mengikuti setting berubah |
| finality policy (confirmations, finalized requirement, grace) | snapshot per top-up/payment | Verifikasi memakai nilai snapshot, bukan setting terbaru |
| provider/reference dan evidence | create/recover + receipt | Immutable/append-only; alias receipt tidak boleh mendanai dua target |

Aturan: snapshot dibentuk sebelum pembayaran (dan sebelum panggilan provider), tidak dihitung ulang dari setting terbaru; perbedaan payload pada replay = konflik; bukti baru memperbarui status/review, bukan nominal/target. Test existing (confirmation threshold memakai snapshot setelah setting berubah; disable setting tidak mengubah snapshot) menjadi bukti regresi untuk aturan ini.

## 7. Binance C2C, Merchant, dan Capital (rail opsional)

| Produk | Kontrak | Untuk funding top-up |
| --- | --- | --- |
| Binance Pay C2C history `/sapi/v1/pay/transactions` | Repo memakai `BinancePayClient`/`BinancePayTransaction`; field `orderId`, `transactionId`, `amount`, `receiverInfo.accountId/binanceId` | **Tidak memenuhi**: history hanya membuktikan transaksi masuk ke akun kita; tidak ada invoice/reference/beneficiary binding ke top-up intent customer, dan identitas pengirim tidak terverifikasi |
| Binance Pay Merchant webhook | Produk terpisah dengan signature RSA/SHA256 public key sendiri | Tidak sama dengan C2C history; belum ada eligibility/agreement merchant |
| Binance Capital deposit address/history | Produk akun exchange; provisioning alamat adalah milik akun, bukan receiver eksklusif per customer | Tidak memenuhi receiver eksklusif H1 |

Keputusan: **Binance funding tetap disabled** tanpa authenticated order/reference atau beneficiary binding yang mengikat pembayaran ke intent/customer, plus bukti akun/legal. Pemakaian C2C existing untuk verifikasi checkout (customer mengirim Order ID/transaction ID, server menyocokkan nominal dan receiver) adalah kontrak terpisah dan tidak boleh dikutip sebagai bukti kualifikasi funding. Klaim di sini dibatasi pada kontrak yang benar-benar diperiksa; halaman C2C pada dokumentasi baru Binance dirender JavaScript sehingga field lanjutan tidak diambil dari sumber tidak resmi.

## 8. Exact acceptance fixtures

Contract tests (sandbox fixtures, bukan production proof):

| Fixture | Ekspektasi | Test |
| --- | --- | --- |
| Evidence lengkap dan konsisten | keenam capability `verified`, `rpc_qualified=true` | `test_well_formed_primary_bsc_fixtures_report_verified_capabilities` |
| Chain 97 (sandbox) | `chain_id` unsupported, fail closed | `test_testnet_chain_fixture_is_unsupported_and_fails_closed` |
| `eth_getCode` = `0x` | contract code unsupported | `test_empty_contract_code_fixture_is_unsupported_and_fails_closed` |
| decimals = 6 | decimals unsupported | `test_decimals_mismatch_fixture_is_unsupported_and_fails_closed` |
| `finalized` method not found | finalized unsupported | `test_method_not_found_finalized_fixture_is_unsupported_and_fails_closed` |
| Block finalized tanpa hash | unknown | `test_incomplete_finalized_block_fixture_is_unknown_and_fails_closed` |
| Tag `finalized` tidak bergerak di belakang head | finalized + canonical receipt unknown | `test_finalized_tag_that_never_moves_behind_head_is_unknown_and_fails_closed` |
| Block finalized tanpa transaksi | canonical receipt unknown | `test_empty_finalized_transactions_keep_canonical_receipt_unknown` |
| Receipt tanpa status | unknown | `test_incomplete_receipt_fixture_is_unknown_and_fails_closed` |
| Receipt blockHash berbeda | unsupported | `test_receipt_block_hash_mismatch_fixture_is_unsupported_and_fails_closed` |
| `eth_getLogs` limit | tidak pernah `verified` | `test_transfer_log_limit_rejection_is_not_verified` |
| HTTP 500 pada metadata | unknown | `test_transport_failure_fixture_is_unknown_and_fails_closed` |
| JSON malformed | unknown | `test_malformed_json_fixture_is_unknown_and_fails_closed` |

Verifier evidence (fail closed, tanpa kredit):

| Fixture | Ekspektasi | Test |
| --- | --- | --- |
| Receipt status `0x0` (failed) | `Mismatch` + review, tidak ada PaymentEvent | `test_failed_receipt_fixture_never_claims_and_opens_review` |
| Receipt tanpa blockNumber | `Mismatch` + review | `test_incomplete_receipt_fixture_never_claims_and_opens_review` |
| Log transfer malformed | `Mismatch` + review | `test_malformed_transfer_log_fixture_never_claims_and_opens_review` |
| Nominal salah/multi-log/hash global | review/conflict existing | `BscPaymentVerificationTest` |

Amount boundary: `BscTokenAmountTest` membuktikan konversi micro→raw exact untuk 0/1/2.500.000/PHP_INT_MAX, decimals 6/18, dan penolakan amount negatif/decimals < 6.

## 9. Sumber resmi

| Sumber | Dipakai untuk | Tanggal/versi |
| --- | --- | --- |
| [BEP-126 Fast Finality](https://github.com/bnb-chain/BEPs/blob/master/BEPs/BEP126.md) | Finality BSC tidak revert; justifikasi policy `finalized` | Status Enabled; diambil 2026-10-04 |
| [BSC JSON-RPC API list](https://docs.bnbchain.org/bnb-smart-chain/developers/json_rpc/bsc-api-list/) | Tag `safe`/`finalized` pada `eth_getBlockByNumber` | Fetch otomatis ditolak; snippet resmi terbaca 2026-10-04; dukungan juga dibuktikan probe live |
| [EIP-20](https://eips.ethereum.org/EIPS/eip-20) | `decimals` OPTIONAL; event Transfer | Final; diambil 2026-10-04 |
| [Tether Supported Protocols](https://tether.to/en/supported-protocols/) | Provenance issuer/bridge kontrak | Diambil 2026-10-04; hanya XAU₮ untuk BNB Smart Chain |
| [Binance Pay REST API](https://developers.binance.com/en/docs/catalog/investment-and-services-pay/api/rest-api/~) | Endpoint history C2C | Katalog resmi 2026-10-04 (konten detail dirender JS) |
| [Binance Merchant API](https://developers.binance.com/en/docs/products/binance-pay-merchant/introduction) | Produk terpisah | 2026-10-04 |
| [Binance Capital API](https://developers.binance.com/en/docs/catalog/core-trading-wallet/api/rest-api/capital) | Deposit address/history akun | 2026-10-04 |

## 10. Batasan dan langkah berikutnya

- Probe live hanya membuktikan endpoint yang dicoba pada satu waktu; provider produksi tetap harus `verified` sebelum funding. Endpoint publik best-effort tidak menjadi production proof.
- Token provenance, kapasitas receiver nyata, legal, treasury/gas dan external return tetap gate #539. #549 menyelesaikan registry teknis; Owner menyetujui penutupan #550 sebagai technical completion saja. Qualification, downstream (#561) dan parent tidak otomatis selesai.
- Tests SQLite/sync tidak membuktikan locking PostgreSQL; race/durability tetap P9.
- Command operasional: `php artisan bsc:check-capabilities --json` (exit non-zero saat `rpc_qualified` false) untuk merekam evidence; `bsc:check-readiness --json` tetap gate checkout existing.

## 11. Implementasi dan evidence teknis U5 (2026-10-06)

- Owner menyetujui pendekatan watch-only yang memakai verified receiver existing, dengan technical completion terpisah dari H1 qualification. Tidak ada provider custody fiktif, private key, ENV content baru, deploy, transfer nyata atau aktivasi funding.
- `BscFinalReceiptInspector` adalah pure inspection yang memakai decoder `BscTokenTransferReader` existing. Successful exact single Transfer, log/receipt identities, contract/receiver, representable micro amount, epoch-second inclusive window, canonical block, confirmations dan canonical `finalized` di belakang head wajib valid. Evidence unavailable/unfinal tetap pending; invalid/multi-log/reorg masuk review. Verifier checkout existing tidak dialihkan ke lifecycle Top-up.
- `usdt_bsc_rail_invoices` merupakan artefak rail, bukan confirmed Top-up atau ledger effect. Reference unik, replay payload conflict, exclusive receiver binding, immutable DB evidence, no overlapping windows dan snapshot finality dari existing active DB setting. New create hanya di `local`/`testing`; `fundingAuthorized` tetap false. Expiry/retirement tidak menghentikan reference recovery atau inspeksi uang yang timestamp-nya masih di dalam window.
- `BscUsdtTransferIngestion` dan `usdt-bsc:scan` membaca enrolled receiver dengan durable block/hash/version checkpoint serta dedup transaction/log observations. Page/log/RPC-operation budgets bounded; incomplete page tidak advance. Retired receiver tetap dimonitor. Reorg pada finalized anchor/log/page boundary mempertahankan bukti lama dan latch review; tidak menghapus ledger atau auto-rewind finalized history. U8 memiliki incident resolution setelah settlement.
- Local runtime smoke memakai **actual Laravel adapter + Guzzle HTTP + Artisan** terhadap server JSON-RPC localhost sintetis: reference recovery identik, inspection `verified`, scan satu page ke block 105 dengan 9 RPC operations, perubahan canonical checkpoint menghasilkan review dengan 6 operations, subsequent inspection review, oversized transport ditolak, money effects 0, SQLite migration rollback/remigrate exit 0. Ini bukan actual independent staging/custody proof.
- Focused rail/BSC/parser regression: 103 tests / 536 assertions, semua pass. Disposable PostgreSQL 16 multiprocess rail evidence: 5 tests / 54 assertions, tanpa skip/failure; identical creates, overlapping references, reference contention antar-Customer dengan recovery setelah unique failure, concurrent checkpoint/log ingestion, DB immutability/foreign receiver guards.
- Full regression SQLite/ParaTest: 3.581 tests, 3.440 passed, 26.415 assertions, 141 skipped, tanpa failure/error. Lane PostgreSQL diverifikasi terpisah; skip pada lane SQLite bukan bukti locking. Frontend assets dibangun lokal dan cache Blade disposable yang kosong diperbaiki sebelum final run; tidak ada workflow CI dijalankan.
- Full lane concurrency PostgreSQL seluruh repo berhenti pada deadline 900 detik tanpa final verdict; tidak diklaim PASS. Kelima race/guard tests khusus U5 dijalankan ulang setelah disposable PostgreSQL recovery dan tetap pass (54 assertions, tanpa skip). Rollback migration U5 `--step=1` lalu remigrate pada PostgreSQL 16 berhasil; Pint untuk seluruh PHP yang berubah pass.
- Context7 diakses melalui public REST `libs/search` dan `context`: `/laravel/docs/__branch__13.x`, `/websites/postgresql_16`, `/brick/math`. [Laravel transactions](https://github.com/laravel/docs/blob/13.x/database.md) dan [pessimistic locking](https://github.com/laravel/docs/blob/13.x/queries.md) mendasari short transaction/row locks; [PostgreSQL INSERT](https://www.postgresql.org/docs/16/sql-insert.html) dan [READ COMMITTED](https://www.postgresql.org/docs/16/transaction-iso.html) mendasari conflict-safe enrollment dan rollback sebelum unique recovery; [Brick Math exact arithmetic](https://github.com/brick/math) divalidasi lewat installed U1 `UsdtMicroAmount` contract tests, bukan asumsi signature main.
- Confirmed intents/Top-up ownership (U6), global receipt claim dan settlement/outbound (U7), incident resolution (U8), customer UI (U9) dan approval/actual staging/production gates tidak diklaim selesai oleh perubahan ini. Owner mengizinkan merge dan penutupan #550 sebagai technical completion; fixture/local smoke tidak menjadi qualification atau izin produksi.

