<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('telegram_expired_order_notification_failed_at')->nullable();
            $table->string('telegram_expired_order_notification_failure_code', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'telegram_expired_order_notification_failed_at',
                'telegram_expired_order_notification_failure_code',
            ]);
        });
    }
};
