# Binance Multi-Rail Checkout: Binance Pay ID + USDT BEP20 (BSC)

**Status:** Implementation-ready spec

**Date:** 2026-08-24

**Scope:** Store Bot and Web Storefront checkout, payment verification, receiving-address management, expiry, cancellation, and automatic fulfillment.

## Decision summary

When a customer chooses **Binance**, the channel must open a second rail selector before creating an Order:

1. **Pay with Binance Pay ID**
2. **Pay with USDT BEP20 (BSC)**
3. **Cancel**

The selector is a pre-checkout interaction. Canceling it creates no Order, Payment, reservation, or outbound effect. After the customer chooses a rail, the existing `OrderCheckoutService` creates exactly one Order and one Payment for that rail.

`BINANCE_PAY_ID` remains the existing Binance Pay rail. `USDT_BEP20` is a new payment method whose source of truth is the BNB Smart Chain, not Binance Pay account history. Both rails continue to use the existing Order lifecycle, inventory reservation, payment claim, receipt, and fulfillment boundaries.

The BSC rail uses a hybrid automatic verification flow:

- a background scanner can find an exact matching token transfer by receiver, token contract, and unique amount;
- the customer can submit a BSC transaction hash for immediate targeted lookup;
- neither a transaction hash nor an explorer link is accepted without authoritative RPC validation;
- payment is claimed only after the transfer satisfies the configured confirmation/finality policy.

This preserves the repository's typed, idempotent Binance verification contract from [ADR-0003](../../adr/0003-binance-payment-verification.md) while adding a chain-specific adapter.

## Current repository contract

The current checkout has these relevant properties:

- [PaymentProviderResolver](../../../src/app/Services/Checkout/PaymentProviderResolver.php) recognizes Binance through the requested payment method and produces a `BinancePayCheckoutIntent`.
- [OrderCheckoutService](../../../src/app/Services/OrderCheckoutService.php) creates Order, Payment, reservations, and checkout effects inside one transaction.
- Current Binance Pay payments use `provider=manual` and `method=BINANCE_PAY_ID`; the method, not the provider alone, identifies Binance.
- [BinancePaymentVerification](../../../src/app/Services/Binance/BinancePaymentVerification.php) reads Binance Pay history, verifies receiver/amount/time/reuse, and delegates successful payment transition to `OrderLifecycle`.
- Telegram currently treats `checkout:binance` and `detail:pay:binance:*` as direct checkout callbacks. These callbacks must become rail-selector entry points.
- Web Storefront currently submits `payment_method=binance_pay` directly and renders one Binance Pay invoice partial. The form must submit a final rail selection.
- Telegram checkout instructions already use a durable outbound-effect ledger. BSC instructions must have an explicit method/template mapping so the ledger does not mislabel a chain transfer as Binance Pay.

Existing `BINANCE_PAY_ID` behavior and historical Payments are compatibility requirements. The new method must not cause old Payments to reread current addresses, current mode settings, or current BSC configuration.

## Domain language

The glossary additions are recorded in [`CONTEXT.md`](../../../CONTEXT.md):

- **Binance Payment Rail** — the customer-selected Binance Pay ID or USDT BEP20 path.
- **BSC Receiving Address** — an Owner-managed public address that may be assigned to a new BSC Payment.
- **BSC Payment Verification** — on-chain validation of the immutable BSC Payment snapshot.

The existing term **Binance Payment Verification** remains reserved for the Binance Pay transfer workflow. The new verifier must not call a BSC transaction a Binance Pay transaction.

## Goals

- Offer the two requested Binance payment rails in both Store Bot and Web Storefront.
- Show a second selector with a working Cancel action before checkout side effects.
- Support several BSC receiving addresses and distribute new Payments across them.
- Snapshot the selected address and token/network contract on every BSC Payment.
- Generate an exact, unique BSC payable amount so amount-only background matching is possible.
- Let a customer submit a BSC transaction hash for immediate verification.
- Automatically confirm only an exact, successful, sufficiently confirmed USDT transfer.
- Preserve the current OrderLifecycle and manual-review boundaries.
- Make expiry, cancellation, late payment, duplicate transaction, RPC failure, and chain mismatch safe and explicit.
- Keep all existing Binance Pay ID tests and behavior valid.

## Non-goals

- No custody of customer funds or private keys.
- No automatic sending, sweeping, or withdrawal from a receiving address.
- No ERC20, TRC20, opBNB, BEP2, native BNB, or arbitrary token support in this release.
- No customer choice of a specific receiving address.
- No automatic conversion between IDR and USDT at checkout time.
- No automatic refund or provider reversal for a late or mismatched transfer.
- No second Payment under an existing Order when the customer wants to change rails.
- No change to historical `BINANCE_PAY_ID` Payments.
- No reliance on Binance Spot/Pay account history as the authority for an on-chain BSC transfer.

