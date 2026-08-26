# Checkout Payment Assembly Architecture

- **Date:** 2026-08-25
- **Status:** Accepted — 2026-08-25; implementation cutover complete through #360, with final documentation and verification tracked by #361
- **Scope:** Shared Order checkout used by the Web Storefront and Store Bot
- **Primary seam:** rail-specific Payment preparation
- **Owner after implementation:** OrderCheckoutService remains the canonical Order checkout module

## Decision summary

OrderCheckoutService currently owns two different kinds of work:

1. the shared checkout transaction: Cart, Order, OrderItem, lock ordering,
   Inventory Ledger, credential allocation, idempotency, and Telegram Outbound
   Effect materialization;
2. rail-specific Payment assembly: Binance fields, unique Binance amount,
   BSC readiness, BSC receiver selection, BSC unique amount, and
   PaymentBscDetail persistence.

The shared checkout transaction is valuable depth. The rail-specific assembly
is the leakage to remove.

The target is a deep CheckoutPaymentPreparation seam:

~~~text
Web Storefront / Store Bot
  → PaymentProviderResolver
  → CheckoutPaymentIntent
  → CheckoutPaymentPreparationResolver
  → CheckoutPaymentPreparation adapter
  → CheckoutPaymentPlan
  → OrderCheckoutService
~~~

The preparation module has two phases:

~~~text
preflight()  → before checkout locks; may perform BSC readiness work
prepare()    → after checkout locks; may allocate a receiver/unique amount,
               but may not perform RPC or other external I/O
~~~

OrderCheckoutService consumes the resulting immutable CheckoutPaymentPlan. It
does not inspect Binance/BSC types, call method_exists, or branch on a payment
method to assemble a Payment.

The five existing rails remain concrete adapters:

- Manual QRIS;
- Pakasir;
- KlikQRIS;
- Binance Pay ID;
- USDT BEP20/BSC.

The change does not alter the Order lifecycle, Inventory Ledger, Telegram
Outbound Effect Ledger, provider switch semantics, or verification workflows.

## Why this seam is real

The current code has five payment rails and a growing number of rail-specific
rules. OrderCheckoutService currently:

- injects BinancePayClient, BinanceUniquePaymentAmount,
  BscPaymentReceiverPool, BscUniquePaymentAmount, and BscReadiness;
- calls method_exists($paymentIntent, 'verificationMode');
- checks BINANCE_PAY_ID and USDT_BEP20 directly;
- writes Binance-specific Payment fields;
- selects a BSC receiver and writes the BSC detail snapshot.

This is more than one hypothetical variation. The seam has real adapters and
real callers on both customer surfaces.

The current interface is also a warning sign:
ProviderBoundCheckoutPaymentIntent mostly forwards the existing
CheckoutPaymentIntent methods while carrying provider-setting metadata. That
wrapper is retained as a compatibility adapter during this change; it is not
the primary target of the first implementation slice.

## Current contract

The following existing contracts remain authoritative:

- [OrderCheckoutService](../../../src/app/Services/OrderCheckoutService.php)
  owns Order creation, reservation, credential allocation, and checkout
  idempotency.
- [CheckoutPaymentIntent](../../../src/app/Services/Checkout/CheckoutPaymentIntent.php)
  represents the selected payment rail.
- [PaymentProviderResolver](../../../src/app/Services/Checkout/PaymentProviderResolver.php)
  resolves the active provider and provider-setting snapshot.
- [InventoryLedger](../../../src/app/Services/InventoryLedger.php) owns
  reservation and stock movement.
- [OrderLifecycle](../../../src/app/Services/OrderLifecycle.php) owns paid,
  expired, cancelled, refunded, and manual-review transitions.
- [TelegramOutboundEffectLedgerProtocol](../../../src/app/Services/Telegram/TelegramOutboundEffectLedgerProtocol.php)
  is the sole durable delivery-state seam.

The implementation map in
[docs/agents/codebase.md](../../agents/codebase.md) remains the source of truth
for lock order, queue ownership, and existing invariants.

## Goals

- Remove rail-specific Payment assembly from OrderCheckoutService.
- Preserve the current public checkout entry point during migration.
- Keep BSC readiness preflight outside checkout locks.
- Keep all transaction-local allocation and snapshot work inside the existing
  database transaction.
