<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_usdt_funding_intents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('telegram_navigation_root_id')->constrained()->restrictOnDelete();
            $table->string('chat_id', 32);
            $table->unsignedBigInteger('canonical_message_id');
            $table->unsignedBigInteger('root_generation');
            $table->string('nonce', 32)->unique();
            $table->string('currency', 4)->default('USDT');
            $table->string('rail', 32);
            $table->unsignedBigInteger('amount_micro');
            $table->unsignedBigInteger('fee_micro');
            $table->unsignedBigInteger('payable_micro');
            $table->unsignedBigInteger('policy_version');
            $table->unsignedBigInteger('terms_version');
            $table->foreignId('receiver_id')->constrained('customer_usdt_deposit_receivers')->restrictOnDelete();
            $table->json('payment_snapshot');
            $table->string('request_fingerprint', 64);
            $table->string('state', 24)->default('pending');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamp('expires_at');
            $table->timestamp('created_at');
            $table->timestamp('claimed_at')->nullable();
            $table->index(['state', 'expires_at', 'id'], 'usdt_intents_expiry_index');
        });
        DB::statement("CREATE UNIQUE INDEX usdt_intents_one_pending_customer ON customer_usdt_funding_intents(customer_id) WHERE state = 'pending'");
        Schema::create('customer_usdt_top_ups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('intent_id')->unique()->constrained('customer_usdt_funding_intents')->restrictOnDelete();
            $table->foreignId('reservation_id')->unique()->constrained('customer_usdt_funding_reservations')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('reference', 64)->unique();
            $table->string('currency', 4)->default('USDT');
            $table->string('rail', 32);
            $table->unsignedBigInteger('amount_micro');
            $table->unsignedBigInteger('fee_micro');
            $table->unsignedBigInteger('payable_micro');
            $table->unsignedBigInteger('policy_version');
            $table->unsignedBigInteger('terms_version');
            $table->foreignId('receiver_id')->constrained('customer_usdt_deposit_receivers')->restrictOnDelete();
            $table->json('payment_snapshot');
            $table->string('request_fingerprint', 64);
            $table->timestamp('expires_at');
            $table->timestamp('created_at');
        });
        Schema::create('customer_usdt_top_up_work', function (Blueprint $table): void {
            $table->uuid('top_up_id')->primary();
            $table->foreign('top_up_id')->references('id')->on('customer_usdt_top_ups')->restrictOnDelete();
            $table->string('state', 24)->default('pending');
            $table->unsignedBigInteger('version')->default(1);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('lease_token', 32)->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('next_attempt_at');
            $table->uuid('rail_invoice_id')->nullable()->unique();
            $table->foreign('rail_invoice_id')->references('id')->on('usdt_bsc_rail_invoices')->restrictOnDelete();
            $table->string('last_error', 64)->nullable();
            $table->timestamp('updated_at');
            $table->index(['state', 'next_attempt_at', 'top_up_id'], 'usdt_top_up_work_due_index');
        });
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (['customer_usdt_funding_intents', 'customer_usdt_top_ups'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_money_check CHECK (currency = 'USDT' AND rail = 'bsc_usdt' AND amount_micro > 0 AND fee_micro >= 0 AND payable_micro >= amount_micro AND payable_micro - amount_micro = fee_micro AND policy_version > 0 AND terms_version > 0)");
        }
        DB::statement("ALTER TABLE customer_usdt_funding_intents ADD CONSTRAINT usdt_intent_state_check CHECK (state IN ('pending', 'confirmed', 'cancelled', 'expired', 'superseded') AND version > 0)");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_usdt_funding_intent() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'USDT funding intents cannot be deleted'; END IF;
 IF (to_jsonb(NEW) - ARRAY['state','version','claimed_at']) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['state','version','claimed_at'])
 OR OLD.state <> 'pending' OR NEW.state = 'pending' OR NEW.version <> OLD.version + 1 OR NEW.claimed_at IS NULL THEN
  RAISE EXCEPTION 'USDT funding intent identity or terminal state is immutable';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER usdt_intent_guard BEFORE UPDATE OR DELETE ON customer_usdt_funding_intents FOR EACH ROW EXECUTE FUNCTION guard_usdt_funding_intent();
CREATE OR REPLACE FUNCTION guard_usdt_top_up() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'USDT top-up snapshots are immutable'; END $$;
CREATE TRIGGER usdt_top_up_guard BEFORE UPDATE OR DELETE ON customer_usdt_top_ups FOR EACH ROW EXECUTE FUNCTION guard_usdt_top_up();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_usdt_top_up_work');
        Schema::dropIfExists('customer_usdt_top_ups');
        Schema::dropIfExists('customer_usdt_funding_intents');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS guard_usdt_top_up()');
            DB::statement('DROP FUNCTION IF EXISTS guard_usdt_funding_intent()');
        }
    }
};
