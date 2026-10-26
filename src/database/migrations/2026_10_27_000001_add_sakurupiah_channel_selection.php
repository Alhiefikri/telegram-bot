<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manual_payment_settings', function (Blueprint $table): void {
            $table->string('sakurupiah_channel', 16)->default('QRIS');
        });

        foreach (['payments', 'store_balance_top_ups'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('sakurupiah_channel', 16)->nullable();
            });

            // Payment records preserve an observed channel; old top-ups used a
            // generic QRIS label even though their create channel was QRISC.
            $historicalChannel = $name === 'payments'
                ? DB::raw("CASE WHEN UPPER(TRIM(provider_payment_method)) IN ('QRIS', 'QRISC', 'QRISMU', 'QRIS2') THEN UPPER(TRIM(provider_payment_method)) ELSE 'QRISC' END")
                : 'QRISC';
            DB::table($name)->where('provider', 'sakurupiah')->update([
                'sakurupiah_channel' => $historicalChannel,
            ]);
        }
    }

    public function down(): void
    {
        foreach (['payments', 'store_balance_top_ups', 'manual_payment_settings'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('sakurupiah_channel');
            });
        }
    }
};
