<?php

namespace App\Enums;

/**
 * A USDT funding rail the store may offer. Binance Pay ID and USDT BEP20 on
 * BNB Smart Chain are the only H0-v1 rails; a rail becomes selectable only
 * after its readiness provider is registered (see UsdtFundingRailReadiness).
 */
enum CustomerUsdtFundingRail: string
{
    case BinancePay = 'binance_pay';
    case BscUsdt = 'bsc_usdt';
    case BscShared = 'bsc_shared';

    public function label(): string
    {
        return match ($this) {
            self::BinancePay => 'Binance Pay ID',
            self::BscUsdt, self::BscShared => 'USDT BEP20 (BNB Smart Chain)',
        };
    }

    public function pauseScope(): CustomerUsdtFundingPauseScope
    {
        return match ($this) {
            self::BinancePay => CustomerUsdtFundingPauseScope::RailBinancePay,
            self::BscUsdt, self::BscShared => CustomerUsdtFundingPauseScope::RailBscUsdt,
        };
    }

    /**
     * Rails offered for new funding. The legacy per-Customer BSC receiver rail
     * remains readable for historical targets but is no longer selectable.
     *
     * @return list<self>
     */
    public static function selectable(): array
    {
        return [self::BinancePay, self::BscShared];
    }
}
