<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('manual_checkout_customer_notification_chat_id')->nullable()->after('manual_checkout_customer_notification_started_at');
            $table->unsignedBigInteger('manual_checkout_customer_notification_message_id')->nullable()->after('manual_checkout_customer_notification_chat_id');
            $table->timestamp('manual_checkout_customer_notification_deleted_at')->nullable()->after('manual_checkout_customer_notification_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'manual_checkout_customer_notification_chat_id',
                'manual_checkout_customer_notification_message_id',
                'manual_checkout_customer_notification_deleted_at',
            ]);
        });
    }
};
