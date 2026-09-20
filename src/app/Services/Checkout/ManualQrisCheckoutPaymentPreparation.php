<?php

namespace App\Services\Checkout;

use App\Models\ManualPaymentSetting;
use App\Services\Checkout\PaymentPlan\ManualQrisCheckoutPaymentSnapshot;
use App\Support\PaymentProvider;

final readonly class ManualQrisCheckoutPaymentPreparation implements CheckoutPaymentPreparation
{
    public const METHOD = 'QR_MANUAL';

    public const PAYMENT_CURRENCY = 'IDR';

    public const IDENTITY = PaymentProvider::MANUAL.'|'.self::METHOD;

    public function preflight(CheckoutPaymentPreflightContext $context): CheckoutPaymentPreflight
    {
        return CheckoutPaymentPreflight::none($context);
    }

    public function prepare(CheckoutPaymentPreparationContext $context): CheckoutPaymentPlan
    {
        $context->preflight->assertMatches(new CheckoutPaymentPreflightContext(
            currentSetting: $context->lockedSetting,
            requestedProvider: PaymentProvider::MANUAL,
            requestedMethod: self::METHOD,
        ));
        $expiresAt = now()->addMinutes(
            $context->lockedSetting?->qrisPaymentTtlMinutes()
                ?? ManualPaymentSetting::DEFAULT_QRIS_PAYMENT_TTL_MINUTES,
        );

        $amount = $context->pricing->idrTotal();
        $snapshot = new ManualQrisCheckoutPaymentSnapshot(
            provider: PaymentProvider::MANUAL,
            method: self::METHOD,
            paymentCurrency: self::PAYMENT_CURRENCY,
            amount: $amount,
            expiresAt: $expiresAt,
        );

        return new CheckoutPaymentPlan(
            orderCurrency: 'IDR',
            orderExpiresAt: $expiresAt,
            provider: PaymentProvider::MANUAL,
            method: self::METHOD,
            paymentCurrency: self::PAYMENT_CURRENCY,
            amount: $amount,
            paymentExpiresAt: $expiresAt,
            railSnapshot: $snapshot,
            postCreationBehavior: CheckoutPostCreationBehavior::ManualQrisInstructions,
        );
    }
}
