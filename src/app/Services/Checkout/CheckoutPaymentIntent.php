<?php

namespace App\Services\Checkout;

interface CheckoutPaymentIntent
{
    public function provider(): string;

    public function method(): string;

    public function postCreationBehavior(): CheckoutPostCreationBehavior;
}
