<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_broadcasts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('broadcast_key')->unique();
            $table->string('source')->default('admin')->index();
            $table->text('message');
            $table->string('status', 32)->default('queued')->index();
            $table->timestamp('recipients_materialized_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('pending_count')->default(0);
            $table->unsignedInteger('terminal_failed_count')->default(0);
            $table->timestamps();
        });

        Schema::create('telegram_broadcast_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('broadcast_id')->constrained('telegram_broadcasts')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('telegram_id');
            $table->string('locale', 5)->default('id');
            $table->timestamp('send_started_at')->nullable()->index();
            $table->uuid('send_claim_token')->nullable();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable()->index();
            $table->timestamp('terminal_failed_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();

            $table->unique(['broadcast_id', 'telegram_id'], 'telegram_broadcast_recipient_unique');
            $table->index(['broadcast_id', 'sent_at', 'send_started_at'], 'telegram_broadcast_delivery_scan_index');
            $table->index(['broadcast_id', 'terminal_failed_at'], 'telegram_broadcast_terminal_scan_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_broadcast_recipients');
        Schema::dropIfExists('telegram_broadcasts');
    }
};
