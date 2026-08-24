<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manual_payment_settings', function (Blueprint $table): void {
            $table->boolean('bsc_payment_enabled')->default(false)->after('binance_pay_enabled');
            $table->unsignedSmallInteger('bsc_payment_ttl_minutes')->default(30)->after('bsc_payment_enabled');
            $table->unsignedTinyInteger('bsc_min_confirmations')->default(3)->after('bsc_payment_ttl_minutes');
            $table->boolean('bsc_require_finalized_block')->default(false)->after('bsc_min_confirmations');
            $table->unsignedTinyInteger('bsc_confirmation_grace_minutes')->default(3)->after('bsc_require_finalized_block');
            $table->unsignedInteger('bsc_unique_amount_increment_micro')->default(100)->after('bsc_confirmation_grace_minutes');
            $table->unsignedTinyInteger('bsc_hash_attempt_limit')->default(5)->after('bsc_unique_amount_increment_micro');
        });
    }

    public function down(): void
    {
        Schema::table('manual_payment_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'bsc_payment_enabled',
                'bsc_payment_ttl_minutes',
                'bsc_min_confirmations',
                'bsc_require_finalized_block',
                'bsc_confirmation_grace_minutes',
                'bsc_unique_amount_increment_micro',
                'bsc_hash_attempt_limit',
            ]);
        });
    }
};
