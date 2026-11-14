<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE suppliers DROP CONSTRAINT supplier_money_guard');
            DB::statement("ALTER TABLE suppliers ADD CONSTRAINT supplier_money_guard CHECK (reserved_balance_micros >= 0 AND exchange_rate_idr > 0 AND version > 0 AND currency IN ('USD', 'USDT', 'IDR', 'VND') AND (provider NOT IN ('nastele', 'miraiapi') OR currency = 'VND') AND (currency <> 'VND' OR provider IN ('nastele', 'miraiapi')) AND (provider <> 'akunding' OR currency = 'USDT'))");
        }
    }

    public function down(): void
    {
        if (DB::table('suppliers')->where('provider', 'akunding')->exists()) {
            throw new RuntimeException('Akunding supplier connections prevent a lossless rollback.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE suppliers DROP CONSTRAINT supplier_money_guard');
            DB::statement("ALTER TABLE suppliers ADD CONSTRAINT supplier_money_guard CHECK (reserved_balance_micros >= 0 AND exchange_rate_idr > 0 AND version > 0 AND currency IN ('USD', 'USDT', 'IDR', 'VND') AND (provider NOT IN ('nastele', 'miraiapi') OR currency = 'VND') AND (currency <> 'VND' OR provider IN ('nastele', 'miraiapi')))");
        }
    }
};
