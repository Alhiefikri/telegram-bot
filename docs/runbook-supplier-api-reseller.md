# Supplier API Reseller — operator runbook

## Boundary

Supplier management is private to Owner/Admin. Customer catalog, invoices, product copy and support remain store-branded. Supplier names, balances, modal prices, API keys, references and raw failures must not be forwarded to customers.

Supplier Balance is the operator's money at the external shop, not Customer Store Balance. A supplier's stock count is not owned inventory: availability is bounded by balance, existing commitments, freshness, equivalent-product mapping and minimum-profit policy. Customer payment can be Paid while Supplier Fulfillment is still pending or in Manual Fulfillment Review.

## Setup through Admin

1. Open **Admin → Supplier Reseller**. Confirm password first; credential changes and operational recovery require recent confirmation.
2. Add a connection: private name, supported provider, API key, currency confirmation, whole-IDR conversion rate, active flag and low-balance threshold.
3. Do not put supplier API keys in ENV. The database stores encrypted credentials using the application's encryption key; saved keys are not displayed back. Keep APP_KEY and encrypted database backups together under the existing backup policy. Losing the key makes those credentials unreadable.
4. For ARRS/Warzone, confirm the actual account currency with the supplier. Dollar-like presentation is not currency evidence. ProdSeller uses USDT; CSxStore and AI Vault use USD.
5. Activate the connection and click **Sinkron katalog dan saldo**. The local button is a CSRF-protected POST, but provider catalog/balance requests are GET-only. Read-only catalog fetching must not buy anything.
6. Open the private catalog. Select a SKU to import as a draft Product, or map it to an existing Product with explicit equivalent-product confirmation.
7. Give the draft store-owned name, description, tutorial, warranty and support terms through normal Product editing. Import does not publish the supplier description or activate the Product automatically.
8. Enable the source mapping and set priority. Lower priority sorts first. Only map products whose entitlement, region, account requirements, redemption deadline and warranty promises are equivalent.
9. Set Product-owned pricing: manual, percentage, fixed profit or hybrid percentage/minimum profit; configure buffer, rounding and minimum-profit guard. Preview the cost/retail calculation before enabling public sales.
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

| Observation | Operator action |
| --- | --- |
| Proven rejection before purchase/debit | Allow eligible equivalent fallback, or fix/top-up the source and use safe retry. |
| Known supplier order ID, delivery pending | Re-fetch that exact order; do not buy another order. |
| Complete delivery payload, actual cost absent | Retain encrypted payload and wallet hold; recover metadata from the same known order. Expected catalog cost is not proof of actual debit. |
| Unknown result, no ID | Inspect supplier records privately. Associate only a reliably verified order, not a guess based on name/time/quantity. |
| Partial/invalid delivery or identity/cost mismatch | Keep stored evidence and resolve through review. Do not repurchase the whole quantity. |
| Stored complete delivery but Telegram failed | Recover/resend the stored customer delivery through the existing outbound ledger, never buy again. |
| No eligible source after customer paid | Fulfill manually or confirm a refund through existing review policy. Payment stays Paid until a real refund is applied. |

Private **Monitor pembelian / pemulihan** exposes safe purchase/fulfillment metadata and actions delegated to the supplier coordinator:

- retry after the source/top-up problem is resolved, only for states proven safe to retry;
- recover an exact supplier order reference;
- provide the complete manual replacement credentials when operator fulfillment is chosen.

Manual customer replacement does not prove that an ambiguous original supplier purchase was cancelled or refunded. Keep its held funds/evidence unresolved until the original economic outcome is established.

Recovering the original purchase must never reopen completed customer fulfillment or revoke access to manually delivered credentials. Once exact order identity, matching quantity and actual debit are established, an original purchase without complete original delivery can become `settled`: its economics are known, while customer delivery remains the separate completed manual obligation. `settled` does not claim that the supplier delivered a complete payload. The hold clears once; recovery never allocates a second set of credentials.

The purchase monitor keeps completed customer fulfillments visible while their original purchases remain unresolved. Such rows expose only original-order recovery, not repurchase or another manual delivery. They leave the actionable list after economic settlement; the private read-only purchase history remains.

Credential/provider/currency/rate changes are blocked while incompatible active commitments or uncertain purchases exist; they could switch account identity or invalidate recovery. Disabling new purchases does not erase existing obligations. Resolve those obligations rather than deleting rows or resetting counters.

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
