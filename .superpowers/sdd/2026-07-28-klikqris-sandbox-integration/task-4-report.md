# Task 4 Report: KlikQRIS Sandbox Checkout Intent

## SHA

- Base SHA before Task 4 implementation: `a6f5cadf2baf553b04139cfc45caf24437f8f40b`
- Task commit message: `feat: add KlikQRIS sandbox checkout intent`
- Note: the final task commit SHA is reported in the assistant completion response because a commit cannot embed its own final SHA without changing that SHA.

## Files Changed

- Created `src/app/Services/Checkout/KlikQrisCheckoutIntent.php`
- Modified `src/app/Services/Checkout/CheckoutPaymentIntents.php`
- Modified `src/app/Services/Checkout/CheckoutPostCreationBehavior.php`
- Modified `src/tests/Feature/OrderCartDomainTest.php`
- Created `.superpowers/sdd/2026-07-28-klikqris-sandbox-integration/task-4-report.md`

## RED

Command:

```bash
cd src && php artisan test tests/Feature/OrderCartDomainTest.php --filter=klik_qris
```

Result: expected failure before production code existed.

Evidence:

```text
FAIL Tests\Feature\OrderCartDomainTest
⨯ klik qris checkout intent owns provider currency deadline and post...
⨯ klik qris checkout persists idr payment for current product total...
⨯ klik qris checkout fails closed in production before order payment...

Call to undefined method App\Services\Checkout\CheckoutPaymentIntents::klikQris()

Tests: 3 failed (0 assertions)
Exit code: 2
```

## GREEN

Command:

```bash
cd src && php artisan test tests/Feature/OrderCartDomainTest.php --filter=klik_qris
```

Result:

```text
PASS Tests\Feature\OrderCartDomainTest
✓ klik qris checkout intent owns provider currency deadline and post...
✓ klik qris checkout persists idr payment for current product total...
✓ klik qris checkout fails closed in production before order payment...

Tests: 3 passed (21 assertions)
Exit code: 0
```

Implementation notes:

- `CheckoutPaymentIntents::klikQris(?CarbonInterface $deadline = null)` returns `KlikQrisCheckoutIntent`.
- `KlikQrisCheckoutIntent` returns provider `klikqris`, method `QRIS`, order/payment currency `IDR`, and sum-of-current-product-price payment amount.
- `expiresAt()` honors an explicit requested deadline first, then constructor deadline, then `config('services.klikqris.qris_ttl_minutes', 15)`.
- `assertReady()` delegates to `KlikQrisProviderReadiness::sandbox()->assertReady()` and wraps readiness failure in customer-safe `InvalidCheckout`.
- `CheckoutPostCreationBehavior::KlikQrisInstructions` was added as enum/intent only.

## Regressions

Command:

```bash
cd src && php artisan test tests/Feature/OrderCartDomainTest.php && ./vendor/bin/pint --dirty
```

Result:

```text
PASS Tests\Feature\OrderCartDomainTest
Tests: 35 passed (162 assertions)

Laravel Pint
PASS 4 files
Exit code: 0
```

Coverage from full `OrderCartDomainTest` includes existing manual QRIS, Binance Pay, Pakasir checkout intent, stock reservation, expiry, payment lifecycle, and refund/release behavior.

## Constraint Self-Review

- Sandbox-only: yes. Readiness uses the existing KlikQRIS sandbox readiness gate.
- Production fail-closed: yes. Added production-environment checkout test expecting `InvalidCheckout` before order/payment/stock reservation.
- Customer-safe failure: yes. Throws `InvalidCheckout` with `KlikQRIS sandbox checkout is not ready...` and readiness configuration message, no secret values.
- No real credentials: yes. Tests use only synthetic values `test-only-key` and `merchant-test`; no env secrets inspected.
- No admin production switch: yes. Did not modify `UpdatePaymentModeRequest`, `AdminPaymentModeController`, admin dashboard, default routing, active routing, production switch, Telegram, or Storefront selectors.
- No Pakasir/manual/Binance behavior changes: yes. Only checkout factory/enum/test additions plus new isolated intent; full `OrderCartDomainTest` regression passed.
- Provider/method/currency/amount: yes. Tests assert provider `klikqris`, method `QRIS`, IDR currencies, and current product price sum.
- Expiry: yes. Tests assert explicit deadline and 15-minute config default.
- Post creation behavior scope: yes. Only enum/intent `KlikQrisInstructions`; no provider create/persistence implementation from Task 5.

Forbidden-file diff check:

```bash
git diff --name-only -- src/app/Http/Requests/Admin/UpdatePaymentModeRequest.php \
  src/app/Http/Controllers/Admin/AdminPaymentModeController.php \
  src/resources/views/admin src/app/Storefront src/app/Services/Telegram | cat
```

Result: no output.

## Concerns / Follow-ups

- Task 4 intentionally stops after checkout intent and enum behavior. Provider create/persistence, Telegram instruction delivery, and QRIS rendering remain for later tasks.
- The task commit SHA is not embedded here to avoid the self-referential commit-hash problem; it is reported after committing.
