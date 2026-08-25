<?php

namespace App\Services\Checkout;

use App\Services\Checkout\PaymentPlan\KlikQrisCheckoutPaymentSnapshot;
use App\Services\Checkout\PaymentPlan\ManualQrisCheckoutPaymentSnapshot;
use App\Services\Checkout\PaymentPlan\PakasirCheckoutPaymentSnapshot;
use App\Support\PaymentProvider;
use LogicException;

/**
 * Compatibility implementation for the legacy intent objects.
 *
 * The real rail adapters will own the preparation rules during the checkout
 * migration. Keeping this bridge small lets existing callers adopt the new
 * interface without changing the current checkout transaction yet.
 */
trait CheckoutPaymentPreparationCompatibility
{
    public function preflight(CheckoutPaymentPreflightContext $context): CheckoutPaymentPreflight
    {
        return CheckoutPaymentPreflight::none($context);
    }

    public function prepare(CheckoutPaymentPreparationContext $context): CheckoutPaymentPlan
    {
        $context->preflight->assertMatches(new CheckoutPaymentPreflightContext(
            currentSetting: $context->lockedSetting,
            requestedProvider: $this->provider(),
            requestedMethod: $this->method(),
        ));

        $expiresAt = $this->expiresAt(setting: $context->lockedSetting);
        $amount = $this->paymentAmount($context->items, $context->products);

        $snapshot = match ([$this->provider(), $this->method()]) {
            [PaymentProvider::MANUAL, 'QR_MANUAL'] => new ManualQrisCheckoutPaymentSnapshot(
                provider: $this->provider(),
                method: $this->method(),
                paymentCurrency: $this->paymentCurrency(),
                amount: $amount,
                expiresAt: $expiresAt,
            ),
            [PaymentProvider::PAKASIR, $this->method()] => new PakasirCheckoutPaymentSnapshot(
                provider: $this->provider(),
                method: $this->method(),
                paymentCurrency: $this->paymentCurrency(),
                amount: $amount,
                expiresAt: $expiresAt,
                flow: $this->method(),
            ),
            [PaymentProvider::KLIKQRIS, 'QRIS'] => new KlikQrisCheckoutPaymentSnapshot(
                provider: $this->provider(),
                method: $this->method(),
                paymentCurrency: $this->paymentCurrency(),
                amount: $amount,
                expiresAt: $expiresAt,
            ),
            default => throw new LogicException(
                'This legacy payment intent requires its dedicated preparation adapter.',
            ),
        };

        return new CheckoutPaymentPlan(
            orderCurrency: $this->orderCurrency(),
            orderExpiresAt: $expiresAt,
            provider: $this->provider(),
            method: $this->method(),
            paymentCurrency: $this->paymentCurrency(),
            amount: $amount,
            paymentExpiresAt: $expiresAt,
            railSnapshot: $snapshot,
            postCreationBehavior: $this->postCreationBehavior(),
        );
    }
}
