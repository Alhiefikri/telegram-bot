<?php

namespace App\Services\Checkout;

use App\Exceptions\InvalidCheckout;
use App\Services\Checkout\PaymentPlan\UsdtBalanceCheckoutPaymentSnapshot;
use App\Support\PaymentProvider;

final readonly class UsdtBalanceCheckoutPaymentPreparation implements CheckoutPaymentPreparation
{
    public const METHOD = 'USDT_BALANCE';

    public const IDENTITY = PaymentProvider::USDT_BALANCE.'|'.self::METHOD;

    public function preflight(CheckoutPaymentPreflightContext $context): CheckoutPaymentPreflight
    {
        return CheckoutPaymentPreflight::none($context);
    }

    public function prepare(CheckoutPaymentPreparationContext $context): CheckoutPaymentPlan
    {
        $context->preflight->assertMatches(new CheckoutPaymentPreflightContext(
            currentSetting: $context->lockedSetting,
            requestedProvider: PaymentProvider::USDT_BALANCE,
            requestedMethod: self::METHOD,
        ));

        $amount = $context->pricing->usdtTotal();
        if ($amount === null || $amount <= 0) {
            throw new InvalidCheckout('USDT Balance checkout requires a positive USDT price for every Cart Item.');
        }

        $expiresAt = now()->addMinutes(15);

        return new CheckoutPaymentPlan(
            orderCurrency: 'USDT',
            orderExpiresAt: $expiresAt,
            provider: PaymentProvider::USDT_BALANCE,
            method: self::METHOD,
            paymentCurrency: 'USDT',
            amount: $amount,
            paymentExpiresAt: $expiresAt,
            railSnapshot: new UsdtBalanceCheckoutPaymentSnapshot(
                PaymentProvider::USDT_BALANCE, self::METHOD, 'USDT', $amount, $expiresAt,
            ),
            postCreationBehavior: CheckoutPostCreationBehavior::UsdtBalancePaid,
        );
    }
}
