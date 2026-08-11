# Checkout Maintenance Implementation Plan

> Implement blockers-first. Each ticket uses red-green-refactor and ends with its focused verification. Run project-wide formatting, builds, concurrency gate, browser smoke, and two-axis code review once in CM-5, not between parallel tickets.

**Goal:** Add a global Owner/Admin-controlled Checkout Maintenance state that blocks only new customer Orders across Web and Telegram while preserving catalog/cart access and every existing Order lifecycle.

**Architecture:** A dedicated singleton setting and deep `CheckoutMaintenance` module own status, locking, mutation, optimistic versioning, and audit. `OrderCheckoutService` is the authoritative guard after existing-Order idempotency checks and before any new checkout side effect. Web and Telegram are presentation adapters that preflight/replace CTA state but never own the business rule.

**Stack:** PHP 8.3, Laravel 13, Eloquent, Blade, Alpine, PostgreSQL concurrency gate, SQLite feature tests, PHPUnit 12.

**Design source:** [`../specs/2026-08-11-checkout-maintenance-design.md`](../specs/2026-08-11-checkout-maintenance-design.md)

## Global Constraints

- Default and migration state is inactive.
- Existing Order reuse is checked before the maintenance guard.
- The authoritative guard and admin mutation lock the same singleton row.
- No new Order, Payment, reservation, credential allocation, or notification may escape an active guard.
- Web maintenance rejection must happen before guest Customer/cart creation when the preflight sees active state; the transactional guard still handles races.
- Existing payment/provider/lifecycle/jobs never read maintenance state.
- Cart management and its 24-hour TTL remain unchanged.
- Public notes are optional, plain text, trimmed, at most 500 characters, and cleared on deactivation.
- Identical submissions are no-ops. Note changes while active are real audited changes. Stale versions fail closed.
- Finance/Support can read status but cannot mutate it.
- No scheduler, broadcast, per-channel switch, or automatic expiry.

## Ticket Graph

```text
CM-1 Core state and checkout cutoff
 ├── CM-2 Admin control and audit UI
 ├── CM-3 Web Storefront presentation
 └── CM-4 Store Bot presentation
      \        |        /
       CM-5 Cross-surface hardening and rollout proof
```

| Ticket | Blocked by | Parallel after blocker |
| --- | --- | --- |
| CM-1 | none | no |
| CM-2 | CM-1 | CM-3, CM-4 |
| CM-3 | CM-1 | CM-2, CM-4 |
| CM-4 | CM-1 | CM-2, CM-3 |
| CM-5 | CM-2, CM-3, CM-4 | no |

---

## CM-1 — Core State and Atomic Checkout Cutoff

**Outcome:** A persisted inactive-by-default state can be changed through the module interface; active state atomically blocks new Orders while returning existing idempotent Orders.

**Files:**

- Create: `src/database/migrations/2026_08_11_000006_create_checkout_maintenance_settings.php`
- Create: `src/app/Models/CheckoutMaintenanceSetting.php`
- Create: `src/app/Services/CheckoutMaintenance/CheckoutMaintenance.php`
- Create: `src/app/Services/CheckoutMaintenance/CheckoutMaintenanceState.php`
- Create: `src/app/Services/CheckoutMaintenance/CheckoutMaintenanceChange.php`
- Create: `src/app/Exceptions/CheckoutMaintenanceActive.php`
- Create: `src/app/Exceptions/CheckoutMaintenanceConflict.php`
- Modify: `src/app/Services/OrderCheckoutService.php`
- Create: `src/tests/Feature/CheckoutMaintenanceTest.php`

**Interface contract:**

```php
current(): CheckoutMaintenanceState
guardNewOrder(): void
change(bool $active, ?string $publicNote, int $expectedVersion, User $actor, ?string $ip): CheckoutMaintenanceChange
```

`guardNewOrder()` is called inside the existing `OrderCheckoutService` transaction. The module obtains `lockForUpdate()` on the singleton and throws `CheckoutMaintenanceActive` carrying the locked state.

