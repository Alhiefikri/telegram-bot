<?php

namespace App\Services\Checkout;

use App\Support\PaymentProvider;

final readonly class StoreBalanceCheckoutIntent implements CheckoutPaymentIntent
{
    public function provider(): string
    {
        return PaymentProvider::STORE_BALANCE;
    }

    public function method(): string
    {
        return StoreBalanceCheckoutPaymentPreparation::METHOD;
    }

    public function postCreationBehavior(): CheckoutPostCreationBehavior
    {
        return CheckoutPostCreationBehavior::StoreBalancePaid;
    }
}
