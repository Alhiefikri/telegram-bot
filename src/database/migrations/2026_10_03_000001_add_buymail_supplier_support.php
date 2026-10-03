<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('external_account_id', 128)->nullable();
            $table->json('account_metadata')->nullable();
            $table->text('webhook_secret')->nullable();
        });
        Schema::table('supplier_purchases', function (Blueprint $table): void {
            $table->text('account_units')->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE suppliers DROP CONSTRAINT supplier_money_guard');
            DB::statement("ALTER TABLE suppliers ADD CONSTRAINT supplier_money_guard CHECK ((balance_micros IS NULL OR balance_micros >= 0) AND reserved_balance_micros >= 0 AND exchange_rate_idr > 0 AND version > 0 AND currency IN ('USD', 'USDT', 'IDR') AND (provider <> 'buymail' OR (currency = 'IDR' AND exchange_rate_idr = 1)))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE suppliers DROP CONSTRAINT supplier_money_guard');
            DB::statement("ALTER TABLE suppliers ADD CONSTRAINT supplier_money_guard CHECK ((balance_micros IS NULL OR balance_micros >= 0) AND reserved_balance_micros >= 0 AND exchange_rate_idr > 0 AND version > 0 AND currency IN ('USD', 'USDT'))");
        }
        Schema::table('supplier_purchases', fn (Blueprint $table) => $table->dropColumn('account_units'));
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn(['external_account_id', 'account_metadata', 'webhook_secret']));
    }
};
