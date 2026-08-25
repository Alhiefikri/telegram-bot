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

        if (! in_array($identity, self::supportedIdentities(), true)) {
            throw new InvalidArgumentException('Unsupported checkout payment rail identity.');
        }

        if (isset($this->adapters[$identity])) {
            return $this->adapters[$identity];
        }

        // Existing intent objects implement the contract as a compatibility
        // bridge until the rail adapters are cut over in later slices.
        return $intent;
    }

    /**
     * @return list<string>
     */
    public static function supportedIdentities(): array
    {
        return [
            CheckoutPaymentPreparationIdentity::key('manual', 'QR_MANUAL'),
            CheckoutPaymentPreparationIdentity::key('pakasir', 'hosted'),
            CheckoutPaymentPreparationIdentity::key('pakasir', 'api_qris'),
            CheckoutPaymentPreparationIdentity::key('klikqris', 'QRIS'),
            CheckoutPaymentPreparationIdentity::key('manual', 'BINANCE_PAY_ID'),
            CheckoutPaymentPreparationIdentity::key('manual', 'USDT_BEP20'),
        ];
    }
}
