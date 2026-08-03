<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_price_change_events', function (Blueprint $table): void {
            $table->timestamp('store_channel_send_started_at')->nullable()->index();
            $table->uuid('store_channel_send_claim_token')->nullable();
            $table->timestamp('store_channel_sent_at')->nullable()->index();
            $table->timestamp('store_channel_failed_at')->nullable()->index();
            $table->text('store_channel_last_error')->nullable();
            $table->unsignedInteger('store_channel_attempts')->default(0);
            $table->string('store_channel_message_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_price_change_events', function (Blueprint $table): void {
            $table->dropColumn([
                'store_channel_send_started_at',
                'store_channel_send_claim_token',
                'store_channel_sent_at',
                'store_channel_failed_at',
                'store_channel_last_error',
                'store_channel_attempts',
                'store_channel_message_id',
            ]);
        });
    }
};
