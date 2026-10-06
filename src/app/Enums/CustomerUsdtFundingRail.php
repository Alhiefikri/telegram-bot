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

    public function label(): string
    {
        return match ($this) {
            self::BinancePay => 'Binance Pay ID',
            self::BscUsdt => 'USDT BEP20 (BNB Smart Chain)',
        };
    }

    public function pauseScope(): CustomerUsdtFundingPauseScope
    {
        return match ($this) {
            self::BinancePay => CustomerUsdtFundingPauseScope::RailBinancePay,
            self::BscUsdt => CustomerUsdtFundingPauseScope::RailBscUsdt,
        };
    }
}
