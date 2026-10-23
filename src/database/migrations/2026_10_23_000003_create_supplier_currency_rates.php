<?php

use App\Services\Suppliers\SupplierCurrencyRateCutover;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            Schema::create('supplier_currency_rates', function (Blueprint $table): void {
                $table->string('currency', 8)->primary();
                $table->decimal('exchange_rate_idr', 16, 6);
                $table->unsignedInteger('version')->default(1);
                $table->timestamps();
            });

            SupplierCurrencyRateCutover::backfill();
        });
    }

    public function down(): void
    {
        SupplierCurrencyRateCutover::dropFence();
        Schema::dropIfExists('supplier_currency_rates');
    }
};
