<?php

namespace App\Services\Checkout;

use App\Support\PaymentProvider;

final readonly class UsdtBep20CheckoutIntent implements CheckoutPaymentIntent
{
    public const METHOD = 'USDT_BEP20';

    public function provider(): string
    {
        return PaymentProvider::MANUAL;
    }

    public function method(): string
    {
        return self::METHOD;
    }

    public function postCreationBehavior(): CheckoutPostCreationBehavior
    {
        return CheckoutPostCreationBehavior::BscInstructions;
    }
}
