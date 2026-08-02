<?php

namespace App\Services\Binance;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BinanceOrderIdActivation
{
    private const AMOUNT_INDEX = 'payments_pending_binance_auto_amount_unique';

    public function activate(): void
    {
        // Production uses PostgreSQL, where an explicit transaction keeps the
        // readiness lock and index switch atomic even when invoked directly
        // from a migration. SQLite's test driver cannot safely run this DDL
        // inside a nested transaction, so its surrounding test transaction is
        // intentionally used as-is.
        if (DB::getDriverName() !== 'sqlite' && DB::transactionLevel() === 0) {
            DB::transaction(function (): void {
                $this->activateWithinTransaction();
            }, 5);

            return;
        }

        $this->activateWithinTransaction();
    }

    private function activateWithinTransaction(): void
    {
        $activeSettings = DB::table('manual_payment_settings')
            ->where('is_active', true)
            ->lockForUpdate()
            ->get();
        $ready = $activeSettings->contains(fn (object $setting): bool => $setting->binance_order_id_compatibility_ready_at !== null);

        if (! $ready) {
            throw new RuntimeException('Binance Order ID activation requires the compatibility readiness marker.');
        }

        $duplicates = DB::table('payments')
            ->where('provider', 'manual')
            ->where('method', 'BINANCE_PAY_ID')
            ->where('status', 'pending')
            ->where('binance_auto_verification_enabled', true)
            ->where('binance_verification_mode', 'amount_unique')
            ->select('amount')
            ->groupBy('amount')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            throw new RuntimeException('Cannot activate Binance Order ID mode while duplicate legacy Binance amounts exist.');
        }

        DB::statement('DROP INDEX IF EXISTS '.self::AMOUNT_INDEX);

        $predicate = DB::getDriverName() === 'pgsql'
            ? "provider = 'manual' AND method = 'BINANCE_PAY_ID' AND status = 'pending' AND binance_auto_verification_enabled IS TRUE AND binance_verification_mode = 'amount_unique'"
            : "provider = 'manual' AND method = 'BINANCE_PAY_ID' AND status = 'pending' AND binance_auto_verification_enabled = 1 AND binance_verification_mode = 'amount_unique'";

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.self::AMOUNT_INDEX.' ON payments (amount) WHERE '.$predicate);
        DB::table('manual_payment_settings')
            ->where('is_active', true)
            ->update(['binance_verification_mode' => 'order_id']);
    }
}
