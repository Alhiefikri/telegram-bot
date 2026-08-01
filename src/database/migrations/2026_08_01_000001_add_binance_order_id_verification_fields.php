<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const AMOUNT_INDEX = 'payments_pending_binance_auto_amount_unique';

    private const ORDER_ID_INDEX = 'payments_binance_order_lookup';

    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('binance_order_id')->nullable()->after('binance_auto_verification_enabled');
            $table->unsignedTinyInteger('binance_order_id_attempts')->default(0)->after('binance_order_id');
            $table->timestamp('binance_order_id_submitted_at')->nullable()->after('binance_order_id_attempts');
            $table->timestamp('binance_verification_grace_until')->nullable()->after('binance_order_id_submitted_at');
            $table->index(['provider', 'method', 'status', 'binance_order_id'], self::ORDER_ID_INDEX);
        });

        DB::statement('DROP INDEX IF EXISTS '.self::AMOUNT_INDEX);
    }

    public function down(): void
    {
        $hasDuplicatePendingAmounts = DB::table('payments')
            ->where('provider', 'manual')
            ->where('method', 'BINANCE_PAY_ID')
            ->where('status', 'pending')
            ->where('binance_auto_verification_enabled', true)
            ->select('amount')
            ->groupBy('amount')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicatePendingAmounts) {
            throw new \RuntimeException('Cannot roll back Binance Order ID migration while duplicate pending Binance amounts exist.');
        }

        DB::statement('DROP INDEX IF EXISTS '.self::ORDER_ID_INDEX);

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn([
                'binance_order_id',
                'binance_order_id_attempts',
                'binance_order_id_submitted_at',
                'binance_verification_grace_until',
            ]);
        });

        $predicate = DB::getDriverName() === 'pgsql'
            ? "provider = 'manual' AND method = 'BINANCE_PAY_ID' AND status = 'pending' AND binance_auto_verification_enabled IS TRUE"
            : "provider = 'manual' AND method = 'BINANCE_PAY_ID' AND status = 'pending' AND binance_auto_verification_enabled = 1";

        DB::statement('CREATE UNIQUE INDEX '.self::AMOUNT_INDEX.' ON payments (amount) WHERE '.$predicate);
    }
};
