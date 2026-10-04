# Supplier API Reseller — operator runbook

## Boundary

Supplier management is private to Owner/Admin. Customer catalog, invoices, product copy and support remain store-branded. Supplier names, balances, modal prices, API keys, references and raw failures must not be forwarded to customers.

Supplier Balance is the operator's money at the external shop, not Customer Store Balance. A supplier's stock count is not owned inventory: availability is bounded by balance, existing commitments, freshness, equivalent-product mapping and minimum-profit policy. Customer payment can be Paid while Supplier Fulfillment is still pending or in Manual Fulfillment Review.

## Setup through Admin

Dashboard menampilkan setiap koneksi sebagai kartu dengan status aktif/nonaktif, saldo tersedia, saldo total, dana ditahan, dan waktu sinkron. Aksi monitor, katalog, sinkronisasi, serta deposit BuyMail tersedia sebagai tombol; form Tambah/Edit koneksi dapat dibuka tanpa berpindah halaman. Daftar produk toko menampilkan harga jual dan status publik dengan tombol **Atur supplier & harga**. Katalog privat memakai kartu produk dengan harga beli, stok, dana/unit ditahan, pemetaan, dan form impor/hubungkan yang dapat dibuka per produk. Semua halaman supplier mendukung layout mobile serta pilihan tema terang/gelap.

1. Open **Admin → Supplier Reseller** and fill the connection form. Clicking **Buat/Simpan supplier** opens an inline password dialog if recent confirmation is missing; successful confirmation resumes saving automatically. Incorrect passwords or cancelling retain the API key and other inputs in the current browser form. No secret is flashed to server session or persisted to browser storage. Operational recovery still requires recent confirmation.
2. Add a connection: supported provider, API key, whole-IDR conversion rate, active flag and low-balance threshold. The Dashboard derives the private name and documented currency from the provider: ProdSeller uses USDT; CSxStore, AI Vault, NyStore Official and ToolOraX (ShopBot) use USD; BuyMail uses IDR with rate 1. Only ARRS/Warzone expose account-currency selection and explicit confirmation. Fixed-provider currency overrides are rejected by request validation.
3. Do not put supplier API keys in ENV. The database stores encrypted credentials using the application's encryption key; saved keys are not displayed back. Keep APP_KEY and encrypted database backups together under the existing backup policy. Losing the key makes those credentials unreadable.
4. For ARRS/Warzone, confirm the actual account currency with the supplier. Dollar-like presentation is not currency evidence. ProdSeller uses USDT; CSxStore, AI Vault, NyStore Official and ToolOraX use USD.
5. Activate the connection and click **Sinkron katalog dan saldo**. The local button is a CSRF-protected POST, but provider catalog/balance requests are GET-only. Read-only catalog fetching must not buy anything.
6. Open the private catalog. Select a SKU to import as a draft Product, or open **Hubungkan ke produk toko yang sudah ada** and select a Product by name from the **Produk toko** dropdown. Options show retail price and active/draft status; the ID is only a disambiguating label, not an input to memorize. Drafts and Products mapped to other suppliers remain selectable. Deleted Products and Products already mapped to this SKU are excluded. Confirm equivalent entitlement before saving; edit an existing mapping through its linked Product button.
7. Give the draft store-owned name, description, tutorial, warranty and support terms through normal Product editing. Import does not publish the supplier description or activate the Product automatically.
8. Enable the source mapping and arrange the try order with the **↑ Naikkan / ↓ Turunkan** controls. "Urutan 1 · utama" is tried first; later ranks are the fallback. The page shows each rank explicitly, so you never have to compare numbers: it keeps the underlying priority normalized to 10, 20, 30, … and lower values still sort first internally. Each card also shows the last observed unit cost in IDR and the supplier currency (`Harga beli/unit`), or a "Belum ada harga" warning when the catalog has no price yet — read-only display, never a purchase authorization. Only map products whose entitlement, region, account requirements, redemption deadline and warranty promises are equivalent.
9. Set Product-owned pricing: manual, percentage, fixed profit or hybrid percentage/minimum profit; configure buffer, rounding and minimum-profit guard. Preview the cost/retail calculation before enabling public sales.
10. Top up the external supplier account through that supplier's own supported payment flow. This application does not create a supplier funding transaction. Sync after funding to update availability.

An account supports multiple SKUs with one credential. Adding another SKU from an implemented provider is an Admin operation, not a code change. A new provider contract or an unsupported fulfillment/input type still needs implementation and qualification.

## Pricing and availability

