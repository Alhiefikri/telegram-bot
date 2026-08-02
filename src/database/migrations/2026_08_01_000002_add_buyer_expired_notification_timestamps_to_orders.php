<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('telegram_expired_order_notification_started_at')->nullable()->after('expires_at');
            $table->timestamp('telegram_expired_order_notified_at')->nullable()->after('telegram_expired_order_notification_started_at');
        });

    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'telegram_expired_order_notification_started_at',
                'telegram_expired_order_notified_at',
            ]);
        });
    }
};
