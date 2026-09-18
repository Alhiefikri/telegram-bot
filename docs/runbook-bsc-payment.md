# Runbook: USDT BEP20 (BSC)

This runbook covers activation and rollback of the BSC Binance Payment Rail. It does not authorize a mainnet payment by itself; use a controlled low-value transaction only after the readiness and release gates pass.

## Deployment-time configuration

Keep BSC disabled until the database configuration and receiving address are ready. The only BSC value that belongs in the VPS `.env` is the server-side RPC authority:

```env
BSC_RPC_URL=https://provider.example/
```

The RPC URL may contain provider credentials and must remain server-side. It must use HTTPS in production. The BSC checkout and verification path uses chain metadata, transaction receipts, and block facts for customer-submitted TX hashes. The URL must never be placed in a Payment, audit log, Telegram message, browser response, or release artifact.

The application pins BNB Smart Chain Mainnet (`chain_id=56`), the approved USDT contract, 18 token decimals, BscScan's safe explorer host, and the RPC safety limits in application configuration. Receiving addresses are database records, not environment variables, and are never hardcoded into the application.

Readiness covers the single receipt-verification path: RPC metadata, approved contract/18 decimals, and at least one active verified receiver. A ready provider does not need `eth_getLogs`; every Payment must carry a customer-submitted TX hash before the verifier performs an RPC lookup. Payments without a hash remain `awaiting_transfer`.
Customer tidak perlu akun Binance untuk membayar. Wallet atau exchange yang mendukung BNB Smart Chain/BEP20 cukup; customer mengirim USDT ke address yang ditampilkan dan mengirim TX hash setelah transaksi tersedia. Binance Pay/deposit history hanya relevan bila produk sengaja memilih settlement berbasis akun Binance, bukan untuk rail address BSC ini.

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

3. Check the sanitized runtime report. A disabled rail is an acceptable release state. An enabled rail must have core readiness for TX-hash verification:

   ```bash
   php artisan bsc:check-readiness --json
   php artisan ops:health --db --queue-schema --queue-depth --bsc
   ```

4. In Admin → Mode pembayaran, leave `bsc_payment_enabled` off while adding receivers. Enter each public BEP20 address, re-authenticate as Owner, and save it. The address is normalized and becomes an active receiver immediately; the Owner is responsible for checking the destination before saving it.
5. Confirm each receiver has the expected chain, approved contract, and decimals. Owner receiver mutations write audit records, send an owner notification, and invalidate the Storefront response-cache version after commit.
6. Re-run `bsc:check-readiness --json`, confirm `ready=true`, then enable the BSC setting in the database-backed dashboard. Confirm the customer instructions visibly require an exact amount and `Submit TX Hash`. This affects new checkout selection only; existing Payments retain their immutable snapshots.

## Verification and review

`BscPaymentVerification` is the only authority for receipt decoding. A TX hash or explorer URL from a customer is only a lookup candidate. Automatic claim requires a successful receipt, BNB Smart Chain chain ID 56, the approved USDT contract, the snapshotted receiver, the exact integer token amount, an inclusion block inside the Payment window, and the configured confirmation policy.

An exact transfer included before the deadline may use the short technical confirmation grace. `orders:expire-pending` skips only when the detail contains matching authoritative confirming evidence. A future timestamp without that evidence is not a valid grace and the Order expires normally.

When a transfer is included after expiry or the Order was cancelled, verification records sanitized evidence and sends the financially valid Payment through `OrderLifecycle`. The Order remains terminal and a pending `ManualFulfillmentReview` is created. Owners must use the existing manual fulfillment review to fulfill, refund, or reject; no controller or verifier directly edits stock or Order status.

Readiness/RPC failures remain pending and are retried by the scheduled reconciler. The reconciler skips Payments without a submitted hash and applies a bounded retry cadence to submitted hashes. Mismatch, duplicate hash, late-payment, and backlog alerts are deduplicated before being sent to the configured owner chat. Inspect the admin Order detail for the selected public evidence fields and the allowlisted explorer link.

## Disable and rollback

For an operational stop, turn off the database `bsc_payment_enabled` setting. Do not remove the RPC/token configuration and do not edit existing receiver or `payment_bsc_details` snapshots. New checkout selectors hide BSC immediately after the committed Storefront cache invalidation; historical Payments continue through the shared lifecycle/reconciliation path.

For a release rollback, use the existing deploy workflow and its immutable image/commit rollback gate. The database backup and release journal are authoritative. BSC rollback must not rewrite Payment snapshots, delete referenced receivers, or make a historical `BINANCE_PAY_ID` Payment read current BSC settings. After rollback, run:

```bash
php artisan migrate:status
php artisan bsc:check-readiness --json
php artisan ops:health --db --queue-schema --queue-depth --bsc
php artisan schedule:list
```

If BSC is intentionally disabled, readiness should report `enabled=false`; this is safe. If it is enabled, resolve RPC, token metadata, receiver, or other core readiness failures before re-enabling checkout. Customers must submit a TX hash; no background amount scanner is part of the BSC verification contract.
