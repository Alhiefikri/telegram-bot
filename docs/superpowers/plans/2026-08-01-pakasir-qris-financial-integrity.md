# Pakasir QRIS Financial Integrity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refuse Pakasir QRIS responses whose fee, total, currency, QR amount, or checksum conflicts with the official Pakasir contract.

**Architecture:** A pure fee policy validates the configured Pakasir tariff and fee-payer equation. A separate EMV QR parser validates the provider payment string. Both creation-time responses and persisted payments pass the same financial-integrity gate before any QR is buyer-visible.

**Tech Stack:** PHP 8.4, Laravel 12, PHPUnit 11, PostgreSQL 16, Docker Compose.

## Global Constraints

- Official tariff as of 21 July 2026: up to Rp105.000 is 0.7% plus Rp310; above Rp105.000 is 1%.
- Permit only floor/ceiling integer-rupiah percentage rounding, never a broad tolerance.
- Support explicit `customer` and `merchant` fee-payer modes.
- Never expose a QR, payment link, raw payment number, API key, or unsanitized provider payload after integrity failure.
- Existing persisted invalid QRIS rows become non-payable after deployment.
- Production Pakasir use remains blocked until the provider confirms this merchant/catalog is supported.

---

### Task 1: Official Fee Policy and Configuration

**Files:**
- Create: `src/app/Services/Pakasir/PakasirQrisFeePolicy.php`
- Modify: `src/config/services.php`
- Modify: `.env.example`
- Modify: `src/.env.example`
- Modify: `docker-compose.yml`
- Modify: `src/app/Bootstrap/ProductionConfigurationValidator.php`
- Test: `src/tests/Unit/Services/Pakasir/PakasirQrisFeePolicyTest.php`
- Test: `src/tests/Feature/FoundationInfrastructureTest.php`

**Interfaces:**
- Produces: `allowedFees(int $amount): array`, `assertValid(int $amount, int $fee, int $total): void`, `feePayer(): string`, and `buyerTotal(int $amount, int $fee): int`.

- [ ] **Step 1: Write failing fee-policy tests**

```php
$policy = new PakasirQrisFeePolicy('customer', 105_000, 70, 310, 100, 0);
$this->assertSame([450], $policy->allowedFees(20_000));
$this->assertSame([1045], $policy->allowedFees(105_000));
$this->assertSame([1050, 1051], $policy->allowedFees(105_001));
$policy->assertValid(20_000, 450, 20_450);
$this->expectException(UnexpectedValueException::class);
$policy->assertValid(20_000, 2_660, 268_660);
```

- [ ] **Step 2: Verify the focused tests fail because the policy is absent**

Run: `cd src && php artisan test tests/Unit/Services/Pakasir/PakasirQrisFeePolicyTest.php`
Expected: FAIL because `PakasirQrisFeePolicy` does not exist.

- [ ] **Step 3: Implement the pure integer fee policy and configuration defaults**

```php
final readonly class PakasirQrisFeePolicy
{
    public function allowedFees(int $amount): array
    {
        $bps = $amount <= $this->threshold ? $this->lowBps : $this->highBps;
        $fixed = $amount <= $this->threshold ? $this->lowFixed : $this->highFixed;
        $numerator = $amount * $bps;
        return array_values(array_unique([
            intdiv($numerator, 10_000) + $fixed,
            intdiv($numerator + 9_999, 10_000) + $fixed,
        ]));
    }
}
```

Add `PAKASIR_QRIS_FEE_PAYER=customer`, threshold `105000`, low BPS `70`, low fixed `310`, high BPS `100`, and high fixed `0` to both env examples and Compose pass-through. Reject invalid payer/rates in production configuration validation.

- [ ] **Step 4: Run policy and configuration tests**

Run: `cd src && php artisan test tests/Unit/Services/Pakasir/PakasirQrisFeePolicyTest.php tests/Feature/FoundationInfrastructureTest.php`
Expected: PASS.

- [ ] **Step 5: Commit the fee-policy task**

```bash
git add src/app/Services/Pakasir/PakasirQrisFeePolicy.php src/config/services.php .env.example src/.env.example docker-compose.yml src/app/Bootstrap/ProductionConfigurationValidator.php src/tests/Unit/Services/Pakasir/PakasirQrisFeePolicyTest.php src/tests/Feature/FoundationInfrastructureTest.php
git commit -m "fix: enforce official Pakasir QRIS fees"
```

