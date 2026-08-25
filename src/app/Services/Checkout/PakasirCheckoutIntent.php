<?php

namespace App\Services\Checkout;

use App\Exceptions\InvalidCheckout;
use App\Support\PakasirCheckoutFlow;
use App\Support\PaymentProvider;

final readonly class PakasirCheckoutIntent implements CheckoutPaymentIntent
{
    public function __construct(
        private string $checkoutFlow,
    ) {
        if (! PakasirCheckoutFlow::isAllowed($checkoutFlow)) {
            throw new InvalidCheckout(PakasirCheckoutFlow::validationMessage());
        }
    }

    public function provider(): string
    {
        return PaymentProvider::PAKASIR;
    }

    public function method(): string
    {
        return $this->checkoutFlow;
    }

    public function postCreationBehavior(): CheckoutPostCreationBehavior
    {
        return CheckoutPostCreationBehavior::PakasirInstructions;
    }
}
