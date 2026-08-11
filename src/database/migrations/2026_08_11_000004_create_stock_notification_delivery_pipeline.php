<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_notification_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_movement_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->timestamp('recipients_snapshotted_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('stock_notification_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_notification_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient_type', 24)->default('customer');
            $table->string('telegram_id');
            $table->text('text');
            $table->json('reply_markup')->nullable();
            $table->timestamp('claim_expires_at')->nullable()->index();
            $table->uuid('claim_token')->nullable();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable()->index();
            $table->timestamp('terminal_failed_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();

            $table->unique(['stock_notification_event_id', 'telegram_id'], 'stock_notification_recipient_unique');
            $table->index(['stock_notification_event_id', 'sent_at', 'terminal_failed_at', 'claim_expires_at'], 'stock_notification_delivery_scan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_notification_recipients');
        Schema::dropIfExists('stock_notification_events');
    }
};
