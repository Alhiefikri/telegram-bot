<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'payments_pending_binance_auto_amount_unique';

    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->boolean('binance_auto_verification_enabled')->default(false)->after('method');
        });

        $predicate = DB::getDriverName() === 'pgsql'
            ? "provider = 'manual' AND method = 'BINANCE_PAY_ID' AND status = 'pending' AND binance_auto_verification_enabled IS TRUE"
            : "provider = 'manual' AND method = 'BINANCE_PAY_ID' AND status = 'pending' AND binance_auto_verification_enabled = 1";

        DB::statement('CREATE UNIQUE INDEX '.self::INDEX.' ON payments (amount) WHERE '.$predicate);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('binance_auto_verification_enabled');
        });
    }
};
