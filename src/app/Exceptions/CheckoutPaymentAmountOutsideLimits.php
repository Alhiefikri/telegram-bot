<?php

namespace App\Exceptions;

final class CheckoutPaymentAmountOutsideLimits extends InvalidCheckout
{
    public function __construct(
        public readonly int $minimum,
        public readonly int $maximum,
    ) {
        parent::__construct('Sakurupiah QRIS amount is outside the provider limits.');
    }
}
