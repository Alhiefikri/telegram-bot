<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_claims', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('supplier_account_unit_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $table->string('proof_path', 160);
            $table->string('proof_digest', 64);
            $table->text('note');
            $table->string('state', 32)->default('received');
            $table->string('provider_decision', 24)->nullable();
            $table->text('provider_chat_url')->nullable();
            $table->json('decision_history')->nullable();
            $table->timestamp('refund_deadline');
            $table->uuid('claim_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('last_error', 80)->nullable();
            $table->string('settlement_choice', 16)->nullable();
            $table->string('settlement_state', 32)->default('awaiting_verification');
            $table->timestamps();
            $table->index(['state', 'next_attempt_at', 'id'], 'warranty_claim_due');
            $table->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_claims');
    }
};
