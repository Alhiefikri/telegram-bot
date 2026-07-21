<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->integer('available_before')->nullable()->after('quantity');
            $table->timestamp('stock_notification_started_at')->nullable()->after('notes');
            $table->timestamp('stock_notification_sent_at')->nullable()->after('stock_notification_started_at');
            $table->string('stock_notification_claim_token', 64)->nullable()->after('stock_notification_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropColumn([
                'available_before',
                'stock_notification_started_at',
                'stock_notification_sent_at',
                'stock_notification_claim_token',
            ]);
        });
    }
};
