<?php

namespace App\Enums;

enum BalancePaymentPreference: string
{
    case IdrOnly = 'idr_only';
    case UsdOnly = 'usd_only';
    case IdrFirst = 'idr_first';
    case UsdFirst = 'usd_first';

    /** @return list<string> Internal ledger currencies, not API denominations. */
    public function currencies(): array
    {
        return match ($this) {
            self::IdrOnly => ['IDR'],
            self::UsdOnly => ['USDT'],
            self::IdrFirst => ['IDR', 'USDT'],
            self::UsdFirst => ['USDT', 'IDR'],
        };
    }
}