- Make the rail-specific implementation testable through one small seam.
- Let a future payment rail add an adapter and focused tests without adding
  branches to OrderCheckoutService.
- Keep the resulting plan typed; do not introduce a raw provider field bag.
- Reduce the OrderCheckoutService constructor dependency set.
- Preserve both Web Storefront and Store Bot behavior.

## Non-goals

- Do not redesign the Cart or checkout-key protocol.
- Do not change the Order state machine or payment verification workflows.
- Do not move reservation, sale, release, credential allocation, or
  fulfillment rules into a payment adapter.
- Do not move Telegram message copy or Telegram Outbound Effect state into a
  payment adapter.
- Do not create a provider-neutral Native QRIS module.
- Do not add automatic provider fallback or create a second Payment.
- Do not change how existing Payments are interpreted.
- Do not change the Payment Provider Switch.
- Do not change database schema unless implementation proves that an existing
  field cannot represent the same snapshot. The default expectation is no
  migration.
- Do not introduce a public interface for the internal plan writer unless a
  second real adapter requires it.

## Domain language and documentation decision

Order, Payment, Inventory Ledger, Product Stock Item, Native QRIS, Binance
Payment Rail, and BSC Payment Verification retain their existing meanings in
[CONTEXT.md](../../../CONTEXT.md).

CheckoutPaymentPreparation and CheckoutPaymentPlan are architecture terms, not
new domain concepts. They do not belong in CONTEXT.md unless the project later
adopts them as business language.

The final seam placement is a candidate for a new ADR only after the
implementation shape is confirmed. An ADR is appropriate if the final
decision becomes a hard-to-reverse, surprising ownership rule with a real
trade-off. This spec does not create that ADR yet.

## Target module responsibilities

### CheckoutPaymentIntent

This remains the selection/presentation-facing module. Its target interface is
intentionally smaller than the current interface:

~~~php
interface CheckoutPaymentIntent extends CheckoutPaymentPreparation
{
    public function provider(): string;

    public function method(): string;

    public function postCreationBehavior(): CheckoutPostCreationBehavior;
}
~~~

During the migration, the existing paymentAmount, paymentCurrency,
orderCurrency, expiresAt, and assertReady methods remain as deprecated
compatibility methods until every caller has moved to the plan. They are
removed only in the cleanup slice after the new seam is proven.

The target interface therefore has:

- three selection/presentation facts required by current callers;
- two preparation operations supplied by CheckoutPaymentPreparation.

CheckoutPaymentIntent must not expose Binance or BSC persistence fields.

### CheckoutPaymentPreparation

This is the real seam for the five rail adapters:

~~~php
interface CheckoutPaymentPreparation
{
    public function preflight(
        CheckoutPaymentPreflightContext $context,
    ): CheckoutPaymentPreflight;

    public function prepare(
        CheckoutPaymentPreparationContext $context,
    ): CheckoutPaymentPlan;
}
~~~

The interface is deliberately two-phase:

- preflight is safe before database locks and may perform bounded remote
  readiness work when the rail requires it;
- prepare runs after the checkout lock order is established. It may perform
  database allocation and snapshot work, but it may not call RPC, provider
  HTTP, Telegram, or a queue.

For a rail that has no external preflight, preflight returns an immutable
no-op result. The no-op is an implementation detail; callers do not branch on
the rail.

### CheckoutPaymentPreflightContext

This context contains only data safe to use before checkout locks:

~~~php
final readonly class CheckoutPaymentPreflightContext
{
    public function __construct(
        public ?ManualPaymentSetting $currentSetting,
        public string $requestedProvider,
        public string $requestedMethod,
    ) {}
}
~~~

It must not contain a locked Cart, locked CartItem collection, or locked
Product collection. Product-specific readiness and authoritative checkout
validation remain in prepare.

CheckoutPaymentPreflight is an opaque immutable result passed back to the same
preparation adapter. The caller does not inspect rail-specific fields. The
result must carry enough identity/fingerprint data for prepare to reject a
stale preflight rather than silently using a report for different
configuration.

