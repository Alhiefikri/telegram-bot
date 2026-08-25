<?php

namespace App\Services\Checkout;

use App\Support\PaymentProvider;

final class CheckoutPaymentPreparationIdentity
{
    public static function key(string $provider, string $method): string
    {
        return PaymentProvider::normalize($provider).'|'.strtoupper(trim($method));
    }
}
