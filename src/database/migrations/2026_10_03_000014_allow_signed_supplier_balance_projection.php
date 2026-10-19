<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE suppliers DROP CONSTRAINT supplier_money_guard');
        }
        Schema::table('suppliers', fn (Blueprint $table) => $table->bigInteger('balance_micros')->nullable()->change());
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE suppliers ADD CONSTRAINT supplier_money_guard CHECK (reserved_balance_micros >= 0 AND exchange_rate_idr > 0 AND version > 0 AND currency IN ('USD', 'USDT', 'IDR'))");
        }
    }

    public function down(): void
    {
        // A rollback must never erase an observed deficit to restore the guard.
        if (DB::table('suppliers')->where('balance_micros', '<', 0)->exists()) {
            throw new RuntimeException('Reconcile supplier balance deficits before rolling back.');
        }
        Schema::table('suppliers', fn (Blueprint $table) => $table->unsignedBigInteger('balance_micros')->nullable()->change());
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE suppliers DROP CONSTRAINT supplier_money_guard');
            DB::statement("ALTER TABLE suppliers ADD CONSTRAINT supplier_money_guard CHECK ((balance_micros IS NULL OR balance_micros >= 0) AND reserved_balance_micros >= 0 AND exchange_rate_idr > 0 AND version > 0 AND currency IN ('USD', 'USDT', 'IDR'))");
        }
    }
};
