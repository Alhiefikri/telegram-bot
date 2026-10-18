<?php

namespace App\Enums;

/**
 * One exclusive ownership channel of a normalized USDT receiver address. A
 * claimed address belongs to exactly one channel forever: shared checkout
 * receivers can never become a Customer-exclusive deposit receiver, and a
 * Customer deposit receiver can never enter the checkout pool.
 */
enum UsdtReceiverChannel: string
{
    case Checkout = 'checkout';
    case CustomerDeposit = 'customer_deposit';
    case SharedTopUp = 'shared_top_up';

    public function label(): string
    {
        return match ($this) {
            self::Checkout => 'Checkout pool',
            self::CustomerDeposit => 'Customer deposit',
            self::SharedTopUp => 'Shared top-up address',
        };
    }
}
