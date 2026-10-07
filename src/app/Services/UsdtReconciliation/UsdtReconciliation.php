<?php

namespace App\Services\UsdtReconciliation;

use App\Services\UsdtBalance\UsdtAccountingAggregator;
use App\Services\UsdtBalance\UsdtBalanceReconciliation;
use App\Services\UsdtBalance\UsdtBalanceReconciliationReport;
use App\Services\UsdtBalance\UsdtReceiptReconciliation;
use App\Services\UsdtBalance\UsdtReceiptReconciliationReport;
use App\Services\UsdtTreasury\UsdtTreasuryAssetRegistry;
use App\Services\UsdtTreasury\UsdtTreasuryReconciliation;
use Brick\Math\BigInteger;
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
            $accounts = $scope->complete
                ? $this->inspectAllAccounts($scope->limit)
                : $this->accounts->inspect($scope->customerId, $scope->afterAccountId, $scope->limit);
            $receipts = $scope->complete
                ? $this->inspectAllReceipts($scope->limit)
                : $this->receipts->inspect(
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

    private function inspectAllAccounts(int $limit): UsdtBalanceReconciliationReport
    {
        $cursor = null;
        $inspected = 0;
        $findings = [];
        do {
            $page = $this->accounts->inspect(null, $cursor, $limit);
            $inspected += $page->accountsInspected;
            foreach ($page->findings as $finding) {
                $findings[] = $finding;
            }
            $cursor = $page->nextCursor;
        } while ($page->hasMore);

        return new UsdtBalanceReconciliationReport($inspected, $findings, null, false);
    }

    private function inspectAllReceipts(int $limit): UsdtReceiptReconciliationReport
    {
        $receiptCursor = null;
        $creditCursor = null;
        $receiptsInspected = 0;
        $creditsInspected = 0;
        $findings = [];
        $unresolved = BigInteger::zero();
        $overcredited = BigInteger::zero();
        do {
            $page = $this->receipts->inspect(null, $receiptCursor, $creditCursor, $limit);
            $receiptsInspected += $page->receiptsInspected;
            $creditsInspected += $page->unreceiptedCreditsInspected;
            foreach ($page->findings as $finding) {
                $findings[] = $finding;
            }
            $unresolved = $unresolved->plus($page->unresolvedDepositMicro);
            $overcredited = $overcredited->plus($page->overcreditedMicro);
            // An exhausted facet must not restart at null while the other
            // continues. No PostgreSQL bigint id can exceed PHP_INT_MAX.
            $receiptCursor = $page->receiptsHasMore ? $page->nextReceiptCursor : PHP_INT_MAX;
            $creditCursor = $page->creditsHaveMore ? $page->nextCreditCursor : PHP_INT_MAX;
        } while ($page->receiptsHasMore || $page->creditsHaveMore);

        return new UsdtReceiptReconciliationReport(
            $receiptsInspected, $creditsInspected, $findings,
            null, null, false, false, $unresolved, $overcredited,
        );
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