The BSC implementation stores the existing BscReadinessReport inside its
typed preflight result. Other rails return their own no-op or provider
readiness result.

### CheckoutPaymentPreparationContext

This context is created only after the existing checkout lock order is in
place:

~~~php
final readonly class CheckoutPaymentPreparationContext
{
    /**
     * @param Collection<int, CartItem> $items
     * @param Collection<int, Product> $products
     */
    public function __construct(
        public Cart $cart,
        public Collection $items,
        public Collection $products,
        public ?ManualPaymentSetting $lockedSetting,
        public CheckoutPaymentPreflight $preflight,
    ) {}
}
~~~

The context is read-only. The preparation implementation may perform
transaction-local database reads and writes required for allocation, but the
context itself does not provide a way to call external transports.

### CheckoutPaymentPlan

CheckoutPaymentPlan is an immutable value object. It carries the complete
snapshot required to create the Order's Payment without exposing rail-specific
knowledge to OrderCheckoutService.

The plan contains:

~~~text
Order snapshot
  - order currency
  - expiry

Payment snapshot
  - provider
  - method
  - payment currency
  - payable amount
  - expiry
  - typed rail snapshot
  - post-creation behavior
~~~

The common Payment fields are typed scalar/value-object properties. Rail
details are typed extensions, not a generic associative array.

The required typed rail snapshots are:

| Rail | Snapshot owned by the adapter |
| --- | --- |
| Manual QRIS | provider/method/currency/amount/expiry only |
| Pakasir | provider/method/flow/currency/amount/expiry |
| KlikQRIS | provider/method/currency/amount/expiry |
| Binance Pay ID | verification mode, auto-verification flag, receiver type/value, verification status |
| USDT BEP20/BSC | receiver ID/address, chain ID, token contract, decimals, base amount, payable amount, expected token units, verification status |

The plan must reject construction of an incomplete rail snapshot. A BSC plan
without a receiver snapshot or expected token units is invalid before the
Payment row is written.

### Plan persistence

The implementation may use a concrete internal CheckoutPaymentPlanWriter:

~~~php
final class CheckoutPaymentPlanWriter
{
    public function persist(
        Order $order,
        CheckoutPaymentPlan $plan,
    ): Payment;
}
~~~

This is an internal implementation module, not a new public adapter seam. It
writes common Payment fields and delegates typed rail extensions to the plan's
internal persistence implementation. OrderCheckoutService sees only the
writer's generic operation and never writes PaymentBscDetail or
Binance-specific columns directly.

The writer must not:

- call a provider;
- call BSC RPC;
- claim a Payment;
- call OrderLifecycle;
- reserve or sell stock;
- create a Telegram Outbound Effect;
- dispatch a queue job.

## Selection and adapter resolution

PaymentProviderResolver remains the owner of active provider selection,
Pakasir readiness fallback, provider version, and setting identity.

Add a concrete CheckoutPaymentPreparationResolver that resolves the
appropriate preparation adapter from the selected intent's provider and
method:

~~~php
final class CheckoutPaymentPreparationResolver
{
    public function for(
        CheckoutPaymentIntent $intent,
    ): CheckoutPaymentPreparation;
}
~~~

The resolver is the only place that maps the five rail identities to
preparation adapters. OrderCheckoutService does not contain that mapping. The
resolver must use the normalized provider/method pair, not translated labels
or customer-facing copy.

The current ProviderBoundCheckoutPaymentIntent remains a compatibility adapter
during the first migration. It must forward the new preparation operations
just as it currently forwards the legacy selection methods.

Removing ProviderBoundCheckoutPaymentIntent is not required for the first
behavior-preserving cutover. If the final selection object can carry provider
version and setting identity without a pass-through intent, its removal can be
proposed as a separate cleanup after this seam is stable.

## Target checkout flow

### Phase A — preflight

The outer checkout() method:

1. normalizes the selected intent;
2. resolves its preparation adapter;
3. runs preflight before entering the checkout transaction;
4. retains the immutable preflight result for the transaction attempt.

For BSC, this is the existing BscReadiness::check() work. No BSC RPC may run
after the transaction acquires checkout locks.

