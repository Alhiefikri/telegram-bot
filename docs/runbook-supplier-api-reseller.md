# Supplier API Reseller — operator runbook

## Boundary

Supplier management is private to Owner/Admin. Customer catalog, invoices, product copy and support remain store-branded. Supplier names, balances, modal prices, API keys, references and raw failures must not be forwarded to customers.

Supplier Balance is the operator's money at the external shop, not Customer Store Balance. A supplier's stock count is not owned inventory: availability is bounded by balance, existing commitments, freshness, equivalent-product mapping and minimum-profit policy. Customer payment can be Paid while Supplier Fulfillment is still pending or in Manual Fulfillment Review.

## Setup through Admin

Dashboard menampilkan setiap koneksi sebagai kartu dengan status aktif/nonaktif, saldo tersedia, saldo total, dana ditahan, dan waktu sinkron. Aksi monitor, katalog, sinkronisasi, serta deposit BuyMail tersedia sebagai tombol; form Tambah/Edit koneksi dapat dibuka tanpa berpindah halaman. Daftar produk toko menampilkan harga jual dan status publik dengan tombol **Atur supplier & harga**. Katalog privat memakai kartu produk dengan harga beli, stok, dana/unit ditahan, pemetaan, dan form impor/hubungkan yang dapat dibuka per produk. Semua halaman supplier mendukung layout mobile serta pilihan tema terang/gelap.

1. Open **Admin → Supplier Reseller** and fill the connection form. Clicking **Buat/Simpan supplier** opens an inline password dialog if recent confirmation is missing; successful confirmation resumes saving automatically. Incorrect passwords or cancelling retain the API key and other inputs in the current browser form. No secret is flashed to server session or persisted to browser storage. Operational recovery still requires recent confirmation.
2. Add a connection: supported provider, API key, whole-IDR conversion rate, active flag and low-balance threshold. The Dashboard derives the private name and configured currency from the provider: ProdSeller uses USDT; CSxStore, AI Vault, NyStore Official and ToolOraX (ShopBot) use USD; BuyMail uses IDR with rate 1. Only ARRS/Warzone expose account-currency selection and explicit confirmation. Fixed-provider currency overrides are rejected by request validation. The configured USD for NyStore/ToolOraX is not production account-currency sign-off; see **Credential, currency and threshold sign-off**.
3. Do not put supplier API keys in ENV. The database stores encrypted credentials using the application's encryption key; saved keys are not displayed back. Keep APP_KEY and encrypted database backups together under the existing backup policy. Losing the key makes those credentials unreadable.
4. For ARRS/Warzone, confirm the actual account currency with the supplier. Also obtain authoritative currency evidence for the actual NyStore/ToolOraX accounts before activation or spend. Dollar-like presentation and USDT funding are not wallet-currency evidence. A mismatch must block activation and receive contract review, not a forced dropdown/ENV override.
5. Activate the connection and click **Sinkron katalog dan saldo**. The local button is a CSRF-protected POST, but provider catalog/balance requests are GET-only. Read-only catalog fetching must not buy anything.
6. Open the private catalog. Select a SKU to import as a draft Product, or open **Hubungkan ke produk toko yang sudah ada** and select a Product by name from the **Produk toko** dropdown. Options show retail price and active/draft status; the ID is only a disambiguating label, not an input to memorize. Drafts and Products mapped to other suppliers remain selectable. Deleted Products and Products already mapped to this SKU are excluded. Confirm equivalent entitlement before saving; edit an existing mapping through its linked Product button.
7. Give the draft store-owned name, description, tutorial, warranty and support terms through normal Product editing. Import does not publish the supplier description or activate the Product automatically.
8. Enable the source mapping and arrange the try order with the **↑ Naikkan / ↓ Turunkan** controls. "Urutan 1 · utama" is tried first; later ranks are the fallback. The page shows each rank explicitly, so you never have to compare numbers: it keeps the underlying priority normalized to 10, 20, 30, … and lower values still sort first internally. Each card also shows the last observed unit cost in IDR and the supplier currency (`Harga beli/unit`), or a "Belum ada harga" warning when the catalog has no price yet — read-only display, never a purchase authorization. Only map products whose entitlement, region, account requirements, redemption deadline and warranty promises are equivalent.
9. Set Product-owned pricing: manual, percentage, fixed profit or hybrid percentage/minimum profit; configure buffer, rounding and minimum-profit guard. Preview the cost/retail calculation before enabling public sales.
10. Top up the external supplier account through that supplier's own supported payment flow. This application does not create a supplier funding transaction. Sync after funding to update availability.

An account supports multiple SKUs with one credential. Adding another SKU from an implemented provider is an Admin operation, not a code change. A new provider contract or an unsupported fulfillment/input type still needs implementation and qualification.

## Nonaktifkan atau lepas mapping

