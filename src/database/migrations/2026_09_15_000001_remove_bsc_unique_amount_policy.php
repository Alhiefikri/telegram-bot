<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const AMOUNT_INDEX = 'payments_pending_bsc_amount_unique';

    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS '.self::AMOUNT_INDEX);
        } elseif (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->dropUnique(self::AMOUNT_INDEX);
            });
        }

        if (Schema::hasColumn('manual_payment_settings', 'bsc_unique_amount_increment_micro')) {
            Schema::table('manual_payment_settings', function (Blueprint $table): void {
                $table->dropColumn('bsc_unique_amount_increment_micro');
            });
        }
    }

    public function down(): void
    {
        Schema::table('manual_payment_settings', function (Blueprint $table): void {
            $table->unsignedInteger('bsc_unique_amount_increment_micro')->default(100)->after('bsc_confirmation_grace_minutes');
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX '.self::AMOUNT_INDEX." ON payments (amount) WHERE provider = 'manual' AND method = 'USDT_BEP20' AND status = 'pending'");
        } elseif (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->unique('amount', self::AMOUNT_INDEX);
            });
        }
    }
};
