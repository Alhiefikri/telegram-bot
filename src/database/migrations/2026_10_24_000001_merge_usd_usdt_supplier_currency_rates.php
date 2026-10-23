<?php

use App\Services\Suppliers\SupplierCurrencyRateCutover;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(fn () => SupplierCurrencyRateCutover::mergeDollars());
    }

    public function down(): void
    {
        throw new RuntimeException('The merged USD/USDT rate cannot be split losslessly; restore a pre-merge backup instead.');
    }
};
