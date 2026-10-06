<?php

namespace App\Services\Checkout;

use App\Support\PaymentProvider;

final readonly class UsdtBalanceCheckoutIntent implements CheckoutPaymentIntent
{
    public function provider(): string
    {
        return PaymentProvider::USDT_BALANCE;
    }

    public function method(): string
    {
        return UsdtBalanceCheckoutPaymentPreparation::METHOD;
    }

    public function postCreationBehavior(): CheckoutPostCreationBehavior
    {
        return CheckoutPostCreationBehavior::UsdtBalancePaid;
    }
}
