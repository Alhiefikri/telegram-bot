# Global receiver ownership: alamat USDT eksklusif per Customer (#549)

U4 implements work package **P5** of [#536](https://github.com/Alhiefikri/telegram-bot/issues/536) on top of the closed dependency A0 [#537](https://github.com/Alhiefikri/telegram-bot/issues/537), under the custody decision of H1 [#539](https://github.com/Alhiefikri/telegram-bot/issues/539)/[ADR-0021](../adr/0021-usdt-exclusive-external-receivers.md). It owns one permanent, channel-exclusive claim for every normalized `(chain_id, address)` used as a BSC USDT receiver and binds public addresses to exactly one Customer for future top-up funding.

It is schema and owner-service work only. No funding rail, invoice, top-up target, ledger effect or settlement exists here, no approved custody adapter is registered, and no private key, seed or signing capability is stored, generated or derived. USDT funding stays **disabled**: requiring receiver capacity fails closed rather than falling back to a shared address with a unique amount.

## 1. What the registry guarantees

| Invariant | Enforcement |
| --- | --- |
| One `(chain_id, address_normalized)` belongs to at most one receiver channel forever | `usdt_receiver_address_claims` unique index + immutable claim row (PostgreSQL trigger rejects UPDATE/DELETE) |
| The checkout pool and Customer-exclusive deposit receivers never share an address | Both receiver tables reference one claim row and a PostgreSQL trigger rejects a receiver whose claim channel/chain/address does not match; `BscPaymentReceiverPool` additionally selects only `channel = checkout` claims |
| A receiver address is never reassigned, recycled or re-bound, including after retire or deletion of a pending binding | Deposit claims are never reused; address/chain/token/customer columns are immutable at model and trigger level |
| At most one non-retired receiver per Customer | PostgreSQL/SQLite partial unique index `usdt_deposit_receivers_active_customer_unique` plus a Customer row lock in the registering transaction |
| A retired receiver keeps being monitored for late money | `retired_at` only stops new assignment; `monitoredReceivers()` keyset scan includes retired rows |
| Receiver capacity, not a shared address, decides whether an invoice is possible | `allocateFor()` fails closed with `UsdtReceiverCapacityUnavailable` when no approved provisioner is available or its capacity is exhausted |
| Only an authorized Owner maps a provider address to a Customer, and every mapping is audited | Owner-only permission checks in the service plus `audit_logs` entries for register/verify/provision/retire with masked address and fingerprint, never raw key material |

Normalization uses the existing `BscPaymentReceiver::normalizeAddress()` contract, so checkout and deposit channels compare byte-identical addresses.

## 2. Schema (additive)

| Table | Purpose |
| --- | --- |
| `usdt_receiver_address_claims` | permanent channel-exclusive claim of one normalized `(chain_id, address_normalized)`; `channel` is `checkout` or `customer_deposit` |
| `customer_usdt_deposit_receivers` | Customer-exclusive public address with immutable `customer_id`, claim, chain, token contract/decimals and custody namespace; verification evidence; `retired_at`/`retirement_reason`/`retired_by` |
| `usdt_deposit_receiver_ownership_challenges` | expiring, single-use ownership-proof challenges for pending receivers (mirrors the checkout challenge contract with its own domain-separated purpose) |
| `bsc_payment_receivers.address_claim_id` | NOT NULL unique FK on PostgreSQL, backfilled for every existing checkout receiver as a `checkout` claim |

PostgreSQL guards (also covered by model guards for the SQLite test lane):

- `usdt_receiver_address_claims`: any UPDATE or DELETE raises.
- `bsc_payment_receivers`: insert/update must reference an existing `checkout` claim whose chain and normalized address match the row.
- `customer_usdt_deposit_receivers`: insert/update must reference a `customer_deposit` claim with matching chain/address; the Customer binding, claim, chain/address, token metadata, custody namespace and source are immutable; verification is final once decided; `retired_at` can only move from NULL to a value and never changes afterwards; DELETE is only allowed for a pending, never-retired row (a typo before verification).

Existing checkout rows are migrated to permanent `checkout` claims. A checkout claim can be re-used only by a new checkout receiver after the previous receiver row was deleted; it can never become a Customer-exclusive binding, and a `customer_deposit` claim is never re-used at all.

## 3. Owner service and provisioning contract

`App\Services\UsdtReceivers\UsdtReceiverAddressClaims` is the single cross-table claim gate:

```php
$claims->claim($chainId, $address, UsdtReceiverChannel::Checkout);       // checkpoint reuse allowed
$claims->claim($chainId, $address, UsdtReceiverChannel::CustomerDeposit); // never reused
```

Conflicting inserts are recovered through a savepoint, so an outer money transaction is never left aborted.

`App\Services\UsdtReceivers\UsdtDepositReceiverRegistry` owns Customer bindings:

- `register(Customer $customer, string $address, User $actor, ?string $ip = null)` — Owner-only; claims the address for the deposit channel, creates a `pending_verification` binding, issues a domain-separated ownership challenge and audits `usdt_deposit_receiver.registered`.
- `verify($receiver, $challengeId, $walletType, $signature, $actor, $ip)` — completes the challenge with the shared `BscWalletOwnershipVerifierInterface::verifyMessage()` contract (EIP-191 EOA or ERC-1271). A missing/stale/used/foreign challenge or an invalid signature fails without touching the binding.
- `manualVerify($receiver, bool $highRiskAcknowledged, $actor, $ip)` — the same explicitly acknowledged Owner custody attestation used by checkout receivers; never a substitute for a qualified custody adapter.
- `retire($receiver, $actor, $reason, $ip)` — Owner-only, reason required, idempotent; keeps the binding, claim and history.
- `allocateFor(Customer $customer): UsdtDepositReceiverSnapshot` — returns the current assignable receiver, or provisions a new exclusive address through an approved adapter; the returned `UsdtDepositReceiverSnapshot` (receiver id, customer id, address, chain, token contract, decimals, custody namespace) is the immutable value a top-up must freeze before its invoice exists.
- `snapshotFor()`, `activeFor()`, `findByAddress()`, `channelOf()`, `monitoredReceivers(?int $afterId, int $limit)` — read seams for the future rail, ingestion scans and reconciliation.

Custody provisioning is an explicit adapter contract:

```php
interface UsdtDepositReceiverProvisioner
{
    public function key(): string;                        // custody namespace, sanitized token
    public function available(): bool;                    // readiness/capacity, fail closed
    public function provide(Customer $customer): ?string; // exclusive public address, null when exhausted
}
```

Adapters are resolved through the container tag `usdt-deposit-receiver-provisioners`, which is intentionally empty in this release. **A test double is not custody evidence**: registering a real adapter is an explicit production decision gated by H1 custody/capacity qualification, so `allocateFor()` currently reports capacity unavailable for every Customer without a registered address.

## 4. Checkout integration

`BscReceiverManager::add()` claims the address as `checkout` before inserting the receiver (a deposit-claimed address is rejected with the existing "sudah terdaftar" contract), `activate()` re-checks the claim channel, and `BscPaymentReceiverPool` selects only `checkout` claims. Every existing checkout test still passes: the checkout receiver contract, readiness gate, assignment order and payment snapshots are unchanged.

## 5. Operator commands

| Command | Purpose |
| --- | --- |
| `usdt-receivers:register --customer-id= \| --telegram-id= --address= --actor= [--manual-verified]` | Registers a pending Customer-exclusive receiver and prints the ownership challenge (or records the acknowledged manual attestation). The Customer must already exist by exact ID or exact numeric Telegram ID; unknown identities are never created. |
| `usdt-receivers:verify --receiver= --challenge= --wallet-type= --signature= --actor=` | Completes the ownership challenge. |
| `usdt-receivers:retire --receiver= --reason= --actor=` | Retires a receiver from new invoice assignment while keeping history and monitoring. |

`--actor` is an exact Owner user ID or email; authorization is enforced inside the service, so every caller shares one gate. Commands print sanitized summaries only (receiver id, customer id, masked address, status, challenge id).

## 6. Races and fail-closed paths

| Race | Result |
| --- | --- |
| Checkout registration vs deposit binding for the same address | Exactly one channel claims the address; the other fails with a conflict and no partial row |
| Customer A vs Customer B binding the same address | Exactly one binding; the other fails with a conflict |
| Concurrent allocation for the same Customer | Both callers converge on the single winning receiver; the loser's claim/receiver transaction rolls back, so no orphan claim or second address remains |
| Allocation with no approved adapter, or an exhausted adapter | `UsdtReceiverCapacityUnavailable`; no receiver, claim or shared-address fallback |
| Adapter returns an address already owned by another channel/receiver | `UsdtReceiverAddressConflict`; the address is never stolen or shared |

## 7. Limitations and downstream ownership

- No custody adapter, address capacity, proof-of-control operation or provider permission is approved; H1 [#539](https://github.com/Alhiefikri/telegram-bot/issues/539) stays open and funding remains a no-go. This ticket implements the contract those gates must satisfy, not their evidence.
- `manualVerify` is an Owner high-risk attestation; it does not prove a qualified custody arrangement and is not legal or provider clearance.
- Owners register addresses one at a time from the console. There is no Admin Dashboard form, queued provisioning, address inventory dashboard or SMS/Telegram operator notification in this ticket; U9 [#554](https://github.com/Alhiefikri/telegram-bot/issues/554)/O1 [#557](https://github.com/Alhiefikri/telegram-bot/issues/557) own customer/operator surfaces.
- Receiver assignment to an invoice, immutable top-up snapshots, ownership attribution and late-money monitoring of retired addresses are U5 [#550](https://github.com/Alhiefikri/telegram-bot/issues/550)/U6 [#551](https://github.com/Alhiefikri/telegram-bot/issues/551)/U7 [#552](https://github.com/Alhiefikri/telegram-bot/issues/552); this ticket provides the registry and snapshot contract they consume.
- Address claims are permanent by design. Deleting a mistyped pending receiver keeps its address out of circulation forever; rotate to a new address instead.
- SQLite/sync tests prove behaviour, not locking. Only the PostgreSQL lane proves cross-table uniqueness, trigger enforcement and multiprocess convergence.

## 8. Verification evidence

Feature (`tests/Feature/UsdtDepositReceiverRegistryTest.php`, `tests/Feature/UsdtDepositReceiverCommandTest.php`, `tests/Feature/CheckoutReceiverAddressClaimBackfillTest.php`, SQLite): claim/audit sanitization, pending non-assignability, manual and cryptographic verification paths, cross-channel conflicts in both directions (checkout → deposit and deposit → checkout), two-Customer conflict, never-reassigned after retire/deletion, immutability of binding/verification/retirement/claims with persisted-state proof, rotation with monitored history, one-active-receiver rule, unsupported chain/decimals fail-closed snapshots, no-provisioner/exhausted/claimed-address fail-closed allocation, reuse without reprovisioning, keyset monitoring pages, role denial for Admin/Finance/Support, checkout-claim reuse contract, legacy claim backfill (idempotent and cross-channel refusal), and command authorization/identity selection without automatic Customer creation.

PostgreSQL (`tests/Feature/UsdtReceiverOwnershipPostgresConcurrencyTest.php`, disposable PostgreSQL 16, multiprocess): checkout vs deposit registration race, two Customers binding one address, concurrent allocation convergence, and database guards rejecting cross-channel reuse, channel rewrite, un-retire and Customer reassignment.

Recorded evidence (base `main` a5321f03):

- `UsdtDepositReceiverRegistryTest` 19 tests, `UsdtDepositReceiverCommandTest` 6 tests, `CheckoutReceiverAddressClaimBackfillTest` 2 tests pass on the SQLite lane;
- `UsdtReceiverOwnershipPostgresConcurrencyTest` 4 tests / 28 assertions pass on disposable PostgreSQL 16 (`RUN_CONCURRENCY_TESTS=1`, `phpunit.concurrency.xml`);
- the complete PostgreSQL concurrency lane (`phpunit --group=concurrency --fail-on-skipped`) passes on disposable PostgreSQL 16: **183 tests / 1689 assertions, 0 skips, 0 failures, 0 errors**, i.e. the new receiver races plus every pre-existing race with the new schema and triggers in place;
- migration round trip (`migrate` → `migrate:rollback --step=1` → `migrate`) verified on both SQLite and PostgreSQL 16, including trigger/foreign-key cleanup;
- the full SQLite suite (`php artisan test --parallel --recreate-databases`) passes: **3526 tests / 26192 assertions, 136 skipped (PostgreSQL-lane tests), 0 failures, 0 risky**, and `vendor/bin/pint --test` passes on the whole repository.
