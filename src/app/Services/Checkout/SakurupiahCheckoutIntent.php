<?php

namespace App\Services\Checkout;

use App\Support\PaymentProvider;

final readonly class SakurupiahCheckoutIntent implements CheckoutPaymentIntent
{
    public function provider(): string
    {
        return PaymentProvider::SAKURUPIAH;
    }

    public function method(): string
    {
        return 'QRIS';
    }

    public function postCreationBehavior(): CheckoutPostCreationBehavior
    {
        return CheckoutPostCreationBehavior::SakurupiahInstructions;
    }
}
