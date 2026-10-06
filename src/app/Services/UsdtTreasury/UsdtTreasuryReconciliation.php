<?php

namespace App\Services\UsdtTreasury;

use App\Enums\UsdtBalanceEntryKind;
use App\Enums\UsdtTreasuryAssetState;
use App\Enums\UsdtTreasuryCoverageVerdict;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;

/**
 * Liability and treasury coverage reconciliation. It compares the canonical
 * signed USDT subledger sum (never the mutable projection alone) plus
 * unresolved deposits against qualified custody assets reported by approved
 * adapters and audited treasury movements:
 *
 *     expected custody = liability + unresolved deposits + accounted fees + movements in - movements out
 *
 * A negative unexplained variance is a backing deficit, a positive one is an
 * unexplained surplus; both are findings. When no approved adapter exists the
 * verdict is unverifiable with a null asset amount, never zero. This service
 * never writes money and never signs, sends or reallocates custody funds.
 */
final class UsdtTreasuryReconciliation
{
    public function __construct(
        private UsdtTreasuryMovements $movements,
    ) {}

    /**
     * @param  list<UsdtTreasuryAssetObservation>  $custody  observations resolved outside any database transaction
     */
    public function inspect(
        ?int $customerId,
        BigInteger $unresolvedDepositMicro,
        BigInteger $overcreditedMicro,
        array $custody = [],
    ): UsdtTreasuryCoverageReport {
        $ledgerLiability = $this->signedLedgerSum($customerId);
        $projectedLiability = $this->projectedLiabilitySum($customerId);
        $totals = $this->movements->totals();
        $feeSum = DB::table('customer_usdt_top_up_fee_events as fees')
            ->join('customer_usdt_top_ups as targets', 'targets.id', '=', 'fees.top_up_id')
            ->when($customerId !== null, fn ($query) => $query->where('targets.customer_id', $customerId))
            ->sum('fees.amount_micro');
        $accountedFees = BigInteger::of((string) $feeSum);

        $incidents = DB::table('usdt_reorg_incidents')
            ->when($customerId !== null, fn ($query) => $query->where('customer_id', $customerId));
        $reorgExposure = BigInteger::of((string) (clone $incidents)->sum('exposure_micro'));
        $spentExposure = BigInteger::of((string) (clone $incidents)->sum('spent_exposure_micro'));
        $incidentCount = (clone $incidents)->count();
        $recoveryCount = DB::table('usdt_reorg_recovery_cases as cases')
            ->join('usdt_reorg_incidents as incidents', 'incidents.id', '=', 'cases.incident_id')
            ->when($customerId !== null, fn ($query) => $query->where('incidents.customer_id', $customerId))->count();
        // Authorizing a recovery case is not recovered money. Keep the entire
        // original exposure visible even when customer liability was spent.
        $reorgFindings = $incidentCount > 0 ? ['unresolved_reorg_loss'] : [];

        $values = ['reorgExposureMicro' => $reorgExposure, 'reorgSpentExposureMicro' => $spentExposure,
            'reorgIncidentCount' => $incidentCount, 'authorizedRecoveryCaseCount' => $recoveryCount,
            'customerId' => $customerId,
            'ledgerLiabilityMicro' => $ledgerLiability,
            'projectedLiabilityMicro' => $projectedLiability,
            'unresolvedDepositMicro' => $unresolvedDepositMicro,
            'overcreditedMicro' => $overcreditedMicro,
            'accountedFundingFeeMicro' => $accountedFees,
            'movementInMicro' => $totals->inMicro,
            'movementOutMicro' => $totals->outMicro,
            'movementCount' => $totals->movementCount,
            'custody' => $custody,
        ];

        if ($customerId !== null) {
            // Custody is a store-wide fact; a per-customer comparison would be
            // a fabricated number, so a scoped run reports liability only.
            return new UsdtTreasuryCoverageReport(
                ...$values,
                custodyState: $this->custodyState($custody),
                custodyAssetsMicro: $this->totalAvailableAssets($custody),
                unexplainedMicro: null,
                verdict: UsdtTreasuryCoverageVerdict::NotEvaluated,
                findings: $reorgFindings,
            );
        }

        $required = $ledgerLiability->plus($unresolvedDepositMicro)->plus($accountedFees);
        $custodyState = $this->custodyState($custody);
        $availableAssets = $this->totalAvailableAssets($custody);

        if ($required->isZero()) {
            return new UsdtTreasuryCoverageReport(
                ...$values,
                custodyState: $custodyState,
                custodyAssetsMicro: $availableAssets,
                unexplainedMicro: null,
                verdict: UsdtTreasuryCoverageVerdict::NotRequired,
                findings: $reorgFindings,
            );
        }

        if ($custodyState !== UsdtTreasuryAssetState::Available->value) {
            return new UsdtTreasuryCoverageReport(
                ...$values,
                custodyState: $custodyState,
                custodyAssetsMicro: null,
                unexplainedMicro: null,
                verdict: UsdtTreasuryCoverageVerdict::Unverifiable,
                findings: [...$reorgFindings, 'missing_custody_input'],
            );
        }

        $expected = $required->plus($totals->inMicro)->minus($totals->outMicro);
        $unexplained = $availableAssets->minus($expected);

        $verdict = match (true) {
            $unexplained->isZero() => UsdtTreasuryCoverageVerdict::Consistent,
            $unexplained->isNegative() => UsdtTreasuryCoverageVerdict::Deficit,
            default => UsdtTreasuryCoverageVerdict::Surplus,
        };

        return new UsdtTreasuryCoverageReport(
            ...$values,
            custodyState: $custodyState,
            custodyAssetsMicro: $availableAssets,
            unexplainedMicro: $unexplained,
            verdict: $verdict,
            findings: [...$reorgFindings, ...($verdict->isFinding() ? ['unexplained_variance'] : [])],
        );
    }

