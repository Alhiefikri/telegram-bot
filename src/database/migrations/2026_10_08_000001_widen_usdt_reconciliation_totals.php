<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PostgreSQL SUM(bigint) returns numeric, and reconciliation totals can
     * exceed PHP's integer range even when each underlying balance is valid.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::table('usdt_balance_reconciliation_runs', function (Blueprint $table): void {
                $table->decimal('liability_micro', 100, 0)->change();
                $table->decimal('unresolved_deposit_micro', 100, 0)->change();
                $table->decimal('custody_assets_micro', 100, 0)->nullable()->change();
                $table->decimal('movement_in_micro', 100, 0)->change();
                $table->decimal('movement_out_micro', 100, 0)->change();
            });

            return;
        }

        // SQLite has no fixed-width integer overflow for this persistence
        // boundary; keep its existing compatible schema for the test lane.
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::table('usdt_balance_reconciliation_runs', function (Blueprint $table): void {
                $table->bigInteger('liability_micro')->change();
                $table->bigInteger('unresolved_deposit_micro')->change();
                $table->bigInteger('custody_assets_micro')->nullable()->change();
                $table->bigInteger('movement_in_micro')->change();
                $table->bigInteger('movement_out_micro')->change();
            });
        }
    }
};
