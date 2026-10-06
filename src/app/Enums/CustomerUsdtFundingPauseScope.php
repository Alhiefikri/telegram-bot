<?php

namespace App\Enums;

/**
 * Owner pause scopes for Customer USDT funding and balance delivery. Funding
 * and rail pauses block new funding targets only; spending pause blocks new
 * USDT checkout debits; delivery pause defers balance receipt messages. None
 * of them ever stops reconciliation, settlement, refund or reversal of money
 * that already entered the system.
 */
enum CustomerUsdtFundingPauseScope: string
{
    case Funding = 'funding';
    case Spending = 'spending';
    case RailBinancePay = 'rail_binance_pay';
    case RailBscUsdt = 'rail_bsc_usdt';
    case Delivery = 'delivery';

    public static function forRail(CustomerUsdtFundingRail $rail): self
    {
        return $rail->pauseScope();
    }

    public function rail(): ?CustomerUsdtFundingRail
    {
        return match ($this) {
            self::RailBinancePay => CustomerUsdtFundingRail::BinancePay,
            self::RailBscUsdt => CustomerUsdtFundingRail::BscUsdt,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Funding => 'Top-up USDT',
            self::Spending => 'Belanja USDT',
            self::RailBinancePay => 'Rail Binance Pay ID',
            self::RailBscUsdt => 'Rail USDT BEP20 (BSC)',
            self::Delivery => 'Pengiriman bukti saldo',
        };
    }
}
