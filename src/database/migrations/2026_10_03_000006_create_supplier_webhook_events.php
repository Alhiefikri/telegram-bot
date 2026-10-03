<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('external_event_id', 128);
            $table->string('event_type', 40);
            $table->string('resource_id', 128)->nullable();
            $table->text('payload');
            $table->string('state', 24)->default('received');
            $table->uuid('claim_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('last_error', 80)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['supplier_id', 'external_event_id'], 'supplier_webhook_event_unique');
            $table->index(['state', 'next_attempt_at', 'id'], 'supplier_webhook_due');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_webhook_events');
    }
};
