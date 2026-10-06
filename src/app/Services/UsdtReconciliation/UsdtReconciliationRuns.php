<?php

namespace App\Services\UsdtReconciliation;

use App\Models\UsdtBalanceReconciliationRun;
use App\Services\UsdtBalance\UsdtBalanceFreezeOutcome;
use Brick\Math\BigInteger;
use InvalidArgumentException;

/**
 * Persists sanitized, append-only evidence of one reconciliation run for
 * operational alerting (#558) and restore replay. The row carries exact
 * component totals and the scoped freeze outcomes; it never carries customer
 * identity, receipt evidence payloads, secrets or chain hashes.
 */
final class UsdtReconciliationRuns
{
    /**
     * @param  array<int, UsdtBalanceFreezeOutcome>  $freezeOutcomes  keyed by account id
     */
    public function record(
        UsdtReconciliationResult $result,
        array $freezeOutcomes = [],
        ?string $actor = null,
    ): UsdtBalanceReconciliationRun {
        if ($actor !== null && (trim($actor) === '' || mb_strlen($actor) > 191 || preg_match('/[^\x20-\x7E]/', $actor) === 1)) {
            throw new InvalidArgumentException('A reconciliation actor must be non-empty printable ASCII of at most 191 characters.');
        }

        $frozen = 0;
        foreach ($freezeOutcomes as $outcome) {
            if ($outcome === UsdtBalanceFreezeOutcome::Frozen) {
                $frozen++;
            }
        }

        return UsdtBalanceReconciliationRun::query()->create([
            'scope' => $result->scope->customerId === null ? 'full' : 'customer',
            'customer_id' => $result->scope->customerId,
            'cursor_after' => $result->scope->afterAccountId,
            'receipt_cursor_after' => $result->scope->afterReceiptId,
            'credit_cursor_after' => $result->scope->afterCreditId,
            'limit' => $result->scope->limit,
            'checked_at' => $result->checkedAt,
            'created_at' => now(),
            'verdict' => $result->verdict(),
            'accounts_inspected' => $result->accounts->accountsInspected,
            'account_finding_count' => count($result->accounts->findings),
            'drift_frozen_count' => $frozen,
            'receipt_finding_count' => count($result->receipts->findings),
            'liability_micro' => $result->treasury->ledgerLiabilityMicro->toInt(),
            'unresolved_deposit_micro' => $result->receipts->unresolvedDepositMicro->toInt(),
            'custody_state' => $result->treasury->custodyState,
            'custody_assets_micro' => $this->nullableInt($result->treasury->custodyAssetsMicro),
            'movement_in_micro' => $result->treasury->movementInMicro->toInt(),
            'movement_out_micro' => $result->treasury->movementOutMicro->toInt(),
            'movement_count' => $result->treasury->movementCount,
            'aggregate_pending_events' => $result->accounting->pendingEvents,
            'actor' => $actor,
            'findings' => $result->toArray($freezeOutcomes),
        ]);
    }

    private function nullableInt(?BigInteger $value): ?int
    {
        return $value?->toInt();
    }
}
