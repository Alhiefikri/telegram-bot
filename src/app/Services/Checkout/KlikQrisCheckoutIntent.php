<?php

namespace App\Services\Checkout;

use App\Support\PaymentProvider;

final readonly class KlikQrisCheckoutIntent implements CheckoutPaymentIntent
{
    public function provider(): string
    {
        return PaymentProvider::KLIKQRIS;
    }

    public function method(): string
    {
        return 'QRIS';
    }

    public function postCreationBehavior(): CheckoutPostCreationBehavior
    {
        return CheckoutPostCreationBehavior::KlikQrisInstructions;
    }
}
