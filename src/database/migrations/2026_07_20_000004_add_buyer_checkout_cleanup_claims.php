<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('manual_checkout_customer_notification_deletion_started_at')->nullable()->after('manual_checkout_customer_notification_deleted_at');
            $table->uuid('manual_checkout_customer_notification_deletion_token')->nullable()->after('manual_checkout_customer_notification_deletion_started_at');
            $table->index(
                ['order_status', 'manual_checkout_customer_notification_deleted_at', 'manual_checkout_customer_notification_deletion_started_at', 'manual_checkout_customer_notification_message_id'],
                'orders_buyer_checkout_cleanup_scan_index'
            );
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->index(['method', 'order_id'], 'payments_method_order_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex('payments_method_order_id_index');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_buyer_checkout_cleanup_scan_index');
            $table->dropColumn([
                'manual_checkout_customer_notification_deletion_started_at',
                'manual_checkout_customer_notification_deletion_token',
            ]);
        });
    }
};
