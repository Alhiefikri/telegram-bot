<?php

namespace App\Services\Checkout;

use App\Support\PaymentProvider;

final readonly class ManualQrisCheckoutIntent implements CheckoutPaymentIntent
{
    public function provider(): string
    {
        return PaymentProvider::MANUAL;
    }

    public function method(): string
    {
        return 'QR_MANUAL';
    }

    public function postCreationBehavior(): CheckoutPostCreationBehavior
    {
        return CheckoutPostCreationBehavior::ManualQrisInstructions;
    }
}
