<?php

namespace App\Exceptions;

final class CheckoutPriceChanged extends InvalidCheckout
{
    public function __construct(public readonly int $idrTotal, public readonly ?int $usdtTotal)
    {
        parent::__construct('Checkout prices changed; authorize fresh totals before purchasing.');
    }
}