### Red

- [ ] Add migration/model tests proving the singleton exists, defaults inactive, casts correctly, and has a unique canonical key.
- [ ] Add module behavior tests for activation without a note, activation with normalized note, active note update, deactivation clearing note, identical no-op, version conflict, and audit rollback atomicity.
- [ ] Add checkout tests proving:
  - active state creates no Order, Payment, InventoryMovement, credential allocation, or cart status mutation;
  - an existing `checkout_key` Order is returned while active;
  - a checked-out cart with an existing non-cancelled Order is returned while active;
  - a cancelled prior Order reactivates its cart but cannot create a replacement while active;
  - inactive checkout behavior remains unchanged.
- [ ] Run:

```bash
cd src && php artisan test tests/Feature/CheckoutMaintenanceTest.php tests/Feature/OrderCartDomainTest.php
```

Expected: FAIL because the state/module and central guard do not exist.

### Green

- [ ] Create the singleton schema with `singleton_key`, `is_active`, nullable `public_note`, `version`, nullable `activated_at`, and timestamps; insert `checkout_maintenance` inactive in the migration.
- [ ] Implement immutable state/change DTOs. Normalize notes once in the module; disabled state always projects a null note.
- [ ] Implement `change()` as one retried database transaction: lock row, compare expected version, detect no-op, persist mutation/version/activation time, and write the corresponding audit entry before commit.
- [ ] Implement `guardNewOrder()` using the same singleton row lock.
- [ ] In `OrderCheckoutService::checkoutOnce()`, preserve this order:
  1. checkout-key reuse;
  2. cart lock;
  3. checked-out-cart Order reuse;
  4. cart validity;
  5. maintenance guard;
  6. cart item/product/provider locks and creation effects.
- [ ] Keep `CheckoutMaintenanceActive` distinct from generic `InvalidCheckout`; do not put channel copy in the domain module.
- [ ] Run the focused tests until green.

### Refactor and check

- [ ] Ensure no controller, view, callback, job, or provider duplicates the active-state business rule.
- [ ] Ensure audit and setting mutation cannot commit separately.
- [ ] Ensure model reads do not silently create singleton rows outside migration/module mutation.

**Suggested commit:** `feat: guard new orders during checkout maintenance`

---

## CM-2 — Admin Control, Authorization, and Audit Presentation

**Blocked by:** CM-1

**Outcome:** Owner/Admin can safely toggle and edit Checkout Maintenance from the dashboard; Finance/Support are read-only; status is prominent and every real change is auditable.

**Files:**

- Create: `src/app/Http/Requests/Admin/UpdateCheckoutMaintenanceRequest.php`
- Create: `src/app/Http/Controllers/Admin/AdminCheckoutMaintenanceController.php`
- Modify: `src/app/Enums/UserRole.php`
- Modify: `src/routes/web.php`
- Modify: `src/app/Services/Admin/AdminDashboardStatsData.php`
- Modify: `src/app/Services/Admin/AdminDashboardData.php` if shared header payload needs explicit state
- Modify: `src/resources/views/admin/dashboard/stats-tab.blade.php`
- Modify: `src/resources/views/admin/dashboard/compact-header.blade.php`
- Modify: `src/resources/views/admin/dashboard/header.blade.php` or the existing shared status partial selected by the current layout
- Modify: `src/tests/Feature/CheckoutMaintenanceTest.php`
- Modify: `src/tests/Feature/AdminDashboardOverviewTest.php`
- Modify: `src/tests/Feature/AdminDashboardAccessTest.php`

### Red

