<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_notification_events', function (Blueprint $table): void {
            $table->timestamp('tier_promotion_snapshotted_at')->nullable()->after('materialization_cursor');
            $table->unsignedInteger('promoted_tier_minimum')->nullable()->after('tier_promotion_snapshotted_at');
            $table->unsignedBigInteger('promoted_tier_price_amount')->nullable()->after('promoted_tier_minimum');
            $table->unsignedBigInteger('promoted_tier_usdt_price_amount')->nullable()->after('promoted_tier_price_amount');
        });
    }

    public function down(): void
    {
        Schema::table('stock_notification_events', function (Blueprint $table): void {
            $table->dropColumn([
                'tier_promotion_snapshotted_at',
                'promoted_tier_minimum',
                'promoted_tier_price_amount',
                'promoted_tier_usdt_price_amount',
            ]);
        });
    }
};
