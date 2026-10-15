# Supplier API Reseller — operator runbook

## Boundary

Supplier management is private to Owner/Admin. Customer catalog, invoices, product copy and support remain store-branded. Supplier names, balances, modal prices, API keys, references and raw failures must not be forwarded to customers.

Supplier Balance is the operator's money at the external shop, not Customer Store Balance. A supplier's stock count is not owned inventory: availability is bounded by balance, existing commitments, freshness, equivalent-product mapping and minimum-profit policy. Customer payment can be Paid while Supplier Fulfillment is still pending or in Manual Fulfillment Review.

## Setup through Admin

Dashboard koneksi supplier tetap mengelola API key, saldo, sinkronisasi, deposit, dan pemulihan. Dari Produk pilih **Kelola produk**, lalu buka accordion **Supplier** untuk mencari item katalog, menghubungkan sumber, mengatur urutan utama/cadangan, atau menonaktifkan mapping. Detail, stok lokal, harga, dan gambar berada di accordion terpisah pada workspace yang sama. Isian tetap berada di memori halaman saat accordion ditutup; simpan per bagian sebelum meninggalkan halaman. Impor katalog membuka draft langsung di workspace. Persentase keuntungan memakai satuan biasa, misalnya 12,5 untuk 12,5%, dengan konversi tepat ke basis points.

Daftar Produk menyediakan pencarian nama produk, pilihan, dan family lintas halaman. Urutan awal adalah Nama A–Z; pilihan jumlah produk, terbaru, harga, dan urutan katalog diterapkan sebelum pagination. Filter tersimpan di URL dan ikut tautan halaman; Reset mengembalikan urutan awal. Family tetap utuh saat salah satu produknya cocok. Thumbnail 56px dan label gambar khusus/default membedakan gambar family dari gambar produk; status Web, Bot, dan Channel pada produk tidak disamakan. Header workspace menampilkan gambar Web tersimpan dan tombol Atur gambar. Shortcut Langsung ke pengaturan produk membuka Detail bila semua bagian tertutup dan memindahkan fokus ke bagian aktif.

Form Detail produk tidak lagi menampilkan Spesifikasi tambahan & dukungan (jenis produk, durasi akses, region, jenis akun, batas perangkat, yang termasuk, batasan penggunaan, estimasi pemenuhan, dan kebijakan dukungan). Penyimpanan detail tidak mengirim ulang field tersebut; nilai lama tetap tersimpan, tanpa penghapusan data atau perubahan tampilan Web Storefront yang memakai metadata lama.

1. Open **Admin → Supplier Reseller** and fill the connection form. Clicking **Buat/Simpan supplier** opens an inline password dialog if recent confirmation is missing; successful confirmation resumes saving automatically. Incorrect passwords or cancelling retain the API key and other inputs in the current browser form. No secret is flashed to server session or persisted to browser storage. Operational recovery still requires recent confirmation.
2. Add a connection: supported provider, API key, whole-IDR conversion rate, active flag and low-balance threshold. The Dashboard derives the private name and configured currency from the provider: ProdSeller uses USDT; CSxStore, AI Vault, NyStore Official, ToolOraX (ShopBot) and Canboso use USD; BuyMail uses IDR with rate 1. Only ARRS/Warzone expose account-currency selection and explicit confirmation. Fixed-provider currency overrides are rejected by request validation. The configured USD for NyStore/ToolOraX is not production account-currency sign-off; see **Credential, currency and threshold sign-off**.
3. Do not put supplier API keys in ENV. The database stores encrypted credentials using the application's encryption key; saved keys are not displayed back. Keep APP_KEY and encrypted database backups together under the existing backup policy. Losing the key makes those credentials unreadable.
4. For ARRS/Warzone, confirm the actual account currency with the supplier. Also obtain authoritative currency evidence for the actual NyStore/ToolOraX accounts before activation or spend. Dollar-like presentation and USDT funding are not wallet-currency evidence. A mismatch must block activation and receive contract review, not a forced dropdown/ENV override.
5. Activate the connection and click **Sinkron katalog dan saldo**. The local button is a CSRF-protected POST, but provider catalog/balance requests are GET-only. Read-only catalog fetching must not buy anything.
6. Open the private catalog. Select a SKU to import as a draft Product, or open **Hubungkan ke produk toko yang sudah ada** and select a Product by name from the **Produk toko** dropdown. Options show retail price and active/draft status; the ID is only a disambiguating label, not an input to memorize. Drafts and Products mapped to other suppliers remain selectable. Deleted Products and Products already mapped to this SKU are excluded. Confirm equivalent entitlement before saving; edit an existing mapping through its linked Product button.
7. Give the draft store-owned name, description, tutorial, warranty and support terms through normal Product editing. Import does not publish the supplier description or activate the Product automatically.
8. Enable the source mapping and arrange the try order with the **↑ Naikkan / ↓ Turunkan** controls. "Urutan 1 · utama" is tried first; later ranks are the fallback. The page shows each rank explicitly, so you never have to compare numbers: it keeps the underlying priority normalized to 10, 20, 30, … and lower values still sort first internally. Each card also shows the last observed unit cost in IDR and the supplier currency (`Harga beli/unit`), or a "Belum ada harga" warning when the catalog has no price yet — read-only display, never a purchase authorization. Only map products whose entitlement, region, account requirements, redemption deadline and warranty promises are equivalent.
9. Set Product-owned pricing: manual, percentage, fixed profit or hybrid percentage/minimum profit; configure rounding and minimum-profit guard. Preview the cost/retail calculation before enabling public sales.
10. Top up the external supplier account through that supplier's own supported payment flow. This application does not create a supplier funding transaction. Sync after funding to update availability.

An account supports multiple SKUs with one credential. Adding another SKU from an implemented provider is an Admin operation, not a code change. A new provider contract or an unsupported fulfillment/input type still needs implementation and qualification.

### Canboso

