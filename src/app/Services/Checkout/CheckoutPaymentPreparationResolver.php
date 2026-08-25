<?php

namespace App\Services\Checkout;

use InvalidArgumentException;

final class CheckoutPaymentPreparationResolver
{
    /**
     * @param  array<string, CheckoutPaymentPreparation>  $adapters
     */
    public function __construct(
        private readonly array $adapters = [],
    ) {
        foreach ($this->adapters as $identity => $adapter) {
            if (! $adapter instanceof CheckoutPaymentPreparation) {
                throw new InvalidArgumentException("Checkout payment adapter [$identity] is invalid.");
            }
        }
    }

    public function for(CheckoutPaymentIntent $intent): CheckoutPaymentPreparation
    {
        $identity = CheckoutPaymentPreparationIdentity::key($intent->provider(), $intent->method());

        return $this->adapters[$identity]
            ?? throw new InvalidArgumentException("No checkout payment preparation is registered for [$identity].");
    }
}