## Draft assumptions to validate before implementation

These are the working decisions for this spec. They are intentionally visible so the user can correct them before implementation:

| Decision | Draft choice | Reason |
| --- | --- | --- |
| Surfaces | Store Bot and Web Storefront | Both surfaces currently expose Binance checkout and should not diverge. |
| BSC method | `USDT_BEP20` | Distinguishes the rail from existing `BINANCE_PAY_ID`. |
| Provider column | `manual` | Matches the existing manual/customer-initiated crypto boundary; `method` remains authoritative. |
| Address management | Owner-managed database pool with direct Owner confirmation | Address rotation and audit must not require a deploy; a valid typed address is saved as an active destination after Owner re-authentication. |
| Address assignment | Least-recently-assigned, locked in checkout transaction | Distributes traffic and is deterministic under concurrency. |
| Unique amount increment | `0.0001` USDT (`100` micro-USDT) | Gives a useful human-readable reference space while retaining integer arithmetic. |
| Unique scope | Global across pending BSC Payments | Avoids ambiguity even when several addresses are active. |
| BSC TTL | 30 minutes | Matches the requested customer copy. |
| Automatic threshold | Exactly 3 confirmations by default; finalized-block data is an optional reorg-safety check | Preserves the requested confirmation UX without adding an invisible extra wait. |
| Hash attempts | Five valid submissions per Payment; invalid format does not consume an attempt | Limits abuse while allowing correction of a copied hash. |
| Technical grace | Three minutes only for a transfer included before the original deadline | Confirmation latency does not become a new payment window. |

These decisions are now resolved for ticketing. The implementation may improve internal names, but must not change the customer-visible rail choices, database-backed activation, Owner-confirmed address management, 30-minute deadline, or three-confirmation default without reopening this spec.

## Activation and operator configuration

The feature must not be gated by a per-address ENV variable or hardcoded wallet address. The first code release and its migrations require the repository's normal deployment process; after that release, routine BSC activation and address rotation are runtime database operations.

### Deploy-time infrastructure configuration

ENV contains only the BSC authority endpoint:

```env
BSC_RPC_URL=https://<rpc-provider-endpoint>
```

`BSC_RPC_URL` is an HTTP JSON-RPC endpoint used to read BSC state/history. A public BNB Chain endpoint is acceptable for development and direct TX-hash lookup; production automatic amount scanning requires an endpoint that supports `eth_getLogs`. The URL and any provider credential remain server-side.

The application pins BSC Mainnet (`chain_id=56`), the approved USDT contract, 18 token decimals, the safe explorer host, and bounded RPC/scan limits. These values are not operator environment settings. A receiving address is stored in the database and must not be hardcoded or exposed as an environment variable.

The v1 verifier reuses the existing `services.binance.verification_queue`/`binance-verification` queue. Do not add a second BSC worker or a new queue ENV in this feature slice. A separate queue is a later capacity decision, not a prerequisite for activation.

### Runtime database configuration

The database stores:

- `bsc_payment_enabled` on the active `ManualPaymentSetting` row;
- BSC TTL and confirmation policy settings;
- the `bsc_payment_receivers` pool;
- address verification state, audit metadata, and activation/deactivation timestamps.

The BSC rail is visible only when:

```text
bsc_payment_enabled = true
AND at least one BSC Receiving Address is active and verified
AND the BSC RPC readiness check passes
```

Adding or deactivating an address does not require an image rebuild or container restart. Disabling the feature affects new checkouts only; existing BSC Payments retain their snapshots and continue through their normal lifecycle.

The Storefront response cache must invalidate after a committed `ManualPaymentSetting` or BSC receiver mutation. The current observer registration covers Product and Category but not payment settings; the implementation must add the missing invalidation/version boundary before claiming instant Storefront activation.

## Customer experience

### Shared state sequence

```text
Binance selected
    ↓
Rail selector shown (no Order yet)
    ├─ Cancel → return to previous payment choices
    ├─ Binance Pay ID → existing Binance Pay checkout
    └─ USDT BEP20 → BSC address + unique amount checkout
                         ↓
                    Order + Payment created
                         ↓
                    Payment instructions delivered
                         ↓
                    scanner and/or hash verification
                         ↓
                    OrderLifecycle payment claim
```

The rail selector must not reserve stock. Stock is reserved only after the final rail is chosen and checkout succeeds. If the selected rail is no longer ready at the authoritative checkout boundary, checkout fails without leaving partial side effects.

### Telegram

The current Binance button becomes a selector entry point. The selector is delivered as a new private-chat message, as requested, and contains:

```text
💎 Pilih metode pembayaran Binance

[Pay with Binance Pay ID]
[Pay with USDT BEP20 (BSC)]
[Cancel]
```

The selector callback must include a customer/cart/message-bound nonce or equivalent stale-intent protection. A callback from an old selector must not create a new Order against a later cart.

