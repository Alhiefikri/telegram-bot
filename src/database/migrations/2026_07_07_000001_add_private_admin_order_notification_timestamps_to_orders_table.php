<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('admin_pending_order_notification_started_at')->nullable()->after('manual_checkout_admin_notification_started_at');
            $table->timestamp('admin_pending_order_notified_at')->nullable()->after('admin_pending_order_notification_started_at');
            $table->timestamp('admin_paid_order_notification_started_at')->nullable()->after('admin_pending_order_notified_at');
            $table->timestamp('admin_paid_order_notified_at')->nullable()->after('admin_paid_order_notification_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'admin_pending_order_notification_started_at',
                'admin_pending_order_notified_at',
                'admin_paid_order_notification_started_at',
                'admin_paid_order_notified_at',
            ]);
        });
    }
};
