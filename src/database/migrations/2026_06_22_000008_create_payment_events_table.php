<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('provider')->index();
            $table->string('event_type')->index();
            $table->string('merchant_ref')->nullable()->index();
            $table->string('provider_reference')->nullable()->index();
            $table->string('provider_status')->nullable()->index();
            $table->jsonb('raw_payload');
            $table->string('signature')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->index(['payment_id', 'received_at']);
            $table->index(['provider', 'merchant_ref', 'provider_reference', 'provider_status'], 'payment_events_idempotency_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
