<?php

namespace App\Services\Checkout;

use App\Support\PaymentProvider;

final readonly class WijayaPayCheckoutIntent implements CheckoutPaymentIntent
{
    public function provider(): string
    {
        return PaymentProvider::WIJAYAPAY;
    }

    public function method(): string
    {
        return 'QRIS';
    }

    public function postCreationBehavior(): CheckoutPostCreationBehavior
    {
        return CheckoutPostCreationBehavior::WijayaPayInstructions;
    }
}
