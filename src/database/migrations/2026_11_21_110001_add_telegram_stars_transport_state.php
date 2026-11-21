<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_stars_approvals', function (Blueprint $table): void {
            $table->foreignUuid('payment_id')->primary()->constrained()->restrictOnDelete();
            $table->string('query_hash', 64)->unique();
            $table->bigInteger('approved_at_epoch');
        });
        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->string('stars_event', 24)->nullable();
            $table->string('stars_query_hash', 64)->nullable()->unique();
            $table->boolean('stars_approved')->nullable();
            $table->index(['stars_event', 'processed_at', 'id'], 'telegram_updates_stars_inbox_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('telegram_stars_approvals')->exists()
            || DB::table('telegram_updates')->whereNotNull('stars_event')->exists()) {
            throw new RuntimeException('Refusing to drop Telegram Stars payment evidence.');
        }

        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->dropIndex('telegram_updates_stars_inbox_idx');
            $table->dropUnique(['stars_query_hash']);
            $table->dropColumn(['stars_event', 'stars_query_hash', 'stars_approved']);
        });
        Schema::dropIfExists('telegram_stars_approvals');
    }
};