Selecting a rail invokes the existing checkout orchestration with a rail-specific `CheckoutPaymentIntent`:

- `BINANCE_PAY_ID` uses the existing Binance Pay instruction effect;
- `USDT_BEP20` creates the BSC Payment and dispatches the BSC instruction effect.

The BSC instruction message contains:

```text
💎 USDT BEP20 Payment

📦 ✉️ Emails Outlook/hotmail × 1
💵 Harga produk: 0.0200 USDT

Kirim tepat:
0.0201 USDT

Ke address:
0x...

Setelah mengirim, tekan tombol Submit TX Hash dan masukkan hash transaksi BSC.

⚠️ Network: BNB Smart Chain (BEP20) ONLY
❌ ERC20, TRC20, opBNB, atau token lain tidak diterima.

⏳ Valid 30 menit — otomatis dikonfirmasi setelah 3 confirmations.

[Submit TX Hash]
[Cek Pembayaran]
[Batalkan Order]
```

The actual displayed amount and address always come from the Payment snapshot. The example address in the request is not a production default.

The `Submit TX Hash` action opens a prompt. The next message is accepted only when the customer has an active BSC hash-submission session for the matching Order. A hash has the strict shape `0x` followed by 64 hexadecimal characters.

### Web Storefront

The existing generic Binance choice in the product modal becomes a two-step Alpine/UI state:

1. show the two Binance rails;
2. set the final hidden `payment_method` to `binance_pay_id` or `usdt_bep20`;
3. submit the existing checkout form.

Cancel returns to the generic payment choices and makes no request. The server accepts only the final rail values. The old `binance_pay` value may remain as a backward-compatible alias for `binance_pay_id`, but the new UI must never submit the ambiguous alias.

The BSC invoice page displays:

- product total and final exact payable amount;
- full receiving address with copy action;
- `BNB Smart Chain (BEP20)` network label;
- warning against ERC20/TRC20 and non-USDT tokens;
- 30-minute deadline;
- confirmation counter while a transaction is found;
- signed form for TX hash submission;
- status refresh button/polling;
- existing signed Cancel Order action.

The Storefront must not expose a BSC address before checkout assigns it. A buyer cannot choose an address from the pool.

## Payment model

### Rail identity

The canonical values are:

| Rail | `provider` | `method` | Authority | Existing behavior |
| --- | --- | --- | --- | --- |
| Binance Pay ID | `manual` | `BINANCE_PAY_ID` | Binance Pay history/API | Preserve exactly |
| USDT BEP20 | `manual` | `USDT_BEP20` | BNB Smart Chain RPC/indexer | New |

The `provider` value must not be used alone to identify the rail. This follows the current repository contract for Binance Pay.

### BSC receiver pool

Add an Owner-managed `bsc_payment_receivers` table/model. Suggested fields:

| Field | Rule |
| --- | --- |
| `id` | Stable identifier; referenced by assignment audit and Payment snapshot. |
| `label` | Human-readable operational label, not customer identity. |
| `address` | Full EVM address, validated and normalized for comparison. |
| `address_normalized` | Lowercase canonical comparison value; unique across the receiver lifecycle. |
| `chain_id` | `56` for production BSC Mainnet. |
| `token_contract` | The approved USDT BSC contract; not customer-supplied. |
| `token_decimals` | Readiness-validated contract metadata, normally expected to be 18 for the configured contract. |
| `is_active` | Only active receivers are eligible for new checkout assignment. |
| `last_assigned_at` | Selection cursor for least-recently-assigned distribution. |
| `created_at`, `updated_at` | Audit timeline. |

There is no private key column. An address is a receiving destination only; the application never signs a transaction.

Address mutation policy:

- Only the Owner role can add, activate, or deactivate a receiver. This is stricter than the existing general payment-settings permission because a receiver controls the destination of customer funds.
- Adding a receiver validates and normalizes the EVM address, then stores it as active `manual_verified` after Owner re-authentication. The add form does not require a wallet-signature challenge.
- The Owner is responsible for checking that the address belongs to the intended receiving wallet before saving it. BSC transfer verification remains authoritative for customer Payments.
- Owner can deactivate a receiver after re-authentication.
- A receiver referenced by a Payment cannot be deleted.
- Deactivation affects new checkouts only.
- Existing Payments keep their snapshotted address and remain auditable.
- Every add/activate/deactivate action writes the existing audit log with actor, label, normalized address fingerprint, verification method, and reason; never log private credentials because none are required.
- The application sends an owner notification after every receiver mutation. The notification includes the action, label, masked address, actor, and timestamp, but never a signature payload or secret.

### BSC Payment snapshot

Use a rail-specific `payment_bsc_details` one-to-one record rather than reading the active receiver table during verification. Suggested fields:

