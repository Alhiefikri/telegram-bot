<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manual_payment_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('qris_payment_ttl_minutes')->default(10)->after('binance_pay_enabled');
            $table->unsignedSmallInteger('binance_payment_ttl_minutes')->default(20)->after('qris_payment_ttl_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('manual_payment_settings', function (Blueprint $table): void {
            $table->dropColumn(['qris_payment_ttl_minutes', 'binance_payment_ttl_minutes']);
        });
    }
};
