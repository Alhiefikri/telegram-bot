# Runbook: USDT BEP20 (BSC)

This runbook covers activation and rollback of the BSC Binance Payment Rail. It does not authorize a mainnet payment by itself; use a controlled low-value transaction only after the readiness and release gates pass.

## Deployment-time configuration

Keep BSC disabled until the database configuration and receiving address are ready and readiness confirms that the RPC can answer the bounded `eth_getLogs` query used for transfer discovery. The pinned public endpoints are best-effort fallbacks; production should use a provider with verified log-query support through the existing `BSC_RPC_URL` override:

```env
BSC_RPC_URL=https://provider.example/
```

`BSC_RPC_URL` is the optional override for a private or credentialed provider and always takes priority over the pinned public endpoints in `src/config/services.php`. Readiness probes chain metadata and a USDT Transfer log filter spanning one configured scanner chunk before reporting the rail ready. If that probe fails, configure `BSC_RPC_URL` to a provider that supports the same bounded log filters and keep BSC disabled until readiness passes. An override may contain provider credentials and must remain server-side; every endpoint must use HTTPS in production. No RPC URL may be placed in a Payment, audit log, Telegram message, browser response, or release artifact.

The client tries each configured endpoint in order and falls through on transport, HTTP, or malformed-response failures. A valid response is authoritative and is never re-queried against another endpoint. The BSC checkout and verification path uses chain metadata, bounded `eth_getLogs` transfer scans for new amount-unique Payments, transaction receipts, and block facts. Public RPC availability is not proof that log scans are supported; only the readiness probe gates new BSC checkout.

The application pins BNB Smart Chain Mainnet (`chain_id=56`), the approved USDT contract, 18 token decimals, BscScan's safe explorer host, and the RPC safety limits in application configuration. Receiving addresses are database records, not environment variables, and are never hardcoded into the application.

Readiness covers the core RPC metadata, approved contract/18 decimals, at least one active verified receiver, and transfer-log query support. New Payments use a unique micro-USDT amount and the verifier scans bounded `eth_getLogs` ranges filtered by the approved contract and snapshotted receiver, then validates the candidate receipt and block facts. Recent BSC Payment amounts remain reserved across the configured late-payment lookback so a reused amount cannot be attributed to the wrong invoice. Existing legacy Payments with `verification_mode=transaction_hash` continue to require a customer-submitted TX hash; they remain compatible with the targeted receipt path.
Customer tidak perlu akun Binance untuk membayar. Wallet atau exchange yang mendukung BNB Smart Chain/BEP20 cukup; customer mengirim nominal unik yang tampil ke address yang ditampilkan. Sistem mencari transfer secara otomatis; tombol Check Payment hanya memicu pemeriksaan ulang. Payment legacy yang masih meminta hash tetap dapat diselesaikan melalui flow TX hash.

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

3. Check the sanitized runtime report. A disabled rail is an acceptable release state. An enabled rail must have core readiness for amount-unique transfer verification:

   ```bash
   php artisan bsc:check-readiness --json
   php artisan ops:health --db --queue-schema --queue-depth --bsc
   ```

4. In Admin → Mode pembayaran, leave `bsc_payment_enabled` off while adding receivers. Enter each public BEP20 address, re-authenticate as Owner, and save it. The address is normalized and becomes an active receiver immediately; the Owner is responsible for checking the destination before saving it.
5. Confirm each receiver has the expected chain, approved contract, and decimals. Owner receiver mutations write audit records, send an owner notification, and invalidate the Storefront response-cache version after commit.
6. Re-run `bsc:check-readiness --json`, confirm `ready=true`, then enable the BSC setting in the database-backed dashboard. Confirm the customer instructions visibly require the exact unique amount and explain automatic detection; new checkout instructions must not require a `Submit TX Hash` action. This affects new checkout selection only; existing Payments retain their immutable snapshots and legacy hash mode.

## Verification and review

`BscPaymentVerification` is the only authority for receipt decoding and claims. For a new amount-unique Payment, a bounded server-side transfer-log match is only a lookup candidate; the verifier still requires a successful receipt, BNB Smart Chain chain ID 56, the approved USDT contract, the snapshotted receiver, the exact integer token amount, an inclusion block inside the Payment window, and the configured confirmation policy. A legacy submitted TX hash follows the same receipt predicate without the log-discovery step.
Canonical ERC-20 `Transfer(address,address,uint256)` logs use topic0 `0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef`. The readiness probe accepts an empty `eth_getLogs` response and checks only a recent scanner chunk, so it verifies provider filter capability but not event-signature correctness or access to older blocks needed by a Payment scan. Use a provider that serves the full scan window and prove the automatic path with a controlled low-value Payment after release.

An exact transfer included before the deadline may use the short technical confirmation grace. `orders:expire-pending` skips only when the detail contains matching authoritative confirming evidence. A future timestamp without that evidence is not a valid grace and the Order expires normally.

If an explicit submitted TX hash proves a transfer was included after expiry or cancellation, verification records sanitized evidence and sends the financially valid Payment through `OrderLifecycle`. The Order remains terminal and a pending `ManualFulfillmentReview` is created. Owners must use the existing manual fulfillment review to fulfill, refund, or reject; no controller or verifier directly edits stock or Order status. The scheduled reconciler does not discover such transfers by continuing amount-unique history scans after the Order becomes terminal.

Readiness/RPC failures are retried with a bounded cadence only while the Payment is pending and its Order remains `pending_payment`. The scheduled reconciler does not select expired, cancelled, completed, failed, or paid Orders; amount-unique history scans stop when an Order becomes terminal. Interactive refreshes and queued jobs also stop there, except that one already-queued legacy TX-hash job may finish a targeted receipt check so evidence submitted before expiry can reach manual review. That terminal hash check is not retried. Transfers first reported after expiry require manual operator review; the scheduler does not keep scanning chain history to discover them. Mismatch, duplicate hash, manual-review, and active-backlog alerts are deduplicated before being sent to the configured owner chat. Inspect the admin Order detail for the selected public evidence fields and the allowlisted explorer link.

## Disable and rollback

For an operational stop, turn off the database `bsc_payment_enabled` setting. Do not remove the RPC/token configuration and do not edit existing receiver or `payment_bsc_details` snapshots. New checkout selectors hide BSC immediately after the committed Storefront cache invalidation; historical nonterminal Payments continue through the shared lifecycle/reconciliation path, while terminal Orders remain out of automatic reconciliation.

For a release rollback, use the existing deploy workflow and its immutable image/commit rollback gate. The database backup and release journal are authoritative. BSC rollback must not rewrite Payment snapshots, delete referenced receivers, or make a historical `BINANCE_PAY_ID` Payment read current BSC settings. After rollback, run:

```bash
php artisan migrate:status
php artisan bsc:check-readiness --json
php artisan ops:health --db --queue-schema --queue-depth --bsc
php artisan schedule:list
```

If BSC is enabled, resolve RPC, token metadata, receiver, transfer-log, or other core readiness failures before re-enabling checkout. New customers do not need to submit a TX hash; the unique amount and receiver address drive automatic discovery. Historical legacy Payments retain their hash-submission compatibility.
