<?php

namespace App\Services\Operations;

use App\Enums\CustomerUsdtFundingRail;
use App\Enums\UsdtReconciliationVerdict;
use App\Models\CustomerUsdtFundingPolicy;
use App\Models\UsdtBalanceReconciliationRun;
use App\Services\MoneyReceipts\ExternalMoneyReceiptCutoverReport;
use App\Services\UsdtFunding\CustomerUsdtFundingGate;
use App\Services\UsdtFunding\UsdtFundingRailReadinessRegistry;

/**
 * Read-only release gate for the IDR/Customer USDT balance rollout.
 *
 * It joins current database evidence with the versioned, human-owned H0/H1/A1
 * decisions. A closed tracker issue is deliberately not treated as authority
 * to accept customer funds: unresolved custody, legal, token provenance, or
 * provider evidence remains a NO-GO here until its record is replaced.
 */
final class BalanceReleaseReadinessReport
{
    public function __construct(
        private readonly ExternalMoneyReceiptCutoverReport $receiptCutover,
        private readonly BalanceRestoreGateReader $restoreGate,
        private readonly CustomerUsdtFundingGate $fundingGate,
        private readonly UsdtFundingRailReadinessRegistry $railReadiness,
        private readonly BalanceCandidateReleaseEvidence $candidateEvidence,
    ) {}

    /** @return array<string, mixed> */
    public function report(int $maxReconciliationAgeMinutes = 1440, ?string $candidateSha = null, ?string $evidenceManifest = null): array
    {
        $cutover = $this->receiptCutover->summary();
        $reconciliation = $this->latestCompleteFullReconciliation($maxReconciliationAgeMinutes);
        $restore = $this->restoreGate->status();
        $bscFunding = $this->fundingGate->availability(CustomerUsdtFundingRail::BscShared);
        $bscReadiness = $this->railReadiness->observe(CustomerUsdtFundingRail::BscShared);
        $policy = CustomerUsdtFundingPolicy::current();
        $releaseEvidence = $this->candidateEvidence->inspect($candidateSha, $evidenceManifest);

        $checks = [
            $this->check(
                'money_receipt_registry',
                $cutover['ready'] === true ? 'pass' : 'blocked',
                $cutover['ready'] === true
                    ? 'All registered external-money writers have complete backfill and no open reservation.'
                    : 'Receipt backfill, writer cutover, or an open money reservation still needs resolution.',
                [
                    'ready' => $cutover['ready'],
                    'open_reservations' => $cutover['reservations']['open'],
                    'writers' => $cutover['writers'],
                ],
            ),
            $this->check(
                'full_reconciliation',
                $reconciliation === null ? 'blocked' : 'pass',
                $reconciliation === null
                    ? 'No complete, consistent, and recent full reconciliation evidence is recorded.'
                    : 'The latest complete full reconciliation has no ledger, receipt, accounting, or treasury finding.',
                $reconciliation ?? ['run_id' => null],
            ),
            $this->check(
                'restore_gate',
                $restore['open'] === false ? 'pass' : 'blocked',
                $restore['open'] === false
                    ? 'The post-restore gate is cleared.'
                    : 'A restore window is open; funding and spending must remain blocked.',
                ['state' => $restore['state'], 'opened_at' => $restore['opened_at']],
            ),
            $this->check(
                'release_evidence',
                $releaseEvidence['ready'] ? 'pass' : 'blocked',
                $releaseEvidence['ready']
                    ? 'All required verification results match the candidate and retained artifacts.'
                    : 'Candidate verification evidence is missing, failed, mismatched, or incomplete.',
                $releaseEvidence,
            ),
            $this->check(
                'idempotent_idr_adjustment_control',
                'accepted_exception',
                'H0-v1 accepts the documented single-Owner per-action-password control; a second approver is not configured for this release.',
                ['decision' => 'docs/balance-h0-operational-decisions.md', 'adr' => 'docs/adr/0020-single-owner-balance-adjustments.md'],
            ),
            $this->check(
                'beta_allowlist',
                'not_applicable',
                'H0-v1 explicitly selects all registered bot Customers and no beta allowlist or business quota.',
                ['decision' => 'docs/balance-h0-operational-decisions.md'],
            ),
            $this->check(
                'primary_bsc_activation',
                'blocked',
                'Primary BSC customer funding remains NO-GO until H1 custody, legal, token provenance, receiver capacity, and treasury evidence are qualified.',
                [
                    'funding_available' => $bscFunding->available,
                    'funding_reason' => $bscFunding->reason->value,
                    'funding_detail' => $bscFunding->detail,
                    'readiness_registered' => $this->railReadiness->isRegistered(CustomerUsdtFundingRail::BscShared),
                    'readiness_ready' => $bscReadiness->ready,
                    'readiness_reason' => $bscReadiness->reason,
                    'funding_enabled' => $policy?->fundingEnabled(),
                    'qualification_gates' => [
                        'exclusive_receiver_custody' => 'unqualified',
                        'token_provenance_and_redemption' => 'unqualified',
                        'finality_and_canonical_receipt' => 'rpc_capability_verified_but_provider_qualification_pending',
                        'provider_attribution' => 'unqualified',
                        'legal_and_customer_funds' => 'unqualified',
                        'treasury_backing_and_gas' => 'unqualified',
                    ],
                    'decision' => 'docs/balance-h1-custody-decision.md',
                    'capability_report' => 'docs/balance-a1-bsc-capability.md',
                ],
            ),
            $this->check(
                'optional_binance_funding',
                'disabled',
                'Binance funding is intentionally disabled because the required beneficiary/reference attribution contract is unsupported; it does not block a qualified primary BSC rail.',
                ['capability_report' => 'docs/balance-a1-bsc-capability.md'],
            ),
        ];

        $blocking = array_values(array_map(
            static fn (array $check): string => $check['key'],
            array_filter($checks, static fn (array $check): bool => $check['status'] === 'blocked'),
        ));

        return [
            'generated_at' => now()->utc()->toIso8601String(),
            'candidate_sha' => $releaseEvidence['candidate_sha'],
            'release_ready' => $blocking === [],
            'blocking_checks' => $blocking,
            'checks' => $checks,
            'next_operator_gate' => 'Issue #562: no deploy, customer funding, or spending activation is performed by this command.',
        ];
    }

