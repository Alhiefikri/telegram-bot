<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Schema;

/** Keeps the Owner adjustment screen inactive until the separate USDT ledger exists. */
final class BalanceAdjustmentDashboardReadiness
{
    private const REQUIRED_USDT_TABLES = [
        'customer_usdt_balance_accounts',
        'customer_usdt_balance_entries',
        'customer_usdt_balance_accounting_events',
    ];

    public function isReady(): bool
    {
        foreach (self::REQUIRED_USDT_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }
}
