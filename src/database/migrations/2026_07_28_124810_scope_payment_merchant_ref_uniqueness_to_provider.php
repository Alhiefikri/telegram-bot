<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique('payments_merchant_ref_unique');
            $table->unique(['provider', 'merchant_ref'], 'payments_provider_merchant_ref_unique');
        });
    }

    public function down(): void
    {
        $hasDuplicateMerchantRefs = DB::table('payments')
            ->select('merchant_ref')
            ->groupBy('merchant_ref')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicateMerchantRefs) {
            throw new RuntimeException('Cannot safely roll back payments merchant_ref uniqueness because duplicate merchant_ref values exist across providers. Resolve the data manually before recreating the global payments_merchant_ref_unique index.');
        }

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique('payments_provider_merchant_ref_unique');
            $table->unique('merchant_ref', 'payments_merchant_ref_unique');
        });
    }
};