    /** @return array{key: string, status: string, summary: string, evidence: array<string, mixed>} */
    private function check(string $key, string $status, string $summary, array $evidence): array
    {
        return compact('key', 'status', 'summary', 'evidence');
    }

    /** @return array<string, mixed>|null */
    private function latestCompleteFullReconciliation(int $maxAgeMinutes): ?array
    {
        $run = UsdtBalanceReconciliationRun::query()
            ->where('scope', 'full')
            ->latest('id')
            ->first();

        if ($run === null
            || $run->verdict !== UsdtReconciliationVerdict::Consistent
            || $run->checked_at === null
            || $run->checked_at->lt(now()->subMinutes($maxAgeMinutes))) {
            return null;
        }

        $findings = is_array($run->findings) ? $run->findings : [];
        $scope = $findings['scope'] ?? null;
        $accounts = $findings['accounts'] ?? null;
        $receipts = $findings['receipts'] ?? null;
        $treasury = $findings['treasury'] ?? null;
        $accounting = $findings['accounting'] ?? null;

        if (! is_array($scope) || ($scope['complete'] ?? false) !== true
            || ! is_array($accounts) || ! is_array($receipts) || ! is_array($treasury) || ! is_array($accounting)
            || ($accounts['has_more'] ?? true) !== false
            || ($receipts['receipts_has_more'] ?? true) !== false
            || ($receipts['credits_have_more'] ?? true) !== false
            || ($accounting['consistent'] ?? false) !== true
            || ! in_array((string) ($treasury['verdict'] ?? ''), ['consistent', 'not_required'], true)) {
            return null;
        }

        return [
            'run_id' => $run->id,
            'checked_at' => $run->checked_at?->toIso8601String(),
            'max_age_minutes' => $maxAgeMinutes,
            'accounts_inspected' => $run->accounts_inspected,
            'liability_micro' => (string) $run->liability_micro,
            'unresolved_deposit_micro' => (string) $run->unresolved_deposit_micro,
            'custody_state' => $run->custody_state,
        ];
    }
}