Canboso memakai origin tetap `https://canboso.com/api/v2/telegram-buyer` sesuai [Buyer API](https://canboso.com/api/swagger/). Adapter membaca `/balance` dan `/products` dengan buyer key, lalu membeli melalui `/purchase` dengan `Idempotency-Key` dan reference purchase yang sama. API key tetap terenkripsi di database.

- Koneksi ini memakai wallet **USD**. `walletCurrency` dan currency harga/payment harus cocok; key wallet VND ditolak, bukan dikonversi diam-diam.
- Hanya produk `account` tanpa `purchaseRequirements` yang eligible untuk fulfillment instan. Slot dan upgrade akun tetap terlihat di katalog privat tetapi tidak dapat menjadi sumber pembelian otomatis.
- Harga katalog menjadi estimasi konservatif tanpa mengandalkan diskon promosi. Bonus atau jumlah delivery yang berbeda dari quantity pesanan masuk review; adapter tidak membuang atau mengalokasikan bonus secara diam-diam.
- GET `/orders?orderCode=...` tidak mengembalikan kredensial. Status `completed` di history tidak mengizinkan delivery; operator perlu memulihkan bukti privat melalui monitor existing. Timeout tanpa order ID mengikuti bounded same-reference replay existing, bukan purchase baru.
- Limiter berbagi budget konservatif 30 request/menit, maksimal 20 read/menit, dan menghormati `Retry-After` sampai enam jam. Tidak ada retry HTTP dalam loop.
- Top-up wallet dilakukan di Canboso. Sesudah top-up, sinkronkan saldo dan pastikan kurs IDR/USD sesuai biaya funding sebelum mapping/publikasi produk.

Regresi: `CanbosoSupplierTest` mencakup exact money, input-only SKU, currency mismatch, korelasi purchase, delivery yang tidak lengkap/bonus, history tanpa credential, dan cooldown enam jam.

Regresi kesiapan tambahan:

- `CanbosoPostgresConcurrencyTest`: delapan race multi-process untuk dispatch tunggal, wallet/stock terakhir, lease sync, rotasi credential saat snapshot berlangsung, replay ber-reference sama, stale finalization, batas replay setelah dispatch hilang, dan budget request bersama. Memerlukan PostgreSQL disposable bernama test, `RUN_CONCURRENCY_TESTS=1`, `pcntl`/`posix`, dan `phpunit.concurrency.xml`; SQLite tidak membuktikan locking.
- `CanbosoQueryBudgetTest`: pertumbuhan katalog 1–401 SKU, Product publik, Dashboard, render katalog privat, chunk write 200 row, dan mapping dengan lima tier harga. Test membedakan SELECT per-SKU yang tidak perlu dari projection UPDATE per-Product yang memang diperlukan.
- `CanbosoSecurityTest`: identitas akun duplikat meski password berbeda, spoofed payment/order, payload/transfer bounds, secret pada nama field maupun nilai JSON, kegagalan cooldown setelah dispatch, transaksi finansial, authorization/password/CSRF, ciphertext, audit/session, dan rotasi saat commitment masih aktif.

`ProductQuantityPricing::quote` membaca maksimal enam tier sebagai sentinel saat relation belum dimuat; relation lengkap yang sudah eager-loaded tidak melakukan overflow probe per kalkulasi. Schedule dengan lebih dari lima tier tetap ditolak.

Jalankan regresi SQLite dari `src/`:

```sh
php artisan test --compact --filter='CanbosoSupplierTest|CanbosoQueryBudgetTest|CanbosoSecurityTest|SupplierClientTest|SupplierAdminTest|SupplierFulfillmentTest|SupplierAvailabilityTest|ProductQuantityPricingTest'
```

Untuk race, siapkan PostgreSQL 16 disposable dengan `DB_DATABASE` yang mengandung `test`, kosongkan `DB_URL`, lalu jalankan dengan environment DB terisolasi dan `RUN_CONCURRENCY_TESTS=1`:

```sh
vendor/bin/phpunit --configuration=phpunit.concurrency.xml --filter='CanbosoPostgresConcurrencyTest|SupplierPostgresConcurrencyTest' --fail-on-skipped --display-skipped --no-coverage
```

Pengujian memakai HTTP sintetis. Budget lintas-proses memakai shared database cache; pengujian ini tidak mengklaim verifikasi Redis production atau debit/delivery real di Canboso. Top-up, pembelian terkendali, bukti delivery provider, dan konfigurasi worker/cache production tetap memerlukan qualification operator sebelum penjualan.

Bukti lokal 14 Oktober 2026:

- PostgreSQL 16: 19 test supplier concurrency lulus, 370 assertion, tanpa skip; termasuk delapan race Canboso.
- Run non-race mencakup 349 skenario: 347 lulus pada run utama. Dua test rotasi membaca instance Eloquent yang belum direfresh; sesudah memperbaiki assertion persisted-state, rerun keduanya lulus dengan 26 assertion.
- Smoke standalone PostgreSQL melewati sync → checkout → pembayaran manual sintetis → worker → replay worker: nol POST sebelum pembayaran, satu POST purchase, satu credential milik Order Item yang benar, wallet `$1.00 → $0.95`, dan seluruh hold dilepas.
- Reproducer akun duplikat/password berbeda serta key echo sebagai nama field berubah dari delivery trusted menjadi ambiguous/untrusted. Empat quote dengan lima tier eager-loaded mempertahankan harga yang sama, dengan query tambahan turun dari empat menjadi nol.




## Nonaktifkan atau lepas mapping

- **Nonaktifkan**: gunakan tombol **Nonaktifkan sumber** pada accordion Supplier. Mapping tetap ada; pembelian baru tidak memilihnya, sementara histori dan pemulihan obligation yang sudah ada tetap terlindungi.
- **Lepas mapping**: pada accordion **Supplier** di **Kelola produk**, gunakan tombol terpisah **Lepas mapping**. Konfirmasi menampilkan nama koneksi/SKU; **Batal** tidak mengirim mutasi. Ini hanya untuk mapping yang belum digunakan: semua purchase, termasuk terminal/settled, reserved SKU, dan retained snapshot (termasuk SKU cadangan yang belum dibeli) untuk Product tersebut menghalangi pelepasan dengan 409. Histori SKU yang sama pada Product lain tidak menghalangi.
- Jika snapshot terenkripsi tidak dapat dibaca, pelepasan ditolak dengan pesan aman. Pulihkan pasangan APP_KEY/backup atau minta pemeriksaan operator; jangan menghapus histori untuk melewati guard. Form yang basi atau identity mapping yang tidak cocok ditolak dengan 422; muat ulang halaman sebelum mencoba lagi.
- Sukses hanya menghapus mapping dan menormalisasi urutan tersisa (10, 20, 30, …), mempertahankan relative order. Audit `supplier.source_detached` menyimpan identity/rank/version tanpa secret atau snapshot. Supplier mutation version naik, proyeksi kapasitas diinvalidasi dalam transaksi dan direfresh setelah commit. Product, koneksi, Catalog Item, credential, pricing policy, tier, dan histori tetap tersimpan; melepas source terakhir menghapus kapasitas eksternal, bukan stok lokal atau harga manual.
- `SupplierAdminTest` mencakup sukses/audit/rank/version, source terakhir, histori terminal, backup snapshot, unreadable snapshot, Product lain, stale/wrong identity, authorization dan CSRF. `SupplierPostgresConcurrencyTest` memakai dua proses dan bukti lock waiter PostgreSQL untuk checkout-first (detach ditolak) serta detach-first (checkout tidak menyimpan source yang sudah dilepas).


## Pricing and availability

- Manual retail price is never overwritten by sync.
- Automatic policy updates base IDR price using configured supplier conversion rate; existing quantity tiers must remain valid and their actual retail totals are checked against the purchase profit floor.
- Basis harga otomatis tersimpan adalah supplier aktif dan layak pertama menurut urutan: **Urutan 1 diprioritaskan**, lalu source berikutnya jika Urutan 1 tidak memenuhi syarat basis harga. Ini bukan pemilihan harga termurah atau janji bahwa kartu Urutan 1 selalu dipakai. Filter canonical tetap milik Supplier Availability/Pricing: mapping/koneksi aktif dan tidak dijeda, currency terkonfirmasi dan sesuai, SKU orderable tanpa input tambahan, observasi segar, serta harga dan jadwal tier valid. Kelayakan basis harga tidak menjamin stock/wallet cukup saat checkout; perhitungan harga dilakukan sebelum pemeriksaan kapasitas pembelian.
- **Hitung simulasi berdasarkan SKU** hanya menghitung SKU terpilih dengan pengaturan form yang belum disimpan, termasuk SKU cadangan. Preview tidak menyimpan policy/retail, mengubah urutan mapping, atau memilih source basis harga tersimpan. **Simpan pengaturan harga** menyimpan kebijakan; SKU pada dropdown simulasi bukan source selector. Mode manual tetap memakai harga dari halaman Produk, dan tanpa mapping tidak ada source untuk harga otomatis atau simulasi. Pesanan yang sudah dibuat tetap memakai snapshot komersialnya.
- Hasil simulasi menampilkan harga beli, harga jual, dan keuntungan per unit (`retail_idr - cost_idr`). Nilai negatif tetap ditampilkan sebagai kerugian, nol sebagai impas, dan biaya yang tidak tersedia tidak dianggap nol. Ini bukan laba bersih final: biaya aktual lain dan diskon jumlah belum dimasukkan. Perubahan draf kebijakan/SKU membatalkan hasil lama di UI tanpa menyimpan data.
- Percentage markup is calculated from converted supplier cost: `ceil(cost_IDR × basis_points / 10000)`. `100` means 1%; `1000` means 10%. Fixed mode uses `profit_idr` and ignores a previously saved percentage. Hybrid uses the larger of the percentage amount and `profit_idr`, not their sum.
- Automatic retail is supplier cost + selected markup/profit, rounded upward to the configured IDR multiple. `minimum_profit_idr` is a separate purchasing guard, not another markup: every external unit must retain that minimum after supplier cost. A computed price below that guard does not authorize a purchase.
- Cadangan biaya dihapus dari form, kontrak preview, perhitungan harga, dan batas biaya pembelian. Migration `2026_10_15_000001_remove_supplier_price_policy_buffer` menghapus kolom lama dan menaikkan versi policy agar form sebelum migrasi ditolak sebagai konflik. Harga Product tersimpan serta snapshot harga/batas biaya Order lama tidak ditulis ulang; mode otomatis memakai rumus baru pada perhitungan ulang berikutnya. Rollback schema membuat kolom lama bernilai nol, bukan memulihkan nilai cadangan yang telah dihapus.
- Configured conversion rate should reflect actual cost of funding the supplier, not an assumed live exchange rate. Pending/paid Order Items keep their immutable retail snapshots and source cost ceiling.
- Stale observations cannot authorize checkout. Failed reads preserve the last observed data rather than inventing zero stock. A stale last observation is not a promise that purchases remain possible.
- Local stock is used first; external units cover the remainder when a safe source is eligible. Unresolved commitments reserve supplier balance and SKU capacity for existing orders.
- While any supplier purchase has an unresolved, uncommitted debit, sync retains the existing wallet observation: the provider may already have charged that purchase. Catalog freshness still updates, but local finalization must not subtract the same debit from an already-reduced wallet.
- If supplier balance is insufficient and no equivalent funded source is available, customer sees the ordinary unavailable state. Private Admin shows the funding warning.
- A genuine authoritative unavailable-to-available Product transition reuses existing Telegram/Store Channel restock notifications and Web waitlist events. Repeated sync, recovery from a transient outage, or restock of an unused primary while a backup remained available must not create duplicate announcements.
- Top-up langsung di supplier dideteksi pada tick sync yang sudah jatuh tempo. Koneksi sehat biasanya dibaca paling cepat tiap menit; ProdSeller memakai interval konservatif `max(60, ceil((jumlah SKU + 2) × 900 / 180))` detik karena setiap SKU bisa membutuhkan detail GET. Ini mengurangi full-catalog polling dan menyisakan budget untuk recovery/purchase. Freshness checkout tetap lima menit; katalog besar yang tidak dapat diperbarui dalam budget akan kedaluwarsa, **bukan** mendapat freshness palsu.
- Angka public adalah kapasitas pembelian aman, bukan raw stock supplier. Jika tetap 4 setelah sync berhasil, bandingkan saldo tersedia (total dikurangi dana ditahan), biaya/unit, unit ditahan, batas quantity provider, dan harga tier retail mulai quantity 5 terhadap minimum profit. Saldo dan raw stock besar tidak boleh melewati tier yang membuat pembelian rugi. Purchase dengan debit belum terselesaikan tetap menjaga wallet observation sebelumnya; selesaikan recovery, jangan menghapus hold.
- Kegagalan sync menyimpan `sync_failures` dan `sync_next_attempt_at` di DB: jeda 2, 4, 8, 16, lalu maksimal 30 menit. HTTP 429 dan local budget exhaustion menunggu setidaknya cooldown yang diketahui; sukses mereset failure count. Scheduler, `--supplier`, dan tombol Admin tidak dapat melewati backoff kegagalan, termasuk setelah Redis/cache hilang. Manual sync koneksi sehat tetap dapat meminta refresh sebelum interval otomatis.
- Client supplier umum menyimpan cooldown bersama per provider setelah HTTP 429, termasuk response pooled detail; `Retry-After` menerima seconds atau HTTP-date (maksimal satu jam, atau enam jam untuk Canboso), dengan fallback 15 menit untuk ProdSeller dan satu menit untuk provider lain. Selama cooldown, GET/POST berhenti sebelum HTTP. Local limiter tetap memisahkan read budget dari kapasitas purchase; tidak ada retry POST otomatis atau perubahan aturan ambiguity. BuyMail mempertahankan limiter/cooldown adapter existing.

### Automated pricing regression

`SupplierPricingTest` covers percentage/fixed/hybrid arithmetic, fractional-rupiah rounding, minimum-profit boundaries, quantity tiers, and true versus intermediate integer overflow. `SupplierMoneyTest` covers exact currency conversion and signed wallet presentation; `SupplierAvailabilityTest` exercises fresh eligible-source projection and retail updates.

`SupplierPricingClarityTest` covers the explicitly requested Admin render hint, manual/no-source copy, and browser/JSON simulation of a cheaper backup SKU without changing saved policy, retail, or ordered sources. Its automatic-price regression keeps rank 1 as the price basis even with zero stock/wallet capacity; the manual-price regression preserves manual retail. Run the Admin copy/preview regressions separately:

```sh
composer test -- --filter='SupplierPricingClarityTest|SupplierAdminTest'
```

Run locally from `src/`:

```sh
composer test -- --filter='SupplierPricingTest|SupplierMoneyTest|SupplierAvailabilityTest'
```

The normal CI suite includes these tests on PRs and pushes to `main`. `.github/workflows/supplier-pricing-regression.yml` also runs this focused suite daily at **03:00 WITA** (19:00 UTC), supports **Run workflow**, and retains a JUnit result for 14 days. Scheduled execution starts only after the workflow is present on the default branch; GitHub may delay its start. These checks use isolated SQLite and fake/blocked provider HTTP, never live supplier purchases or funding.

## Alert health supplier privat

`suppliers:notify-health --limit=25` berjalan setiap menit melalui scheduler
existing, background, dengan mutex 2 menit. Scan DB dibatasi 1–100 koneksi dan
1–100 obligation per invocation; cursor PostgreSQL berputar menurut ID sehingga
limit kecil tidak menahan koneksi/kasus di belakang selamanya. Scan memiliki
deadline 60 detik dan batas statement/lock PostgreSQL 10/5 detik; timeout
membatalkan transaksi, bukan mengonsumsi dedupe. Tidak ada supplier HTTP.
Untuk scheduler multi-server kelak, gunakan `onOneServer` dengan shared cache
yang didukung; mutex tetap bukan otoritas dedupe.

- Tujuan hanya `TELEGRAM_ADMIN_CHAT_ID` existing berupa private chat. Target
  kosong/tidak valid menghasilkan outcome aman dan tidak mengonsumsi episode.
  Tidak ada pesan ke Customer atau Store Channel.
- Saldo tersedia dihitung dengan integer micros `max(0, balance - reserved)`;
  nilai diketahui dan fresh, threshold positif, serta **<= threshold** memicu
  satu alert dalam episode low. Saldo unknown/stale bukan nol dan tidak menutup
  episode. `balance_observed_at` adalah waktu observasi wallet nyata: sync yang
  menahan wallet karena debit belum settled tidak memperbaruinya meskipun
  freshness katalog diperbarui. Cutover menunggu observasi wallet baru.
  Timestamp mengikuti kolom Supplier existing (`timestamp` tanpa timezone);
  nilai offset-free Eloquent dibaca dalam timezone aplikasi yang sama, bukan
  diinterpretasi ulang oleh timezone session PostgreSQL.
- Threshold nol menonaktifkan/reset episode. Observasi normal yang sah menutup
  episode agar low berikutnya dapat memberi alert baru. Sync hanya menyimpan
  observations/recovery; `low_balance_alerted_at` dimiliki materializer dan
  berarti waktu event + effect durable dibuat, **bukan** bukti Telegram menerima.
  Migration hanya mereset timestamp legacy sebelum writer baru aktif; delivery
  history tidak dihapus.
- Authentication, invalid key, credential unreadable, currency mismatch,
  account identity mismatch, dan duplicate account adalah fault kredensial/
  konfigurasi yang memberi alert segera. Transport dan rate limit adalah
  gangguan baca sementara: tidak ada alert pada satu atau dua kegagalan.
  Alert koneksi baru dibuat setelah **3 kegagalan baca berurutan** (ambang
  bounded; dengan backoff awal biasanya sekitar 6 menit), lalu satu episode
  koneksi dipakai bersama walaupun kode berubah transport↔rate limit.
  `suppliers:sync` tetap menjadi pemulihan baca otomatis yang durable melalui
  backoff DB 2–30 menit; semua operasi ini GET-only dan tidak membeli ulang,
  fallback, atau mengubah saldo.
  A→B→A tidak mengulang A. Episode baru hanya di-rearm setelah **15 menit
  observasi koneksi sehat**, bukan satu sync sukses di antara error. Error baru
  atau gap freshness mereset waktu recovery; wallet yang ditahan tidak menghalangi
  recovery koneksi. Review/hold/low-wallet tetap memakai episode sendiri, tidak
  disenyapkan oleh cooldown error. Pesan hanya memakai alasan allowlisted,
  tidak exception/header/body provider atau credential.
- Review/ambigu baru didedupe menurut identity record + `health_review_episode`,
  bukan state, total count, atau supplier version. `ambiguous → review` maupun
  recovery read dalam episode yang sama tidak mengirim ulang. Purchase unresolved
  tetap termasuk meskipun customer fulfillment delivered. Resolusi definitif
  membersihkan identity pada scan berikutnya; episode baru dapat mengirim lagi.
  Migration #573 mempertahankan dedupe legacy dengan membuang suffix state.
  Purchase/fulfillment obligation yang sama dihitung satu kali per koneksi.
  Tanpa purchase, reservation/snapshot yang dapat dibuktikan menentukan koneksi;
  jika tidak, pesan menyatakan **belum ada koneksi terpilih**.
- Observasi wallet berusia **>= 15 menit** menghasilkan alert tersendiri dari
  alert transaksi, termasuk ketika katalog tetap fresh. Supplier aktif atau
  mempunyai hold/purchase unresolved tetap dimonitor. Untuk wallet yang belum
  pernah ter-observasi, usia dihitung sejak Supplier dibuat. Alert menyebut waktu
  observasi terakhir dan maksimal 10 purchase blocker (ID, SKU ID, hold, waktu);
  saldo stale tidak ditampilkan sebagai saldo tersedia terkini. Episode ditutup
  setelah observasi wallet baru, bukan hanya karena sync katalog sukses.
- Hold purchase unresolved atau reservation tanpa purchase berusia **>= 15 menit**
  mendapat satu alert sampai hold diselesaikan/dilepas; perubahan state recovery
  tidak mengulangnya. Ini sinyal operator, bukan izin melepas hold atau membeli ulang.
- Kondisi transaksi baru per koneksi dalam scan digabung menjadi pesan ringkas:
  nama escaped, invoice, purchase/fulfillment ID, SKU ID, quantity, hold/currency,
  waktu, `dispatch_outcome_unknown` yang allowlisted, dan monitor privat.
  Detail dibatasi agar payload Telegram tetap ringkas; tidak membaca Customer,
  credential, delivery payload, atau exception provider.
  Credential unreadable memerlukan pasangan APP_KEY/backup atau
  re-entry terkontrol; invalid key memerlukan verifikasi/rotasi; ambiguous
  purchase memerlukan exact-order evidence, **jangan beli ulang**.
- Event, dedupe marker dan outbound effect commit atomik. Dispatch sesudah
  commit; Redis hanya wakeup. Jalankan command lagi atau
  `telegram:recover-outbound` untuk menemukan pending/definite-failed effect
  yang dispatch-nya hilang. Ledger existing membatasi retry definite failure.
  Status Telegram **unknown/ambiguous tidak blind resend**: tinjau monitor
  outbound dan bukti penerimaan sebelum explicit operator recovery. Ini bukan
  jaminan exactly-once pada jaringan Telegram.

### Bukti verifikasi #573

Smoke pengiriman nyata pada 7 Oktober 2026 memakai SQLite `:memory:` dan fixture
bertanda **SMOKE #573 / DATA SINTETIS**, tanpa supplier HTTP atau mutasi DB produksi.
Pipeline `SupplierHealthDeliveryPipeline` menerima acknowledgement Telegram dan
menyimpan `sent`, `attempts=1` untuk tiga efek:

| Sinyal | Effect ID | Telegram message ID |
| --- | --- | --- |
| Ambiguitas dispatch | `01a11509-2c5c-72e0-8994-e665e686c31a` | `1086197` |
| Hold menua | `01a11509-2c8a-727f-84b3-049c9936be2c` | `1086198` |
| Observasi wallet tertahan | `01a11509-2c8f-71a0-a3d7-f5b71adf3547` | `1086199` |

Smoke juga membuktikan dedupe scan ulang, transisi ambiguous→review tanpa efek
tambahan, delivery ulang efek sent tanpa resend, dan auto-clear setelah settlement
serta observasi baru. Ini bukti transport nyata dengan data sintetis, **bukan**
bukti deployment produksi atau resolusi historis Purchase #93.

Keputusan transaksi merujuk Context7 Laravel 13:
[transaction/deadlock retries](https://github.com/laravel/docs/blob/13.x/database.md),
[pessimistic locking](https://github.com/laravel/docs/blob/13.x/queries.md), dan
[after-commit dispatch](https://github.com/laravel/docs/blob/13.x/queues.md).
Event, dedupe dan effect tetap satu transaksi; HTTP hanya pada delivery setelah commit.

Smoke command isolated DB/HTTP fake menjalankan command aktual dua kali:
effect durable satu, Telegram request satu, state `sent`, HTTP transaction
level nol, supplier HTTP nol. Gunakan `SupplierHealthTest` dan
`SupplierHealthPostgresConcurrencyTest` untuk regression boundaries/races;
SQLite `SupplierHealthTest`: **37 tests, 366 assertions passed**. PostgreSQL 16
`SupplierHealthPostgresConcurrencyTest`: **2 tests, 27 assertions passed**, termasuk
dua materializer paralel yang hanya membuat satu episode wallet stale.
Lima acceptance/cutover scenarios #573 di PostgreSQL 16: **5 tests, 37 assertions
passed** (ambiguity/dedupe/clear, freeze/hold, ambang/rearm wallet, reservation hold,
dan preservasi episode legacy saat migration).
`php artisan schedule:list` harus menampilkan `* * * * *` untuk health scan.

## Fulfillment and recovery

The application buys external units only after authoritative customer payment. It saves dispatch intent before HTTP, sends outside database locks, then persists evidence before customer delivery. A timeout is not proof of rejection.

### Supervised purchase transport and crash evidence (#571)

`suppliers:recover` only dispatches `ProcessSupplierFulfillment` to connection
`supplier`, queue `suppliers`, for every provider. It never executes purchase
HTTP. The existing `laravel-supplier-worker` Supervisor program runs these jobs
with `tries=1`, `timeout=120`; the job fails on timeout and connection
`retry_after=180` exceeds that timeout. PostgreSQL discovery, not Redis job
survival, owns recovery.

The claim commits first. Immediately before financial transport, a second
transaction fences the live claim token and persists a
`supplier_purchase_dispatches` episode (`id`, Purchase FK, token, `started_at`,
`finished_at`, outcome and sanitized error). `dispatched_at` is set at this
transport-intent boundary, not when a fresh Purchase is claimed. A missing
intent and null `dispatched_at` mean a crashed claim did not authorize HTTP and
may resume. An intent means **possibly sent**, not proof the socket transmitted
or the provider accepted it: recovery marks its unfinished episode `unknown`
and retains the hold unless existing documented idempotency or provider
evidence resolves it. Legacy nonnull `dispatched_at` remains conservative.
The Purchase ID/reference, dispatch episode ID/token and durable review episode
provide reconciliation identity even after the active claim token is cleared.

Unknown-identity ToolOraX purchases are rediscovered in review and scanned by
the same supplier worker: at most three automatic GETs, 100 rows each, five
minutes between attempts. A persisted `scan_in_progress` row and the locked
Purchase/Order prevent concurrent scans; interrupted scans consume their
budget and remain inspectable. System scans have `actor_user_id = null`.
ShopBot timestamps have no offset, so automated UTC parsing is only a
placeholder: nonempty scans are `inconclusive` /
`history_timezone_unverified`, with no authoritative coverage interval.
Empty/truncated/unavailable reads never prove no-debit either. The worker
does not guess an order ID, POST again, or release a hold. An operator attests
the actual provider timezone, runs the existing history GET, then confirms
no-debit from a complete `no_order` scan or confirms debit through exact-order
GET. Both decisions remain audited and idempotent.

Inspect dispatch evidence read-only, without dumping keys or delivery payloads:

```sql
SELECT id, supplier_purchase_id, claim_token, started_at, finished_at, outcome, error_code
FROM supplier_purchase_dispatches
WHERE supplier_purchase_id = <purchase-id>
ORDER BY id;
```

Verification uses `SupplierIndependentRegressionTest` and the PostgreSQL
`SupplierPostgresConcurrencyTest`: the crash case kills a real `queue:work`
process with SIGKILL after financial dispatch starts, recovers one unknown
episode without a second POST, preserves the hold, then proves one no-debit
decision through the existing evidence action. A disposable PostgreSQL smoke
also exercised the actual recovery command and a separate worker process:
scheduler HTTP=0/pending → worker committed-intent POST → delivered/hold=0.
No real provider purchase is part of either scenario.

After release, inspect `supervisorctl status laravel-supplier-worker:*`, worker
job logs and the episode rows for an approved ordinary fulfillment. Correlate
its Purchase/reference with `ProcessSupplierFulfillment`; verify that the
scheduler only discovers/enqueues it. Production process observation is a
release gate, not evidence supplied by the local smoke. Do not trigger a real
purchase, retry Purchase #93, clear its hold, or kill production processes just
to test this fix.

Queue settings were checked with Context7 against the official
[Laravel 13 queue documentation](https://github.com/laravel/docs/blob/13.x/queues.md):
supervised workers, after-commit dispatch, timeout below `retry_after`, and
Supervisor shutdown grace above the longest job duration.


CSxStore's authenticated order responses use `status: completed` with boolean
`success: true`. The adapter recognizes that combination only for CSxStore;
exact order/SKU identity, quantity, actual cost and complete distinct delivery
remain necessary. This contract was verified through read-only order retrieval
and one operator-authorized SKU 48 purchase in the isolated Telegram test.

NyStore Official authenticates with `X-API-Key`, requires an `Idempotency-Key`
per purchase and returns `order_status: success|pending` with `delivered_products[]`.
The adapter sends the documented fastest mode (`precheck=false`,
`include_proofs=false`); the store still audits after delivery. A POST answered
with 503 `DELIVERY_VERIFICATION_PENDING`, or a 409 refund/idempotency conflict,
keeps the returned `order_id` as identity evidence so recovery reads that exact
order instead of buying again.

ToolOraX (ShopBot) authenticates with `X-Shop-API-Key` (or Bearer), buys with
`POST /orders {product_id, quantity}` and reads `GET /orders/{order_code}`.
The [first-party API documentation](https://shopbot.00969600.xyz/shop-api/docs)
documents a create success with `ok`, `order_code`, `quantity`, `total`,
`balance_after`, `channel` and `delivered_keys`, but no status, product identity
or currency. Canonical GET includes `product_id` and `status: fulfilled`.
Do not infer a trusted fulfilled outcome from `ok: true` alone: correlate the
exact order and SKU, quantity, actual cost and complete distinct delivery.
Documented pre-purchase conflicts (`Insufficient balance.`,
`Quantity is outside the available range.`) are safe rejections; any other 409
stays ambiguous. ToolOraX **`supportsIdempotency=false`**: no idempotency header
or automatic POST replay is authorized by the available contract. See the
qualification record below; documentation examples are not live purchase proof.

Both providers' balance and catalog were verified read-only with the operator's
keys; a funded live purchase still requires the controlled qualification in
**Production qualification** before real selling.

Only correlated exact identity and a recognized supplier outcome establish economic evidence. Explicit failure, wrong SKU/reference, or missing exact order ID cannot authorize delivery, debit settlement, hold release, or refund. Untrusted observations do not pin recovery to their claimed order ID. A locally blocked replay (including exhausted rate budget or an unsupported source) does not prove the original dispatched purchase was rejected; its ambiguity and hold remain.

| Observation | Operator action |
| --- | --- |
| Proven rejection before purchase/debit | Allow eligible equivalent fallback, or fix/top-up the source and use safe retry. |
| Known supplier order ID, delivery pending | Re-fetch that exact order; do not buy another order. |
| Complete delivery payload, actual cost absent | Retain encrypted payload and wallet hold; recover metadata from the same known order. Expected catalog cost is not proof of actual debit. |
| Unknown result, no ID | Inspect supplier records privately. Associate only a reliably verified order, not a guess based on name/time/quantity. |
| Partial/invalid delivery or identity/cost mismatch | Keep stored evidence and resolve through review. Do not repurchase the whole quantity. |
| Duplicate credential units | Retain encrypted evidence in review. Repeated single-use credentials cannot fulfill multiple units, even when the original debit is proven. |
| Stored complete delivery but Telegram failed | Recover/resend the stored customer delivery through the existing outbound ledger, never buy again. |
| No eligible source after customer paid | Fulfill manually or confirm a refund through existing review policy. Payment stays Paid until a real refund is applied. |

Private **Monitor pembelian / pemulihan** exposes safe purchase/fulfillment metadata and actions delegated to the supplier coordinator:

- retry after the source/top-up problem is resolved, only for states proven safe to retry;
- recover an exact supplier order reference;
- provide the complete manual replacement credentials when operator fulfillment is chosen.
- record **sudah diserahkan di luar bot** only from the supplier Manual Fulfillment Review. Select the exact fulfillment, record the delivered quantity and an auditable note after password confirmation. This never creates a Product Stock Item, credential message, replacement purchase, or Telegram receipt. It completes the customer obligation while preserving the original supplier purchase and hold for economic recovery.
- record **debit confirmed** only with the exact supplier order ID plus quantity and cost from provider evidence. The coordinator performs the provider's exact-order GET outside the transaction, compares ID, quantity and actual cost with those values, then reuses `recoverOrder()` to settle the original purchase. The evidence and audit row commit with the normal economic finalization. Any mismatch leaves the purchase ambiguous and keeps the hold.
- run the private **ToolOraX history GET** only for ToolOraX purchases with unknown order identity. It requests at most 100 order-history rows, records a hash, returned count, provider timestamp range, dispatch time, observation time, and operator-selected IANA timezone. It can prove `no_debit` only when the request starts after dispatch, the list is complete (`count < 100` and count matches returned rows), and the oldest returned timestamp reaches back to dispatch. A same-SKU order at/after dispatch is recorded as a candidate and still needs exact-order GET recovery. A saturated/truncated list, count mismatch, stale window, invalid timestamp/timezone, or unavailable endpoint is inconclusive; it cannot release the hold. The API docs show a local timestamp without an offset, so verify ShopBot's timezone before choosing one in the form.
- confirm **no-debit** only after password confirmation and an evidence source plus note. A ToolOraX `provider_history` confirmation must reference the latest durable scan for that exact purchase whose result is `no_order`; case/statement evidence must carry its provider reference. The original uncommitted Purchase becomes rejected and its hold is released once. This does not retry, fallback, send credentials, or refund automatically. A later retry, outside-bot handoff, or financial refund requires a separate explicit decision.
- read **CSxStore history candidates** for an ambiguous/review Purchase without a known order ID. This is operator discovery only, never no-debit evidence. Select a candidate explicitly and confirm through the existing exact-order debit recovery; even one matching candidate is not definitive identity.

Manual customer replacement does not prove that an ambiguous original supplier purchase was cancelled or refunded. Keep its held funds/evidence unresolved until the original economic outcome is established.

Do not infer `no_debit` from a stale wallet balance, a local rate-limit, or a failed retry. A provider order-history scan may be used as operator evidence only when its documented response window demonstrably covers the original dispatch instant and is complete; a truncated list, unavailable endpoint, or unverifiable timestamp remains ambiguous. Never POST a replacement merely because a history read is inconclusive.

The private Order detail page and paid Admin Telegram notification show three separate states: **payment**, **customer delivery**, and **supplier purchase**. The paid alert says payment was received; it includes the current delivery and purchase state so `Paid` cannot stand for delivered. Public Sales Proof remains a Paid Order Proof Event. Admin Product stock management shows the supplier's safe capacity and an allowlisted reason when it is zero (freshness, mapping, wallet/hold, SKU availability, or profit guard); none of this supplier data is included in Customer messages.

An allocated supplier fulfillment without `telegram_receipt_sent_at` is labelled as available fulfillment with an unconfirmed Telegram receipt. Outside-bot handoff remains a separately recorded completed customer delivery. Private supplier names, costs, holds, wallet observations and handoff notes on Order detail are restricted to Owner/Admin; Finance/Support cannot open the Admin Dashboard at all (403), so they never receive order-detail supplier economics nor the customer-delivery status there.

An actual fresh available-capacity transition still uses the existing `InventoryMovement → StockNotificationEvent → Telegram Outbound Effect` pipeline. Recovery discovers lost queue wakeups. A Telegram timeout stays `unknown` in the outbound ledger, and the pipeline excludes it from automatic retry; inspect Telegram and resolve the existing effect through Manual Delivery Review. Stale recovery or an identical sync creates no new transition event.

Outside-bot handoff quantities are included in the fulfilled quantity when calculating remaining manual delivery or missing-unit refunds. Exact-order recovery after even a partial handoff settles the original debit and leaves the remaining customer obligation in review; it never allocates the original full payload again. Automatic receipt materialization and delivery also check handoff records, including source discovery after a lost queue wakeup. An empty history list has no verified dispatch coverage and stays inconclusive. Proof forms bind the decision to the Purchase ID shown when opened, so a stale submission cannot release a different attempt's hold.

### CSxStore candidate-history recovery change — 2026-10-08 (#587)

**Admin → Supplier Reseller → Monitor pembelian** now exposes **Baca riwayat order CSxStore** for unknown-ID ambiguous/review purchases. Owner/Admin authorization and the existing fifteen-minute password confirmation remain required. The form binds the Purchase ID/version; durable scans snapshot its claim token, reject changed/in-flight purchases, and invalidate results if purchase identity/version/claim changes while HTTP is in progress.

The [first-party CSxStore docs](https://api.cssx.store/docs) document authenticated GET `/api/v1/orders` and GET `/api/v1/order/{id}`, but do not specify pagination parameters or timestamp timezone. Accordingly the adapter issues **one first-page GET**, without speculative pagination, following the existing fixed-origin `X-API-Key`, rate/cooldown budgets, twelve-second response/four-second connection bounds and two-MiB body ceiling. More than 100 returned rows reject the scan; no scan processes more than 100 rows. Reported remaining rows/pages or a saturated page are `truncated`; even an apparently complete first page is **unqualified/incomplete coverage**.

Candidate facts are encrypted and hidden from model serialization. The private monitor shows only allowlisted order ID, SKU, quantity, exact micro-cost and configured currency, recognized status (otherwise `unrecognized`), raw calendar timestamp, capture time, match reasons and limitations. Offset-free timestamps are `unknown_offset_free`, not UTC/Jakarta instants and not corrected by a guessed two-hour offset. Matching SKU/quantity/expected cost is a discovery heuristic, not proof of local Purchase identity; multiple candidates require a deliberate operator choice.

1. Read history without changing money, holds, allocation, Telegram obligations or completion. Empty/truncated/error/expired/rate-limited/malformed/transport-failed scans **never prove no-debit**. CSxStore history is explicitly ineligible for `provider_history` no-debit authority; provider case/statement decisions keep their existing separate requirements.
2. Review the chosen candidate and record the operator's attribution note. Use its **Pilih … dan konfirmasi debit melalui GET exact** action within fifteen minutes of capture. Latest-scan/Purchase/version/claim fencing and existing order-ownership collision checks run under recovery locks before the network claim. A stale form is rejected; reload/rescan instead of changing IDs manually.
3. Existing `confirmDebit` reads the exact order outside financial locks and compares its identity, required SKU, requested quantity, actual cost and recognized supplier outcome. Its existing evidence/audit/finalizer owns settlement; there is no second money writer or replacement POST. Customer allocation/completion still requires complete distinct delivery. Proven debit with incomplete delivery can settle economics while customer delivery remains in review; it must not allocate or send a receipt.
4. Check the correct Order Item's acquired credential allocation separately from economics. Receipt uses the existing outbound obligations. Verify receipt group effects are `sent` with Telegram message IDs/`sent_at` and the Order receipt marker; HTTP request counts or Completed alone are not delivery acknowledgements. Identical committed confirmations replay without another debit/allocation/receipt obligation.

Local synthetic verification exercises lost POST response → bounded discovery → explicit exact-order confirmation, private Admin browser states (single/multiple candidates, unknown timezone, error, stale form), real receipt job acknowledgement ledger and PostgreSQL concurrent confirmations/collisions. Regression entry points: `CsxStoreHistoryRecoveryTest`, existing supplier client/Admin/fulfillment regressions and `SupplierPostgresConcurrencyTest`. This is **not live provider qualification**, idempotency proof or a diagnosis of the historical transport failure. No production incident recovery, authenticated provider request or deployment is authorized by this change.

Framework patterns were checked using Context7 `/laravel/docs` branch `13.x`: [transactions/deadlock retries](https://github.com/laravel/docs/blob/13.x/database.md), [pessimistic locks](https://github.com/laravel/docs/blob/13.x/queries.md), and [HTTP timeouts/fakes/stray-request prevention](https://github.com/laravel/docs/blob/13.x/http-client.md). Retry closures contain only local DB work; network remains outside them.

### CSxStore contract qualification — 2026-10-09 (#588)

The [capability report](supplier-csxstore-capability.md) records the recaptured public documentation, unsuccessful Context7 provider resolution, per-capability `verified`/`unknown` findings, account/sign-off limitations and separate live-spend authorization gate. The docs still name no implementable idempotency identifier, timezone or complete-history guarantee. No provider capability is declared `unsupported` without a denial. Retain one-shot ambiguity and operator-confirmed exact-order recovery; this report enables no retry, automatic attribution, history-derived no-debit or rollout. Provider/Owner account-scoped sign-off remains absent.



### Missing Telegram receipt recovery — invoice `INV-20261005-01M4627XZT628TRQWH2SP343CW`

The production snapshot for Order `01a10c23-f7fa-70c5-91f9-5842998107af` proved Paid/Completed and Supplier Fulfillment `delivered`, but had no Telegram receipt marker or receipt effect. It did not establish whether the after-commit dispatch, worker materialization, or another runtime step failed. Do not attribute this invoice to scheduler OOM; that has separate ownership in #570.

1. In private Admin Order detail, verify Payment is `paid`, Order is `completed`, each Supplier Fulfillment is `delivered`, and the original supplier purchase/delivery is already recorded. Check the customer delivery field separately; supplier completion is not Telegram receipt delivery.
2. Check `orders.telegram_receipt_sent_at` and inspect only receipt ledger identity/state (never `outcome_metadata`, message payload, or credentials):

   ```sql
   SELECT order_status, payment_status, telegram_receipt_sent_at
   FROM orders
   WHERE invoice_number = 'INV-20261005-01M4627XZT628TRQWH2SP343CW';

   SELECT effect_group_key, sequence, state, attempts, telegram_message_id
   FROM telegram_outbound_effects
   WHERE source_type = 'App\Models\Order'
     AND source_id = '01a10c23-f7fa-70c5-91f9-5842998107af'
     AND effect_type = 'receipt.credentials'
   ORDER BY effect_group_key, sequence;
   ```

3. If any receipt effect is `unknown` or `manual_review`, stop automatic recovery. Verify the customer chat and resolve that same effect in Manual Delivery Review; do not create a credential replacement or explicit resend without duplicate-risk review. Existing `pending`/eligible `failed` effects belong to normal ledger retry.
4. Only when the receipt marker is NULL, no receipt effect group/resend exists, Payment is Paid, all Supplier Fulfillments are delivered, there is no external manual handoff, and the customer has a Telegram destination, run the normal chunked recovery command (also scheduled every minute):

   ```sh
   php artisan telegram:recover-outbound --chunk=25 --queue=notifications
   ```

   Source recovery materializes from this Order and its already allocated Product Stock Items. It does not settle payment again, call a supplier, or allocate stock. Repeated runs use the deterministic receipt group and ledger claim.
5. Re-run the read-only checks. A pending effect is a queued delivery, not proof of receipt; only the completed group sets `telegram_receipt_sent_at`. If the group remains absent, inspect sanitized `telegram.receipt_recovery_failed` logs by Order ID, stage, and error code. Never copy raw exception text or credential data into logs/tickets. An `unknown`/`manual_review` effect always requires operator evidence; it is never auto-resent.

This same procedure applies to a future missing receipt: do not replay supplier fulfillment, payment settlement, or raw `SendTelegramReceipt(force=true)` as a shortcut.

The history timestamp range must also end no later than the captured HTTP observation time. A future timestamp produces `provider_clock_inconsistent`, keeps the result inconclusive, and cannot authorize hold release. The form leaves provider timezone blank so an operator supplies its confirmed IANA timezone explicitly.

Local #565 verification includes the customer handoff/recovery/receipt and no-debit evidence regressions, fresh/stale supplier capacity transitions, traced successful/unknown recipient delivery, Admin render states, and the PostgreSQL two-worker no-debit race (one hold release, one evidence row, one audit row). The final full PostgreSQL concurrency group passed **226 tests / 2,137 assertions**, with zero failures, errors or skips and exit code 0. Source files under `src/app`, `database`, `resources` and `tests` had the same aggregate SHA-256 before and after the run: `b3a43b35793dfdb17cb2718258895688dea0706eac19cfe772a276081d403d37`. JUnit and debug output are retained locally at `/tmp/telegram-bot-565-gate-v2-junit.xml` and `/tmp/telegram-bot-565-gate-v2-phpunit.log`.

The final gate used isolated PostgreSQL 16 with a 1 GiB temporary data filesystem and `max_wal_size=128MB` / `min_wal_size=32MB`; durability settings were left at their defaults. An earlier interrupted run and a failed 512 MiB fixture that ran out of WAL storage are not counted as passed gates. PostgreSQL documents `max_wal_size` as a soft checkpoint threshold, so the fixture retained additional headroom rather than treating it as a hard disk cap. These isolated results do not establish the exact historical cause of the production stock/notification incidents; the issue's snapshots do not include the capacity transition and recipient outcomes needed for that attribution. No production data was changed.

### Incident attribution for #565

The recorded read-only production evidence in [#570](https://github.com/Alhiefikri/telegram-bot/issues/570) and [#571](https://github.com/Alhiefikri/telegram-bot/issues/571) establishes the observed sequence for Purchase #93: the non-BuyMail scheduler path claimed the purchase at 20:55:19 WIB; the supervised job lost the same claim race; the claim holder disappeared without a recorded transport outcome; and the next expired-lease recovery produced `dispatch_outcome_unknown` at 20:57:20. Scheduler OOM kills were recorded around that interval. This is evidence for the lost execution/unknown-debit incident; runtime OOM remediation and moving supplier transport to the supervised worker remain owned by those separate issues. #565 adds the audited path that can resolve the resulting domain obligation with provider evidence.

The Purchase #46 snapshot in #565 shows 1,853 raw SKU units, a 0.98 USDT hold for two units, and 10.68 USDT as the last wallet observation. Those values alone do not explain a capacity of zero: freshness, mapping, quantity-tier/profit policy and held units are also required. Likewise, a restock event must be joined to its recipient and outbound-effect outcomes to attribute a missing notification. The root cause for those historical reports remains unproven from the published snapshots; an OOM finding does not establish the cause of every missing restock or receipt. The Admin diagnostics and existing durable event/recipient recovery provide the concrete evidence to collect on recurrence.

### Historical capacity/restock evidence capture (#575)

**Authorized production audit, 7 October 2026, starting 14:18 WIB:** the Owner authorized SSH production diagnosis in the current conversation. Target `VM-0-7-ubuntu`, application timezone `Asia/Jakarta`; database capture used bounded PostgreSQL `REPEATABLE READ READ ONLY` transactions (read-only flag observed `on`, statement/lock deadlines 5/1 seconds). No production write, supplier HTTP, recovery command, Telegram send, hold release or deployment was performed.

The affected Product is **#5**, from Order Item #3679 / Fulfillment #49. Retained records establish these transitions and recipient outcomes on **5 October 2026 (WIB)**:

| Movement → Event | Time / capacity | Recipients / Effects | Telegram acknowledged | HTTP 403 | Unknown |
| --- | --- | --- | --- | --- | --- |
| #8377 → #735 | 18:38:45 / 0→6 | 2,409 / 2,409 | 2,054 | 354 | 1 |
| #8421 → #750 | 23:24:27 / 0→7 | 2,413 / 2,413 | 2,058 | 354 | 1 |

Both Event snapshots completed; recipient/effect counts match. Each includes **one Store Channel Effect in `sent`**, with the remaining rows addressed to Customers. Thus these two restocks were materialized and dispatched, not missing at the event/dispatch boundary. HTTP 403 is a recorded Telegram rejection; its specific reason is not established by the safe error code alone. Unknown recipient #845650 / Effect `39ecc9d9-54fb-4789-ad5a-b63fdef36378` (Event #735) and recipient #849854 / Effect `e0afae0c-ffbf-48a9-9957-50240569e60c` (Event #750) each have one attempt and `telegram_transport_ambiguous`; preserve Manual Delivery Review, not blind resend. API acknowledgement does not prove customer reading.

The preceding zero transitions are also retained: Movement #8376 at 18:37:13 (3→0), and #8420 at 23:21:31 (2→0). Their sold-out events #734/#749 have 1,708/1,063 Effects marked `sold_out_stale`: outdated sold-out messages were suppressed after capacity returned, not failed restock delivery. No further Product #5 `availability` Movements exist in the audited half-open interval 6 October 00:00 through 7 October 14:30. This does not prove every historical observation remained fresh or positive: projection expiry does not create an authoritative zero Movement.

Recovered local audit output from 5 October 19:28–19:29 records SKU #20900 unit cost 490,000 micro-USDT, stock 1,853, reserved quantity 2, wallet 10,680,000 micros and held funds 980,000 micros. At that later observation, wallet/stock bounds alone permit 19 units, so that hold alone does not explain zero. The current 7 October 14:19 capture has fresh active mapping, zero holds, wallet 2,289,999 micros, cost 380,000 micros, stock 350 and retail Rp11,000 / minimum profit Rp2,500; application capacity is six, reproduced through the actual Availability owner in an isolated smoke.

**Remaining capacity attribution gap:** no retained per-transition pricing/freshness/mapping/wallet/SKU decision snapshot was found in the audited records. Audit #3848 records retail Rp12,000→Rp13,000 at 18:38:29 and #3849 a source move at 18:38:45, but temporal proximity is not proof that price or priority caused the recovery. Current mutable rows and the later 19:28 snapshot cannot reconstruct the inputs at 18:37:13. Do not label ambiguity, OOM, price, priority or expiry as the historical cause without that evidence.

Source identity: running app/scheduler image digest `ghcr.io/alhiefikri/telegram-bot@sha256:d311c00f6b6624665fbfde3c1f469c69430ea72ffc4faa2127080099d9fbe072`, started 4 October; host checkout SHA `56294d70bdda9c9a0722a6b7c2eb3949211f1e81` is not proof of image SHA (image revision label absent). Deployed Availability source includes stale-to-fresh suppression, but lacks the newer `explainZeroCapacity()` Admin diagnostics. Historical scheduler log query for 5 October 18:30–19:00 returned no lines; Docker retention is three 10 MiB files, not guaranteed time coverage. Current scheduler logs show repeated non-zero `schedule:run` warnings while supplier observations continue to advance; these are not proof of the earlier capacity cause and runtime remediation stays in #570/#571. Direct host access to application log storage was permission-denied; no privilege escalation was used.

**Remediation decision:** no application defect was proven in these restock outcomes. Do not retry terminal 403 or unknown Effects, announce every supplier sync, relax profit/freshness guards or remove purchase holds. Existing #565 Admin diagnostics should be rolled out only through the separately authorized CI/deployment process, not direct SSH patching. For a future zero-capacity report, the operator/agent collects the bounded capture below; the Owner does not need to assemble SQL artifacts manually.

Verification: `SupplierAvailabilityTest` passed 19 tests / 170 assertions. An isolated application smoke (SQLite memory, queue fake, HTTP blocked) proved the old wallet/hold bounds permit 19 units under explicitly synthetic fresh/eligible inputs, stale→same-fresh creates no extra restock, an unprofitable synthetic cost gives zero despite positive raw stock, profitable retail restores capacity, and the captured current economics give six units. The synthetic cost/price scenario is not historical attribution. Context7's [Telegram Bot API response/sendMessage contract](https://core.telegram.org/bots/api) confirms that `ok=true`/returned Message is API success, not a read receipt; failure and flood-control response parameters remain distinct.

**Subsequent reproduced application defect / #575 fix:** `SupplierAvailability::refreshLocked()` took the earliest expiry of every positive-capacity source, although the advertised quantity is the maximum supported by one source, not their sum. A primary expiring in one minute and a fully capable fallback expiring in five minutes therefore made public stock zero after two minutes. The failing-before regression observed `availableStock() = 0` instead of 10 through the real Product boundary. This reproduces an intermittent false-zero pattern; it does not retrospectively prove the cause of the 5 October zero transitions.

The corrected projection pairs its capacity with the expiry of a source supporting that **entire** range. Equal-capacity sources choose the latest expiry; a smaller-capacity source neither shortens nor extends the larger range's cutoff. When the source backing the largest range expires, the scalar projection still fails closed until normal refresh reprojects the smaller remaining range. All-source expiry, quantity/profit constraints and purchase holds remain unchanged. Stale-to-same-fresh recovery still does not announce a fabricated restock: correcting a false unavailable presentation is not permission to broadcast every sync.

Seven additional `SupplierAvailabilityTest` regressions cover equal-capacity fallback longevity, a smaller short-lived fallback, a larger short-lived fallback that cannot borrow the smaller source's expiry, all-source expiry, lost restock wakeup after projection expiry, partial delivery recovery without duplicates, and mixed sent/terminal-403/unknown outcomes without resend. The focused `SupplierAvailabilityTest|SupplierPricingTest|SupplierIndependentRegressionTest|ProductInventoryRaceTest|ProductStockIntakeTest` run passed **153 tests / 1,194 assertions**. Actual isolated application smoke proved public availability after primary expiry, no false sold-out/restock cycle, authoritative zero-wallet rejection, durable genuine 0→10 restock, recovery of the lost wakeup, one acknowledged simulated Telegram send on job replay and unchanged commitments. These runs used disposable local data and blocked/fake HTTP, not production purchases or customer sends.

The fix retains the existing transaction/lock order and after-commit durable notification handoff. Context7 Laravel 13 references: [transactional pessimistic locks](https://github.com/laravel/docs/blob/13.x/queries.md), [transaction retry semantics](https://github.com/laravel/docs/blob/13.x/database.md), and [queued jobs/database transactions](https://github.com/laravel/docs/blob/13.x/queues.md). No new ENV, schema, financial policy or notification retry policy is introduced. Application remediation exists in the local worktree; release to production remains a separate authorized CI/CD operation.

PostgreSQL 16 verification on a disposable local database: `SupplierPostgresConcurrencyTest` passed **9 tests / 108 assertions**, with `--fail-on-skipped` and no skips. This includes sync racing checkout, shared-wallet lock ordering and supplier purchase/recovery races. The temporary database container and application smoke script were removed; no production database was used.

The final expanded `SupplierAvailabilityTest` run passed **26 tests / 209 assertions** after test cleanup, up from the initial 19-test suite.

For a recurrence, capture **before** sync, recovery or an Admin change:

1. Obtain explicit read-only authorization naming host/environment, Product IDs, incident window, approved tables/logs, maximum rows/pages and private artifact destination. Alternatively accept sanitized captured artifacts. Issue #575 itself does not grant production access. Do not guess a host, retrieve credentials, invoke supplier HTTP, or run `suppliers:sync`, `suppliers:recover`, `telegram:recover-outbound` or notification jobs as audit commands: they mutate state and/or enqueue transport.
2. Record capture start/end in UTC and application timezone, the reported surface/quantity and observed capacity, immutable deployed commit/image digest, application timezone and DB timezone. Bound log collection to the approved window and redact tokens, customer identities, message bodies, credentials and raw provider responses. Keep private economic facts out of customer-facing reports.
3. Use an approved read-only SQL connection and one short snapshot per bounded page. PostgreSQL `REPEATABLE READ` keeps reads within that transaction on a stable snapshot; a later page is a new observation, not an atomic continuation. Stop on timeout or row/page budget and label the capture incomplete. Do not hold an idle transaction open while collecting logs.

   ```sql
   BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY;
   SET LOCAL statement_timeout = '5s';
   SET LOCAL lock_timeout = '1s';
   SET LOCAL idle_in_transaction_session_timeout = '10s';
   SELECT CURRENT_TIMESTAMP AS captured_at,
          current_setting('TimeZone') AS database_timezone;
   -- Approved, bounded SELECTs only.
   ROLLBACK;
   ```

4. From the same snapshot, allowlist the affected Product's active/deleted state, `stock`, `reserved_stock`, `supplier_available_stock`, `supplier_available_until`, base prices and quantity tiers; count only local Available credentials (`supplier_purchase_id`, `order_item_id`, `retired_at` all NULL), retaining whether local credential history exists. Capture each mapped source ID/rank/active flag and SKU/Supplier ID. For Supplier retain activity/currency confirmation/pause, conversion rate, `balance_micros`, `reserved_balance_micros`, `balance_observed_at`, `synced_at`, `sync_expires_at`, version and safe error code; for SKU retain currency, stock/reserved quantity, orderability/input flag, unit micros, price tiers/bulk discounts and observation time. Capture Product price-policy mode, markup, profit, minimum-profit, rounding and version. Never use `SELECT *` or export encrypted payloads/keys. Include unresolved Purchase/Fulfillment IDs, quantity, hold, commit/debit status and dispatch episode timestamps/outcomes; customer handoff completion is not supplier economic resolution.
5. Discover Inventory Movements by exact Product ID and approved half-open time window, ordered by ID with an `id > :after_movement_id` cursor and `LIMIT 101` (100 rows plus an overflow sentinel). Retain ID, type, quantity, `available_before`, `available_after`, `became_unavailable`, creation time and notification marker. Include the last prior movement when authorized; distinguish an authoritative zero→positive observation from stale→fresh suppression. Record the returned last ID and whether the sentinel/page budget leaves undiscovered rows.
6. For each selected Movement, query its full notification chain in bounded recipient-ID pages. Bind `:movement_id` and `:after_recipient_id` (initially zero) as parameters, not pasted user text. Run inside the read-only block above. The source discriminator and effect type are required; another Effect on the same numeric source ID is not delivery evidence.

   ```sql
   SELECT m.id AS movement_id, m.available_before, m.available_after,
          n.id AS event_id, n.kind, n.recipients_snapshotted_at,
          n.materialization_cursor,
          r.id AS recipient_id, r.recipient_type,
          r.sent_at AS recipient_sent_at, r.failed_at,
          r.terminal_failed_at, r.attempts AS recipient_attempts,
          e.id AS effect_id, e.state, e.attempts AS effect_attempts,
          e.next_attempt_at, e.lease_until, e.sent_at AS effect_sent_at,
          e.unknown_at, e.manual_review_at, e.last_error_code,
          e.telegram_message_id
   FROM inventory_movements AS m
   LEFT JOIN stock_notification_events AS n ON n.inventory_movement_id = m.id
   LEFT JOIN stock_notification_recipients AS r ON r.stock_notification_event_id = n.id
   LEFT JOIN telegram_outbound_effects AS e
     ON e.source_type = 'App\Models\StockNotificationRecipient'
    AND e.source_id = CAST(r.id AS TEXT)
    AND e.effect_type = 'stock.notification_recipient'
   WHERE m.id = :movement_id
     AND (r.id IS NULL OR r.id > :after_recipient_id)
   ORDER BY r.id, e.id
   LIMIT 101;
   ```

   Keep the overflow row for the next page; advance by the last fully captured recipient, not past the sentinel. Confirm the deployed ledger's recipient-effect uniqueness before using this cursor; multiple matching Effects require a composite recipient/effect cursor rather than dropping rows. Restrict any expected-recipient lookup to an authorized identity and report opaque recipient IDs, not Telegram IDs. An absent recipient cannot establish exclusion reason without retained eligibility/preferences and materialization-time evidence.
7. Classify each boundary separately: no Event is an event-registration/retention gap; NULL `recipients_snapshotted_at` is incomplete materialization; a completed snapshot with no recipients needs audience/eligibility evidence; recipient without Effect is effect-materialization work; pending/due failed Effect without a dispatch claim needs worker/queue evidence. `sent` with Telegram message ID is an API acknowledgement, not proof the customer read it. Terminal failure explains that recipient's non-delivery; `unknown`/`manual_review` remains unproven and must not be blindly resent. The Movement notification marker alone is not a sent count.
8. Correlate sanitized scheduler/stock-worker/outbound logs with those exact IDs and timestamps, including dispatch, rate-limit deferral, worker interruption and recovery discovery. Record log/DB retention start/end and unavailable artifacts. Report observations, missing evidence and causal inference separately. Only a reproduced defect at the existing Availability or Stock Notification owner boundary authorizes a code fix/regression; preserve freshness suppression, purchase ambiguity holds and unknown-Telegram review.

The audit transaction pattern was checked through Context7's PostgreSQL 16 documentation: [BEGIN / read-only modes](https://www.postgresql.org/docs/16/sql-begin.html) and [Repeatable Read snapshot semantics](https://www.postgresql.org/docs/16/transaction-iso.html). Snapshot isolation is a capture tool, not a replacement for the application's financial locks or a historical time-travel query.

### Local incident-boundary verification

Focused local regressions:

```sh
cd src
php artisan test --filter='SupplierIndependentRegressionTest|SupplierAvailabilityTest|SupplierClientTest|ManualFulfillmentReviewTest|AdminDashboardSalesRenderingTest'
```

Laravel 13 lock/transaction boundaries follow the documented `lockForUpdate()`-inside-transaction and outside-lock HTTP pattern; queue handoffs dispatch after commit where durable state must exist first. See the official [pessimistic locking documentation](https://github.com/laravel/docs/blob/13.x/queries.md) and [database transaction/job after-commit documentation](https://github.com/laravel/docs/blob/13.x/queues.md).

Recovering the original purchase must never reopen completed customer fulfillment or revoke access to manually delivered credentials. Once exact order identity, matching quantity and actual debit are established, an original purchase without complete original delivery can become `settled`: its economics are known, while customer delivery remains the separate completed manual obligation. `settled` does not claim that the supplier delivered a complete payload. The hold clears once; recovery never allocates a second set of credentials.

When manual replacement is supplied after the original debit is already committed with exact identity, matching quantity, and actual cost, the original purchase becomes `settled` immediately; no further supplier GET is required. Unknown economics remain held and unresolved.

Trusted acquired-unit evidence is encrypted separately from raw delivery observations. Metadata-only or subset recovery retains the previous stronger evidence. Conflicting sets require review, never an inferred union. The evidence migration does not retroactively trust older raw payloads: unresolved legacy deliveries require an exact-order read with trusted delivery evidence before automatic completion.

Once a purchase debit is committed, a later trusted read with a different cost or quantity enters `economic_evidence_conflict` review. The committed economic fields remain unchanged; conflicting evidence cannot complete delivery or authorize a refund. Resolve the supplier discrepancy explicitly rather than editing the wallet or purchase row.

The purchase monitor keeps completed customer fulfillments visible while their original purchases remain unresolved. Such rows expose only original-order recovery, not repurchase or another manual delivery. They leave the actionable list after economic settlement; the private read-only purchase history remains.

Credential/provider/currency/rate changes are blocked while incompatible active commitments or uncertain purchases exist; they could switch account identity or invalidate recovery. Disabling new purchases does not erase existing obligations. Resolve those obligations rather than deleting rows or resetting counters.

All supplier purchasing/recovery/manual operations require password confirmation within 15 minutes, including the supplier-specific **Fulfill** action in the legacy Manual Fulfillment Review page. Ordinary non-supplier review actions keep their existing behavior.

Admin Product availability uses local-only existence/count projections plus fresh supplier capacity. Supplier-owned paid credential history remains bounded and read-only; it does not turn numeric local inventory into a credential-managed pool. The credential-history summary can show zero Available credentials while the Product still has numeric local stock.

## Refunds

- Store Balance customer refunds go through OrderLifecycle/StoreBalanceLedger and credit exactly once.
- QRIS/transfer refunds require provider confirmation where supported or the existing manual external refund evidence. Recording a refund is not itself a bank transfer.
- A full external refund after a registered partial refund records the unclaimed remainder as one confirmed OrderRefund from the same exact transfer evidence; pending or uncertain registered refunds must be confirmed or recovered first.
- Never silently convert an externally funded payment into Store Balance.
- In-flight, awaiting-delivery, ambiguous and unresolved partial purchases block refund because supplier debit/delivery may still exist.
- Delivered supplier credentials stay permanently order-owned and cannot return to local Available stock after refund.

## Scheduled and manual commands

```bash
php artisan suppliers:sync --limit=5
php artisan suppliers:sync --supplier=<supplier-id> --limit=1
php artisan suppliers:recover --limit=25
```

The scheduler launches bounded sync/recovery first each minute in the background with a two-minute overlap mutex, before synchronous payment/notification sweeps. Sync skips accounts observed less than one minute ago, keeps the five-minute freshness gate, uses attempted-at ordering for fairness and a durable per-account read lease. Stock-notification reconciliation also runs in the background, using family-scoped ledger queries and PostgreSQL recovery cursor indexes; historical terminal Telegram HTTP 400/403 failures no longer occupy its dispatch index. Recovery discovers durable PostgreSQL work even if Redis lost a wakeup job. Provider request budgets, bounded snapshots/bodies/catalog counts and freshness gates are safety boundaries; increasing only a row limit does not remove them.

Recovery selects due fulfillment IDs in one bounded query and queues every provider without inline HTTP. Warranty recovery classifies claim upload priority in batch queries: new, undispatched proofs retain the urgent `supplier-refunds` queue; polling and already-dispatched claims use `supplier-claims`. Customer catalog reads use Product availability projections without querying each supplier.

ProdSeller's list omits stock counts, so sync reads each missing product detail
in groups of at most five concurrent GET requests within the same 40-second
snapshot deadline. At most 100 details are allowed; oversized detail work is
rejected before sending those reads. Every request retains the provider/IP
budget, fixed origin, redirect prohibition and response-body limits. A failed
detail rejects the whole snapshot; no partial catalog is applied. Dashboard
reports safe causes such as API authentication rejection, rate limiting or
timeout, and disables the sync button while a request is running.

Run commands on the correct application environment using the established deployment process. Do not run `migrate:fresh`, manually rewrite supplier holds, or change paid source snapshots on production. Review `last_error` safe codes and timestamps in private Admin; the Supplier dashboard distinguishes **Saldo terakhir diamati** from catalog-sync time, shows held funds, and flags stale/unknown wallet observations. Do not paste raw provider payloads into logs, issues or customer messages.

## Production qualification

Keep three approvals separate: **code gate** (final-revision isolated tests/CI), **live supplier qualification** (authorized account/contract/debit/delivery evidence), and **production ops sign-off** (credentials, runtime and recoverable backups). Passing one never marks the others complete. This checklist does not authorize deployment, credential rotation, threshold changes, backup creation/restore, or a supplier purchase.

Other existing qualification obligations remain: obtain CSxStore's missing idempotency identifier contract before permitting replay; ARRS/Warzone stay one-shot on ambiguous requests without documented dedupe. Provider resale rights, equivalent entitlement, warranty/refund terms and externally visible branding also require operator review.

### ToolOraX contract qualification — 2026-10-04

**Result: partial documented contract; server idempotency unproven; funded purchase and production readiness not signed off.** No supplier POST, authenticated account read, spend, SSH connection, deploy or live mutation was performed in this qualification research.

Sources and accessibility:

- First-party [Shop API docs](https://shopbot.00969600.xyz/shop-api/docs): HTTP 200, **REST v1**, base/origin `https://shopbot.00969600.xyz/shop-api/v1`. Retrieved at `2026-10-04T04:23:29Z`; raw HTML SHA-256 `93843723472a9de583dd29d83c544981b9cd8cd0a75fca9d664c2397c7ed4ad8`. No ETag, Last-Modified, commit SHA or finer contract revision was exposed. This hash identifies the observed documentation snapshot, not the deployed server implementation.
- Actual public GET [health](https://shopbot.00969600.xyz/shop-api/v1/health) returned `{"ok":true,"service":"shop-api","status":"ok","version":"v1"}`. Health proves neither account access nor purchase/idempotency behavior.
- GET `/shop-api/v1` and `/shop-api/v1/docs` returned 404; `/shop-api` resolved to the public shop. The correct docs route above succeeded. Public [catalog](https://shopbot.00969600.xyz/catalog) was readable, but its dollar prices/public product slugs are not account currency, account API quotes or numeric API SKU identity.
- Authenticated GitHub owner/affiliated repository inventories were accessible. Code searches for `"shop-api"`, `"X-Shop-API-Key"` and `"delivered_keys" "order_code"` under `Alhiefikri`, plus `"shop-api"` under the other accessible owner identities (`aliffikripedia`, `alitefikri`, `fikripedia46`), returned no matches. This is scoped search evidence, not proof that a private/unindexed server repository does not exist.
- Local `/home/alhiefikri/GITHUB` source/docs search found `api-store-buyer/server.js:254-275`, a **client** that submits `{product_id, quantity}` and consumes `order_code`, `total`, `delivered_keys`; it does not establish server enforcement. No shop-api server implementation was located in the inspected local repository inventory/source matches. Broad semantic/web search tools were unavailable; primary docs above were fetched directly.

Code handling follows the observed envelope: only an otherwise correlated create with an unrecognized/missing status (`invalid_order_status`) is read once through the exact order endpoint, **including when POST contains delivery lines**. POST identity/payload mismatch is never hydrated into apparent success. Canonical GET must confirm exact ID/SKU, requested quantity and actual cost; available POST quantity/cost and the distinct delivery-unit set/count must agree with it. A failed GET or conflict retains an ambiguous known order for recovery without treating unrecognized POST deliveries as trusted allocation. `SupplierClientTest` covers documented delivery-bearing create, GET failure, mismatched/missing metadata/delivery, and no extra request for POST mismatch/missing ID/unknown conflict. These isolated regressions require the final code gate; they are not live qualification proof.
Failed or conflicting hydration retains only the correlated order ID: POST quantity, debit and delivery are withheld from settlement. The persisted financial hold remains until authoritative recovery resolves the evidence. `SupplierFulfillmentTest` proves a differing GET debit neither commits the POST debit nor allocates its delivery units.

The following are **sanitized first-party documentation examples, not responses from a purchase performed here**. Only the documented dummy delivery line is replaced with a redaction marker:

```json
{
  "ok": true,
  "order_code": "ORD-1788524767-3AA1A5",
  "quantity": 1,
  "total": 3.000,
  "balance_after": 22.500,
  "channel": "api",
  "delivered_keys": ["<redacted-unit-1>"]
}
```

Canonical `GET /orders/ORD-1788524767-3AA1A5` documentation example (non-economic descriptive fields omitted):

```json
{
  "ok": true,
  "order": {
    "order_code": "ORD-1788524767-3AA1A5",
    "product_id": 2,
    "quantity": 1,
    "total": 3.000,
    "status": "fulfilled",
    "channel": "api",
    "delivered_keys": ["<redacted-unit-1>"]
  }
}
```

Documented POST 409 error examples:

```json
{"error":"Insufficient balance.","required":1.500,"balance":0.200,"shortfall":1.300}
{"error":"Quantity is outside the available range."}
```

There is **no documented pending POST envelope**, `order_id` alias, merchant reference correlation, explicit ISO currency, or exact-order pending/error envelope. Do not fabricate examples for those gaps. Docs describe `order_code` as unix time plus random hex, key-owner order access, three-decimal money, immediate debit and `fulfilled` order creation. API price is list price plus optional API discount, floored at cost; Telegram bulk tiers do not apply. A backup API fulfillment can alter the quoted source charge. These statements do not establish a price lock, actual account currency, or live delivery validity.

| Server idempotency requirement | Evidence/result |
| --- | --- |
| Supported and enforced `Idempotency-Key` | Not documented; no server implementation available. |
| Key scope per shop account (including key rotation) | API key is documented as account-bound, but **dedupe-key scope is not**. |
| Retention window, expiry semantics and replay boundary | Not documented; the application's existing ten-minute window is not a supplier guarantee. |
| Atomic concurrent duplicates, one wallet debit/stock allocation | Not documented/proven. |
| Replay returns the same response/order and economic effect | Not documented/proven. |
| Same key/different payload rejects without debit/allocation | Not documented/proven. |

Therefore retain [ToolOraX capability `false`](../src/app/Enums/SupplierProvider.php) and do not send a speculative header or blind replay. Unknown outcome stays held/ambiguous; only a trusted exact order identity authorizes GET recovery, never heuristic name/time matching or supplier fallback. Prerequisites for stronger qualification are authorized server repository/OpenAPI access with deployed revision, provider confirmation of each row above (or explicit unsupported result), authoritative account currency, and privately sanitized historical POST success/pending/error plus matching exact-order GET evidence. Existing fake-response fixtures and the local buyer adapter do not substitute for these.

### Authorized controlled purchase

Before **any spend**, complete the security/currency/backup prerequisites below. Explicit user authorization must identify account, environment, numeric API SKU, quantity **1**, current quote/currency and **maximum permitted debit**; separately authorize a duplicate test only if idempotency is verified. A public catalog's cheapest-looking product is not an approved API SKU/quote.

1. With the rotated key, use authorized read-only profile/catalog/detail evidence to select the cheapest eligible equivalent SKU: funded balance after holds, valid input/delivery type, orderable stock and quantity limits. Record private account identity, observation time and quote; stop on currency mismatch or missing evidence.
2. Use one persisted Supplier Purchase identity/reference and the normal financial coordinator, not an ad-hoc purchase script. Record before/after Supplier Balance, actual debit and currency, exact supplier order identity, exact GET retrieval and distinct valid delivery count privately. Publish only sanitized economic/result metadata, never delivered credentials.
3. With current ToolOraX idempotency unproven, **do not submit a second POST**. Demonstrate local repeat blocking in isolation and exact-order GET recovery instead. If subsequently verified and expressly authorized, replay only the identical account/SKU/quantity/persisted reference/key inside the guaranteed retention window, proving unchanged order/debit/allocation.
4. Timeout, unknown debit or conflicting/incomplete evidence stops all repurchase/fallback. Retain holds and review the original outcome; do not automatically refund or replace the purchase identity. Check supplier branding/resale and warranty terms independently of financial success.

### Credential, currency and threshold sign-off

1. Back up **APP_KEY plus DB as a recoverable pair before rotation**; review outstanding holds/ambiguous obligations and account-change guards. Rotation needs explicit ops authorization. Do not bypass `SupplierManager` by editing encrypted DB rows or inventing maintenance authorization.
2. Rotate exposed NyStore/ToolOraX keys at the provider and enter replacements through Admin. ToolOraX docs specify Website **Account → API → Create / Rotate** or Telegram **Profile → API Key**. Verify replacement using authorized GET-only sync on the same account, cut over safely, then revoke the old key; record safe identity/timestamps, never key values. Resolve uncertain obligations or obtain an approved maintenance plan before an incompatible account change.
3. Obtain actual **both-account currencies** from authoritative profile/account evidence or provider contract confirmation. Repo defaults are USD, but ToolOraX docs/examples have no ISO currency field. Neither `$` nor funding via USDT establishes wallet USD. A mismatch blocks selling and requires a contract change, not ENV/presentation overrides.
4. Set each low-balance threshold through Admin in the actual currency's integer micros, based on several relevant fulfillment costs and expected top-up lead time. Record nominal amount, currency, cost observations and rationale. No actual threshold/amount is approved by this research.

### Runtime, backup and restore sign-off

**Access evidence in this research:** `ssh://` reported no configured hosts; local SSH config alias was absent. Local key-file presence does not identify/authorize a destination. GitHub repository secret **names/updatedAt only** list `PRODUCTION_HOST`, `PRODUCTION_PATH`, `PRODUCTION_SSH_PORT`, `PRODUCTION_SSH_PRIVATE_KEY`, `PRODUCTION_USER` (updated 2026-07-03); production environment secret metadata was empty. GitHub does not expose existing secret values through this access. No VPS was connected to or audited, and no destination was guessed from keys/known_hosts. Prerequisite: an authorized host/user/port/path, host-key verification and approved read-only access; obtain mutation/drill permission separately.

Repository configuration is **not runtime proof**:

- [Supervisor](../supervisord.conf) configures `laravel-supplier-worker` on connection `supplier`, queue `suppliers`, `--tries=1`, `--timeout=120`; [queue config](../src/config/queue.php) gives `retry_after=180`, greater than timeout. The outbound worker consumes `telegram-outbound`.
- [Compose](../docker-compose.yml) configures a scheduler heartbeat healthcheck with maximum age 180 seconds. This does not prove a running healthy container or recent executed jobs.
- [DB backup script](../scripts/backup-db.sh) emits timestamped, GPG-encrypted `pg_dump` backups, sets mode 600 and verifies decryptable gzip. It does **not** back up APP_KEY, prove isolated PostgreSQL restore, or establish offsite retention/access policy.

On the authorized VPS, collect safe timestamped evidence of deployed revision and immutable image digest; scheduler container health/recent heartbeat and `schedule:list`; actual latest sync/recover execution; Supplier Balance observation freshness; Supervisor supplier/outbound workers RUNNING with effective connection/queues, timeout/tries/retry_after; and failed/backlog counts including delayed/reserved jobs. RUNNING alone is not draining evidence: inspect recent successful non-purchase execution or authorize a safe non-purchase job in a specified environment. Do not buy a product to test workers, emit full Compose config, or dump secret-bearing logs.

Obtain versioned/encrypted/access-controlled APP_KEY escrow paired with each DB backup, offsite retention and access owner, latest successful backup metadata, and restore-drill permission plus an isolated target with supplier/Telegram HTTP disabled. Restore DB there and prove supplier credentials decrypt with the matching original key **without printing plaintext**, recording only success/count, artifact identifiers, timestamps and revision. GPG/gzip readability is not a DB/decryption restore drill. Never regenerate APP_KEY; loss/mismatch is `credential_unreadable`, not `invalid_key`, and requires original-key recovery or controlled credential re-entry.

**Outstanding ops prerequisites:** actual authorized VPS access/runtime evidence; outstanding-obligation review; paired APP_KEY/DB backup and isolated restore evidence; authorized provider/Admin rotation and old-key revocation; actual NyStore/ToolOraX currencies and justified thresholds; controlled-purchase approval/evidence. No production readiness checkbox is satisfied merely by this documentation or green CI. [Manual production deploy](../.github/workflows/deploy-production.yml) additionally requires a successful push-CI on the exact `main` revision and its bound image; PR CI is not deployment authorization.

See [implementation plan](supplier-api-reseller-implementation-plan.md), [ADR-0016](adr/0016-private-supplier-fulfillment-and-capacity.md), [codebase guide](agents/codebase.md) and [real external validation](pre-production-real-external-validation.md).