- [ ] Add authorization tests: Owner/Admin POST succeeds; Finance/Support POST returns 403 and leaves version/state/audit unchanged.
- [ ] Add validation tests: `active` required boolean, `version` required integer, note nullable string max 500; HTML is stored/rendered as plain escaped text rather than executable markup.
- [ ] Add mutation tests for activate, active-note update, deactivate/clear, identical no-op, double submit, and stale-version conflict.
- [ ] Assert audit action, actor, IP, old/new values, and exactly-once count.
- [ ] Add dashboard rendering tests for active/inactive badges, active timestamp, note, read-only roles, hidden version, and both confirmation targets.
- [ ] Run:

```bash
cd src && php artisan test tests/Feature/CheckoutMaintenanceTest.php tests/Feature/AdminDashboardOverviewTest.php tests/Feature/AdminDashboardAccessTest.php
```

Expected: FAIL because the route/controller/dashboard control do not exist.

### Green

- [ ] Add `UserRole::canManageStoreOperations()` returning true only for Owner/Admin. Do not reuse payment-specific naming in the new feature.
- [ ] Add `POST /admin/checkout-maintenance` under existing `admin.access` middleware and authorize again in the Form Request.
- [ ] Controller passes validated target, normalized note, expected version, actor, and IP to `CheckoutMaintenance::change()`; it maps validation/conflict/no-op/change outcomes to existing dashboard return/flash patterns.
- [ ] Extend dashboard data with a stable presentation array: state badge/tone, note, activation timestamp, version, mutation URL, and `can_manage`.
- [ ] Add a prominent status badge to shared admin chrome and the full form/card to Stats.
- [ ] Reuse the dashboard's existing Alpine confirmation-modal pattern if practical. Both activation and deactivation must explicitly name the target state and impact before native form submission.
- [ ] Keep the note editable while active. Disable/omit mutation controls for read-only roles without hiding current state.
- [ ] Run focused tests until green.

### Refactor and check

- [ ] Confirm identical requests do not increment version, add audit rows, or churn storefront cache identity.
- [ ] Confirm note content is escaped in Blade and is not written to logs outside the sanitized AuditLog payload.

**Suggested commit:** `feat: control checkout maintenance from admin`

---

## CM-3 — Web Storefront Notice, CTA State, and Direct-Request Safety

**Blocked by:** CM-1

**Outcome:** Every Web shopping surface immediately reflects maintenance; stale/direct checkout is rejected with specific copy and no orphan guest checkout rows.

**Files:**

- Modify: `src/app/Storefront/StorefrontResponseCache.php`
- Modify: `src/app/Storefront/StorefrontCatalogPageBuilder.php`
- Modify: `src/app/Storefront/StorefrontPresenter.php`
- Modify: `src/app/Storefront/StorefrontShopCheckout.php`
- Modify: `src/app/Http/Controllers/StorefrontProductController.php` only if presenter data cannot supply state cleanly
- Modify: `src/app/Http/Controllers/StorefrontShopCheckoutController.php`
- Create: `src/resources/views/storefront/partials/checkout-maintenance.blade.php`
- Modify: `src/resources/views/storefront.blade.php`
- Modify: `src/resources/views/storefront/product.blade.php`
- Modify: `src/resources/views/storefront/partials/featured-offer.blade.php`
- Modify: `src/resources/views/storefront/partials/product-modal.blade.php`
- Modify: `src/resources/views/storefront/partials/shop/catalog.blade.php` and any actually routed catalog partial sharing the CTA
- Modify: `src/tests/Feature/StorefrontLandingPageTest.php`
- Modify: `src/tests/Feature/StorefrontCatalogPageBuilderTest.php`
- Modify: `src/tests/Feature/StorefrontProductCheckoutTest.php`
- Modify: `src/tests/Feature/StorefrontShopCheckoutTest.php`

### Red

