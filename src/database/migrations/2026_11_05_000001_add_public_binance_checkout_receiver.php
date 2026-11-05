<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manual_payment_settings', function (Blueprint $table): void {
            $table->string('binance_receiver_type', 16)->nullable();
            $table->string('binance_receiver_value', 57)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('manual_payment_settings', function (Blueprint $table): void {
            $table->dropColumn(['binance_receiver_type', 'binance_receiver_value']);
        });
    }
};
