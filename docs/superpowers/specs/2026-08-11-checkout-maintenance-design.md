# Checkout Maintenance Design

## Goal

Add one global **Checkout Maintenance** state that temporarily prevents new customer Orders in both the Store Bot and Web Storefront while preserving catalog access, stock visibility, cart management, and every lifecycle operation for an Order that already exists.

The state is manually controlled by Owner and Admin roles. It is not a store shutdown, payment-provider switch, broadcast mechanism, or scheduler.

## Domain Contract

When Checkout Maintenance is inactive, customer checkout behaves exactly as it does today.

When Checkout Maintenance is active:

- customers may browse/search the catalog, open product details, inspect current stock, and manage carts;
- no new Order, Payment, inventory reservation, credential allocation, or pending-order notification may be created;
- an Order and its Payment that already exist continue through payment instruction creation/recovery, provider callbacks, reconciliation, customer status refresh, cancellation, expiry, fulfillment, receipts, Sales Proof Post handling, and customer order access;
- a cart or Telegram prompt is not an Order and receives no exemption;
- cart TTL remains 24 hours and cart contents do not reserve stock;
- availability is validated again when checkout later reopens.

The cutoff is commit-order based. Checkout and maintenance mutation lock the same singleton row. If checkout commits first, the Order exists before activation and remains valid. If activation commits first, checkout is rejected. There is no timestamp-only check and no partially created Order.

Idempotent reuse remains allowed while maintenance is active. A repeated checkout key or already checked-out cart that resolves to an existing Order returns that Order instead of being rejected as a new checkout.

## Customer Experience

### Default notice

Indonesian default:

> Checkout sedang dalam maintenance. Kamu tetap bisa melihat produk, stok, dan mengelola keranjang, tetapi belum dapat membuat pesanan baru. Pesanan yang sudah dibuat tetap diproses. Silakan cek status kembali beberapa saat lagi.

The Store Bot retains its existing locale behavior by providing an equivalent English default. An optional admin-authored public note is appended after the default. The note is plain text, trimmed, limited to 500 characters, escaped by the Web Storefront, and sent without enabling user-controlled Telegram formatting.

### Web Storefront

- Show an active-status banner on the home catalog, catalog/search results, product details, and checkout modal/cart surface.
- Keep catalog links, product details, stock information, support links, account access, and existing Order pages usable.
- Replace/disable checkout CTAs while active; do not open a usable checkout form.
- A stale tab, no-JavaScript form, or direct `POST /shop/checkout` receives the maintenance notice and creates no customer, cart, Order, Payment, reservation, credential allocation, or notification.
- After deactivation, the next request/reload renders normal checkout actions. No realtime push is required.

### Store Bot

- Include a compact active-status notice in the main shopping menu, category/product catalog, product details/stock choices, cart confirmation, and checkout response.
- Replace newly rendered checkout/direct-purchase actions with a **Cek Status** callback while keeping browsing and cart-management actions available.
- Old `checkout:*` and direct `qty:<rail>:...` callbacks remain safe: the central guard rejects them with the maintenance notice.
- **Cek Status** returns the current notice while active. Once inactive, it confirms checkout is available and returns the appropriate shopping/cart action instead of editing every historical message.
- No direct-message broadcast and no Store Channel announcement is sent on activation or deactivation.

## Persistence

Create a dedicated singleton `checkout_maintenance_settings` table rather than adding store-operational state to `ManualPaymentSetting`.

Fields:

- `id`;
- unique `singleton_key` with canonical value `checkout_maintenance`;
- `is_active` boolean, default `false`;
- nullable `public_note` string/text constrained by request/domain validation to 500 characters;
- unsigned `version`, default `0`, for optimistic admin mutation;
- nullable `activated_at`;
- timestamps.

The migration inserts the inactive singleton row. Missing state is defensively interpreted as inactive for read-only presentation, but the transactional checkout guard must lock the persisted singleton row. Deployment therefore runs the migration before serving the new application version.

The public note is forced to `null` on deactivation. Historical note values remain available only in audit records.

## Module and Interface

Add a deep `CheckoutMaintenance` module at the checkout seam. Its small interface owns persistence normalization, status projection, locking, optimistic conflict detection, no-op detection, and audit coupling:

- `current(): CheckoutMaintenanceState` — read-only projection for adapters;
- `guardNewOrder(): void` — lock the singleton row inside the caller's checkout transaction and throw `CheckoutMaintenanceActive` with the locked state when active;
- `change(bool $active, ?string $publicNote, int $expectedVersion, User $actor, ?string $ip): CheckoutMaintenanceChange` — transactional Owner/Admin mutation.

`CheckoutMaintenanceState` exposes only active state, normalized public note, version, and activation time. `CheckoutMaintenanceChange` reports old/new state and whether anything changed. `CheckoutMaintenanceActive` is distinct from stock/cart failures so each channel can present the correct outcome.

Same-state rules:

