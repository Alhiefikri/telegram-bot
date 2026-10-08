<?php

namespace App\Services\UsdtFunding;

use App\Enums\CustomerUsdtFundingRail;
use App\Models\ManualPaymentSetting;
use App\Services\Binance\BinancePayClient;
use InvalidArgumentException;

/** Local, non-blocking gate for the configured Binance Pay account. */
final class BinancePayFundingReadiness implements UsdtFundingRailReadiness
{
    public function __construct(private readonly BinancePayClient $client) {}

    public function railKey(): string
    {
        return CustomerUsdtFundingRail::BinancePay->value;
    }

    public function readiness(): UsdtFundingRailReadinessObservation
    {
        if (! $this->client->configured()) {
            return UsdtFundingRailReadinessObservation::unavailable('binance_pay', 'binance_credentials_unavailable');
        }
        if (trim((string) config('services.binance.pay_id')) === '' && trim((string) config('services.binance.pay_uid')) === '') {
            return UsdtFundingRailReadinessObservation::unavailable('binance_pay', 'binance_receiver_unavailable');
        }
        if (ManualPaymentSetting::current()?->binance_pay_enabled !== true) {
            return UsdtFundingRailReadinessObservation::unavailable('binance_pay', 'binance_admin_disabled');
        }

        return UsdtFundingRailReadinessObservation::ready('binance_pay');
    }

    public function assertRail(CustomerUsdtFundingRail $rail): void
    {
        if ($rail !== CustomerUsdtFundingRail::BinancePay) {
            throw new InvalidArgumentException('Binance readiness cannot authorize another rail.');
        }
    }
}
