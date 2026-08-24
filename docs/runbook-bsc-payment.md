# Runbook: USDT BEP20 (BSC)

This runbook covers activation and rollback of the BSC Binance Payment Rail. It does not authorize a mainnet payment by itself; use a controlled low-value transaction only after the readiness and release gates pass.

## Deployment-time configuration

Keep BSC disabled until the database configuration and receiving address are ready. The only BSC authority values that belong in the VPS `.env` are:

```env
BSC_RPC_URL=https://provider.example/
BSC_INDEXED_SOURCE=
BSC_CHAIN_ID=56
BSC_USDT_CONTRACT=0x55d398326f99059ff775485246999027b3197955
BSC_USDT_DECIMALS=18
BSC_READINESS_CACHE_SECONDS=60
```

RPC credentials, if the provider requires them, remain server-side in the URL/secret environment and must never be placed in a Payment, audit log, Telegram message, browser response, or release artifact. Receiving addresses are database records, not environment variables, and are never hardcoded into the application.

Readiness performs a bounded `eth_getLogs` capability probe for automatic transfer scanning. Set `BSC_INDEXED_SOURCE` only when the configured reconciliation deployment provides an equivalent indexed transfer source; otherwise an RPC that rejects `eth_getLogs` keeps BSC unavailable. The readiness result is shared for the configured short TTL and invalidated after committed BSC setting or receiver mutations.

The application uses the existing `BINANCE_VERIFICATION_QUEUE` / `binance-verification` worker. Do not add a second BSC worker or supervisor process.

## Activation

1. Deploy the additive migrations through the normal GitHub Actions/VPS release flow. Do not run Railway commands or add Railway configuration.
2. Confirm migration and scheduler state:

   ```bash
   cd src
   php artisan migrate:status
   php artisan schedule:list
   ```

   `bsc:reconcile --chunk=100` and `orders:expire-pending` must both appear every minute.

3. Check the sanitized runtime report. A disabled rail is an acceptable release state; an enabled but unready rail must fail the gate:

   ```bash
   php artisan bsc:check-readiness --json
   php artisan ops:health --db --queue-schema --queue-depth --bsc
   ```

4. In Admin → Mode pembayaran, leave `bsc_payment_enabled` off while adding the receiver. Add the public address, re-authenticate as Owner, and complete its EIP-191 EOA or ERC-1271 smart-wallet challenge. The custodial/manual path is an explicit high-risk exception and is not cryptographic ownership proof.
5. Activate only a receiver whose chain, approved contract, and decimals match readiness. Owner receiver mutations write audit records, send an owner notification, and invalidate the Storefront response-cache version after commit.
6. Re-run `bsc:check-readiness --json`, then enable the BSC setting in the database-backed dashboard. This affects new checkout selection only; existing Payments retain their immutable snapshots.

## Verification and review

`BscPaymentVerification` is the only authority for receipt/log decoding. A TX hash or explorer URL from a customer is only a lookup candidate. Automatic claim requires a successful receipt, BNB Smart Chain chain ID 56, the approved USDT contract, the snapshotted receiver, the exact integer token amount, an inclusion block inside the Payment window, and the configured confirmation policy.

An exact transfer included before the deadline may use the short technical confirmation grace. `orders:expire-pending` skips only when the detail contains matching authoritative confirming evidence. A future timestamp without that evidence is not a valid grace and the Order expires normally.

When a transfer is included after expiry or the Order was cancelled, verification records sanitized evidence and sends the financially valid Payment through `OrderLifecycle`. The Order remains terminal and a pending `ManualFulfillmentReview` is created. Owners must use the existing manual fulfillment review to fulfill, refund, or reject; no controller or verifier directly edits stock or Order status.

Readiness/RPC failures remain pending and are retried by the scheduled reconciler. Mismatch, duplicate hash, late-payment, and backlog alerts are deduplicated before being sent to the configured owner chat. Inspect the admin Order detail for the selected public evidence fields and the allowlisted explorer link.

## Disable and rollback

For an operational stop, turn off the database `bsc_payment_enabled` setting. Do not remove the RPC/token configuration and do not edit existing receiver or `payment_bsc_details` snapshots. New checkout selectors hide BSC immediately after the committed Storefront cache invalidation; historical Payments continue through the shared lifecycle/reconciliation path.

For a release rollback, use the existing deploy workflow and its immutable image/commit rollback gate. The database backup and release journal are authoritative. BSC rollback must not rewrite Payment snapshots, delete referenced receivers, or make a historical `BINANCE_PAY_ID` Payment read current BSC settings. After rollback, run:

```bash
php artisan migrate:status
php artisan bsc:check-readiness --json
php artisan ops:health --db --queue-schema --queue-depth --bsc
php artisan schedule:list
```

If BSC is intentionally disabled, readiness should report `enabled=false`; this is safe. If it is enabled, resolve RPC, token metadata, receiver, or backlog failures before re-enabling checkout.