- [ ] Add rendering tests for home/search, featured product, product detail, and modal/no-JavaScript fallback: active notice visible; exact default copy and optional escaped note visible; checkout form/usable CTA absent or disabled; stock/catalog links remain.
- [ ] Add cache test: inactive HTML is cached, state changes version, the first next request renders active HTML; deactivation similarly returns normal HTML without waiting 30 minutes.
- [ ] Add direct POST tests proving active state returns the maintenance-specific message and leaves counts unchanged for Customer, Cart, Order, Payment, InventoryMovement, stock item allocation, and queued notification effects.
- [ ] Add stale-preflight race coverage at module/service level: presentation may say inactive, but activation before transaction still causes central rejection.
- [ ] Add existing checkout-key reuse coverage through `StorefrontShopCheckout`: an existing pending Order remains reachable while active and native QR recovery/instruction behavior remains allowed.
- [ ] Add deactivation test: note disappears and a fresh checkout succeeds with current stock validation.
- [ ] Run:

```bash
cd src && php artisan test tests/Feature/StorefrontLandingPageTest.php tests/Feature/StorefrontCatalogPageBuilderTest.php tests/Feature/StorefrontProductCheckoutTest.php tests/Feature/StorefrontShopCheckoutTest.php
```

Expected: FAIL because views, cache identity, and preflight do not consume maintenance state.

### Green

- [ ] Include maintenance version in the cached storefront response identity; do not rely on TTL for status propagation. Preserve existing static-homepage invalidation/fallback behavior.
- [ ] Add one shared escaped notice partial and structured state to catalog/product view data.
- [ ] Replace active checkout CTAs with non-action status UI. Ensure Alpine modal payload and no-JavaScript forms cannot submit a newly rendered checkout while active.
- [ ] In `StorefrontShopCheckout::startOnce()`, preserve existing checkout-key reuse first, then perform non-authoritative active preflight before Product-dependent guest Customer/cart creation.
- [ ] Keep the central `OrderCheckoutService` guard; never trust the preflight or cached HTML.
- [ ] Map `CheckoutMaintenanceActive` to the exact maintenance notice rather than the generic stock/cart error while retaining the established recovery redirect and submitted-event rejection semantics.
- [ ] Run focused tests until green.

### UI proof for this ticket

Do not run a separate project-wide suite. Launch the app and inspect at desktop and narrow mobile widths:

- active banner hierarchy and escaped optional note;
- stock/product browsing still usable;
- CTA replacement and keyboard/focus semantics;
- direct POST rejection feedback;
- inactive reload restoring checkout.

Record screenshots/observations for CM-5.

**Suggested commit:** `feat: present checkout maintenance on storefront`

---

## CM-4 — Store Bot Notice, CTA Replacement, and Cek Status

**Blocked by:** CM-1

**Outcome:** Telegram browsing/cart remain usable, newly rendered purchase actions become maintenance status actions, stale callbacks fail specifically, and Cek Status restores shopping after reopening.

**Files:**

- Create: `src/app/Services/Telegram/Callbacks/TelegramCheckoutMaintenanceCallbackHandler.php`
- Modify: `src/app/Providers/AppServiceProvider.php`
- Modify: `src/app/Services/Telegram/TelegramMenuIntents.php`
- Modify: `src/app/Services/Telegram/TelegramCatalogIntents.php`
- Modify: `src/app/Services/Telegram/TelegramCartIntents.php`
- Modify: `src/app/Services/Telegram/Callbacks/TelegramCheckoutCallbackHandler.php`
- Modify: `src/tests/Feature/Telegram/CartCheckoutTest.php`
- Modify: `src/tests/Feature/Telegram/TelegramCustomerLocaleTest.php`
- Modify: `src/tests/Unit/Services/Telegram/TelegramCallbackHandlerPipelineTest.php`
- Modify: `src/tests/Support/TelegramBotTestSupport.php` only if reusable callback assertions need extension

### Red

