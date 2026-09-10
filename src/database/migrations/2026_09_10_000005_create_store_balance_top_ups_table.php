<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_balance_top_ups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_balance_funding_intent_id')
                ->unique()
                ->constrained('store_balance_funding_intents')
                ->cascadeOnDelete();
            $table->foreignId('telegram_navigation_root_id')
                ->nullable()
                ->constrained('telegram_navigation_roots')
                ->nullOnDelete();
            $table->string('chat_id', 64);
            $table->unsignedBigInteger('canonical_message_id');
            $table->string('locale', 8)->nullable();
            $table->string('provider', 32);
            $table->string('merchant_ref', 191);
            $table->string('idempotency_key', 191);
            $table->string('currency', 3)->default('IDR');
            $table->unsignedBigInteger('amount');
            $table->string('status', 32)->default('pending');
            $table->string('provider_reference', 191)->nullable();
            $table->json('provider_payload')->nullable();
            $table->text('provider_qris_url')->nullable();
            $table->unsignedBigInteger('provider_fee')->nullable();
            $table->unsignedBigInteger('provider_total_payment')->nullable();
            $table->string('provider_payment_method', 32)->nullable();
            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('provider_expires_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('provider_signature')->nullable();
            $table->string('provider_create_state', 64)->nullable();
            $table->timestamp('provider_create_attempted_at')->nullable();
            $table->string('provider_create_last_error', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'merchant_ref'], 'store_balance_top_ups_provider_ref_unique');
            $table->unique('idempotency_key', 'store_balance_top_ups_idempotency_unique');
            $table->index(['customer_id', 'status', 'expires_at'], 'store_balance_top_ups_customer_state_index');
            $table->index(['provider', 'status', 'provider_create_state'], 'store_balance_top_ups_recovery_index');
            $table->index(['chat_id', 'canonical_message_id'], 'store_balance_top_ups_chat_message_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE store_balance_top_ups ADD CONSTRAINT store_balance_top_ups_amount_positive CHECK (amount > 0)');
            DB::statement('ALTER TABLE store_balance_top_ups ADD CONSTRAINT store_balance_top_ups_fee_nonnegative CHECK (provider_fee IS NULL OR provider_fee >= 0)');
            DB::statement('ALTER TABLE store_balance_top_ups ADD CONSTRAINT store_balance_top_ups_total_nonnegative CHECK (provider_total_payment IS NULL OR provider_total_payment >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_balance_top_ups');
    }
};
