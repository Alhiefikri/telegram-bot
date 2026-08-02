# Binance Manual Review Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Binance Pay amounts easy to enter manually and let authorised admins safely resolve mismatched Binance payments from the order dashboard.

**Architecture:** Keep the exact-match verifier as the only automatic delivery path. Normalize auto-verification invoice amounts to mill-USDT increments, persist sanitized evidence only when a candidate mismatch is found, and expose the already-existing manual order state transitions through protected dashboard endpoints.

**Tech Stack:** Laravel, PHP 8, PHPUnit feature tests, Blade, Eloquent transactions, Telegram Bot API.

## Global Constraints

- Automatic delivery requires an exact incoming USDT transaction to the configured Binance Pay ID after checkout.
- Auto-verification amounts must display exactly three decimal places and retain pending-payment uniqueness.
- All manual resolution actions must use `OrderLifecycle` through `AdminOrderOperations`; do not update order, payment, stock, or delivery state directly.
- Admin actions are POST-only, CSRF-protected, policy-authorised, audited, and safe under repeated/concurrent attempts.
- Do not alter the no-credential Binance screenshot flow.

---

## File Structure

- `src/app/Services/Binance/BinanceUniquePaymentAmount.php`: allocate unique three-decimal payable amounts.
- `src/app/Services/Binance/BinanceAutoVerification.php`: retain sanitized mismatch evidence on the pending payment.
- `src/app/Http/Requests/Admin/AdminProductFormRequest.php`: restrict new USDT prices to three decimals.
- `src/app/Http/Controllers/Admin/AdminOrderManualCompleteController.php`: authorise and complete an order manually.
- `src/app/Http/Controllers/Admin/AdminOrderManualRejectController.php`: authorise and reject an order manually.
- `src/routes/web.php`: register protected order-resolution routes.
- `src/app/Http/Controllers/Admin/AdminOrderDetailController.php`: prepare payment review data and action permissions.
- `src/resources/views/admin/orders/show.blade.php`: render evidence and confirmed resolution controls.
- `src/tests/Feature/Telegram/BinanceAutoVerifyTest.php`: cover allocation and mismatch evidence.
- `src/tests/Feature/AdminOrderPaymentOperationsTest.php`: cover dashboard approval/rejection and state guards.

### Task 1: Allocate Three-Decimal Binance Amounts

**Files:**
- Modify: `src/app/Services/Binance/BinanceUniquePaymentAmount.php`
- Modify: `src/app/Http/Requests/Admin/AdminProductFormRequest.php`
- Modify: `src/tests/Feature/Telegram/BinanceAutoVerifyTest.php`

**Interfaces:**
- Consumes: `BinanceUniquePaymentAmount::allocate(int $baseAmountMicro): int`
- Produces: a pending Binance amount divisible by `1_000` micro-USDT and greater than or equal to the product price plus `0.001` USDT.

- [ ] **Step 1: Write failing allocation tests**

```php
public function test_binance_checkout_allocates_a_unique_three_decimal_amount(): void
{
    $this->enableBinancePay();
    [, $payment] = $this->createBinanceOrder();

    $this->assertSame(0, $payment->amount % 1_000);
    $this->assertGreaterThanOrEqual(self::BASE_USDT_MICRO + 1_000, $payment->amount);
    $this->assertLessThanOrEqual(self::BASE_USDT_MICRO + 999_000, $payment->amount);
}
```

- [ ] **Step 2: Run the focused test and verify it fails**

Run: `php artisan test tests/Feature/Telegram/BinanceAutoVerifyTest.php --filter=three_decimal_amount`

Expected: FAIL because the existing random micro-USDT offset does not guarantee divisibility by `1_000`.

- [ ] **Step 3: Implement the smallest allocation change**

```php
$base = (int) (ceil($baseAmountMicro / 1_000) * 1_000);
$candidate = $base + random_int(1, 999) * 1_000;
```

Keep the pending `payments.amount` collision query; increase retry attempts enough to sample the 999-value space before throwing the existing ambiguity exception.

- [ ] **Step 4: Limit product input to three decimals**

Change the USDT validation expression from `\d{1,6}` to `\d{1,3}` and change the validation message to state a maximum of three decimals. Leave stored integer micro-USDT representation unchanged.

- [ ] **Step 5: Run focused tests and verify they pass**

Run: `php artisan test tests/Feature/Telegram/BinanceAutoVerifyTest.php --filter='unique|three_decimal|exact_amount_without'`

Expected: PASS; the no-credential test still proves the exact base amount is used.

### Task 2: Persist Mismatch Evidence

**Files:**
- Modify: `src/app/Services/Binance/BinanceAutoVerification.php`
- Modify: `src/tests/Feature/Telegram/BinanceAutoVerifyTest.php`

**Interfaces:**
- Consumes: `BinanceVerificationResult::of(BinanceVerificationOutcome::Mismatch, BinancePayTransaction $transaction)`.
- Produces: the pending payment's `provider_payload` containing only `BinancePayTransaction::sanitizedPayload()` for the detected candidate.

- [ ] **Step 1: Write the failing mismatch-evidence test**

```php
$this->postTelegramWebhook($this->callbackUpdate(341, 'binance-orderid', 'binance:orderid:'.$order->id))->assertOk();
$this->postTelegramWebhook($this->messageUpdate(342, '445064386362580992'))->assertOk();

$payload = $order->payments()->sole()->fresh()->provider_payload;
$this->assertSame('0.1', $payload['amount']);
$this->assertSame('USDT', $payload['currency']);
$this->assertSame('P_386362580992', $payload['transactionId']);
```

