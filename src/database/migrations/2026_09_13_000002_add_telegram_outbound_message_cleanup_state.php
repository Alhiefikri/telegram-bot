<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_outbound_effects', function (Blueprint $table): void {
            $table->timestamp('telegram_message_deleted_at')->nullable()->after('telegram_message_id');
            $table->timestamp('telegram_message_deletion_started_at')->nullable()->after('telegram_message_deleted_at');
            $table->uuid('telegram_message_deletion_token')->nullable()->after('telegram_message_deletion_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_outbound_effects', function (Blueprint $table): void {
            $table->dropColumn([
                'telegram_message_deleted_at',
                'telegram_message_deletion_started_at',
                'telegram_message_deletion_token',
            ]);
        });
    }
};
