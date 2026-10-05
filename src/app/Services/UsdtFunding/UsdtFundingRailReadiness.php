<?php

namespace App\Services\UsdtFunding;

use App\Enums\CustomerUsdtFundingRail;

/**
 * Readiness of one USDT funding rail for new targets. Implementations are
 * registered under the `usdt-funding-rail-readiness` container tag only after
 * the rail passes its H1/A1 qualification (H1 #539 is still open, so this
 * release registers none and funding fails closed).
 *
 * The observation MUST be local and non-blocking: the reservation service may
 * consult it inside its short database transaction, so a provider must keep a
 * cached/observed readiness state (refreshed by its own bounded job) and MUST
 * NOT perform network, RPC or provider I/O here.
 */
interface UsdtFundingRailReadiness
{
    /** Must match one CustomerUsdtFundingRail value. */
    public function railKey(): string;

    public function readiness(): UsdtFundingRailReadinessObservation;

    /**
     * Guard against a misregistered adapter claiming a rail it does not own.
     */
    public function assertRail(CustomerUsdtFundingRail $rail): void;
}