- [ ] **Step 2: Run the focused test and verify it fails**

Run: `php artisan test tests/Feature/Telegram/BinanceAutoVerifyTest.php --filter=wrong_amount`

Expected: FAIL because mismatch handling currently returns the transaction only to the notifier.

- [ ] **Step 3: Implement evidence capture before returning a mismatch**

Add a private helper that updates `provider_payload` only while the payment remains pending and only with `$transaction->sanitizedPayload()`. Call it in the two mismatch branches: the direct provider-order-ID path and the candidate mismatch path in `matchAndConfirm`.

- [ ] **Step 4: Run focused tests and verify they pass**

Run: `php artisan test tests/Feature/Telegram/BinanceAutoVerifyTest.php --filter='wrong_amount|wrong_currency|another_receiver'`

Expected: PASS; each remains pending and does not auto-deliver.

### Task 3: Expose Safe Dashboard Resolution Actions

**Files:**
- Create: `src/app/Http/Controllers/Admin/AdminOrderManualCompleteController.php`
- Create: `src/app/Http/Controllers/Admin/AdminOrderManualRejectController.php`
- Modify: `src/routes/web.php`
- Modify: `src/app/Http/Controllers/Admin/AdminOrderDetailController.php`
- Modify: `src/resources/views/admin/orders/show.blade.php`
- Modify: `src/tests/Feature/AdminOrderPaymentOperationsTest.php`

**Interfaces:**
- Consumes: `AdminOrderOperations::canCompleteManualOrder(Order $order): bool`, `completeManualOrder(Order $order, ?User $user, ?string $ip): Order`, and `rejectManualOrder(Order $order, ?User $user, ?string $ip): Order`.
- Produces: `POST /admin/orders/{order}/complete-manual` and `POST /admin/orders/{order}/reject-manual`, redirected back to the order detail page.

- [ ] **Step 1: Write failing HTTP tests for approve and reject**

```php
$this->actingAsAdmin($user)
    ->post(route('admin.orders.complete-manual', $order))
    ->assertRedirect(route('admin.orders.show', $order));

$this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
$this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
```

Write a separate rejection test asserting `Cancelled`, `Failed`, restored available stock, and `order.manual_rejected` audit logging. Write an unauthorised-user test for each route asserting the existing admin access denial.

- [ ] **Step 2: Run the focused tests and verify they fail**

Run: `php artisan test tests/Feature/AdminOrderPaymentOperationsTest.php --filter='dashboard_can_(complete|reject)_manual'`

Expected: FAIL because neither route exists.

- [ ] **Step 3: Add two thin authorised controllers**

```php
Gate::authorize('update', $order);
$completed = $operations->completeManualOrder($order, $request->attributes->get('adminUser'), $request->ip());

return redirect()->route('admin.orders.show', $completed)->with('status', 'Pembayaran disetujui dan stok sedang dikirim ke buyer.');
```

Use the equivalent `rejectManualOrder` call and a rejection status message in the second controller. Convert `InvalidArgumentException` into the order validation error response used by the existing cancel controller.

- [ ] **Step 4: Register POST routes and render review controls**

Add routes inside the existing `admin.access` orders group. In the detail controller, pass `canCompleteManualOrder`, `canRejectManualOrder`, and a normalized `binanceMismatch` value from the pending Binance payment payload. Render expected versus detected transfer details when present. Render approval and rejection forms only when their corresponding capability is true; each form includes a confirmation prompt.

- [ ] **Step 5: Run focused tests and verify they pass**

Run: `php artisan test tests/Feature/AdminOrderPaymentOperationsTest.php`

Expected: PASS; approval completes once, rejection releases stock once, and terminal orders expose neither action.

### Task 4: Full Regression Verification

**Files:**
- Modify: `src/tests/Feature/AdminDashboardAccessTest.php`
- Modify: `src/tests/Feature/AdminDashboardSalesRenderingTest.php` only if route inventory or order-detail assertions require it.

**Interfaces:**
- Consumes: the new named admin routes and all prior changes.
- Produces: route-access and visual-regression coverage for the complete flow.

- [ ] **Step 1: Write failing route-inventory and dashboard-evidence tests**

Add `admin.orders.complete-manual` and `admin.orders.reject-manual` to the expected protected route inventory. Add an order-detail response assertion that a pending Binance mismatch displays both expected and received USDT details and the approval/rejection controls.

- [ ] **Step 2: Run tests and verify they fail**

Run: `php artisan test tests/Feature/AdminDashboardAccessTest.php tests/Feature/AdminDashboardSalesRenderingTest.php --filter='binance|orders'`

Expected: FAIL until routes and the detail view expose the new controls.

- [ ] **Step 3: Make only assertion-alignment changes required by the implemented UI**

Do not introduce behaviour beyond Tasks 1-3. Keep labels in Indonesian consistent with the dashboard copy.

- [ ] **Step 4: Run all affected suites**

Run: `php artisan test tests/Feature/Telegram/BinanceAutoVerifyTest.php tests/Feature/AdminOrderPaymentOperationsTest.php tests/Feature/AdminDashboardAccessTest.php tests/Feature/AdminDashboardSalesRenderingTest.php`

Expected: PASS with no skipped or risky tests.

- [ ] **Step 5: Run the full test suite**

Run: `php artisan test`

Expected: PASS.