- **Nonaktifkan**: hapus centang **Gunakan supplier ini untuk membeli produk**, konfirmasikan ekuivalensi dan simpan. Mapping tetap ada; pembelian baru tidak memilihnya, sementara histori dan pemulihan obligation yang sudah ada tetap terlindungi.
- **Lepas mapping**: pada halaman **Atur supplier & harga**, gunakan tombol terpisah **Lepas mapping**. Konfirmasi menampilkan nama koneksi/SKU; **Batal** tidak mengirim mutasi. Ini hanya untuk mapping yang belum digunakan: semua purchase, termasuk terminal/settled, reserved SKU, dan retained snapshot (termasuk SKU cadangan yang belum dibeli) untuk Product tersebut menghalangi pelepasan dengan 409. Histori SKU yang sama pada Product lain tidak menghalangi.
- Jika snapshot terenkripsi tidak dapat dibaca, pelepasan ditolak dengan pesan aman. Pulihkan pasangan APP_KEY/backup atau minta pemeriksaan operator; jangan menghapus histori untuk melewati guard. Form yang basi atau identity mapping yang tidak cocok ditolak dengan 422; muat ulang halaman sebelum mencoba lagi.
- Sukses hanya menghapus mapping dan menormalisasi urutan tersisa (10, 20, 30, …), mempertahankan relative order. Audit `supplier.source_detached` menyimpan identity/rank/version tanpa secret atau snapshot. Supplier mutation version naik, proyeksi kapasitas diinvalidasi dalam transaksi dan direfresh setelah commit. Product, koneksi, Catalog Item, credential, pricing policy, tier, dan histori tetap tersimpan; melepas source terakhir menghapus kapasitas eksternal, bukan stok lokal atau harga manual.
- `SupplierAdminTest` mencakup sukses/audit/rank/version, source terakhir, histori terminal, backup snapshot, unreadable snapshot, Product lain, stale/wrong identity, authorization dan CSRF. `SupplierPostgresConcurrencyTest` memakai dua proses dan bukti lock waiter PostgreSQL untuk checkout-first (detach ditolak) serta detach-first (checkout tidak menyimpan source yang sudah dilepas).


## Pricing and availability

- Manual retail price is never overwritten by sync.
- Automatic policy updates base IDR price using configured supplier conversion rate; existing quantity tiers must remain valid and their actual retail totals are checked against the purchase profit floor.
- Basis harga otomatis tersimpan adalah supplier aktif dan layak pertama menurut urutan: **Urutan 1 diprioritaskan**, lalu source berikutnya jika Urutan 1 tidak memenuhi syarat basis harga. Ini bukan pemilihan harga termurah atau janji bahwa kartu Urutan 1 selalu dipakai. Filter canonical tetap milik Supplier Availability/Pricing: mapping/koneksi aktif dan tidak dijeda, currency terkonfirmasi dan sesuai, SKU orderable tanpa input tambahan, observasi segar, serta harga dan jadwal tier valid. Kelayakan basis harga tidak menjamin stock/wallet cukup saat checkout; perhitungan harga dilakukan sebelum pemeriksaan kapasitas pembelian.
- **Hitung simulasi berdasarkan SKU** hanya menghitung SKU terpilih dengan pengaturan form yang belum disimpan, termasuk SKU cadangan. Preview tidak menyimpan policy/retail, mengubah urutan mapping, atau memilih source basis harga tersimpan. **Simpan pengaturan harga** menyimpan kebijakan; SKU pada dropdown simulasi bukan source selector. Mode manual tetap memakai harga dari halaman Produk, dan tanpa mapping tidak ada source untuk harga otomatis atau simulasi. Pesanan yang sudah dibuat tetap memakai snapshot komersialnya.
- Percentage markup is calculated from converted supplier cost, not from the cost plus buffer: `ceil(cost_IDR × basis_points / 10000)`. `100` means 1%; `1000` means 10%. Fixed mode uses `profit_idr` and ignores a previously saved percentage. Hybrid uses the larger of the percentage amount and `profit_idr`, not their sum.
- Automatic retail is supplier cost + selected markup/profit + buffer, rounded upward to the configured IDR multiple. `minimum_profit_idr` is a separate purchasing guard, not another markup: every external unit must retain that minimum after its buffer. A computed price below that guard does not authorize a purchase.
- Configured conversion rate should reflect actual cost of funding the supplier, not an assumed live exchange rate. Pending/paid Order Items keep their immutable retail snapshots and source cost ceiling.
- Stale observations cannot authorize checkout. Failed reads preserve the last observed data rather than inventing zero stock. A stale last observation is not a promise that purchases remain possible.
- Local stock is used first; external units cover the remainder when a safe source is eligible. Unresolved commitments reserve supplier balance and SKU capacity for existing orders.
- While any supplier purchase has an unresolved, uncommitted debit, sync retains the existing wallet observation: the provider may already have charged that purchase. Catalog freshness still updates, but local finalization must not subtract the same debit from an already-reduced wallet.
- If supplier balance is insufficient and no equivalent funded source is available, customer sees the ordinary unavailable state. Private Admin shows the funding warning.
- A genuine authoritative unavailable-to-available Product transition reuses existing Telegram/Store Channel restock notifications and Web waitlist events. Repeated sync, recovery from a transient outage, or restock of an unused primary while a backup remained available must not create duplicate announcements.

### Automated pricing regression

