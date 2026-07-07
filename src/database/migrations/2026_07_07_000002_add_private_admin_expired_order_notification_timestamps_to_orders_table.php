<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('admin_expired_order_notification_started_at')->nullable()->after('admin_paid_order_notified_at');
            $table->timestamp('admin_expired_order_notified_at')->nullable()->after('admin_expired_order_notification_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'admin_expired_order_notification_started_at',
                'admin_expired_order_notified_at',
            ]);
        });
    }
};
