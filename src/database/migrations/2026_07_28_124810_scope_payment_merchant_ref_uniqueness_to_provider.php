<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique('payments_provider_merchant_ref_unique');
            $table->unique('merchant_ref', 'payments_merchant_ref_unique');
        });
    }
};
