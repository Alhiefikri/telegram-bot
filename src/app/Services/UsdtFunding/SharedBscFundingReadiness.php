<?php

namespace App\Services\UsdtFunding;

use App\Enums\CustomerUsdtFundingRail;
use App\Models\UsdtSharedTopUpReceiver;
use App\Services\Bsc\BscReadiness;
use InvalidArgumentException;

/** Local, non-blocking readiness gate for the shared-address BSC rail. */
final class SharedBscFundingReadiness implements UsdtFundingRailReadiness
{
    public function railKey(): string
    {
        return CustomerUsdtFundingRail::BscShared->value;
    }

    public function readiness(): UsdtFundingRailReadinessObservation
    {
        $receiver = UsdtSharedTopUpReceiver::query()->active()->orderByDesc('id')->first();
        if ($receiver === null) {
            return UsdtFundingRailReadinessObservation::unavailable('bsc_shared', 'shared_receiver_unavailable');
        }

        if ((int) config('services.bsc.chain_id', 56) !== 56
            || strtolower((string) config('services.bsc.usdt_contract')) !== strtolower(BscReadiness::APPROVED_USDT_CONTRACT)
            || ! filled(config('services.bsc.rpc_urls'))) {
            return UsdtFundingRailReadinessObservation::unavailable('bsc_shared', 'bsc_configuration_unavailable');
        }

        if ((int) $receiver->chain_id !== 56
            || strtolower((string) $receiver->token_contract) !== strtolower(BscReadiness::APPROVED_USDT_CONTRACT)
            || (int) $receiver->token_decimals !== 18) {
            return UsdtFundingRailReadinessObservation::unavailable('bsc_shared', 'shared_receiver_network_or_token_mismatch');
        }

        return UsdtFundingRailReadinessObservation::ready('bsc_shared');
    }

    public function assertRail(CustomerUsdtFundingRail $rail): void
    {
        if ($rail !== CustomerUsdtFundingRail::BscShared) {
            throw new InvalidArgumentException('Shared BSC readiness cannot authorize another rail.');
        }
    }
}