If preflight is not ready, checkout fails with the existing safe
InvalidCheckout behavior and creates no new Order, Payment, reservation,
credential allocation, or Telegram Outbound Effect.

### Phase B — existing transaction and lock order

OrderCheckoutService retains the current transaction and retry loop:

~~~text
checkout_key lookup/reuse
  → Cart lock
  → Checkout Maintenance guard
  → CartItem/Product locks
  → provider-setting lock
  → provider selection snapshot validation
  → CheckoutPaymentPreparation::prepare()
  → Order creation
  → generic Payment plan persistence
  → OrderItem snapshots
  → credential allocation
  → Inventory Ledger reservation
  → Telegram Outbound Effect materialization
  → Cart checked_out
~~~

The implementation must preserve the documented lock order:

~~~text
cart
→ checkout_maintenance_settings
→ cart items/products
→ ManualPaymentSetting
~~~

The preparation adapter receives the locked setting and the preflight result.
It performs rail-specific validation and transaction-local allocation.

### Phase C — shared checkout completion

After the plan is persisted:

- OrderCheckoutService creates OrderItems exactly as it does today;
- ProductCredentialAllocator remains the owner of credential allocation;
- InventoryLedger remains the owner of reservation movement;
- TelegramOutboundEffectService remains the owner of durable effect
  materialization;
- the Cart is marked checked_out only after all required work succeeds.

The plan module must not move any of those responsibilities behind the
payment seam.

## Rail-specific behavior

### Manual QRIS

- preflight is a no-op;
- prepare validates the existing Manual payment setting and creates the same
  provider/method/currency/amount/expiry snapshot;
- no provider request is made during checkout;
- Manual instructions continue through the existing Telegram Outbound Effect
  family.

### Pakasir legacy compatibility

- Pakasir is not a checkout preparation adapter and is absent from new
  checkout resolution;
- `PaymentProviderResolver` rejects a stale Pakasir QRIS setting with
  `InvalidCheckout` rather than selecting Manual;
- existing callback, status, reconciliation, cancellation, receipt, and
  queue handlers remain outside the new checkout seam until the retirement
  gate passes;
- native QRIS creation remains available only for an already-created
  historical Pakasir Payment.

### KlikQRIS

- preserve KlikQrisNativeQrisService as the only owner of native QRIS
  creation, recovery, persistence, payability, and safe outcomes;
- prepare creates the same initial Payment snapshot and expiry;
- no QR creation, recovery, provider HTTP, or fallback occurs inside prepare.

This preserves ADR-0006: the change does not introduce a provider-neutral
Native QRIS module or move Pakasir behind the KlikQRIS seam.

### Binance Pay ID

prepare owns:

- the selected verification mode;
- the base USDT amount;
- unique amount allocation when the mode requires it;
- auto-verification configuration;
- receiver type and receiver value snapshot;
- persisted verification status.

It does not own Binance history reads, transaction verification,
OrderLifecycle transitions, manual-review decisions, or Telegram
presentation. Those remain in the existing Binance Payment Verification and
channel adapters.

### USDT BEP20/BSC

preflight runs the existing BSC readiness check before locks.

prepare owns:

- locked readiness snapshot validation;
- BSC receiver selection;
- unique integer amount allocation;
- expected token-unit calculation;
- the complete PaymentBscDetail snapshot plan;
- BSC initial verification status.

prepare must not call BSC RPC. A stale readiness report, changed setting,
changed active receiver set, or invalid locked configuration fails closed
before Payment persistence.

BscPaymentVerification remains the owner of receipt/log validation,
confirmation/finality, late evidence, duplicate transaction handling, single
claim, and OrderLifecycle delegation.

## Provider setting and existing checkout reuse

The following behavior remains unchanged:

- provider switches affect only new checkouts;
- an existing Payment is never reinterpreted through the current provider;
- checkout_key reuse returns the existing Order;
- changing between Binance payment rails on an existing pending Cart remains
  rejected according to the existing assertCheckoutRailCanBeReused rule;
- a cancelled checkout may be retried according to current behavior;
- the selected provider setting version and setting ID remain snapshotted and
  validated.

