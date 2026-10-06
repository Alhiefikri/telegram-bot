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

Rail qualification for a future USDT funding rail is recorded separately with `php artisan bsc:check-capabilities --json`. The command classifies chain, contract code, decimals, `finalized` block, canonical receipt, and bounded transfer-log capability as `verified`, `unsupported`, or `unknown`, and exits non-zero unless `rpc_qualified` is true. A qualified result is RPC evidence only: it does not prove token issuer/bridge provenance, custody, provisioning/recover-by-reference, or legal authorization, and funding remains no-go until #539 and the downstream rail tickets pass. See [balance-a1-bsc-capability.md](balance-a1-bsc-capability.md).

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

`BscPaymentVerification` is the checkout authority for receipt decoding and claims; the separate USDT balance watch-only rail below does not invoke this Order lifecycle. For a new amount-unique Payment, a bounded server-side transfer-log match is only a lookup candidate; the verifier still requires a successful receipt, BNB Smart Chain chain ID 56, the approved USDT contract, the snapshotted receiver, the exact integer token amount, an inclusion block inside the Payment window, and the configured confirmation policy. A legacy submitted TX hash follows the same receipt predicate without the log-discovery step.
Canonical ERC-20 `Transfer(address,address,uint256)` logs use topic0 `0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef`. The readiness probe accepts an empty `eth_getLogs` response and checks only a recent scanner chunk, so it verifies provider filter capability but not event-signature correctness or access to older blocks needed by a Payment scan. Use a provider that serves the full scan window and prove the automatic path with a controlled low-value Payment after release.

An exact transfer included before the deadline may use the short technical confirmation grace. `orders:expire-pending` skips only when the detail contains matching authoritative confirming evidence. A future timestamp without that evidence is not a valid grace and the Order expires normally.

If an explicit submitted TX hash proves a transfer was included after expiry or cancellation, verification records sanitized evidence and sends the financially valid Payment through `OrderLifecycle`. The Order remains terminal and a pending `ManualFulfillmentReview` is created. Owners must use the existing manual fulfillment review to fulfill, refund, or reject; no controller or verifier directly edits stock or Order status. The scheduled reconciler does not discover such transfers by continuing amount-unique history scans after the Order becomes terminal.

Readiness/RPC failures are retried with a bounded cadence only while the Payment is pending and its Order remains `pending_payment`. The scheduled reconciler does not select expired, cancelled, completed, failed, or paid Orders; amount-unique history scans stop when an Order becomes terminal. Interactive refreshes and queued jobs also stop there, except that one already-queued legacy TX-hash job may finish a targeted receipt check so evidence submitted before expiry can reach manual review. That terminal hash check is not retried. Transfers first reported after expiry require manual operator review; the scheduler does not keep scanning chain history to discover them. Mismatch, duplicate hash, manual-review, and active-backlog alerts are deduplicated before being sent to the configured owner chat. Inspect the admin Order detail for the selected public evidence fields and the allowlisted explorer link.

## Receiver ownership registry (checkout vs Customer deposit)

Every BSC USDT receiver address is claimed permanently and channel-exclusively in `usdt_receiver_address_claims` (#549, [U4 doc](balance-u4-receiver-ownership.md)):

- Checkout receivers added from Admin or `BscReceiverManager` take the `checkout` claim. A checkout address can never be registered again as a Customer deposit receiver, and deleting an unreferenced checkout receiver does not release the claim.
- Customer-exclusive deposit receivers (future USDT top-up rail) are registered from the console, never from Admin, and bind the address to exactly one Customer:

  ```bash
  php artisan usdt-receivers:register --customer-id=123 --address=0x… --actor=owner@example.com
  php artisan usdt-receivers:verify --receiver=1 --challenge=<uuid> --wallet-type=eoa --signature=0x…
  php artisan usdt-receivers:retire --receiver=1 --reason="rotasi address" --actor=owner@example.com
  ```

  `--actor` must resolve to an Owner user; `--telegram-id` may replace `--customer-id` when the Customer is selected by exact numeric Telegram ID. Add `--manual-verified` only to record the explicitly acknowledged high-risk Owner custody attestation instead of a signed ownership challenge.
- One non-retired receiver per Customer; rotate by retiring the old receiver and registering a new address. Retired rows stay monitored and immutable, and no address is ever reassigned to another Customer.
- Custody provisioning adapters are registered only through the container tag `usdt-deposit-receiver-provisioners`, which is intentionally empty until the H1 custody/capacity gates pass. Until then a Customer without a registered address reports capacity unavailable and no invoice can be created; there is no shared-address fallback.
- Never edit claims, receiver identity columns or retirement columns directly in the database: PostgreSQL triggers and model guards reject those writes, and rollback must not delete claims or receiver history.

## USDT balance watch-only rail: disposable technical verification only

U5 #550 implements an actual RPC adapter for U4's verified operator-controlled Customer receivers. This is separate from checkout's shared receiver/unique-amount strategy. H1 qualification is still missing; readiness reports `fundingAuthorized=false`, and new local rail invoices are restricted to disposable `local`/`testing` environments. Do not change production `APP_ENV` to bypass this gate. Do not publish payable instructions, accept real Customer funds, run a canary or treat synthetic receipts as custody/backing evidence.

`createOrRecover` uses one trusted reference, Customer, exact USDT amount and deadline; confirmed intents/customer adapters are U6/U9, not a console replacement for authorization. It persists a local invoice and scan enrollment together. A lost response is recovered by the same reference without an external create or an alternate receiver. Immutable epoch-second windows, zero application fee, network/token/receiver/finality snapshots and one non-overlapping window per receiver are enforced; existing snapshots remain inspectable after expiry/retirement.

In a disposable environment with synthetic RPC evidence:

```bash
php artisan usdt-bsc:scan --json --limit=25 --pages=2 --requests=32
php artisan usdt-bsc:scan --json --after=<next_after> --limit=25 --pages=2 --requests=32
php artisan usdt-bsc:scan --receiver=<id> --from-block=<explicit-first-block> --json
```

The first two commands rediscover durable enrollment after worker/Redis loss; pass the returned `next_after` for keyset pagination and restart at zero after the inventory traversal. The third command enrolls an explicit receiver history window; later calls must retain its original first block. Retired enrolled receivers remain monitored. The operation budget is shared across receivers per command; each page spans at most 1,000 blocks and 100 logs, with a 1 MiB RPC response ceiling. Empty validated pages advance, but malformed, oversized, RPC-unavailable or budget-incomplete pages do not.

Observations and checkpoint writes are not global receipt claims or balance credits. A successful receipt must independently pass exact single-log attribution, canonical hash, payment-window and finalized checks before U7 can claim and settle it. Missing finality remains pending; invalid/multi-log evidence enters review. Canonical checkpoint/log/page changes latch receiver review and retain immutable history; do not clear or rewind a finalized checkpoint by SQL. U8 owns incident resolution and freezing after a settled reorg.

No new scheduler, funding UI, Telegram delivery path or production activation is installed by U5. Checkout's `bsc_payment_enabled` toggle is not approval of Customer USDT funding. Technical evidence and limitations: [A1/U5 report](balance-a1-bsc-capability.md#11-implementasi-dan-evidence-teknis-u5-2026-10-06).

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