    private function signedLedgerSum(?int $customerId): BigInteger
    {
        $debits = UsdtBalanceEntryKind::debitValues();
        $placeholders = implode(', ', array_fill(0, count($debits), '?'));

        $row = DB::table('customer_usdt_balance_entries as entries')
            ->join('customer_usdt_balance_accounts as accounts', 'accounts.id', '=', 'entries.customer_usdt_balance_account_id')
            ->when($customerId !== null, fn ($query) => $query->where('accounts.customer_id', $customerId))
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN entries.kind IN ({$placeholders}) THEN -entries.amount_micro ELSE entries.amount_micro END), 0) as signed_sum",
                $debits,
            )
            ->first();

        return BigInteger::of((string) ($row->signed_sum ?? '0'));
    }

    private function projectedLiabilitySum(?int $customerId): BigInteger
    {
        $sum = DB::table('customer_usdt_balance_accounts')
            ->when($customerId !== null, fn ($query) => $query->where('customer_id', $customerId))
            ->sum('balance_micro');

        return $sum === null || $sum === '' ? BigInteger::zero() : BigInteger::of((string) $sum);
    }

    /**
     * @param  list<UsdtTreasuryAssetObservation>  $custody
     */
    private function totalAvailableAssets(array $custody): ?BigInteger
    {
        $total = BigInteger::zero();
        foreach ($custody as $observation) {
            if (! $observation->state->provesAssets()) {
                return null;
            }

            $total = $total->plus($observation->assetsMicro ?? BigInteger::zero());
        }

        return $custody === [] ? null : $total;
    }

    /**
     * @param  list<UsdtTreasuryAssetObservation>  $custody
     */
    private function custodyState(array $custody): ?string
    {
        if ($custody === []) {
            return 'missing';
        }

        $state = UsdtTreasuryAssetState::Available;
        foreach ($custody as $observation) {
            if ($observation->state === UsdtTreasuryAssetState::Unsupported) {
                return UsdtTreasuryAssetState::Unsupported->value;
            }

            if ($observation->state === UsdtTreasuryAssetState::Unavailable) {
                $state = UsdtTreasuryAssetState::Unavailable;
            }
        }

        return $state->value;
    }
}