- Manual retail price is never overwritten by sync.
- Automatic policy updates base IDR price using configured supplier conversion rate; existing quantity tiers must remain valid and their actual retail totals are checked against the purchase profit floor.
- Percentage markup is calculated from converted supplier cost, not from the cost plus buffer: `ceil(cost_IDR × basis_points / 10000)`. `100` means 1%; `1000` means 10%. Fixed mode uses `profit_idr` and ignores a previously saved percentage. Hybrid uses the larger of the percentage amount and `profit_idr`, not their sum.
- Automatic retail is supplier cost + selected markup/profit + buffer, rounded upward to the configured IDR multiple. `minimum_profit_idr` is a separate purchasing guard, not another markup: every external unit must retain that minimum after its buffer. A computed price below that guard does not authorize a purchase.
- Configured conversion rate should reflect actual cost of funding the supplier, not an assumed live exchange rate. Pending/paid Order Items keep their immutable retail snapshots and source cost ceiling.
- Stale observations cannot authorize checkout. Failed reads preserve the last observed data rather than inventing zero stock. A stale last observation is not a promise that purchases remain possible.
- Local stock is used first; external units cover the remainder when a safe source is eligible. Unresolved commitments reserve supplier balance and SKU capacity for existing orders.
- While any supplier purchase has an unresolved, uncommitted debit, sync retains the existing wallet observation: the provider may already have charged that purchase. Catalog freshness still updates, but local finalization must not subtract the same debit from an already-reduced wallet.
- If supplier balance is insufficient and no equivalent funded source is available, customer sees the ordinary unavailable state. Private Admin shows the funding warning.
- A genuine authoritative unavailable-to-available Product transition reuses existing Telegram/Store Channel restock notifications and Web waitlist events. Repeated sync, recovery from a transient outage, or restock of an unused primary while a backup remained available must not create duplicate announcements.
- Top-up yang dilakukan langsung di supplier dideteksi pada tick sync berikutnya setelah snapshot berusia satu menit; Admin **Sinkron katalog dan saldo** dapat meminta refresh langsung. Provider outage/rate limit dan antrean akun tetap dapat menunda observasi, bukan mengotorisasi stok dari saldo yang belum terbukti.
- Angka public adalah kapasitas pembelian aman, bukan raw stock supplier. Jika tetap 4 setelah sync berhasil, bandingkan saldo tersedia (total dikurangi dana ditahan), biaya/unit, unit ditahan, batas quantity provider, dan harga tier retail mulai quantity 5 terhadap minimum profit/buffer. Saldo dan raw stock besar tidak boleh melewati tier yang membuat pembelian rugi. Purchase dengan debit belum terselesaikan tetap menjaga wallet observation sebelumnya; selesaikan recovery, jangan menghapus hold.

### Automated pricing regression

`SupplierPricingTest` covers percentage/fixed/hybrid arithmetic, fractional-rupiah rounding, buffers, minimum-profit boundaries, quantity tiers, and true versus intermediate integer overflow. `SupplierMoneyTest` covers exact currency conversion and signed wallet presentation; `SupplierAvailabilityTest` exercises fresh eligible-source projection and retail updates.

Run locally from `src/`:

```sh
composer test -- --filter='SupplierPricingTest|SupplierMoneyTest|SupplierAvailabilityTest'
```

The normal CI suite includes these tests on PRs and pushes to `main`. `.github/workflows/supplier-pricing-regression.yml` also runs this focused suite daily at **03:00 WITA** (19:00 UTC), supports **Run workflow**, and retains a JUnit result for 14 days. Scheduled execution starts only after the workflow is present on the default branch; GitHub may delay its start. These checks use isolated SQLite and fake/blocked provider HTTP, never live supplier purchases or funding.

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

ToolOraX (ShopBot) authenticates with `X-Shop-API-Key`, buys with
`POST /orders {product_id, quantity}` and reads `GET /orders/{order_code}`
(`status: fulfilled`, `delivered_keys[]`). Its create-response envelope is not
documented, so a create body without a recognized status is immediately
re-read once through the canonical order endpoint. Documented pre-purchase
conflicts (`Insufficient balance.`, `Quantity is outside the available range.`)
are safe rejections; any other 409 stays ambiguous and is never retried
automatically.

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

The scheduler launches bounded sync/recovery first each minute in the background with a two-minute overlap mutex, before synchronous payment/notification sweeps. Sync skips accounts observed less than one minute ago, keeps the five-minute freshness gate, uses attempted-at ordering for fairness and a durable per-account read lease. Stock-notification reconciliation also runs in the background, using family-scoped ledger queries and PostgreSQL recovery cursor indexes; historical terminal Telegram HTTP 400/403 failures no longer occupy its dispatch index. Recovery discovers durable PostgreSQL work even if Redis lost a wakeup job. Provider request budgets, bounded snapshots/bodies/catalog counts and freshness gates are safety boundaries; increasing only a row limit does not remove them.

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

Development acceptance uses isolated databases and fake provider responses. Before funding/activating production selling:

1. Rotate any keys previously pasted into chats. Enter replacements through Admin, never commit them.
2. Verify provider account currency, product equivalence, permitted resale and product-specific warranty/refund terms.
3. Obtain missing CSxStore idempotency identifier contract before treating it as safely replayable. ARRS/Warzone remain one-shot on ambiguous requests without a documented dedupe contract.
4. Explicitly authorize a controlled real purchase and verify debit, quantity, retrieval, delivery validity and safe duplicate-request behavior only where documented. Development work does not authorize this spend.
5. Check delivered links/credentials for supplier branding. The application does not fetch or rewrite activation URLs; source identity may remain visible in the external destination.
6. Ensure migrations, application key/backups, default queue workers and scheduler are ready before enabling mapped Products.

See [implementation plan](supplier-api-reseller-implementation-plan.md), [ADR-0016](adr/0016-private-supplier-fulfillment-and-capacity.md), [codebase guide](agents/codebase.md) and [real external validation](pre-production-real-external-validation.md).