- [ ] Add active-state tests for main shopping menu, categories/product list, product stock choices, quantity choices, and cart confirmation. Assert stock/catalog/cart controls remain and direct purchase/checkout controls are replaced by `checkout-maintenance:status`.
- [ ] Add stale callback tests for `checkout`, `checkout:current`, `checkout:binance`, and direct `qty:<rail>:<product>:<quantity>`. Assert no Order/Payment/reservation/notification and a maintenance-specific response, not the generic stock/cart failure.
- [ ] Prove cart add/update remains allowed and TTL is not changed.
- [ ] Add Cek Status tests:
  - active returns localized default plus optional plain note and another Cek Status action;
  - inactive acknowledges reopening and returns existing product/cart navigation actions;
  - no broadcast or historical-message edit job is dispatched.
- [ ] Add Indonesian and English default-copy assertions while leaving the admin-authored public note unchanged.
- [ ] Assert the new callback handler is registered once at the correct post-privacy/post-community-gate stage.
- [ ] Run:

```bash
cd src && php artisan test tests/Feature/Telegram/CartCheckoutTest.php tests/Feature/Telegram/TelegramCustomerLocaleTest.php tests/Unit/Services/Telegram/TelegramCallbackHandlerPipelineTest.php
```

Expected: FAIL because Telegram adapters do not consume maintenance state or support Cek Status.

### Green

- [ ] Add a dedicated status callback handler instead of expanding checkout creation logic with unrelated navigation behavior. Register it in the existing callback pipeline; do not create another router.
- [ ] Add compact localized maintenance notice helpers at the Telegram presentation seam. Do not move Telegram copy into the domain module.
- [ ] In menu/catalog/cart renderers, read current state and replace only purchase/checkout CTA rows; preserve category, product detail, stock, cart mutation, and back-navigation rows.
- [ ] In `TelegramCheckoutCallbackHandler`, catch `CheckoutMaintenanceActive` before the broad checkout exceptions and render its locked state with Cek Status.
- [ ] Do not short-circuit cart additions: direct-quantity flows may add to cart, but central checkout creation must still fail while active.
- [ ] When Cek Status observes inactive state, return clear reopened copy and existing callbacks for viewing products/cart; do not replay the rejected checkout automatically.
- [ ] Run focused tests until green.

### Adapter smoke for this ticket

Exercise representative Telegram updates through the existing test/dogfood adapter:

- browse product/stock while active;
- manage cart while active;
- press a stale checkout callback and observe specific notice;
- press Cek Status active, deactivate as admin, press it again, then create one fresh Order.

Record observed Bot API payloads for CM-5; do not contact production customers.

**Suggested commit:** `feat: present checkout maintenance in store bot`

---

## CM-5 — PostgreSQL Race Proof, Regression Closure, Docs, and Rollout

**Blocked by:** CM-2, CM-3, CM-4

**Outcome:** Cross-surface contracts are proven under real locking, existing Orders continue through lifecycle, UI is smoke-tested, implementation maps are current, and the diff passes Standards + Spec review.

**Files:**

- Create or modify: `src/tests/Feature/CheckoutMaintenancePostgresConcurrencyTest.php`
- Modify: `scripts/concurrency-deploy-gate.sh` if the new test is not discovered by the current gate
- Modify: existing provider/lifecycle test files only where one focused existing-Order-under-maintenance regression belongs naturally
- Modify: `docs/agents/codebase.md`
- Modify: design/plan only if implementation discoveries require an explicit spec correction

### Red/Green concurrency proof

- [ ] Add a PostgreSQL race with two independent connections/processes sharing the singleton:
  - checkout holds the maintenance lock path first, creates/commits Order, then activation completes; result is one valid existing Order;
  - activation commits first, checkout then observes active state; result is zero new Orders/Payments/reservations;
  - repeated concurrent activation with the same expected version yields one change/audit and one conflict or deterministic no-op, never two version increments.
- [ ] Assert no deadlock from lock ordering: cart → maintenance setting → cart items/products → payment setting.
- [ ] Run the focused PostgreSQL test through the repository's disposable database harness, then include it in `composer test:concurrency-gate`.

### Existing Order lifecycle regression

