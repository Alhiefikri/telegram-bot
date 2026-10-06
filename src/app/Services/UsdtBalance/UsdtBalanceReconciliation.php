<?php

namespace App\Services\UsdtBalance;

use App\Enums\UsdtBalanceDriftIssue;
use App\Enums\UsdtBalanceEntryKind;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Bounded, read-only detection of a Customer USDT Balance whose projection
 * cursor and immutable signed ledger history do not agree exactly. It reads
 * the projection, the signed sum of every entry, the entry count and the
 * newest entry in one SQL statement, so a concurrent posting can never be
 * observed as a real divergence. Findings are evidence: repair and money
 * writes stay explicit operator decisions.
 */
final class UsdtBalanceReconciliation
{
    public const MAX_LIMIT = 1000;

    public function inspect(?int $customerId, ?int $afterAccountId, int $limit): UsdtBalanceReconciliationReport
    {
        $this->assertInputs($customerId, $afterAccountId, $limit);

        $rows = $this->accountQuery($customerId, $afterAccountId, limit: $limit + 1)
            ->orderBy('accounts.id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        if ($hasMore) {
            $rows = $rows->slice(0, $limit)->values();
        }

        $findings = [];
        foreach ($rows as $row) {
            $finding = $this->assess($row);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return new UsdtBalanceReconciliationReport(
            accountsInspected: $rows->count(),
            findings: $findings,
            nextCursor: $hasMore ? (int) $rows->last()->account_id : null,
            hasMore: $hasMore,
        );
    }

    /**
     * Re-read one account exactly. Callers use this under the account row lock
     * (the same lock every ledger posting takes), so a drift finding can never
     * be based on a half-written posting.
     */
    public function inspectAccount(int $accountId): ?UsdtBalanceDriftFinding
    {
        $row = $this->accountQuery(accountId: $accountId)->first();

        return $row === null ? null : $this->assess($row);
    }

    private function accountQuery(?int $customerId = null, ?int $afterAccountId = null, ?int $accountId = null, ?int $limit = null)
    {
        // Bound the input history as well as the output page. Applying LIMIT
        // only after global grouped joins repeatedly reads unrelated accounts.
        // Both copies of this scope share the same SQL snapshot, so an active
        // writer still cannot manufacture a reconciliation finding.
        $scope = DB::table('customer_usdt_balance_accounts')
            ->when($customerId !== null, fn ($query) => $query->where('customer_id', $customerId))
            ->when($afterAccountId !== null, fn ($query) => $query->where('id', '>', $afterAccountId))
            ->when($accountId !== null, fn ($query) => $query->where('id', $accountId))
            ->orderBy('id')
            ->when($limit !== null, fn ($query) => $query->limit($limit));
        $debits = UsdtBalanceEntryKind::debitValues();
        $placeholders = implode(', ', array_fill(0, count($debits), '?'));

        $entriesForAccount = fn () => DB::table('customer_usdt_balance_entries')
            ->whereColumn('customer_usdt_balance_account_id', 'accounts.id');
        $base = DB::query()->fromSub($scope, 'accounts');
        $accountColumns = [
            'accounts.id as account_id',
            'accounts.customer_id',
            'accounts.balance_micro',
            'accounts.version',
            'accounts.last_entry_id',
            'accounts.frozen_reason',
        ];

        if (DB::getDriverName() === 'pgsql') {
            // Make the account key an outer parameter in PostgreSQL's plan.
            // A correlated WHERE IN was decorrelated into a full ledger scan.
            $ledger = $entriesForAccount()->selectRaw(
                "COUNT(*) as entry_count, MIN(account_sequence) as min_sequence, MAX(account_sequence) as max_sequence, SUM(CASE WHEN kind IN ({$placeholders}) THEN -amount_micro ELSE amount_micro END) as signed_sum",
                $debits,
            );
            $latest = $entriesForAccount()->select([
                'id as latest_entry_id',
                'account_sequence as latest_sequence',
                'balance_after_micro as latest_balance_after',
            ])->orderByDesc('id')->limit(1);

            return $base->joinLateral($ledger, 'ledger')
                ->leftJoinLateral($latest, 'latest')
                ->select([
                    ...$accountColumns,
                    'ledger.entry_count',
                    'ledger.min_sequence',
                    'ledger.max_sequence',
                    'ledger.signed_sum',
                    'latest.latest_entry_id',
                    'latest.latest_sequence',
                    'latest.latest_balance_after',
                ]);
        }

        // SQLite retains the one-statement snapshot with correlated
        // projections; PostgreSQL uses LATERAL to prevent aggregate flattening.
        $entries = $entriesForAccount();
        $latest = (clone $entries)->orderByDesc('id')->limit(1);

        return $base->select($accountColumns)
            ->selectSub((clone $entries)->selectRaw('COUNT(*)'), 'entry_count')
            ->selectSub((clone $entries)->selectRaw('MIN(account_sequence)'), 'min_sequence')
            ->selectSub((clone $entries)->selectRaw('MAX(account_sequence)'), 'max_sequence')
            ->selectSub((clone $entries)->selectRaw("SUM(CASE WHEN kind IN ({$placeholders}) THEN -amount_micro ELSE amount_micro END)", $debits), 'signed_sum')
            ->selectSub((clone $latest)->select('id'), 'latest_entry_id')
            ->selectSub((clone $latest)->select('account_sequence'), 'latest_sequence')
            ->selectSub((clone $latest)->select('balance_after_micro'), 'latest_balance_after');
    }

    private function assess(object $row): ?UsdtBalanceDriftFinding
    {
        $version = (int) $row->version;
        $balance = (int) $row->balance_micro;
        $lastEntryId = $row->last_entry_id === null ? null : (int) $row->last_entry_id;
        $entryCount = (int) ($row->entry_count ?? 0);
        $minSequence = $row->min_sequence === null ? null : (int) $row->min_sequence;
        $maxSequence = $row->max_sequence === null ? null : (int) $row->max_sequence;
        $signedSum = $row->signed_sum === null ? 0 : (int) $row->signed_sum;
        $latestEntryId = $row->latest_entry_id === null ? null : (int) $row->latest_entry_id;
        $latestSequence = $row->latest_sequence === null ? null : (int) $row->latest_sequence;
        $latestBalanceAfter = $row->latest_balance_after === null ? null : (int) $row->latest_balance_after;

        $issues = [];

        if ($balance !== $signedSum) {
            $issues[] = UsdtBalanceDriftIssue::BalanceMismatch;
        }

        $cursorConsistent = $version === 0
            ? $latestEntryId === null && $lastEntryId === null && $balance === 0
            : $latestEntryId !== null
                && $lastEntryId === $latestEntryId
                && $latestSequence === $version
                && $latestBalanceAfter === $balance;
        if (! $cursorConsistent) {
            $issues[] = UsdtBalanceDriftIssue::CursorMismatch;
        }

        $historyConsistent = $version === 0
            ? $entryCount === 0 && $lastEntryId === null
            : $entryCount === $version && $maxSequence === $version && $minSequence === 1;
        if (! $historyConsistent) {
            $issues[] = UsdtBalanceDriftIssue::HistoryGap;
        }

        if ($issues === []) {
            return null;
        }

        return new UsdtBalanceDriftFinding(
            accountId: (int) $row->account_id,
            customerId: (int) $row->customer_id,
            projectedMicro: $balance,
            ledgerMicro: $signedSum,
            version: $version,
            lastEntryId: $lastEntryId,
            entryCount: $entryCount,
            latestSequence: $latestSequence,
            latestBalanceAfterMicro: $latestBalanceAfter,
            issues: $issues,
        );
    }

    private function assertInputs(?int $customerId, ?int $afterAccountId, int $limit): void
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('USDT balance reconciliation limit must be between 1 and '.self::MAX_LIMIT.'.');
        }

        if ($customerId !== null && $customerId <= 0) {
            throw new InvalidArgumentException('USDT balance reconciliation customer id must be a positive integer.');
        }

        if ($afterAccountId !== null && $afterAccountId < 0) {
            throw new InvalidArgumentException('USDT balance reconciliation cursor must not be negative.');
        }
    }
}
