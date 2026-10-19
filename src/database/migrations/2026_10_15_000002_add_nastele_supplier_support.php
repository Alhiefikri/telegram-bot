<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', fn (Blueprint $table) => $table->decimal('exchange_rate_idr', 16, 6)->change());
        Schema::table('supplier_purchases', fn (Blueprint $table) => $table->decimal('exchange_rate_idr', 16, 6)->change());
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE suppliers DROP CONSTRAINT supplier_money_guard');
            DB::statement("ALTER TABLE suppliers ADD CONSTRAINT supplier_money_guard CHECK (reserved_balance_micros >= 0 AND exchange_rate_idr > 0 AND version > 0 AND currency IN ('USD', 'USDT', 'IDR', 'VND') AND (provider <> 'nastele' OR currency = 'VND') AND (currency <> 'VND' OR provider = 'nastele'))");
        }
    }

    public function down(): void
    {
        if (DB::table('suppliers')->where('currency', 'VND')->exists()
            || DB::table('suppliers')->whereRaw('exchange_rate_idr <> FLOOR(exchange_rate_idr)')->exists()
            || DB::table('supplier_purchases')->whereRaw('exchange_rate_idr <> FLOOR(exchange_rate_idr)')->exists()) {
            throw new RuntimeException('VND connections and fractional historical rates prevent a lossless rollback.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE suppliers DROP CONSTRAINT supplier_money_guard');
            DB::statement("ALTER TABLE suppliers ADD CONSTRAINT supplier_money_guard CHECK (reserved_balance_micros >= 0 AND exchange_rate_idr > 0 AND version > 0 AND currency IN ('USD', 'USDT', 'IDR'))");
        }
        Schema::table('supplier_purchases', fn (Blueprint $table) => $table->unsignedInteger('exchange_rate_idr')->change());
        Schema::table('suppliers', fn (Blueprint $table) => $table->unsignedInteger('exchange_rate_idr')->change());
    }
};
