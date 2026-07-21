<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manual_payment_settings', function (Blueprint $table): void {
            $table->string('whatsapp_support_url')->nullable()->after('admin_telegram_chat_id');
            $table->string('whatsapp_support_number', 40)->nullable()->after('whatsapp_support_url');
        });
    }

    public function down(): void
    {
        Schema::table('manual_payment_settings', function (Blueprint $table): void {
            $table->dropColumn(['whatsapp_support_url', 'whatsapp_support_number']);
        });
    }
};