If the active provider setting changes while the transaction is waiting, the
existing re-resolution behavior is preserved. The preparation plan is always
built from the final selected intent and locked setting used by that
transaction attempt.

## Error and rollback contract

| Situation | Result | Side effects |
| --- | --- | --- |
| Unsupported rail | InvalidCheckout | No new rows or effects |
| Missing/invalid payment setting | Existing safe checkout error | Transaction rolls back |
| BSC preflight not ready | Existing safe readiness error | No transaction-local side effects |
| Stale BSC setting/readiness snapshot | InvalidCheckout | Transaction rolls back |
| Missing USDT price | Existing InvalidCheckout | Transaction rolls back |
| Insufficient numeric or credential stock | Existing stock exception | Transaction rolls back |
| Unique amount exhausted | Existing allocation failure | Receiver/amount allocation rolls back |
| Duplicate checkout_key | Existing Order reused | No duplicate Payment/effect |
| Database unique conflict | Existing bounded transaction retry | No partial commit |
| Provider HTTP/RPC failure during preparation | Fail before external send | No Payment/effect commit |
| Provider HTTP after checkout | Existing post-commit lifecycle | Payment remains pending/recoverable |

The preparation adapter must not swallow an error to produce a partially
populated plan. A plan is either complete or absent.

## Dependency strategy

Use constructor injection for preparation adapters and the resolver. Keep
external transports behind the existing adapters:

- BscReadiness, BscPaymentReceiverPool, and BscUniquePaymentAmount move behind
  the BSC preparation adapter;
- BinancePayClient and BinanceUniquePaymentAmount move behind the Binance
  preparation adapter;
- existing Pakasir/KlikQRIS readiness dependencies remain with their current
  modules;
- OrderLifecycle, InventoryLedger, TelegramOutboundEffectService, and
  ProductCredentialAllocator remain outside the payment seam.

Laravel's current container supports constructor injection for interface
bindings and test replacement through the application test container. The
implementation should use those existing mechanisms rather than static global
factories.

## Proposed file changes

### New modules

- src/app/Services/Checkout/CheckoutPaymentPreparation.php
- src/app/Services/Checkout/CheckoutPaymentPreflight.php
- src/app/Services/Checkout/CheckoutPaymentPreflightContext.php
- src/app/Services/Checkout/CheckoutPaymentPreparationContext.php
- src/app/Services/Checkout/CheckoutPaymentPlan.php
- src/app/Services/Checkout/CheckoutPaymentPlanWriter.php
- src/app/Services/Checkout/CheckoutPaymentPreparationResolver.php
- typed plan/snapshot value objects under
  src/app/Services/Checkout/PaymentPlan/

The exact number of typed snapshot files may be reduced if the resulting
interface remains explicit and no raw field bag is introduced.

### Existing modules to change

- src/app/Services/Checkout/CheckoutPaymentIntent.php
- src/app/Services/Checkout/ProviderBoundCheckoutPaymentIntent.php
- src/app/Services/Checkout/PaymentProviderResolver.php only if selection
  wiring needs the new resolver
- src/app/Services/Checkout/ManualQrisCheckoutIntent.php
- src/app/Services/Checkout/KlikQrisCheckoutIntent.php
- src/app/Services/Checkout/BinancePayCheckoutIntent.php
- src/app/Services/Checkout/UsdtBep20CheckoutIntent.php
- src/app/Services/OrderCheckoutService.php
- src/app/Providers/AppServiceProvider.php

### Existing modules that must not gain rail-specific logic

- Storefront controllers and presenters;
- Telegram callbacks, routers, and presentation modules;
- OrderStateMachine;
- EloquentInventoryLedger;
- BscPaymentVerification;
- BinancePaymentVerification;
- KlikQrisNativeQrisService;
- Telegram Outbound Effect pipelines and ledger protocol.

## Migration sequence

### Slice 0 — characterization and seam tests

Add or strengthen tests that capture the current Payment row and side-effect
contract for:

- Manual QRIS;
- historical Pakasir payments are covered by the compatibility/lifecycle
  tests, not by a new checkout preparation adapter;
- KlikQRIS;
- Binance Pay ID amount-unique and Order ID modes;
- USDT BEP20/BSC.

