<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_waitlist_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->text('email');
            $table->char('email_hash', 64);
            $table->string('status', 32)->default('pending_confirmation');
            $table->timestamp('confirmation_expires_at')->nullable()->index();
            $table->timestamp('confirmation_sent_at')->nullable();
            $table->timestamp('confirmation_delivery_started_at')->nullable();
            $table->timestamp('confirmed_at')->nullable()->index();
            $table->timestamp('unsubscribed_at')->nullable()->index();
            $table->timestamp('notified_at')->nullable()->index();
            $table->unsignedInteger('confirmation_attempts')->default(0);
            $table->string('confirmation_last_error', 128)->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'email_hash'], 'product_waitlist_subscription_unique');
            $table->index(['product_id', 'status', 'id'], 'product_waitlist_subscription_scan');
        });

        Schema::create('product_waitlist_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_movement_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('available_stock');
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('materialization_cursor')->nullable();
            $table->timestamp('materialized_at')->nullable()->index();
            $table->timestamp('skipped_at')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'materialized_at'], 'product_waitlist_event_scan');
        });

        Schema::create('product_waitlist_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_waitlist_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained('product_waitlist_subscriptions')->cascadeOnDelete();
            $table->string('status', 24)->default('pending');
            $table->timestamp('delivery_started_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable()->index();
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error', 128)->nullable();
            $table->timestamps();

            $table->unique(['product_waitlist_event_id', 'subscription_id'], 'product_waitlist_notification_unique');
            $table->index(['status', 'next_attempt_at'], 'product_waitlist_notification_scan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_waitlist_notifications');
        Schema::dropIfExists('product_waitlist_events');
        Schema::dropIfExists('product_waitlist_subscriptions');
    }
};
