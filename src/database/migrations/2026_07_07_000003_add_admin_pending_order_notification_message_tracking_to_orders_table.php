<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('admin_pending_order_notification_chat_id')->nullable()->after('admin_pending_order_notified_at');
            $table->unsignedBigInteger('admin_pending_order_notification_message_id')->nullable()->after('admin_pending_order_notification_chat_id');
            $table->timestamp('admin_pending_order_notification_deleted_at')->nullable()->after('admin_pending_order_notification_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'admin_pending_order_notification_chat_id',
                'admin_pending_order_notification_message_id',
                'admin_pending_order_notification_deleted_at',
            ]);
        });
    }
};
