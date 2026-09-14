<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('telegram_credential_message_chat_id')->nullable()->after('telegram_receipt_sent_at');
            $table->unsignedBigInteger('telegram_credential_message_id')->nullable()->after('telegram_credential_message_chat_id');
            $table->timestamp('telegram_credential_pinned_at')->nullable()->after('telegram_credential_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'telegram_credential_message_chat_id',
                'telegram_credential_message_id',
                'telegram_credential_pinned_at',
            ]);
        });
    }
};