Add a focused test proving that BSC preparation preflight performs all RPC
work before checkout locks.

No production behavior changes in this slice.

### Slice 1 — plan and preparation contracts

Add the contexts, opaque preflight result, typed plan, internal plan writer,
and preparation resolver. Bind the resolver in AppServiceProvider.

Add in-memory preparation adapters for tests. They must return plans without
touching the database or network.

### Slice 2 — BSC and Binance adapters

Move the newest, highest-friction rail logic first:

- BSC readiness report handoff;
- locked BSC snapshot validation;
- receiver selection;
- unique amount allocation;
- PaymentBscDetail plan;
- Binance mode, receiver, unique amount, and verification field plan.

Remove the corresponding BSC/Binance branches and dependencies from
OrderCheckoutService.

### Slice 3 — Manual and KlikQRIS adapters

Move the remaining new-checkout Payment amount/currency/expiry/readiness
assembly behind the same preparation seam. Preserve native QRIS creation
outside checkout; Pakasir remains a separate compatibility lane.

### Slice 4 — legacy interface cleanup

After all callers and tests use CheckoutPaymentPlan:

- remove the legacy Payment getter methods from CheckoutPaymentIntent;
- remove duplicated paymentAmount, expiresAt, and assertReady logic;
- remove method_exists from checkout assembly;
- remove unused imports and constructor dependencies;
- retain ProviderBoundCheckoutPaymentIntent only if provider-setting
  snapshot compatibility still requires it;
- otherwise remove it as a separate cleanup commit.

### Slice 5 — documentation and release evidence

Update docs/agents/codebase.md with the new ownership map and two-phase rule.
Update relevant runbook/checklist entries if file ownership or verification
commands change.

Create an ADR only if the final seam is accepted as a durable architectural
ownership decision. Do not add implementation details to CONTEXT.md.

## Test plan

### Unit tests

Add focused tests for:

- each preparation adapter's preflight result;
- each rail's valid CheckoutPaymentPlan;
- incomplete plan rejection;
- BSC stale preflight rejection;
- Binance mode and receiver snapshot;
- BSC expected token-unit calculation;
- no external transport from prepare();
- no raw field bag or secret leakage in plan representations.

Recommended location:

~~~text
src/tests/Unit/Services/Checkout/
~~~

### Feature tests

Preserve and update:

- tests/Feature/StorefrontShopCheckoutTest.php;
- tests/Feature/Telegram/CartCheckoutTest.php;
- tests/Feature/Telegram/TelegramCheckoutOutboundEffectTest.php;
- tests/Feature/Telegram/BscCheckoutRailTest.php;
- tests/Feature/BinancePaymentRailSelectionTest.php;
- tests/Feature/BscPaymentAllocationTest.php;
- tests/Feature/BscReadinessTest.php;
- tests/Feature/PaymentProviderSwitchTest.php.

Assertions must continue to cover:

- exact provider/method/currency/amount;
- Binance receiver and verification mode snapshots;
- BSC receiver and amount snapshots;
- one Order, one Payment, one reservation, and one checkout effect;
- no QR creation inside the checkout transaction;
- no effect creation when preparation fails;
- provider switch and existing-Payment interpretation.

### PostgreSQL concurrency tests

Preserve and update:

- tests/Feature/ProductionConcurrencyTest.php;
- tests/Feature/ProductStockIntakePostgresConcurrencyTest.php only if shared
  checkout setup changes;
- tests/Feature/BscPaymentAllocationTest.php where it exercises indexes and
  allocation rollback.

Required races:

- two BSC checkouts receive distinct unique amounts;
- two BSC checkouts receive valid receiver snapshots;
- allocation exhaustion rolls back Order, Payment, receiver assignment, and
  effects;
- provider switch racing checkout preserves one valid setting snapshot;
- duplicate checkout requests create one Order and one Payment;
- checkout racing stock mutation preserves Inventory Ledger invariants.

### Verification commands

At minimum:

~~~bash
cd src
composer test
composer test:concurrency-gate
~~~

Run browser/build checks only if implementation changes Storefront assets or
presentation. This architecture change should not need npm run build.

### Final verification record (#361)

