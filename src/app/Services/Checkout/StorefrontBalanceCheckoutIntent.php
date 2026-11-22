<?php

namespace App\Services\Checkout;

use App\Support\PaymentProvider;

final readonly class StorefrontBalanceCheckoutIntent implements CheckoutPaymentIntent
{
    public function __construct(
        public int $customerId,
        public int $expectedTotal,
        public ?string $email,
        public ?string $supportChannel,
        public ?string $supportValue,
    ) {}

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
