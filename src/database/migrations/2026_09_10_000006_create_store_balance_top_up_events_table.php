<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_balance_top_up_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_balance_top_up_id')
                ->constrained('store_balance_top_ups')
                ->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('event_type', 32);
            $table->string('merchant_ref', 191);
            $table->string('provider_reference', 191)->nullable();
            $table->string('provider_status', 32)->nullable();
            $table->json('raw_payload');
            $table->string('idempotency_key', 64);
            $table->unsignedInteger('received_count')->default(1);
            $table->timestamp('received_at');
            $table->timestamp('last_received_at');
            $table->timestamp('processed_at')->nullable();

            $table->unique(
                ['provider', 'event_type', 'idempotency_key'],
                'store_balance_top_up_events_idempotency_unique',
            );
            $table->index(
                ['store_balance_top_up_id', 'event_type', 'received_at'],
                'store_balance_top_up_events_history_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_balance_top_up_events');
    }
};