### Task 2: EMV QR Integrity Validation

**Files:**
- Create: `src/app/Services/Pakasir/QrisEmvPayload.php`
- Create: `src/tests/Support/QrisFixture.php`
- Test: `src/tests/Unit/Services/Pakasir/QrisEmvPayloadTest.php`

**Interfaces:**
- Produces: `QrisEmvPayload::parse(string $payload): self`, `currency(): string`, `amountRupiah(): int`, and `assertMatchesIdrAmount(int $amount): void`.

- [ ] **Step 1: Write failing tests with a hand-checked EMV fixture**

```php
$payload = QrisFixture::dynamic(amount: 20_450, currency: '360');
$qris = QrisEmvPayload::parse($payload);
$this->assertSame('360', $qris->currency());
$this->assertSame(20_450, $qris->amountRupiah());
$qris->assertMatchesIdrAmount(20_450);
```

Add separate tests that mutate tag 53, tag 54, a TLV length, and the final CRC; each must throw `UnexpectedValueException`.

- [ ] **Step 2: Verify EMV tests fail because the parser is absent**

Run: `cd src && php artisan test tests/Unit/Services/Pakasir/QrisEmvPayloadTest.php`
Expected: FAIL because `QrisEmvPayload` does not exist.

- [ ] **Step 3: Implement strict top-level TLV and CRC16/CCITT-FALSE parsing**

```php
for ($offset = 0; $offset < strlen($payload);) {
    $tag = substr($payload, $offset, 2);
    $length = (int) substr($payload, $offset + 2, 2);
    $value = substr($payload, $offset + 4, $length);
    $fields[$tag] = $value;
    $offset += 4 + $length;
}
```

Require a terminal `6304` field, verify CRC across the payload through `6304`, require numeric tag 54 with at most two decimal places, and compare it to integer rupiah without floating-point arithmetic.

- [ ] **Step 4: Run EMV tests**

Run: `cd src && php artisan test tests/Unit/Services/Pakasir/QrisEmvPayloadTest.php`
Expected: PASS.

- [ ] **Step 5: Commit the EMV validator task**

```bash
git add src/app/Services/Pakasir/QrisEmvPayload.php src/tests/Unit/Services/Pakasir/QrisEmvPayloadTest.php src/tests/Support/QrisFixture.php
git commit -m "fix: validate native QRIS payload amounts"
```

### Task 3: Fail-Closed Provider and Persistence Boundary

**Files:**
- Modify: `src/app/Services/Pakasir/PakasirQrisTransaction.php`
- Modify: `src/app/Services/Pakasir/PakasirClient.php`
- Modify: `src/app/Services/Pakasir/PakasirNativeQrisService.php`
- Modify: `src/app/Models/Payment.php`
- Modify: `src/tests/Support/PakasirNativeQrisScenarios.php`
- Test: `src/tests/Unit/Services/Pakasir/PakasirQrisTransactionTest.php`
- Test: `src/tests/Feature/PakasirGatewayTest.php`
- Test: `src/tests/Feature/StorefrontShopCheckoutTest.php`

**Interfaces:**
- Consumes: `PakasirQrisFeePolicy::assertValid()` and `QrisEmvPayload::assertMatchesIdrAmount()`.
- Produces: `PakasirQrisTransaction::assertMatches(Payment $payment, string $project, PakasirQrisFeePolicy $fees): void` and a single pure persisted-payment readiness decision.

- [ ] **Step 1: Write the reported regression as failing boundary tests**

```php
$transaction = $this->transaction(amount: 20_000, fee: 2_660, total: 268_660, qrAmount: 268_660);
$this->expectExceptionMessage('Pakasir QRIS fee mismatch.');
$transaction->assertMatches($payment, 'warung-ai', $policy);
```

Add feature assertions that invalid create and recovery responses never persist a payable QR and `isPayableNativePakasirQris()` returns false for an existing row with the same bad numbers.

- [ ] **Step 2: Verify the regression tests fail by accepting the bad total**

Run: `cd src && php artisan test tests/Unit/Services/Pakasir/PakasirQrisTransactionTest.php tests/Feature/PakasirGatewayTest.php --filter='financial|fee|total|persisted'`
Expected: FAIL because the malformed financials are currently accepted.

- [ ] **Step 3: Apply both validators at every create/recovery/reconstruction boundary**