- [ ] While maintenance is active, prove at least one pending automatic-provider Order can receive/verify payment and transition through the existing `OrderLifecycle` path.
- [ ] Prove pending Order status refresh/cancel/expiry remains available.
- [ ] Assert no payment callback, reconciliation command, instruction job, or lifecycle code gained a maintenance dependency.

### Final verification

- [ ] Run focused feature set:

```bash
cd src && php artisan test \
  tests/Feature/CheckoutMaintenanceTest.php \
  tests/Feature/StorefrontLandingPageTest.php \
  tests/Feature/StorefrontCatalogPageBuilderTest.php \
  tests/Feature/StorefrontProductCheckoutTest.php \
  tests/Feature/StorefrontShopCheckoutTest.php \
  tests/Feature/Telegram/CartCheckoutTest.php \
  tests/Feature/Telegram/TelegramCustomerLocaleTest.php \
  tests/Feature/OrderCartDomainTest.php
```

- [ ] Run formatting and static/build checks once:

```bash
cd src && vendor/bin/pint --test && npm run build
```

- [ ] Run checkout/stock locking proof:

```bash
cd src && composer test:concurrency-gate
```

- [ ] Run the project's full relevant suite (`composer test`) after focused tests are green.
- [ ] Launch the application and perform the end-to-end browser smoke at desktop and mobile widths:
  1. inactive catalog and checkout parity;
  2. Owner activates with an escaped test note after confirmation;
  3. active status appears on admin and the first next storefront request;
  4. catalog/stock remain usable and direct checkout creates no side effects;
  5. existing Order status/payment flow still works;
  6. Owner deactivates after confirmation;
  7. note is cleared, storefront reload restores CTA, and a fresh checkout succeeds.
- [ ] Exercise the equivalent Telegram smoke from CM-4 against a non-production chat/test adapter.

### Documentation and review

- [ ] Update `docs/agents/codebase.md` with the canonical owner module, setting row lock order, Web/Telegram presentation entry points, cache version behavior, and focused test entry points.
- [ ] Keep `CONTEXT.md` limited to the glossary definition already added; do not copy implementation details into it.
- [ ] Do not add an ADR unless implementation discovers a new hard-to-reverse, surprising tradeoff not already resolved by the design.
- [ ] Run two-axis review against the pre-feature fixed point:
  - **Standards:** repo conventions, module depth/locality, security, locking, error copy, test quality, and no stale paths;
  - **Spec:** every acceptance criterion in the design, especially existing-Order allowance and zero side effects on rejected new checkout.
- [ ] Fix every blocking review finding and rerun only the affected focused checks, then the final gate if locking/build files changed.

**Suggested commit:** `test: prove checkout maintenance rollout safety`

## Rollout Checklist

- [ ] Migration is applied and singleton reads inactive before activation.
- [ ] No environment variable or scheduler configuration is required.
- [ ] Owner/Admin know the dashboard location and that deactivation is the operational rollback.
- [ ] Controlled activation confirms Web/Telegram notices and central rejection.
- [ ] One existing pending Order is observed progressing during active state.
- [ ] Controlled deactivation confirms note clearing, cache identity change, and fresh checkout recovery.

## Plan Self-Review

- **Spec coverage:** CM-1 owns the invariant and cutoff; CM-2 owns safe operation; CM-3 and CM-4 provide channel-specific presentation without duplicating policy; CM-5 proves races, lifecycle non-interference, UI, docs, and rollout.
- **Blocking edges:** Only CM-1 is foundational. Admin, Web, and Telegram work can proceed concurrently after its interface lands. CM-5 waits for all three surfaces.
- **Idempotency:** Existing Order reuse precedes the guard; stale/direct new attempts are rejected; same admin mutation is a no-op.
- **Side effects:** The plan explicitly covers Web guest rows, Order/Payment, inventory, credentials, notifications, cache, audit, and provider lifecycle.
- **No deferred scope:** Scheduling, broadcasts, per-channel modes, cart reservation, and lifecycle pausing are explicitly excluded rather than postponed.
