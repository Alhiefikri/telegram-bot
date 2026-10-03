<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_deposits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 100);
            $table->string('account_id_snapshot', 128);
            $table->string('external_id', 128)->nullable();
            $table->unsignedBigInteger('amount');
            $table->string('state', 24)->default('requested');
            $table->string('operation', 16)->default('create');
            $table->text('payment_url')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('claim_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('last_error', 80)->nullable();
            $table->timestamps();
            $table->unique(['supplier_id', 'idempotency_key'], 'supplier_deposit_intent_unique');
            $table->unique(['supplier_id', 'external_id'], 'supplier_deposit_external_unique');
            $table->index(['state', 'next_attempt_at'], 'supplier_deposit_due');
        });
        DB::statement("CREATE UNIQUE INDEX supplier_deposit_one_active ON supplier_deposits (supplier_id) WHERE state IN ('requested', 'sending', 'unknown', 'pending', 'cancel_requested', 'review')");
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplier_deposits ADD CONSTRAINT supplier_deposit_amount_guard CHECK (amount BETWEEN 5000 AND 5000000)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_deposits');
    }
};
