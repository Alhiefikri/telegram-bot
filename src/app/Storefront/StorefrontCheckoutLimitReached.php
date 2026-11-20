<?php

namespace App\Storefront;

use App\Exceptions\InvalidCheckout;

final class StorefrontCheckoutLimitReached extends InvalidCheckout
{
    /** @param list<string> $invoiceUrls */
    public function __construct(public readonly array $invoiceUrls, bool $sharedNetwork = false)
    {
        parent::__construct($sharedNetwork
            ? StorefrontLocale::text('pending_network_limit')
            : StorefrontLocale::text('pending_orders_limit'));
    }
}
