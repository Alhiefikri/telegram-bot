<?php

namespace App\Services\UsdtTreasury;

use App\Enums\UsdtTreasuryCoverageVerdict;
use App\Services\UsdtBalance\UsdtMicroAmount;
use Brick\Math\BigInteger;

/**
 * Read-only coverage report for Customer USDT liability plus unresolved
 * deposits against qualified custody assets and audited treasury movements.
 * The custody state is explicit: `available` carries proven exact assets,
 * `unavailable`/`unsupported` mean nothing could be proven, and `missing`
 * means no approved adapter exists — none of them is reported as zero
 * backing. Deficit, surplus and unverifiable verdicts are findings.
 */
final readonly class UsdtTreasuryCoverageReport
{
    /**
     * @param  list<UsdtTreasuryAssetObservation>  $custody
     * @param  list<string>  $findings
     */
    public function __construct(
        public ?int $customerId,
        public BigInteger $ledgerLiabilityMicro,
        public BigInteger $projectedLiabilityMicro,
        public BigInteger $unresolvedDepositMicro,
        public BigInteger $overcreditedMicro,
        public BigInteger $accountedFundingFeeMicro,
        public BigInteger $movementInMicro,
        public BigInteger $movementOutMicro,
        public int $movementCount,
        public array $custody,
        public ?string $custodyState,
        public ?BigInteger $custodyAssetsMicro,
        public ?BigInteger $unexplainedMicro,
        public UsdtTreasuryCoverageVerdict $verdict,
        public array $findings,
        public ?BigInteger $reorgExposureMicro = null,
        public ?BigInteger $reorgSpentExposureMicro = null,
        public int $reorgIncidentCount = 0,
        public int $authorizedRecoveryCaseCount = 0,
    ) {}

    public function isFinding(): bool
    {
        return $this->findings !== [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'currency' => UsdtMicroAmount::CURRENCY,
            'customer_id' => $this->customerId,
            'ledger_liability_micro' => $this->ledgerLiabilityMicro->toString(),
            'projected_liability_micro' => $this->projectedLiabilityMicro->toString(),
            'unresolved_deposit_micro' => $this->unresolvedDepositMicro->toString(),
            'overcredited_micro' => $this->overcreditedMicro->toString(),
            'reorg_exposure_micro' => ($this->reorgExposureMicro ?? BigInteger::zero())->toString(),
            'reorg_spent_exposure_micro' => ($this->reorgSpentExposureMicro ?? BigInteger::zero())->toString(),
            'reorg_incident_count' => $this->reorgIncidentCount,
            'authorized_recovery_case_count' => $this->authorizedRecoveryCaseCount,
            'accounted_funding_fee_micro' => $this->accountedFundingFeeMicro->toString(),
            'movement_in_micro' => $this->movementInMicro->toString(),
            'movement_out_micro' => $this->movementOutMicro->toString(),
            'movement_count' => $this->movementCount,
            'custody_state' => $this->custodyState,
            'custody_assets_micro' => $this->custodyAssetsMicro?->toString(),
            'unexplained_micro' => $this->unexplainedMicro?->toString(),
            'verdict' => $this->verdict->value,
            'findings' => $this->findings,
            'custody' => array_map(
                static fn (UsdtTreasuryAssetObservation $observation): array => $observation->toArray(),
                $this->custody,
            ),
        ];
    }
}
