<?php

namespace App\Services\Checkout;

use App\Exceptions\InvalidCheckout;
use App\Services\Checkout\PaymentPlan\KlikQrisCheckoutPaymentSnapshot;
use App\Services\KlikQris\KlikQrisCheckoutSelector;
use App\Support\PaymentProvider;
use RuntimeException;

final readonly class KlikQrisCheckoutPaymentPreparation implements CheckoutPaymentPreparation
{
    public const METHOD = 'QRIS';

    public const PAYMENT_CURRENCY = 'IDR';

    public const IDENTITY = PaymentProvider::KLIKQRIS.'|'.self::METHOD;

    public function __construct(
        private KlikQrisCheckoutSelector $selector,
    ) {}

    public function preflight(CheckoutPaymentPreflightContext $context): CheckoutPaymentPreflight
    {
        return CheckoutPaymentPreflight::none($context);
    }

    public function prepare(CheckoutPaymentPreparationContext $context): CheckoutPaymentPlan
    {
        $context->preflight->assertMatches(new CheckoutPaymentPreflightContext(
            currentSetting: $context->lockedSetting,
            requestedProvider: PaymentProvider::KLIKQRIS,
            requestedMethod: self::METHOD,
        ));

        try {
            $this->selector->assertReady('KlikQRIS active provider checkout is not ready.');
        } catch (RuntimeException $exception) {
            throw new InvalidCheckout(
                'KlikQRIS checkout is not ready. Please try again later or contact support.',
                previous: $exception,
            );
        }

        $expiresAt = now()->addMinutes((int) config('services.klikqris.qris_ttl_minutes', 15));

        $amount = $context->pricing->idrTotal();
        $snapshot = new KlikQrisCheckoutPaymentSnapshot(
            provider: PaymentProvider::KLIKQRIS,
            method: self::METHOD,
            paymentCurrency: self::PAYMENT_CURRENCY,
            amount: $amount,
            expiresAt: $expiresAt,
        );

        return new CheckoutPaymentPlan(
            orderCurrency: 'IDR',
            orderExpiresAt: $expiresAt,
            provider: PaymentProvider::KLIKQRIS,
            method: self::METHOD,
            paymentCurrency: self::PAYMENT_CURRENCY,
            amount: $amount,
            paymentExpiresAt: $expiresAt,
            railSnapshot: $snapshot,
            postCreationBehavior: CheckoutPostCreationBehavior::KlikQrisInstructions,
        );
    }
}
