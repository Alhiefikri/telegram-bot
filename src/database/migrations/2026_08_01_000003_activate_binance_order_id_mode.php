<?php

use App\Services\Binance\BinanceOrderIdActivation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const AMOUNT_INDEX = 'payments_pending_binance_auto_amount_unique';

    public function up(): void
    {
        $ready = DB::table('manual_payment_settings')
            ->whereNotNull('binance_order_id_compatibility_ready_at')
            ->exists();

        if (! $ready) {
            // Release A may apply this migration before the live canary. Keep
            // the legacy amount_unique index and let the readiness command
            // perform activation after the canary succeeds.
            return;
        }

        app(BinanceOrderIdActivation::class)->activate();
    }

    public function down(): void
    {
        $duplicates = DB::table('payments')
            ->where('provider', 'manual')
            ->where('method', 'BINANCE_PAY_ID')
            ->where('status', 'pending')
            ->where('binance_auto_verification_enabled', true)
            ->where('binance_verification_mode', 'order_id')
            ->select('amount')
            ->groupBy('amount')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            throw new RuntimeException('Cannot roll back Binance Order ID activation while duplicate order_id amounts exist.');
        }

        DB::statement('DROP INDEX IF EXISTS '.self::AMOUNT_INDEX);
        DB::table('manual_payment_settings')
            ->where('is_active', true)
            ->update([
                'binance_verification_mode' => 'amount_unique',
                'binance_order_id_compatibility_ready_at' => null,
            ]);

        $predicate = DB::getDriverName() === 'pgsql'
            ? "provider = 'manual' AND method = 'BINANCE_PAY_ID' AND status = 'pending' AND binance_auto_verification_enabled IS TRUE"
            : "provider = 'manual' AND method = 'BINANCE_PAY_ID' AND status = 'pending' AND binance_auto_verification_enabled = 1";

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.self::AMOUNT_INDEX.' ON payments (amount) WHERE '.$predicate);
    }
};
