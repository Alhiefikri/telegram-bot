<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->string('stars_namespace', 64)->nullable();
            $table->string('stars_charge_hash', 64)->nullable();
            $table->index(['stars_event', 'stars_namespace', 'stars_charge_hash'], 'telegram_updates_stars_charge_idx');
        });
        Schema::table('telegram_stars_approvals', function (Blueprint $table): void {
            $table->string('bot_namespace', 64)->nullable();
        });
        Schema::create('telegram_stars_settlements', function (Blueprint $table): void {
            $table->foreignUuid('payment_id')->primary()->constrained()->restrictOnDelete();
            $table->foreignId('external_money_receipt_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('telegram_update_id')->unique()->constrained()->restrictOnDelete();
            $table->boolean('late');
            $table->timestamp('created_at');
        });
        Schema::create('telegram_stars_payment_reviews', function (Blueprint $table): void {
            $table->string('evidence_key', 64)->primary();
            $table->foreignId('telegram_update_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignUuid('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('external_money_receipt_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('reason', 40);
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        if (DB::table('telegram_stars_settlements')->exists() || DB::table('telegram_stars_payment_reviews')->exists()
            || DB::table('telegram_updates')->whereNotNull('stars_namespace')->exists()) {
            throw new RuntimeException('Refusing to drop Telegram Stars settlement evidence.');
        }

        Schema::dropIfExists('telegram_stars_payment_reviews');
        Schema::dropIfExists('telegram_stars_settlements');
        Schema::table('telegram_stars_approvals', fn (Blueprint $table) => $table->dropColumn('bot_namespace'));
        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->dropIndex('telegram_updates_stars_charge_idx');
            $table->dropColumn(['stars_namespace', 'stars_charge_hash']);
        });
    }
};
