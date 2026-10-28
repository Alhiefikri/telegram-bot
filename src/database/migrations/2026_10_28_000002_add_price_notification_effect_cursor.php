<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_price_change_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('recipient_effect_cursor')->default(0);
        });
        Schema::table('product_price_change_notification_recipients', function (Blueprint $table): void {
            $table->index(['event_id', 'id'], 'price_change_effect_cursor_index');
        });
    }

    public function down(): void
    {
        Schema::table('product_price_change_notification_recipients', function (Blueprint $table): void {
            $table->dropIndex('price_change_effect_cursor_index');
        });
        Schema::table('product_price_change_events', function (Blueprint $table): void {
            $table->dropColumn('recipient_effect_cursor');
        });
    }
};
