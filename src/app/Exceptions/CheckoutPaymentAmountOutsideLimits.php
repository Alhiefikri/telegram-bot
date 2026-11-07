<?php

namespace App\Exceptions;

final class CheckoutPaymentAmountOutsideLimits extends InvalidCheckout
{
    public function __construct(
        public readonly int $minimum,
        public readonly int $maximum,
        string $providerLabel = 'Sakurupiah',
    ) {
        parent::__construct($providerLabel.' QRIS amount is outside the provider limits.');
    }
}
