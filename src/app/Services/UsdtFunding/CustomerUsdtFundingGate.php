<?php

namespace App\Services\UsdtFunding;

use App\Enums\CustomerUsdtFundingAvailabilityReason;
use App\Enums\CustomerUsdtFundingPauseScope;
use App\Enums\CustomerUsdtFundingRail;
use App\Exceptions\CustomerUsdtFundingUnavailable;
use App\Models\CustomerUsdtFundingPause;
use App\Models\CustomerUsdtFundingPolicy;
use App\Services\Operations\BalanceRestoreGateReader;

/**
 * Fail-closed availability gate for new Customer USDT funding targets and
 * USDT spending. A missing policy row, an Owner-disabled activation flag, a
 * funding/spending/rail pause, an open post-restore gate or an unregistered/
 * unready rail blocks the relevant action; nothing here blocks reconciliation,
 * settlement, refund or reversal of money that already entered the system.
 *
 * The reservation service calls the readiness registry inside its short
 * transaction, so rail readiness observations must be local and cheap (see
 * UsdtFundingRailReadiness). The pause checks are serialized against pause
 * writers through the policy row lock the reservation transaction already
 * holds.
 */
final class CustomerUsdtFundingGate
{
    public function __construct(
        private readonly UsdtFundingRailReadinessRegistry $readiness,
        private readonly BalanceRestoreGateReader $restoreGate,
    ) {}

    public function availability(CustomerUsdtFundingRail $rail): CustomerUsdtFundingAvailability
    {
        return $this->inspect($rail, CustomerUsdtFundingPolicy::current());
    }

    /**
     * Availability check used by the reservation transaction: the caller
     * already holds the policy row lock, so a concurrent Owner activation or
     * pause change cannot interleave between this check and the insert.
     */
    public function inspectForReservation(CustomerUsdtFundingRail $rail): CustomerUsdtFundingAvailability
    {
        return $this->inspect($rail, CustomerUsdtFundingPolicy::current());
    }

    public function spendingAvailability(): CustomerUsdtFundingAvailability
    {
        $policy = CustomerUsdtFundingPolicy::current();
        if ($policy === null) {
            return CustomerUsdtFundingAvailability::blocked(CustomerUsdtFundingAvailabilityReason::PolicyMissing);
        }

        if ($this->paused(CustomerUsdtFundingPauseScope::Spending)) {
            return CustomerUsdtFundingAvailability::blocked(CustomerUsdtFundingAvailabilityReason::SpendingPaused);
        }

        if ($this->restoreGate->isOpen()) {
            return CustomerUsdtFundingAvailability::blocked(CustomerUsdtFundingAvailabilityReason::RestoreGateOpen);
        }

        return CustomerUsdtFundingAvailability::available();
    }

    public function assertFundingAvailable(CustomerUsdtFundingRail $rail): void
    {
        $availability = $this->availability($rail);
        if (! $availability->available) {
            throw new CustomerUsdtFundingUnavailable($availability->reason, $availability->detail);
        }
    }

    public function assertSpendingAvailable(): void
    {
        $availability = $this->spendingAvailability();
        if (! $availability->available) {
            throw new CustomerUsdtFundingUnavailable($availability->reason, $availability->detail);
        }
    }

    private function inspect(CustomerUsdtFundingRail $rail, ?CustomerUsdtFundingPolicy $policy): CustomerUsdtFundingAvailability
    {
        if ($policy === null) {
            return CustomerUsdtFundingAvailability::blocked(CustomerUsdtFundingAvailabilityReason::PolicyMissing);
        }

        if (! $policy->fundingEnabled()) {
            return CustomerUsdtFundingAvailability::blocked(CustomerUsdtFundingAvailabilityReason::FundingDisabled);
        }

        if ($this->paused(CustomerUsdtFundingPauseScope::Funding)) {
            return CustomerUsdtFundingAvailability::blocked(CustomerUsdtFundingAvailabilityReason::FundingPaused);
        }

        if ($this->paused($rail->pauseScope())) {
            return CustomerUsdtFundingAvailability::blocked(CustomerUsdtFundingAvailabilityReason::RailPaused);
        }

        if ($this->restoreGate->isOpen()) {
            return CustomerUsdtFundingAvailability::blocked(CustomerUsdtFundingAvailabilityReason::RestoreGateOpen);
        }

        $observation = $this->readiness->observe($rail);
        if (! $observation->ready) {
            return CustomerUsdtFundingAvailability::blocked(
                $this->readiness->isRegistered($rail)
                    ? CustomerUsdtFundingAvailabilityReason::RailNotReady
                    : CustomerUsdtFundingAvailabilityReason::RailNotRegistered,
                $observation->reason,
            );
        }

        return CustomerUsdtFundingAvailability::available();
    }

    private function paused(CustomerUsdtFundingPauseScope $scope): bool
    {
        return CustomerUsdtFundingPause::query()->where('scope', $scope->value)->exists();
    }
}
