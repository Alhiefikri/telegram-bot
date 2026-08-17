<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_outbound_effects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('effect_key')->unique();
            $table->string('effect_group_key')->nullable();
            $table->unsignedInteger('sequence')->default(1);
            $table->string('effect_type');
            $table->string('source_type');
            $table->string('source_id');
            $table->string('target_type')->default('customer');
            $table->string('telegram_chat_id');
            $table->string('queue')->default('telegram-outbound');
            $table->string('telegram_method');
            $table->string('locale', 10)->nullable();
            $table->string('template_version')->nullable();
            $table->string('state')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->string('claim_token', 128)->nullable();
            $table->string('telegram_message_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('unknown_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('manual_review_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error')->nullable();
            $table->json('outcome_metadata')->nullable();
            $table->timestamps();

            $table->index(['state', 'next_attempt_at'], 'telegram_outbound_effects_ready_idx');
            $table->index(['state', 'lease_until'], 'telegram_outbound_effects_lease_idx');
            $table->index(['source_type', 'source_id', 'effect_type'], 'telegram_outbound_effects_source_idx');
            $table->index(['effect_group_key', 'sequence'], 'telegram_outbound_effects_group_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_outbound_effects');
    }
};