Inject `PakasirQrisFeePolicy` through the container, reject before `persistCreatedTransaction()`, record a sanitized `provider_financial_integrity_failed` state, and make model readiness use the same policy values from config. Never persist or redisplay `payment_number` from a rejected response.

- [ ] **Step 4: Run provider and persistence tests**

Run: `cd src && php artisan test tests/Unit/Services/Pakasir/PakasirQrisTransactionTest.php tests/Feature/PakasirGatewayTest.php tests/Feature/StorefrontShopCheckoutTest.php`
Expected: PASS.

- [ ] **Step 5: Commit the fail-closed boundary task**

```bash
git add src/app/Services/Pakasir/PakasirQrisTransaction.php src/app/Services/Pakasir/PakasirClient.php src/app/Services/Pakasir/PakasirNativeQrisService.php src/app/Models/Payment.php src/tests/Support/PakasirNativeQrisScenarios.php src/tests/Unit/Services/Pakasir/PakasirQrisTransactionTest.php src/tests/Feature/PakasirGatewayTest.php src/tests/Feature/StorefrontShopCheckoutTest.php
git commit -m "fix: reject inconsistent Pakasir QRIS totals"
```

### Task 4: Buyer UX, Operations, and QRIS Release Gate

**Files:**
- Modify: `src/app/Services/Telegram/TelegramPaymentInstructionRenderer.php`
- Modify: `src/app/Storefront/StorefrontShopSignedInvoice.php`
- Modify: `src/app/Storefront/StorefrontShopPaymentStatusRefresh.php`
- Modify: `src/app/Console/Commands/ValidatePakasirNativeQris.php`
- Modify: `docs/ci-cd.md`
- Test: `src/tests/Unit/Services/Telegram/TelegramPaymentInstructionRendererTest.php`
- Test: `src/tests/Feature/StorefrontShopOrderInvoiceTest.php`
- Test: `src/tests/Feature/PakasirNativeQrisValidationCommandTest.php`

**Interfaces:**
- Consumes: the common financial-integrity decision from Task 3.
- Produces: customer/merchant-specific fee copy and sanitized release diagnostics.

- [ ] **Step 1: Write failing UX and command tests**

Assert customer-payer Rp20.000 renders fee Rp450 and total Rp20.450; merchant-payer renders buyer total Rp20.000 and says the merchant bears Rp450. Assert an invalid persisted QR renders no image or payment link and the validation command exits nonzero with sanitized mismatch details.

- [ ] **Step 2: Verify focused UX tests fail**

Run: `cd src && php artisan test tests/Unit/Services/Telegram/TelegramPaymentInstructionRendererTest.php tests/Feature/StorefrontShopOrderInvoiceTest.php tests/Feature/PakasirNativeQrisValidationCommandTest.php --filter='fee|financial|invalid'`
Expected: FAIL on payer wording or invalid QR exposure.

- [ ] **Step 3: Render fee-payer-aware copy and reuse the runtime integrity gate in diagnostics**

Use `PakasirQrisFeePolicy::feePayer()` for captions, remove payment actions when readiness is false, and make the validation command report the exact sanitized invariant that failed.

- [ ] **Step 4: Run QRIS focused and integration suites**

Run: `cd src && php artisan test tests/Unit/Services/Pakasir tests/Unit/Services/Telegram/TelegramPaymentInstructionRendererTest.php tests/Feature/PakasirGatewayTest.php tests/Feature/PakasirNativeQrisValidationCommandTest.php tests/Feature/StorefrontShopCheckoutTest.php tests/Feature/StorefrontShopOrderInvoiceTest.php tests/Feature/Telegram/CartCheckoutTest.php`
Expected: PASS.

- [ ] **Step 5: Commit QRIS UX and operations**

```bash
git add src/app/Services/Telegram/TelegramPaymentInstructionRenderer.php src/app/Storefront/StorefrontShopSignedInvoice.php src/app/Storefront/StorefrontShopPaymentStatusRefresh.php src/app/Console/Commands/ValidatePakasirNativeQris.php docs/ci-cd.md src/tests/Unit/Services/Telegram/TelegramPaymentInstructionRendererTest.php src/tests/Feature/StorefrontShopOrderInvoiceTest.php src/tests/Feature/PakasirNativeQrisValidationCommandTest.php
git commit -m "fix: fail closed on unsafe QRIS checkout"
```