| Field | Rule |
| --- | --- |
| `payment_id` | Unique foreign key to `payments`. |
| `receiver_id` | Original pool record, retained for audit. |
| `receiver_address` | Immutable full address snapshot. |
| `chain_id` | Immutable `56` snapshot. |
| `token_contract` | Immutable approved contract snapshot. |
| `token_decimals` | Immutable validated decimals snapshot. |
| `base_amount_micro` | Catalog USDT amount before unique reference increment. |
| `payable_amount_micro` | Final amount; must equal `payments.amount`. |
| `expected_token_units` | Exact integer token units, stored as a decimal string or sufficiently wide numeric value. |
| `verification_status` | BSC-specific state machine below. |
| `tx_submission_version` | Monotonic version for stale job protection. |
| `tx_attempts` | Valid hash attempt count. |
| `verification_grace_until` | Technical confirmation grace only. |
| `last_verified_at` | Last authoritative RPC verification time. |
| `current_tx_hash` | Latest submitted/observed hash, if any. |
| `current_confirmations` | Safe presentation value; never the source of truth by itself. |
| `current_block_number` | Observed inclusion block. |
| `current_from_address` | Sanitized sender address. |
| `evidence_payload` | Sanitized fields only; never store arbitrary RPC responses. |

If multiple candidate hashes must be retained, add `payment_bsc_transfer_evidence` with a globally unique `tx_hash`. A submitted hash must not overwrite historical confirmed/mismatch evidence.

On a successful claim, `payments.provider_reference` may be populated with the validated TX hash for existing reporting compatibility. It must never be populated from unvalidated customer input.

### Amount arithmetic and uniqueness

The existing `payments.amount` convention is integer micro-USDT. BSC continues using that convention for customer-facing amounts. Conversion to token base units is integer-only:

```text
expected_token_units = payable_amount_micro × 10^(token_decimals - 6)
```

The implementation must reject unsupported decimals rather than use floating point.

Allocator algorithm:

1. Read the catalog USDT amount in micro-USDT.
2. Start with the exact base amount.
3. If the candidate is occupied by a pending automatic `USDT_BEP20` Payment, increment by `100` micro-USDT (`0.0001` USDT).
4. Continue to the lowest free candidate within a bounded allocation window.
5. Persist the candidate as `payments.amount` and the base amount in BSC detail.
6. Enforce a database partial unique index for pending automatic BSC amounts.
7. Let the existing checkout retry path handle a concurrent unique conflict; never silently reuse an ambiguous amount.

The uniqueness scope is global across all BSC receiver records, including inactive and pending records. An address is still part of the verification predicate, but global uniqueness makes scanner matching, customer support, and manual review safer.

The allocator must not alter Binance Pay ID amount semantics. In particular, an Order ID Binance Pay Payment remains exact-price as required by the current design.

### Checkout transaction invariants

For a BSC checkout, all of the following happen in the existing checkout transaction:

1. resolve and validate the final rail;
2. lock the cart and checkout-maintenance boundary in the existing order;
3. validate the product's USDT price;
4. lock/select one active BSC receiver;
5. allocate a unique BSC amount;
6. create Order and Payment;
7. create BSC detail with immutable snapshots;
8. reserve inventory and allocate credentials;
9. materialize checkout and admin outbound effects;
10. mark the cart checked out.

If receiver readiness or amount allocation fails, the transaction rolls back. No Order, Payment, stock reservation, or customer instruction is left behind.

## BSC verification protocol

### Trusted source

The verifier uses a server-side BSC RPC/indexer adapter. It must never trust:

- customer-supplied amount, receiver, token contract, block number, or sender;
- a screenshot;
- an explorer page response as the authority;
- a client-side “success” flag;
- an arbitrary token that shares the `USDT` symbol.

