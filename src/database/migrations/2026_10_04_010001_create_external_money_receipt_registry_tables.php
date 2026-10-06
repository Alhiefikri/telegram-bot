<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_money_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('canonical_key', 191)->unique();
            $table->string('provider', 32);
            $table->string('identity_kind', 32);
            $table->string('account_namespace', 64)->nullable();
            $table->string('reference', 64)->nullable();
            $table->unsignedBigInteger('chain_id')->nullable();
            $table->string('tx_hash', 66)->nullable();
            $table->unsignedBigInteger('log_index')->nullable();
            $table->string('beneficiary', 191);
            $table->string('currency', 16);
            $table->string('amount_units', 78);
            $table->unsignedSmallInteger('decimals')->nullable();
            $table->string('status', 16);
            $table->string('target_type', 32);
            $table->string('target_id', 64);
            $table->string('fingerprint', 64);
            $table->string('review_reason', 64)->nullable();
            $table->json('evidence_payload')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'reference'], 'external_money_receipts_provider_reference_index');
            $table->index(['chain_id', 'tx_hash'], 'external_money_receipts_chain_tx_index');
            $table->index(['target_type', 'target_id'], 'external_money_receipts_target_index');
            $table->index(['status', 'id'], 'external_money_receipts_status_index');
        });

        Schema::create('external_money_receipt_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('external_money_receipt_id')
                ->constrained('external_money_receipts')
                ->restrictOnDelete();
            $table->string('alias_key', 191)->unique();
            $table->string('alias_type', 32);
            $table->timestamp('created_at');

            $table->index(['external_money_receipt_id', 'id'], 'external_money_receipt_aliases_receipt_index');
        });

        Schema::create('external_money_receipt_parent_claims', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('chain_id');
            $table->string('tx_hash', 66);
            $table->foreignId('external_money_receipt_id')
                ->nullable()
                ->constrained('external_money_receipts')
                ->restrictOnDelete();
            $table->string('status', 16);
            $table->string('target_type', 32);
            $table->string('target_id', 64);
            $table->string('review_reason', 64)->nullable();
            $table->timestamps();

            $table->unique(['chain_id', 'tx_hash'], 'external_money_receipt_parent_claims_chain_tx_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE external_money_receipts ADD CONSTRAINT external_money_receipts_amount_units_positive CHECK (amount_units ~ '^[1-9][0-9]*$')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('external_money_receipt_parent_claims');
        Schema::dropIfExists('external_money_receipt_aliases');
        Schema::dropIfExists('external_money_receipts');
    }
};
