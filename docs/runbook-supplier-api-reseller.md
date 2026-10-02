# Supplier API Reseller — operator runbook

## Boundary

Supplier management is private to Owner/Admin. Customer catalog, invoices, product copy and support remain store-branded. Supplier names, balances, modal prices, API keys, references and raw failures must not be forwarded to customers.

Supplier Balance is the operator's money at the external shop, not Customer Store Balance. A supplier's stock count is not owned inventory: availability is bounded by balance, existing commitments, freshness, equivalent-product mapping and minimum-profit policy. Customer payment can be Paid while Supplier Fulfillment is still pending or in Manual Fulfillment Review.

## Setup through Admin

1. Open **Admin → Supplier**. If the password-confirmation session is older than 15 minutes the page shows a **Konfirmasi password** button; complete it before saving credentials or running recovery.
2. Add a connection through **+ Tambah supplier**: private name, supported provider, API key, currency confirmation, whole-IDR conversion rate, low-balance threshold (entered in the supplier currency) and active flag.
3. Do not put supplier API keys in ENV. The database stores encrypted credentials using the application's encryption key; saved keys are not displayed back. Keep APP_KEY and encrypted database backups together under the existing backup policy. Losing the key makes those credentials unreadable.
4. For ARRS/Warzone, confirm the actual account currency with the supplier. Dollar-like presentation is not currency evidence. ProdSeller uses USDT; CSxStore and AI Vault use USD.
5. Activate the connection and click **Sinkronkan sekarang**. The local button is a CSRF-protected POST, but provider catalog/balance requests are GET-only. Read-only catalog fetching must not buy anything.
6. Open **Buka katalog & jual**. Use the search box and status filters (semua, belum dijual, sudah dijual, bisa instan, perlu input manual) to find the SKU.
7. On the SKU press **Jual produk ini** and pick one path:
   - **Buat produk baru** — enter the store-owned name, retail price (prefilled with cost + 20% suggestion) and an optional short description. The description can be typed, copied from the supplier SKU as a draft (must be edited), or copied from an existing store product through the **Salin deskripsi dari produk yang ada** picker. The product is created inactive and you are taken straight to its supplier page.
   - **Hubungkan ke produk yang ada** — search for the existing store product, confirm equivalence, and link the SKU.
8. On the **Produk & harga** page complete the checklist: activate the supplier source, set the price rule (live calculator: Manual, Tambah untung %, Tambah untung Rp, or Persentase dengan minimum; advanced settings hold rounding, cost buffer and the minimum-profit guard), then publish the product. Use **Edit produk lengkap** to open the product edit modal (photos, icon, category, description, tutorial, warranty) without leaving the supplier workspace, and the **Katalog & family** card to assign a Product Family — every family member must share the same category or the save is rejected.
9. Only map products whose entitlement, region, account requirements, redemption deadline and warranty promises are equivalent. Lower priority sorts first when several sources exist.
10. Top up the external supplier account through that supplier's own supported payment flow. This application does not create a supplier funding transaction. Sync after funding to update availability.

An account supports multiple SKUs with one credential. Adding another SKU from an implemented provider is an Admin operation, not a code change. A new provider contract or an unsupported fulfillment/input type still needs implementation and qualification.

## Pricing and availability

- Manual retail price is never overwritten by sync.
- Automatic policy updates base IDR price using configured supplier conversion rate; existing quantity tiers must remain valid and their actual retail totals are checked against the purchase profit floor.
- Configured conversion rate should reflect actual cost of funding the supplier, not an assumed live exchange rate. Pending/paid Order Items keep their immutable retail snapshots and source cost ceiling.
- Stale observations cannot authorize checkout. Failed reads preserve the last observed data rather than inventing zero stock. A stale last observation is not a promise that purchases remain possible.
- Local stock is used first; external units cover the remainder when a safe source is eligible. Unresolved commitments reserve supplier balance and SKU capacity for existing orders.
- If supplier balance is insufficient and no equivalent funded source is available, customer sees the ordinary unavailable state. Private Admin shows the funding warning.
- A genuine authoritative unavailable-to-available Product transition reuses existing Telegram/Store Channel restock notifications and Web waitlist events. Repeated sync, recovery from a transient outage, or restock of an unused primary while a backup remained available must not create duplicate announcements.

## Fulfillment and recovery

The application buys external units only after authoritative customer payment. It saves dispatch intent before HTTP, sends outside database locks, then persists evidence before customer delivery. A timeout is not proof of rejection.

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

The purchase monitor keeps completed customer fulfillments visible while their original purchases remain unresolved. Such rows expose only original-order recovery, not repurchase or another manual delivery. They leave the actionable list after economic settlement; the private read-only purchase history remains.

Credential/provider/currency/rate changes are blocked while incompatible active commitments or uncertain purchases exist; they could switch account identity or invalidate recovery. Disabling new purchases does not erase existing obligations. Resolve those obligations rather than deleting rows or resetting counters.

All supplier purchasing/recovery/manual operations require password confirmation within 15 minutes, including the supplier-specific **Fulfill** action in the legacy Manual Fulfillment Review page. Ordinary non-supplier review actions keep their existing behavior.

Admin Product availability uses local-only existence/count projections plus fresh supplier capacity. Supplier-owned paid credential history remains bounded and read-only; it does not turn numeric local inventory into a credential-managed pool. The credential-history summary can show zero Available credentials while the Product still has numeric local stock.

## Refunds

- Store Balance customer refunds go through OrderLifecycle/StoreBalanceLedger and credit exactly once.
- QRIS/transfer refunds require provider confirmation where supported or the existing manual external refund evidence. Recording a refund is not itself a bank transfer.
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
