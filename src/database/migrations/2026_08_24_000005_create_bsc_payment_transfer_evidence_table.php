<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bsc_payment_transfer_evidence', function (Blueprint $table): void {
            $table->id();
            $table->uuid('payment_id');
            $table->char('tx_hash', 66)->unique();
            $table->char('token_contract', 42);
            $table->char('receiver_address', 42);
            $table->char('from_address', 42)->nullable();
            $table->decimal('amount_units', 78, 0);
            $table->unsignedBigInteger('block_number')->nullable();
            $table->char('block_hash', 66)->nullable();
            $table->timestamp('block_timestamp')->nullable();
            $table->string('receipt_status', 16)->nullable();
            $table->unsignedBigInteger('log_index')->nullable();
            $table->unsignedBigInteger('confirmations')->nullable();
            $table->string('verification_status', 32)->index();
            $table->json('evidence_payload')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->foreign('payment_id')
                ->references('id')
                ->on('payments')
                ->cascadeOnDelete();
            $table->index(['payment_id', 'verification_status'], 'bsc_evidence_payment_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bsc_payment_transfer_evidence');
    }
};
