<?php

namespace App\Services\UsdtReconciliation;

use App\Services\UsdtBalance\UsdtAccountingAggregator;
use App\Services\UsdtBalance\UsdtBalanceReconciliation;
use App\Services\UsdtBalance\UsdtReceiptReconciliation;
use App\Services\UsdtTreasury\UsdtTreasuryAssetRegistry;
use App\Services\UsdtTreasury\UsdtTreasuryReconciliation;
use Illuminate\Support\Facades\DB;

/**
 * Coordinates one bounded USDT reconciliation. Every component read runs in a
 * single read-only repeatable-read database snapshot on PostgreSQL, so a
 * concurrent posting can never be observed as a false projection/cursor drift
 * or as liability that does not match its receipts. The coordinator only
 * reads: freezing drifted accounts, recording evidence and operator decisions
 * happen outside it.
 */
final class UsdtReconciliation
{
    public function __construct(
        private UsdtBalanceReconciliation $accounts,
        private UsdtReceiptReconciliation $receipts,
        private UsdtAccountingAggregator $accounting,
        private UsdtTreasuryReconciliation $treasury,
        private UsdtTreasuryAssetRegistry $treasuryAssets,
    ) {}

    public function run(UsdtReconciliationScope $scope): UsdtReconciliationResult
    {
        // Custody observations may perform provider or RPC I/O; they are
        // resolved before the database snapshot opens so no external call
        // ever extends a transaction.
        $custody = $this->treasuryAssets->observeAll();

        return $this->duringConsistentSnapshot(function () use ($scope, $custody): UsdtReconciliationResult {
            $accounts = $this->accounts->inspect($scope->customerId, $scope->afterAccountId, $scope->limit);
            $receipts = $this->receipts->inspect(
                $scope->customerId,
                $scope->afterReceiptId,
                $scope->afterCreditId,
                $scope->limit,
            );
            $accounting = $this->accounting->status(UsdtAccountingAggregator::DEFAULT_CONSUMER);
            $treasury = $this->treasury->inspect(
                $scope->customerId,
                $receipts->unresolvedDepositMicro,
                $receipts->overcreditedMicro,
                $custody,
            );

            return new UsdtReconciliationResult(
                scope: $scope,
                accounts: $accounts,
                receipts: $receipts,
                treasury: $treasury,
                accounting: $accounting,
                checkedAt: now(),
            );
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function duringConsistentSnapshot(callable $callback)
    {
        return DB::transaction(function () use ($callback) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }

            return $callback();
        }, 1);
    }
}
