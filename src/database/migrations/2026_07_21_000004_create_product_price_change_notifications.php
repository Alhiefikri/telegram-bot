<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_price_change_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_key')->unique();
            $table->foreignId('product_id')->index()->constrained()->cascadeOnDelete();
            $table->string('product_name');
            $table->bigInteger('old_price_amount');
            $table->bigInteger('new_price_amount');
            $table->bigInteger('old_usdt_price_amount')->nullable();
            $table->bigInteger('new_usdt_price_amount')->nullable();
            $table->timestamp('recipients_materialized_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('product_price_change_notification_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('product_price_change_events')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('telegram_id');
            $table->string('locale', 5)->default('id');
            $table->timestamp('send_started_at')->nullable()->index();
            $table->uuid('send_claim_token')->nullable();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();

            $table->unique(['event_id', 'customer_id'], 'price_change_event_customer_unique');
            $table->index(['event_id', 'sent_at', 'send_started_at'], 'price_change_delivery_scan_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_change_notification_recipients');
        Schema::dropIfExists('product_price_change_events');
    }
};
