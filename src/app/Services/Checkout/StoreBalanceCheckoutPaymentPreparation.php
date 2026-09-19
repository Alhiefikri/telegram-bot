<?php

namespace App\Services\Checkout;

use App\Exceptions\InvalidCheckout;
use App\Services\Checkout\PaymentPlan\StoreBalanceCheckoutPaymentSnapshot;
use App\Support\PaymentProvider;

final readonly class StoreBalanceCheckoutPaymentPreparation implements CheckoutPaymentPreparation
{
    public const METHOD = 'STORE_BALANCE';

    public const PAYMENT_CURRENCY = 'IDR';

    public const IDENTITY = PaymentProvider::STORE_BALANCE.'|'.self::METHOD;

    public function preflight(CheckoutPaymentPreflightContext $context): CheckoutPaymentPreflight
    {
        return CheckoutPaymentPreflight::none($context);
    }

    public function prepare(CheckoutPaymentPreparationContext $context): CheckoutPaymentPlan
    {
        $context->preflight->assertMatches(new CheckoutPaymentPreflightContext(
            currentSetting: $context->lockedSetting,
            requestedProvider: PaymentProvider::STORE_BALANCE,
            requestedMethod: self::METHOD,
        ));

        $amount = $context->pricing->idrTotal();
        if ($amount <= 0) {
            throw new InvalidCheckout('Store Balance checkout requires a positive IDR total.');
        }

        $expiresAt = now()->addMinutes(15);
        $snapshot = new StoreBalanceCheckoutPaymentSnapshot(
            provider: PaymentProvider::STORE_BALANCE,
            method: self::METHOD,
            paymentCurrency: self::PAYMENT_CURRENCY,
            amount: $amount,
            expiresAt: $expiresAt,
        );

        return new CheckoutPaymentPlan(
            orderCurrency: self::PAYMENT_CURRENCY,
            orderExpiresAt: $expiresAt,
            provider: PaymentProvider::STORE_BALANCE,
            method: self::METHOD,
            paymentCurrency: self::PAYMENT_CURRENCY,
            amount: $amount,
            paymentExpiresAt: $expiresAt,
            railSnapshot: $snapshot,
            postCreationBehavior: CheckoutPostCreationBehavior::StoreBalancePaid,
        );
    }

}
