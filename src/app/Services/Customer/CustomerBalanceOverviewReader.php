<?php

namespace App\Services\Customer;

use App\Models\Customer;
use App\Services\StoreBalance\StoreBalanceSnapshot;
use App\Services\UsdtBalance\UsdtBalanceSnapshot;
use Illuminate\Support\Facades\DB;

final class CustomerBalanceOverviewReader
{
    public function forCustomer(Customer $customer): CustomerBalanceOverview
    {
        // Keep both projections and their immutable cursors in one database
        // snapshot; separate ledger reads could straddle a concurrent post.
        $row = DB::table('customers as customers')
            ->leftJoin('customer_balance_accounts as idr_accounts', 'idr_accounts.customer_id', '=', 'customers.id')
            ->leftJoin('customer_usdt_balance_accounts as usdt_accounts', 'usdt_accounts.customer_id', '=', 'customers.id')
            ->where('customers.id', $customer->getKey())
            ->select([
                'idr_accounts.balance as idr_balance',
                'usdt_accounts.balance_micro as usdt_balance_micro',
                'usdt_accounts.version as usdt_version',
                'usdt_accounts.frozen_reason as usdt_frozen_reason',
            ])
            ->selectSub(function ($query): void {
                $query->from('store_balance_entries')
                    ->select('balance_after')
                    ->whereColumn('store_balance_entries.customer_balance_account_id', 'idr_accounts.id')
                    ->orderByDesc('id')
                    ->limit(1);
            }, 'idr_latest_balance_after')
            ->selectSub(function ($query): void {
                $query->from('customer_usdt_balance_entries')
                    ->select('balance_after_micro')
                    ->whereColumn('customer_usdt_balance_entries.customer_usdt_balance_account_id', 'usdt_accounts.id')
                    ->orderByDesc('id')
                    ->limit(1);
            }, 'usdt_latest_balance_after')
            ->selectSub(function ($query): void {
                $query->from('customer_usdt_balance_entries')
                    ->select('account_sequence')
                    ->whereColumn('customer_usdt_balance_entries.customer_usdt_balance_account_id', 'usdt_accounts.id')
                    ->orderByDesc('id')
                    ->limit(1);
            }, 'usdt_latest_account_sequence')
            ->first();

        if ($row === null) {
            return new CustomerBalanceOverview(StoreBalanceSnapshot::unavailable(), UsdtBalanceSnapshot::unavailable());
        }

        $storeBalance = $this->storeBalanceSnapshot($row);
        $usdtBalance = $this->usdtBalanceSnapshot($row);

        return new CustomerBalanceOverview($storeBalance, $usdtBalance);
    }

    private function storeBalanceSnapshot(object $row): StoreBalanceSnapshot
    {
        if ($row->idr_balance === null) {
            return StoreBalanceSnapshot::available(0);
        }

        if ($row->idr_latest_balance_after === null) {
            return (int) $row->idr_balance === 0
                ? StoreBalanceSnapshot::available(0)
                : StoreBalanceSnapshot::unavailable();
        }

        return (int) $row->idr_latest_balance_after === (int) $row->idr_balance
            ? StoreBalanceSnapshot::available((int) $row->idr_balance)
            : StoreBalanceSnapshot::unavailable();
    }

    private function usdtBalanceSnapshot(object $row): UsdtBalanceSnapshot
    {
        if ($row->usdt_balance_micro === null) {
            return UsdtBalanceSnapshot::available(0);
        }

        if ($row->usdt_latest_balance_after === null) {
            return (int) $row->usdt_version === 0 && (int) $row->usdt_balance_micro === 0
                ? UsdtBalanceSnapshot::available(0, $row->usdt_frozen_reason)
                : UsdtBalanceSnapshot::unavailable();
        }

        if ((int) $row->usdt_latest_balance_after !== (int) $row->usdt_balance_micro
            || (int) $row->usdt_latest_account_sequence !== (int) $row->usdt_version) {
            return UsdtBalanceSnapshot::unavailable();
        }

        return UsdtBalanceSnapshot::available((int) $row->usdt_balance_micro, $row->usdt_frozen_reason);
    }
}