| Gate | Result |
| --- | --- |
| Full SQLite suite (`cd src && composer test`) | PASS — 1,857 tests, 1,812 passed, 45 skipped, 13,441 assertions |
| PostgreSQL concurrency gate (default artifact path) | BLOCKED before PHPUnit — repository artifact directory is not writable |
| PostgreSQL concurrency gate (writable temporary artifact path) | BLOCKED before PHPUnit — PHP CLI is missing `pdo_pgsql` |
| Documentation link targets and whitespace | PASS — referenced spec/preparation files exist and `git diff --check` is clean |
| Changed-path/config review | PASS — only the codebase guide and accepted spec changed; no Railway file or command was introduced |

The PostgreSQL gate fails closed during preflight, so no PHPUnit concurrency
tests executed in this environment.

## Acceptance criteria

1. OrderCheckoutService has no BSC-specific class imports.
2. OrderCheckoutService has no Binance-specific amount/receiver field
   branches.
3. OrderCheckoutService contains no method_exists call for Payment assembly.
4. Rail-specific Payment attributes are produced by a
   CheckoutPaymentPreparation adapter.
5. BSC preflight performs RPC work before checkout locks.
6. BSC preparation performs no RPC and rejects a stale readiness snapshot.
7. The final Payment row and BSC detail snapshot are identical to the
   pre-refactor behavior for the same inputs.
8. Existing Web Storefront and Store Bot callers keep their public behavior.
9. checkout_key reuse remains idempotent.
10. Inventory reservation, credential allocation, Telegram Outbound Effect
    materialization, and OrderLifecycle behavior remain unchanged.
11. No provider-neutral Native QRIS module or automatic fallback is added.
12. SQLite tests pass.
13. PostgreSQL concurrency tests pass.
14. The repository remains free of Railway commands/configuration.
15. The final diff identifies one preparation adapter per rail and one small
    plan seam; no generic field bag or family-specific logic is added to the
    checkout core.

## Rollout and recovery

This is an application-only refactor with no expected schema change.

Rollout order:

1. merge characterization/seam tests;
2. merge plan contracts and adapters behind the existing checkout path;
3. deploy after the normal CI and release gates are green;
4. verify one checkout per rail in the non-production validation lane;
5. remove legacy methods only after the new path has passed focused and
   PostgreSQL suites;
6. restart queue workers only when the release contains queue code changes;
7. verify existing payment lifecycle and Telegram effect recovery commands.

If the new preparation path fails, revert the application release through the
repository's normal GitHub Actions/VPS deployment process. Do not change
historical Payment rows or introduce a database rollback for an
application-only refactor.

## Alternatives considered

### One generic prepare method on the current intent

Rejected as the final shape because the current intent interface already
contains eight methods and would become a large rail-specific surface. It is
acceptable as a temporary migration bridge, not as the target interface.

### A raw paymentAttributes array

Rejected because it turns rail-specific invariants into undocumented keys,
allows incomplete BSC snapshots, and makes the interface shallow. Typed plans
keep the implementation behind the seam and make missing data constructively
invalid.

### Moving all Payment creation into OrderCheckoutService helpers

Rejected because it only moves complexity inside the same module. The
deletion test says the rail-specific knowledge would still return to the
checkout core.

### A provider-neutral Native QRIS module

Rejected by ADR-0006. KlikQRIS remains its own module and Pakasir remains a
legacy path.

## References

- [CONTEXT.md](../../../CONTEXT.md)
- [docs/agents/codebase.md](../../agents/codebase.md)
- [ADR-0002 — Telegram direct checkout idempotency](../../adr/0002-telegram-direct-checkout-idempotency.md)
- [ADR-0003 — Binance Payment Verification](../../adr/0003-binance-payment-verification.md)
- [ADR-0006 — KlikQRIS Native QRIS and Pakasir retirement](../../adr/0006-klikqris-native-qris-and-pakasir-retirement.md)
- [ADR-0007 — Telegram Outbound Effects](../../adr/0007-telegram-outbound-effects.md)
- [Laravel 13 container documentation](https://github.com/laravel/docs/blob/13.x/container.md)
- [Laravel 13 mocking documentation](https://github.com/laravel/docs/blob/13.x/mocking.md)