The production configuration pins BSC Mainnet chain ID `56` and an approved USDT contract. BNB Chain documents BSC Mainnet as chain ID 56 and notes that BSC is EVM-compatible. Its RPC documentation also warns that some public endpoints disable `eth_getLogs`; scanner readiness must therefore require a provider that supports the required log queries, or a separately configured indexed source. See the [BSC wallet configuration](https://docs.bnbchain.org/bnb-smart-chain/developers/wallet-configuration/), [BSC JSON-RPC endpoint guidance](https://docs.bnbchain.org/bnb-smart-chain/developers/json_rpc/json-rpc-endpoint/), and [BSC finality guidance](https://docs.bnbchain.org/bnb-smart-chain/introduction/).

The initial approved BSC USDT contract should be configured as `0x55d398326f99059fF775485246999027B3197955`, matching the Binance-published common token address list. The application must still call and validate the contract's `decimals()` metadata during readiness; token name/symbol alone is not sufficient. See [Binance Common Token Addresses](https://www.binance.com/en-JP/skills/detail/binance-web3/binance-agentic-wallet).

### RPC capabilities

The adapter must support:

- `eth_chainId`;
- `eth_call` for token metadata such as `decimals()`;
- `eth_getTransactionReceipt` for submitted hashes;
- `eth_getBlockByNumber` for block timestamps and the latest/finalized head;
- `eth_getLogs` for exact `Transfer` event scanning, or an explicitly configured indexed equivalent.

Each operation has a bounded timeout, retry policy, and sanitized failure classification. RPC credentials, if a managed provider requires them, remain server-side.

### Exact acceptance predicate

A transfer is eligible for automatic confirmation only when every predicate passes:

```text
receipt exists
AND receipt.status == success
AND receipt.blockNumber exists
AND chain_id == 56
AND log.address == snapshotted token_contract
AND log.topic0 == keccak256("Transfer(address,address,uint256)")
AND decoded log.to == snapshotted receiver_address
AND decoded log.amount == expected_token_units
AND the selected log is unambiguous
AND block timestamp is within the Payment's allowed window
AND confirmations >= configured minimum (3 by default)
AND optional finalized-block/reorg safety passes when explicitly enabled
AND tx_hash has not already been claimed
AND Order and Payment are still eligible for automatic claim
```

The sender address is evidence and is displayed to admins, but is not restricted to a configured sender.

A transaction with multiple matching transfer logs is not automatically accepted unless the implementation explicitly proves the sum and identity rules. The safe default for this release is to mark multiple matching logs as `manual_review`.

### Hash-submission path

The channel adapter validates only the hash shape, then calls a domain submission service. The submission service, inside a transaction:

1. locks the Order and BSC detail;
2. verifies the Order is still pending and the Payment is `USDT_BEP20`;
3. rejects submissions after the automatic window/grace is closed;
4. returns an idempotent accepted result for the same current hash;
5. enforces five valid hash attempts;
6. increments `tx_submission_version` and `tx_attempts` for a new hash;
7. stores the hash as an untrusted lookup candidate, not as provider reference;
8. dispatches `VerifyBscPayment` after commit.

The request/webhook path must not perform an unbounded RPC call. If the queue dispatch fails, the durable submission state remains and reconciliation can recover it.

### Scanner path

The scheduled BSC reconciler finds pending BSC Payments within their window and queries the approved token contract for transfers to each snapshotted receiver. It filters by:

- receiver address;
- token contract;
- block range derived from Payment creation/deadline;
- exact expected token units;
- successful receipt and confirmation policy.

If no hash was submitted and exactly one candidate matches, the scanner can claim it. If zero candidates are found, the Payment remains `not_found`/`awaiting_transfer`. If multiple candidates match, the Payment becomes `manual_review`; it must not guess.

### Single claim

Successful verification calls the shared lifecycle boundary:

```text
BSC verifier → Payment claim transaction → OrderLifecycle::applyPaymentPaid()
```

The claim transaction locks the Order, Payment, and any transfer-evidence row. It must atomically:

- re-check pending Order/Payment state;
- check global TX hash reuse;
- persist sanitized evidence and provider reference;
- mark Payment paid;
- invoke the existing OrderLifecycle transition;
- record the PaymentEvent;
- enqueue post-commit customer/admin/receipt effects.

Repeated jobs, browser refreshes, Telegram retries, and duplicate hashes must produce `AlreadyPaid`, `StaleSubmission`, or `TransactionAlreadyUsed` outcomes without duplicate stock allocation or duplicate fulfillment.

## BSC verification states and outcomes

### Persisted states

| State | Meaning | Automatic action |
| --- | --- | --- |
| `awaiting_transfer` | No candidate has been observed. | Scanner continues while window is open. |
| `tx_submitted` | A hash is stored for lookup. | Targeted verifier is queued. |
| `confirming` | Exact transfer found but below threshold. | Retry until threshold/grace. |
| `confirmed` | Exact transfer passed all predicates. | Claim through OrderLifecycle. |
| `not_found` | Latest bounded lookup found no valid transfer. | Remain pending/reconcile. |
| `mismatch` | Evidence exists but amount/address/token/time is wrong. | Do not auto-claim; escalate. |
| `transaction_already_used` | Hash is already tied to another Payment. | Do not auto-claim; escalate. |
| `provider_error` | RPC/indexer failed or timed out. | Retry with bounded backoff. |
| `manual_review` | Ambiguous/multiple/late evidence needs an Owner decision. | Existing manual review boundary. |
| `expired` | Automatic window and any technical grace are closed. | Order expiry releases reservation. |

RPC outage is not `mismatch` and must never release an otherwise eligible Order. It is `Payment Verification Pending` until the bounded retry/reconciliation policy is exhausted.

## Expiry, cancellation, and late payment

### Deadline

The BSC Payment receives a 30-minute `expired_at` snapshot. The deadline is not extended because a customer pressed Check Payment or submitted a hash.

### Technical confirmation grace

The system may keep an Order pending for a short technical grace only when evidence shows that the exact transfer was included on-chain before the original deadline but the required confirmations arrived afterward. The draft default is `expired_at + 3 minutes`.

The grace window is not a new payment window. A transaction included after the deadline cannot become an automatic Paid Order merely because a hash was submitted.

`orders:expire-pending` must skip only Orders with a valid active confirmation grace condition, analogous to the current Binance Order ID grace behavior. Once grace closes, the existing OrderLifecycle expiry releases reservations.

### Cancel

There are two distinct Cancel actions:

- **Selector Cancel:** returns to payment choices and creates no Order.
- **Order Cancel:** uses the existing pending-cancellation lifecycle and releases reserved stock.

After Order Cancel, all BSC verification jobs become no-ops for automatic fulfillment. A later transfer is a Late Payment and requires manual review; it never revives the cancelled Order automatically.

## Admin and operational configuration

### Settings

Add a BSC-specific readiness/configuration surface without changing historical Binance Pay settings:

- `bsc_payment_enabled` on the existing manual payment setting;
- `bsc_payment_ttl_minutes`, default `30`;
- `bsc_min_confirmations`, default `3`, production minimum `3`;
- `bsc_require_finalized_block`, default `false`; when enabled it is an additional safety gate, not the default customer-visible threshold;
- `bsc_confirmation_grace_minutes`, default `3`;
- `bsc_unique_amount_increment_micro`, default `100`;
- `bsc_hash_attempt_limit`, default `5`;
- `BSC_RPC_URL`, the server-side RPC authority endpoint;
- BSC network identity, approved token contract, token decimals, explorer host, and RPC/scan limits, pinned in application configuration;
- existing `BINANCE_VERIFICATION_QUEUE`, reused by BSC verification jobs in v1.

BSC is considered ready only when it is enabled, the approved RPC responds, chain ID is 56, the token contract is reachable, decimals are valid, and at least one active BSC Receiving Address exists.

If BSC is not ready, the rail appears disabled/hidden according to the existing checkout presentation policy. A direct stale callback or crafted request must still fail inside the authoritative checkout boundary.

### Dashboard

The admin dashboard needs:

- BSC enable/disable control;
- BSC TTL and minimum-confirmation display;
- add, activate, and deactivate receiver actions;
- re-authentication state for receiver mutations;
- readiness status and last RPC health result;
- active receiver count;
- pending BSC Payment count;
- link to BSC Payment review evidence.

Destination addresses are public payment data, but their mutation is financially sensitive. Restrict mutation to the Owner role used for payment-provider control; do not reuse the broader Owner/Admin `canManagePaymentSettings()` predicate for receiver destination changes.

### Monitoring

Record safe metrics/log fields for:

- selected rail;
- receiver ID fingerprint, never an unbounded raw address in general logs;
- amount bucket, not sensitive customer data;
- RPC operation and status;
- verification outcome;
- confirmation count;
- queue latency;
- duplicate hash and mismatch count;
- expired/late BSC Payment count;
- address activation/deactivation audit.

Never log API keys, RPC credentials, private keys, raw customer screenshots, or unbounded RPC payloads.

## Channel implementation boundaries

### Shared domain/application services

Expected seams:

- `UsdtBep20CheckoutIntent` — BSC checkout intent and readiness.
- `BscPaymentReceiverPool` — locked address selection and immutable snapshot creation.
- `BscUniquePaymentAmount` — exact integer amount allocation.
- `BscRpcClient` — bounded JSON-RPC transport.
- `BscTokenTransferReader` — receipt/log decoding into sanitized typed evidence.
- `BscPaymentVerification` — status, submission, retry, candidate matching, and single claim.
- `BscVerificationQueue` — queue transport only.
- `VerifyBscPayment` — thin job adapter.
- `ReconcileBscPayments` — bounded scanner/reconciliation command.

These services must not send Telegram messages or render Blade copy. They return typed outcomes and evidence DTOs, following ADR-0003.

### Checkout selection

Update the checkout intent boundary so a requested rail is explicit rather than a boolean `binance` flag:

```text
qris
binance_pay_id
usdt_bep20
```

The existing `binance_pay` request/callback value can be retained only as a compatibility alias and must resolve to `binance_pay_id` at the server boundary.

The selector itself is channel-specific presentation. It must not become a second checkout service or create a provisional Payment.

### Telegram outbound effects

Add an explicit BSC checkout effect/template, for example:

- `TYPE_BSC_CHECKOUT`;
- `TEMPLATE_BSC_CHECKOUT = bsc-payment-v1`;
- `SendBscCheckoutInstructions`;
- BSC branch in `CheckoutInstructionDeliveryPipeline` and `TelegramOutboundEffectDispatcher`.

Do not reuse the Binance Pay ID effect type for BSC merely because both choices are under a Binance button. The durable ledger must be able to identify the actual rail, render a text or photo instruction correctly, and recover/resend the correct method.

### Storefront routes

Keep the signed-invoice boundary and add a signed BSC hash endpoint beside the existing Binance Order ID endpoint:

```text
POST /shop/orders/{order}/bsc-tx-hash
```

It must use the existing `storefront.signed` middleware, a dedicated rate limiter, strict hash validation, idempotent submission, and after-commit queue dispatch.

The existing status endpoint may return persisted BSC status and trigger a bounded single-flight verification, but it must not accept untrusted transaction details from the browser.

### Admin review

BSC mismatch, late, duplicate, and ambiguous evidence must flow through existing manual-review/manual-completion boundaries. Controllers and Blade views must not call `OrderLifecycle` directly or update stock/payment status ad hoc.

## Error and safety behavior

| Situation | Customer state | Payment action | Order action |
| --- | --- | --- | --- |
| No active BSC receiver | Rail unavailable | No Payment created | No Order created for that rail |
| RPC temporary failure | Checking / pending | `provider_error`, retry | Remains pending |
| Hash format invalid | Validation error | No attempt consumed | Unchanged |
| Hash not found yet | Waiting | `tx_submitted`/`not_found` | Remains pending |
| Exact transfer below 3 confirmations | Confirmations `n/3` | `confirming` | Remains pending |
| Wrong chain/token/address/amount | Manual review or mismatch | Persist sanitized evidence | Never auto-paid |
| Failed transaction | Not confirmed | `mismatch`/`not_found` according to evidence | Remains pending until normal expiry |
| Hash already claimed | Manual review | `transaction_already_used` | Never auto-paid |
| Exact transfer after expiry | Late Payment | Evidence retained | No automatic revival |
| Buyer cancels before payment | Cancelled | Verification jobs no-op | Existing cancellation lifecycle |
| Exact transfer confirmed | Success | Atomic claim | Existing fulfillment/delivery |

The copy must clearly distinguish:

- `not_found` — no valid transfer has been found yet;
- `confirming` — the correct transfer exists but is not sufficiently confirmed;
- `mismatch` — a transfer was found but does not satisfy the Payment contract;
- `provider_error` — the authority was unavailable and the system cannot conclude.

## Security requirements

- Validate and normalize EVM addresses before they enter the active pool.
- Pin chain ID and token contract server-side.
- Never accept the token contract, destination, amount, or network from the customer as authoritative.
- Use integer/string arithmetic for token amounts; never PHP/JavaScript floating point for comparison.
- Use constant-time/equivalent safe comparison for normalized hashes/addresses where applicable.
- Store TX hash attempts/evidence with a global uniqueness constraint.
- Lock Order, Payment detail, and evidence during the final claim.
- Rate-limit Telegram hash prompts and Storefront hash submissions separately.
- Do not perform unbounded RPC calls in webhook/request handlers.
- Treat explorer links as navigation aids only; build them from validated hashes and a server-side allowlisted host.
- Never show a customer a different active address after their Payment has been created.
- Do not automatically accept a partial amount caused by an exchange withdrawal fee. Route it to manual review.
- Treat a token with the right symbol but the wrong contract as invalid.
- Keep the existing exact receiver/amount/time/reuse checks for Binance Pay ID untouched.

## Migration and implementation slices

The implementation should be delivered in small, independently testable slices:

### Slice 1 — rail/domain/schema foundation

- Add the `USDT_BEP20` method contract.
- Add BSC receiver and Payment-detail migrations/models/factories.
- Add readiness configuration, Owner-only receiver management, and re-authentication.
- Add integer amount conversion/allocator with partial unique index.
- Add Storefront cache invalidation/versioning for committed BSC setting and receiver mutations.
- Add schema/concurrency tests.

### Slice 2 — BSC verifier

- Implement bounded RPC transport and typed RPC errors.
- Implement receipt/log decoding and exact predicate.
- Implement hash submission/versioning and the verification queue job.
- Implement reconciliation scanner and scheduler command.
- Implement atomic OrderLifecycle claim and PaymentEvent evidence.
- Reuse the existing Binance verification queue and worker in v1; do not add a new supervisor process.

### Slice 3 — checkout selection

- Refactor intent selection from boolean Binance to explicit rail.
- Change Telegram Binance callbacks into selector + rail-specific callbacks.
- Add Storefront secondary rail selector and final method validation.
- Prove selector Cancel has zero checkout side effects.

### Slice 4 — instructions and verification UI

- Add Telegram BSC effect/template/message/prompt.
- Add Storefront BSC invoice presentation, hash endpoint, status display, and copy actions.
- Add localized copy in `id`, `en`, and `ms` catalogs.
- Add the network/token/late-payment warnings.

### Slice 5 — expiry, review, and operations

- Extend pending-expiry guard for valid BSC confirmation grace.
- Add BSC evidence to admin order detail and manual review.
- Add owner alerts for mismatch, duplicate, late, and RPC-readiness failures.
- Add metrics, health checks, and deployment/runbook notes.

### Slice 6 — production validation

- Run focused PHPUnit tests, full suite, migration status, schedule list, and production configuration validation.
- Validate with a controlled low-value mainnet payment only after the BSC feature remains disabled by default and readiness checks pass.
- Deploy through the repository's existing GitHub Actions/VPS process; do not add Railway configuration or commands.

## Acceptance test matrix

### Selection and checkout

- Telegram Binance click opens a new rail selector.
- Storefront Binance click opens the secondary selector.
- Selector Cancel returns without Order, Payment, reservation, customer instruction, or admin notification.
- Pay with Binance Pay ID creates `BINANCE_PAY_ID` and retains all existing mode behavior.
- Pay with BSC creates `USDT_BEP20` and exactly one BSC detail snapshot.
- Stale selector callbacks cannot create an Order against a different cart.
- Crafted requests with unavailable/invalid rails fail at the checkout boundary with zero new side effects.
- Duplicate checkout submissions remain idempotent.

### Receiver and amount allocation

- Invalid or duplicate receivers cannot be saved; valid distinct addresses can be saved directly as active receivers by the Owner.
- Two concurrent BSC checkouts receive safe distinct amounts and valid receiver snapshots.
- Receiver selection is distributed according to the documented cursor policy.
- Pending BSC amount uniqueness is enforced by both allocator and database index.
- Allocation exhaustion rolls back the entire checkout.
- Deactivating a receiver does not change existing Payment instructions.
- Existing Binance Pay amount allocation/index behavior remains unchanged.

### On-chain verification

- Exact successful BSC USDT transfer is confirmed only after 3 confirmations.
- Exact transfer with a submitted hash and exact transfer found by scanner both claim successfully.
- Wrong chain, wrong contract, wrong receiver, wrong amount, failed receipt, and wrong time never auto-claim.
- ERC20/TRC20/native BNB transfers never satisfy the BSC predicate.
- A pending transaction transitions to `confirming` without fulfillment.
- Repeated hash submission is idempotent; invalid hashes do not consume attempts.
- A fourth/sixth valid hash according to the configured limit is rejected without an RPC call.
- Reused TX hash cannot pay a second Payment.
- Multiple matching candidates become manual review rather than an arbitrary claim.
- RPC timeout/error remains Payment Verification Pending and is recovered by reconciliation.
- A reorg/disappearing receipt cannot leave a false Paid transition.

### Lifecycle and expiry

- Confirmed BSC transfer calls `OrderLifecycle` and delivers stock exactly once.
- Browser polling, Telegram callbacks, scanner jobs, and queue retries cannot duplicate fulfillment.
- Transfer included before expiry but confirmed during technical grace can complete.
- Transfer included after expiry becomes Late Payment/manual review.
- Expired pending BSC Orders release reservations after grace.
- Cancelled Orders never auto-revive from a later BSC transfer.

### Channel and operations

- Telegram prompt accepts hashes only for the active customer/Order session.
- Storefront hash endpoint requires a valid signed invoice and rate limit.
- BSC instruction effect uses the BSC template/method and survives queue recovery.
- Admin order detail shows sanitized BSC evidence and safe explorer link.
- Missing RPC/receiver/token readiness hides or disables BSC without breaking Binance Pay ID.
- An address typed by an Admin/Support user cannot become active; Owner re-authentication is required.
- A valid address can be added, activated, deactivated, and rotated from the dashboard without changing ENV or restarting the application.
- Storefront availability changes after a committed setting/receiver mutation without waiting for the catalog cache TTL.
- Existing Binance Pay regression suite remains green.

## Ticketing status

The product decisions are resolved for implementation ticketing. Remaining implementation details—such as the exact wallet-connect browser adapter and the final BSC RPC provider—must preserve the contracts above and do not require a new product decision.

## References

- [ADR-0003 — Binance Payment Verification](../../adr/0003-binance-payment-verification.md)
- [Repository domain glossary](../../../CONTEXT.md)
- [Repository implementation map](../../agents/codebase.md)
- [BNB Chain wallet configuration](https://docs.bnbchain.org/bnb-smart-chain/developers/wallet-configuration/)
- [BNB Chain JSON-RPC endpoint guidance](https://docs.bnbchain.org/bnb-smart-chain/developers/json_rpc/json-rpc-endpoint/)
- [BNB Chain introduction and finality](https://docs.bnbchain.org/bnb-smart-chain/introduction/)
- [Binance Common Token Addresses](https://www.binance.com/en-JP/skills/detail/binance-web3/binance-agentic-wallet)
- [EIP-191 — Signed Data Standard](https://eips.ethereum.org/EIPS/eip-191)
- [ERC-1271 — Contract Signature Validation](https://eips.ethereum.org/EIPS/eip-1271)
- [Binance Spot API security model](https://github.com/binance/binance-spot-api-docs/blob/master/rest-api.md)
