<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->text('notification_email')->nullable()->after('checkout_key');
        });

        Schema::create('order_notification_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('order_id');
            $table->string('event_type', 64);
            $table->string('status', 24)->default('pending');
            $table->timestamp('delivery_started_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->unique(['order_id', 'event_type'], 'order_notification_event_unique');
            $table->index(['status', 'delivery_started_at'], 'order_notification_delivery_scan_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_notification_events');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('notification_email');
        });
    }
};
