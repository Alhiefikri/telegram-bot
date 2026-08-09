<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('buyer_order_detail_message_chat_id')->nullable();
            $table->unsignedBigInteger('buyer_order_detail_message_id')->nullable();
            $table->timestamp('buyer_order_detail_message_deleted_at')->nullable();
            $table->timestamp('buyer_order_detail_message_deletion_started_at')->nullable();
            $table->uuid('buyer_order_detail_message_deletion_token')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'buyer_order_detail_message_chat_id',
                'buyer_order_detail_message_id',
                'buyer_order_detail_message_deleted_at',
                'buyer_order_detail_message_deletion_started_at',
                'buyer_order_detail_message_deletion_token',
            ]);
        });
    }
};