`SupplierPricingTest` covers percentage/fixed/hybrid arithmetic, fractional-rupiah rounding, buffers, minimum-profit boundaries, quantity tiers, and true versus intermediate integer overflow. `SupplierMoneyTest` covers exact currency conversion and signed wallet presentation; `SupplierAvailabilityTest` exercises fresh eligible-source projection and retail updates.

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

`suppliers:notify-health --limit=25` berjalan setiap 15 menit melalui scheduler
existing, background, dengan mutex 10 menit. Scan DB dibatasi 1–100 koneksi dan
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
- Threshold nol menonaktifkan/reset episode. Observasi normal yang sah menutup
  episode agar low berikutnya dapat memberi alert baru. Sync hanya menyimpan
  observations/recovery; `low_balance_alerted_at` dimiliki materializer dan
  berarti waktu event + effect durable dibuat, **bukan** bukti Telegram menerima.
  Migration hanya mereset timestamp legacy sebelum writer baru aktif; delivery
  history tidak dihapus.
- Authentication, invalid key, credential unreadable, rate limit, dan transport
  masing-masing memberi satu alert per kode dalam episode error aktif.
  A→B→A tanpa normal recovery tidak mengulang A. Pesan hanya memakai alasan
  allowlisted, tidak exception/header/body provider atau credential.
- Review/ambigu baru didedupe menurut identity record/state yang sedang aktif,
  bukan total count atau supplier version. Purchase unresolved tetap termasuk
  meskipun customer fulfillment delivered. Purchase/fulfillment obligation yang
  sama dihitung satu kali per koneksi. Tanpa purchase, reservation/snapshot yang
  dapat dibuktikan menentukan koneksi; jika tidak, pesan menyatakan
  **belum ada koneksi terpilih**. Resolusi saja tidak menimbulkan alarm baru.
- Kondisi baru per koneksi dalam scan digabung menjadi pesan Indonesia ringkas:
  nama escaped, saldo/currency, waktu observasi/stale, alasan aman, jumlah review,
  saran aksi, dan monitor privat. Daftar panjang tetap di monitor, bukan payload
  Telegram. Credential unreadable memerlukan pasangan APP_KEY/backup atau
  re-entry terkontrol; invalid key memerlukan verifikasi/rotasi; ambiguous
  purchase memerlukan exact-order evidence, **jangan beli ulang**.
- Event, dedupe marker dan outbound effect commit atomik. Dispatch sesudah
  commit; Redis hanya wakeup. Jalankan command lagi atau
  `telegram:recover-outbound` untuk menemukan pending/definite-failed effect
  yang dispatch-nya hilang. Ledger existing membatasi retry definite failure.
  Status Telegram **unknown/ambiguous tidak blind resend**: tinjau monitor
  outbound dan bukti penerimaan sebelum explicit operator recovery. Ini bukan
  jaminan exactly-once pada jaringan Telegram.

Smoke command isolated DB/HTTP fake menjalankan command aktual dua kali:
effect durable satu, Telegram request satu, state `sent`, HTTP transaction
level nol, supplier HTTP nol. Gunakan `SupplierHealthTest` dan
`SupplierHealthPostgresConcurrencyTest` untuk regression boundaries/races;
`php artisan schedule:list` harus menampilkan `*/15 * * * *`.

## Fulfillment and recovery

The application buys external units only after authoritative customer payment. It saves dispatch intent before HTTP, sends outside database locks, then persists evidence before customer delivery. A timeout is not proof of rejection.

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

Manual customer replacement does not prove that an ambiguous original supplier purchase was cancelled or refunded. Keep its held funds/evidence unresolved until the original economic outcome is established.

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

The scheduler runs bounded sync/recovery each minute in the background with a two-minute overlap mutex. Sync skips fresh accounts, renews observations within one minute of their five-minute expiry, uses attempted-at ordering for fairness and a durable per-account read lease. Recovery discovers durable PostgreSQL work even if Redis lost a wakeup job. Provider request budgets, bounded snapshots/bodies/catalog counts and freshness gates are safety boundaries; increasing only a row limit does not remove them.

Recovery classifies fulfillment providers and claim upload priority in batch queries, not one lookup per record. New, undispatched warranty proofs retain the urgent `supplier-refunds` queue; polling and already-dispatched claims use `supplier-claims`. Customer catalog reads use Product availability projections without querying each supplier.

ProdSeller's list omits stock counts, so sync reads each missing product detail
in groups of at most five concurrent GET requests within the same 40-second
snapshot deadline. At most 100 details are allowed; oversized detail work is
rejected before sending those reads. Every request retains the provider/IP
budget, fixed origin, redirect prohibition and response-body limits. A failed
detail rejects the whole snapshot; no partial catalog is applied. Dashboard
reports safe causes such as API authentication rejection, rate limiting or
timeout, and disables the sync button while a request is running.

Run commands on the correct application environment using the established deployment process. Do not run `migrate:fresh`, manually rewrite supplier holds, or change paid source snapshots on production. Review `last_error` safe codes and timestamps in private Admin; do not paste raw provider payloads into logs, issues or customer messages.

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
