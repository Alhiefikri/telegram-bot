<?php

namespace App\Services\Checkout;

use App\Support\PaymentProvider;

final readonly class BinancePayCheckoutIntent implements CheckoutPaymentIntent
{
    public const METHOD = 'BINANCE_PAY_ID';

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
        return CheckoutPostCreationBehavior::BinancePayInstructions;
    }
}
