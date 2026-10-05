<?php

namespace App\Services\UsdtReconciliation;

use App\Enums\UsdtReconciliationVerdict;
use App\Services\UsdtBalance\UsdtAccountingStatus;
use App\Services\UsdtBalance\UsdtBalanceFreezeOutcome;
use App\Services\UsdtBalance\UsdtBalanceReconciliationReport;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\UsdtReceiptReconciliationReport;
use App\Services\UsdtTreasury\UsdtTreasuryCoverageReport;
use DateTimeInterface;

/**
 * One bounded reconciliation result. It is pure evidence: component totals,
 * exact liability/unresolved amounts and findings. Freeze outcomes are
 * attached for the audit trail, and no component can repair or move money.
 */
final readonly class UsdtReconciliationResult
{
    public function __construct(
        public UsdtReconciliationScope $scope,
        public UsdtBalanceReconciliationReport $accounts,
        public UsdtReceiptReconciliationReport $receipts,
        public UsdtTreasuryCoverageReport $treasury,
        public UsdtAccountingStatus $accounting,
        public DateTimeInterface $checkedAt,
    ) {}

    public function hasFindings(): bool
    {
        return $this->accounts->findings !== []
            || $this->receipts->findings !== []
            || $this->treasury->isFinding()
            || ! $this->accounting->isConsistent();
    }

    public function verdict(): UsdtReconciliationVerdict
    {
        return $this->hasFindings()
            ? UsdtReconciliationVerdict::Findings
            : UsdtReconciliationVerdict::Consistent;
    }

    /**
     * @param  array<int, UsdtBalanceFreezeOutcome>  $freezeOutcomes  keyed by account id
     * @return array<string, mixed>
     */
    public function toArray(array $freezeOutcomes = []): array
    {
        $accounts = [];
        foreach ($this->accounts->findings as $finding) {
            $accounts[] = $finding->toArray() + [
                'freeze' => isset($freezeOutcomes[$finding->accountId])
                    ? $freezeOutcomes[$finding->accountId]->value
                    : null,
            ];
        }

        return [
            'currency' => UsdtMicroAmount::CURRENCY,
            'checked_at' => $this->checkedAt->format(DateTimeInterface::ATOM),
            'scope' => $this->scope->toArray(),
            'verdict' => $this->verdict()->value,
            'accounts' => [
                'inspected' => $this->accounts->accountsInspected,
                'next_cursor' => $this->accounts->nextCursor,
                'has_more' => $this->accounts->hasMore,
                'findings' => $accounts,
            ],
            'receipts' => [
                'inspected' => $this->receipts->receiptsInspected,
                'unreceipted_credits_inspected' => $this->receipts->unreceiptedCreditsInspected,
                'next_receipt_cursor' => $this->receipts->nextReceiptCursor,
                'next_credit_cursor' => $this->receipts->nextCreditCursor,
                'receipts_has_more' => $this->receipts->receiptsHasMore,
                'credits_have_more' => $this->receipts->creditsHaveMore,
                'unresolved_deposit_micro' => $this->receipts->unresolvedDepositMicro->toString(),
                'overcredited_micro' => $this->receipts->overcreditedMicro->toString(),
                'findings' => array_map(
                    static fn ($finding): array => $finding->toArray(),
                    $this->receipts->findings,
                ),
            ],
            'treasury' => $this->treasury->toArray(),
            'accounting' => $this->accounting->toArray(),
        ];
    }
}
