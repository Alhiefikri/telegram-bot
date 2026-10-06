<?php

namespace App\Services\StoreBalance;

use App\Enums\StoreBalanceEntryKind;
use App\Models\Customer;
use App\Models\CustomerBalanceAccount;

/**
 * Detects a Store Balance projection that no longer matches the sum of its
 * immutable ledger entries. It never changes money: a repair would have to be
 * an explicit, idempotent, audited operation of its own.
 */
final class StoreBalanceReconciliation
{
    public function inspect(?Customer $customer = null, int $limit = 100): StoreBalanceReconciliationReport
    {
        $accounts = CustomerBalanceAccount::query()
            ->select([
                'customer_balance_accounts.customer_id',
                'customer_balance_accounts.balance',
            ])
            ->selectSub(function ($query): void {
                $debits = StoreBalanceEntryKind::debitValues();
                $placeholders = implode(', ', array_fill(0, count($debits), '?'));
                $query->from('store_balance_entries')
                    ->selectRaw(
                        "COALESCE(SUM(CASE WHEN kind IN ({$placeholders}) THEN -amount ELSE amount END), 0)",
                        $debits,
                    )
                    ->whereColumn('store_balance_entries.customer_balance_account_id', 'customer_balance_accounts.id');
            }, 'ledger_balance')
            ->when($customer !== null, fn ($query) => $query->where('customer_balance_accounts.customer_id', $customer->id))
            ->orderBy('customer_balance_accounts.customer_id')
            ->limit(max(1, $limit))
            ->get();

        $mismatches = [];
        foreach ($accounts as $account) {
            $projected = (int) $account->balance;
            $ledger = (int) $account->ledger_balance;

            if ($projected !== $ledger) {
                $mismatches[] = new StoreBalanceProjectionMismatch(
                    (int) $account->customer_id,
                    $projected,
                    $ledger,
                );
            }
        }

        return new StoreBalanceReconciliationReport($accounts->count(), $mismatches);
    }
}
