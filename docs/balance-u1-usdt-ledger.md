# Customer USDT balance ledger, exact money and accounting events (#546)

U1 implements work package **P3** of [#536](https://github.com/Alhiefikri/telegram-bot/issues/536) under the [proposed ADR-0019](../adr/0019-idr-usdt-economic-sources-and-receipt-claims.md) contract. Dependency A0 [#537](https://github.com/Alhiefikri/telegram-bot/issues/537) is closed. No deployment, production activation, provider credential, real funding or USDT rail is authorized by U1; USDT funding stays **disabled** and `balance:money-receipt-cutover-report` still reports the USDT rail as `disabled`.

U1 adds an additive, currency-specific Customer USDT subledger next to the canonical IDR Store Balance. It never migrates, dual-writes or reads IDR money.

## 1. Exact micro-USDT money

`App\Services\UsdtBalance\UsdtMicroAmount` is the customer-input and raw-chain boundary:

- one positive integer amount in micro-USDT (`10^6` per USDT), no float anywhere;
- `fromDecimalString()` accepts only an unsigned, canonical decimal string of at most 32 characters (`0`, leading-zero, `+`, exponent, whitespace, separators and `.5`/`1.` forms are rejected); excess fractional **non-zero** digits and `PHP_INT_MAX` overflow are rejected, trailing zeros beyond 6 digits are exact and accepted;
- `fromUnits()` accepts only a positive native integer;
- `fromRawTokenUnits()` parses canonical unsigned uint256-scale decimal strings with Brick `BigInteger`, converts exactly for any `0..36` token decimals and rejects a non-zero remainder (`RoundingNecessaryException`) or native-integer overflow (`IntegerOverflowException`) instead of rounding;
- `decimal()` serializes a fixed 6-decimal canonical string; `integerUnits()` exposes the explicit integer-unit string.

Provider-side parsers (`BinanceUsdtAmount` for signed Binance history, `BscTokenAmount` for micro→raw token units) are unchanged; U1 verifies and pins the installed **brick/math 0.18.0** signatures it relies on (`BigInteger::of`/`ten`/`power`/`dividedBy`/`multipliedBy`/`toInt`, `BigDecimal::of`/`toScale`/`getUnscaledValue`/`ofUnscaledValue` and the `Unnecessary` rounding exceptions) in `UsdtMicroAmountTest`. BSC raw amounts remain signed-integer-free BigInteger/string values until a qualified verifier proves them representable.

## 2. Typed economic sources

The ledger exposes **only** typed entry points; there is no arbitrary public credit API and controllers cannot select a source string:

| Method | Source | Kind | Source claim |
| --- | --- | --- | --- |
| `creditVerifiedTopUp` | `VerifiedUsdtFunding` | `funding_credit` | `customer_usdt_top_up` + top-up id |
| `debitPurchase` | `UsdtPurchase` | `purchase_debit` | `order` + Order id |
| `refundEntitlement` | `UsdtRefund` | `refund_credit` | `order_refund` + refund id |
| `reverseApprovedEntry` | `ApprovedUsdtReversal` | `reversal_debit` | `approved_reversal` + original entry id |

Each immutable source binds variant, kind, exact currency/amount as a parsed positive `UsdtMicroAmount` (a raw integer micro amount cannot reach the ledger), Customer and its business identity, and derives a versioned `sha256` request fingerprint over the canonical payload (variant/kind/currency/customer/amount plus source-specific identity such as receipt id and evidence version). Replaying the same idempotency key, source identity or fingerprint returns the existing entry — even after the account was frozen or its projection drifted, because a matching replay resolves recorded money without mutating anything. Reusing any claim with changed identity, amount, customer or fingerprint is a conflict with no second posting.

Source-specific linkage is enforced under the account lock: refunds require the original purchase debit of the same Order and cap cumulative refunds against its remaining amount; reversals require the full original credit entry and are allowed exactly once. Reversal accounting legs mirror the original event (funding reversal debits `customer_usdt_liability` against `usdt_settlement_asset`; refund/purchase reversals mirror their own counterparts). Funding credits post `usdt_settlement_asset` against `customer_usdt_liability`, purchase debits `customer_usdt_liability` against `usdt_sale`, and refund credits `usdt_sale_refund` against `customer_usdt_liability`.

## 3. Additive schema and database guards

Migration `2026_10_05_170000_create_customer_usdt_balance_tables.php` adds exactly the three tables the I2 #544 readiness gate names:

- `customer_usdt_balance_accounts` — one account per Customer (`unique customer_id` with **RESTRICT** delete), `currency = 'USDT'` CHECK, nonnegative `balance_micro`, monotonic `version` cursor, `last_entry_id` cursor and nullable `frozen_reason`;
- `customer_usdt_balance_entries` — account **RESTRICT** FK, per-account `account_sequence`, `kind`, positive `amount_micro` CHECK, nonnegative `balance_after_micro` CHECK, `source_type`/`source_id`, per-account `idempotency_key`, `request_fingerprint`, nullable linked `original_entry_id` and `refund_order_id` (Order **RESTRICT** FK); unique claims on `(account, account_sequence)`, `(account, idempotency_key)` and `(source_type, source_id, kind)`;
- `customer_usdt_balance_accounting_events` — one unique append per entry, `USDT` CHECK, positive amount CHECK, balanced `debit_account`/`credit_account` legs and the source fingerprint.

PostgreSQL `protect_customer_usdt_balance_history()` triggers reject **every** UPDATE and DELETE of an entry or accounting event regardless of role; Eloquent model guards add the in-process `LogicException` defense. The projection row stays mutable only for the ledger's atomic cursor update, and `balance_micro` is not mass assignable. The runtime-role least-privilege split (app role may INSERT entries/events but not UPDATE/DELETE them) is documented in [runbook-usdt-ledger-privileges.md](runbook-usdt-ledger-privileges.md).

## 4. Ledger behaviour

`CustomerUsdtBalanceLedger` owns every mutation:

- `snapshot()` reads projection + newest entry balance + cursor in **one SQL statement**, returns `available` only when the cursor/balance agree and exposes `frozenReason` separately; drift fails closed to `unavailable`;
- `lockForTransaction()` creates the account with `INSERT … ON CONFLICT DO NOTHING` followed by a real `SELECT … FOR UPDATE`, then refuses a frozen account or a drifted cursor, so an empty-account creation race can never observe a half-created account and downstream money callers cannot start from an unsafe projection;
- every posting runs in one short transaction (5 deadlock attempts at the top level): lock account → replay/conflict check → reject frozen or drifted cursor → source linkage → integer-overflow and nonnegative guards → append entry with the next `account_sequence` → conditional projection update (`balance_micro = before` for credits, `balance_micro >= amount` for debits, affected-row check) → insert the accounting event;
- `UsdtBalanceMutationResult` reports `applied` vs `replayed`; `UsdtBalanceSnapshot` reports the fail-closed projection; `InsufficientUsdtBalance`, `UsdtBalanceFrozenException` and `UsdtBalanceProjectionMismatchException` carry the typed failure reasons;
- no HTTP, Telegram, queue or provider call happens inside the closure. Redis is never a money authority.

## 5. Separation from IDR

No IDR table, writer or reader is touched. `StoreBalanceLedger` and `CustomerUsdtBalanceLedger` keep independent tables and idempotency namespaces; a USDT posting never creates or reads an IDR account/entry and vice versa. The existing IDR full-history checks, adjustment/reversal semantics and receipt claims are unchanged.

## 6. Limitations and downstream ownership

- U1 is the ledger/schema/money contract only. Funding intents, receiver ownership, rail adapters, settlement, late money/compensation, policy/quota/pause, reconciliation, Telegram/web surfaces, checkout debit and refunds remain [#547](https://github.com/Alhiefikri/telegram-bot/issues/547)–[#556](https://github.com/Alhiefikri/telegram-bot/issues/556) and [#558](https://github.com/Alhiefikri/telegram-bot/issues/558).
- The I2 #544 Dashboard readiness gate now opens on migrated databases (`BalanceAdjustmentDashboardReadiness` checks the three real tables). #544 still tracks only positive IDR balances; positive USDT directory integration remains a #544 follow-up, not part of U1.
- Provider/custody/legal gates ([#539](https://github.com/Alhiefikri/telegram-bot/issues/539), [#540](https://github.com/Alhiefikri/telegram-bot/issues/540)) remain open; a green ledger test suite is not funding authorization.
- SQLite/sync tests prove behaviour, not locking; PostgreSQL process races are the locking evidence.
- `ApprovedUsdtReversal.ref` is a typed ledger seam, not an approval workflow: it binds a full original credit entry and an authorization reference, but the owner-review flow that mints that reference is downstream ([#553](https://github.com/Alhiefikri/telegram-bot/issues/553)/[#558](https://github.com/Alhiefikri/telegram-bot/issues/558)). No controller, Telegram callback or job calls the reversal today, and the method requires a caller-owned transaction.
- The proposed `actor/reference` entry column is deliberately not added: the entry carries its typed source identity, linkage and versioned fingerprint, while actor/user attribution remains with the owning service's audit records — matching the canonical IDR ledger.
- Mutation guards use the locked cursor version (`version` + `last_entry_id` + `balance_after`) rather than a per-posting full-history aggregate, per P3's no-full-history-aggregate rule; the bounded signed-sum reconciliation owner is [#547](https://github.com/Alhiefikri/telegram-bot/issues/547).
- Reversal is whole-amount and limited to credit entries in this work package; an outstanding-amount/partial reversal would need its own approved-request contract and is not part of U1.
- `UsdtPurchase` binds the Order and internal payment identity plus the exact debit amount; the frozen pricing/item snapshot that produced that amount remains the checkout transaction's responsibility ([#555](https://github.com/Alhiefikri/telegram-bot/issues/555)).

## 7. Verification evidence

`tests/Unit/Services/UsdtBalance/UsdtMicroAmountTest.php` pins the parser/raw-chain/Brick boundary; `UsdtBalanceSourceTest.php` pins the typed source identity/fingerprint closure. `tests/Feature/CustomerUsdtBalanceLedgerTest.php` covers atomic append/projection/accounting, replay/conflict on key/source/fingerprint (including replay resolution on frozen/drifted accounts), one account per Customer, refund linkage/cap, full linked single reversal with mirrored legs, immutability, projection/cursor drift freeze, frozen accounts and strict `lockForTransaction`, overflow rollback, atomic rollback after the entry was written, accounting uniqueness and IDR separation.

`tests/Feature/CustomerUsdtBalancePostgresConcurrencyTest.php` proves on disposable PostgreSQL 16: four parallel credits keep sequence/balance/accounting exact and leave IDR counts unchanged; competing debits never commit a negative balance (one `InsufficientUsdtBalance`); empty-account creation races settle on one account; concurrent refunds cannot exceed the original debit; reversal versus spend keeps one economic effect; named constraints reject negative balance, `IDR` currency, an out-of-enum `kind`, zero amounts and duplicate source claims; entry/accounting UPDATE/DELETE are denied at runtime (trigger plus least-privilege role where role creation is available); readers never observe a projection/cursor divergence while a writer posts.

Evidence recorded for this implementation (base `main` 59cff856, PHP 8.5.9): focused `tests/Unit/Services/UsdtBalance` 10 tests / 124 assertions and `CustomerUsdtBalanceLedgerTest` 17 tests / 136 assertions; full `php artisan test --parallel` 3.461 tests / 25.844 assertions, 0 failures, 129 PostgreSQL-only skips; disposable PostgreSQL 16 gate `composer test:concurrency-gate:local` 176 tests / 1.579 assertions with 0 skips/failures (gate report 156 tests / 1.420 assertions), including the focused `CustomerUsdtBalancePostgresConcurrencyTest` 9 tests / 91 assertions; Pint `--test` passes. SQLite/sync tests do not prove locking or triggers — only the PostgreSQL lanes do.