- same active state plus the same normalized note is a no-op: no version increment and no audit entry;
- active plus a changed note updates the note, increments the version, and creates an audit entry;
- inactive always stores a null note;
- a stale expected version fails with a conflict message requiring reload and reconfirmation.

Audit and setting mutation commit in the same transaction. Actions are `checkout_maintenance.activated`, `checkout_maintenance.updated`, and `checkout_maintenance.deactivated`, with old/new active state and public note, actor, IP, and timestamp.

## Checkout Ordering and Side Effects

`OrderCheckoutService` remains the canonical business guard. Within its existing transaction, ordering is:

1. reuse an existing `checkout_key` Order when present;
2. lock the cart;
3. reuse a valid existing Order for an already checked-out cart;
4. reject invalid/expired cart state;
5. call `CheckoutMaintenance::guardNewOrder()`;
6. only then lock cart items/products/provider settings and create Order, Payment, inventory reservation, credentials, and notifications.

The Storefront performs a non-authoritative preflight after existing-order reuse but before creating its guest Customer/cart. This avoids orphan guest rows on a normal maintenance rejection. The transactional guard remains mandatory because state can change after preflight.

Telegram may continue adding/updating cart items before the guard because cart management is explicitly allowed.

No callback processor, reconciliation command, Order lifecycle module, payment instruction job, expiry command, or fulfillment path reads Checkout Maintenance.

## Admin Experience and Authorization

Add a prominent Checkout Maintenance status card to the admin Stats dashboard and shared compact header status:

- active/inactive badge;
- activation timestamp when active;
- current public note;
- textarea for optional note;
- hidden optimistic `version`;
- activate/deactivate action;
- explicit confirmation for both directions describing the target state and impact.

Only `owner` and `admin` may submit changes. `finance` and `support` may see the status but receive no mutation control and get HTTP 403 on direct submission. Add a semantically named role predicate for store-operation settings rather than coupling this feature to payment-provider terminology.

## Storefront Cache Consistency

The cached homepage currently has a 30-minute TTL. Checkout Maintenance state/version must participate in the storefront response cache key or trigger an equivalent atomic version invalidation after a committed change. The preferred design includes the persisted maintenance version in the cache identity so the first request after a real change cannot reuse HTML from the previous state. A no-op keeps the same version and cache identity.

The authoritative checkout guard never depends on rendered or cached UI state.

## Failure Behavior

- A stale admin version changes nothing and shows a reload/reconfirm conflict.
- Invalid note input changes nothing and returns field-scoped validation.
- Audit failure rolls back the state mutation.
- Storefront cache generation failure uses the existing response-cache fallback behavior; direct checkout remains guarded.
- A stale Web/Telegram CTA is rejected with the specific maintenance notice, not a misleading stock/cart error.
- Deactivation does not retry, recreate, or alter any earlier rejected attempt; the customer initiates checkout again.

## Acceptance Criteria

1. Owner/Admin can activate with no note or with a valid plain-text note and can deactivate after two-way confirmation.
2. Finance/Support cannot mutate the state.
3. Every committed change is audited exactly once; identical submissions are no-ops; stale versions are rejected.
4. Both Web and Telegram show the active state on every agreed shopping surface and keep catalog/stock/cart behavior available.
5. Newly rendered purchase CTAs are unavailable while active; stale/direct requests are still rejected centrally.
6. A rejected attempt creates none of the new-checkout side effects: Customer/cart on Web preflight, Order, Payment, inventory movement/reservation, credential allocation, or notification dispatch.
7. Existing pending and paid Orders continue all current payment and lifecycle behavior while active.
8. Existing checkout-key/cart idempotent retries return the existing Order while active.
9. Toggle/checkout races obey the shared-row lock cutoff under PostgreSQL concurrency tests.
10. Deactivation clears the public note, immediately changes cached storefront presentation on the next request, and lets a fresh checkout succeed.
11. Telegram **Cek Status** shows the notice while active and restores the relevant shopping action after deactivation.
12. Cart TTL remains 24 hours and stock is revalidated after reopening.

## Non-Goals

- Scheduled start/end times or automatic deactivation.
- Per-channel, per-product, per-category, or per-payment-provider maintenance.
- Store Channel announcements, customer broadcasts, or editing every historical Telegram message.
- Pausing payment callbacks, reconciliation, fulfillment, expiry, cancellation, receipts, or Sales Proof Posts.
- Reserving stock for carts or extending cart TTL.
- A full site outage/HTTP maintenance page.
- Changing payment provider selection, payment snapshots, inventory lifecycle, or Order state machines.

## Rollout and Rollback

1. Deploy the inactive singleton migration and code with the default state off.
2. Verify inactive-state parity on Web and Telegram.
3. Activate briefly in a controlled environment, verify banners/CTA replacement/direct-request rejection, and confirm an existing pending Order still progresses.
4. Deactivate, verify note clearing/cache version change, and complete one fresh checkout.
5. Rollback is operationally the deactivation action. Code rollback is safe only while the table remains; the migration table can stay inert because its default is inactive and older code ignores it.
