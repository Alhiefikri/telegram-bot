<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE suppliers DROP CONSTRAINT supplier_money_guard');
            DB::statement("ALTER TABLE suppliers ADD CONSTRAINT supplier_money_guard CHECK (reserved_balance_micros >= 0 AND exchange_rate_idr > 0 AND version > 0 AND currency IN ('USD', 'USDT', 'IDR', 'VND') AND (provider NOT IN ('nastele', 'miraiapi') OR currency = 'VND') AND (currency <> 'VND' OR provider IN ('nastele', 'miraiapi')))");
        }
    }

    public function down(): void
    {
        if (DB::table('suppliers')->where('provider', 'miraiapi')->where('currency', 'VND')->exists()) {
            throw new RuntimeException('MiraiAPI VND connections prevent a lossless rollback.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE suppliers DROP CONSTRAINT supplier_money_guard');
            DB::statement("ALTER TABLE suppliers ADD CONSTRAINT supplier_money_guard CHECK (reserved_balance_micros >= 0 AND exchange_rate_idr > 0 AND version > 0 AND currency IN ('USD', 'USDT', 'IDR', 'VND') AND (provider <> 'nastele' OR currency = 'VND') AND (currency <> 'VND' OR provider = 'nastele'))");
        }
    }
};
