<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_balance_top_ups', function (Blueprint $table): void {
            $table->bigInteger('payment_setting_id')->nullable()->after('provider');
            $table->bigInteger('payment_provider_version')->nullable()->after('payment_setting_id');
            $table->text('provider_qris_payload')->nullable()->after('provider_qris_url');

            $table->index(
                ['provider', 'status', 'expires_at', 'id'],
                'store_balance_top_ups_rail_expiry_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('store_balance_top_ups', function (Blueprint $table): void {
            $table->dropIndex('store_balance_top_ups_rail_expiry_index');
            $table->dropColumn(['payment_setting_id', 'payment_provider_version', 'provider_qris_payload']);
        });
    }
};
