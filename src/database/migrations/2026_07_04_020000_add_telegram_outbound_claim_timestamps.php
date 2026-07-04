<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('telegram_receipt_send_started_at')->nullable()->after('telegram_receipt_sent_at');
            $table->timestamp('manual_checkout_customer_notification_started_at')->nullable()->after('manual_checkout_customer_notified_at');
            $table->timestamp('manual_checkout_admin_notification_started_at')->nullable()->after('manual_checkout_admin_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'telegram_receipt_send_started_at',
                'manual_checkout_customer_notification_started_at',
                'manual_checkout_admin_notification_started_at',
            ]);
        });
    }
};
