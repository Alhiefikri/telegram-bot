<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_usdt_top_ups', function (Blueprint $table): void {
            $table->index(['receiver_id', 'created_at', 'id'], 'usdt_targets_receiver_attribution');
        });
        Schema::table('usdt_bsc_scan_checkpoints', function (Blueprint $table): void {
            $table->timestamp('last_attempt_at')->nullable()->index();
        });
        Schema::create('customer_usdt_top_up_settlement_work', function (Blueprint $table): void {
            $table->uuid('top_up_id')->primary();
            $table->foreign('top_up_id')->references('id')->on('customer_usdt_top_ups')->restrictOnDelete();
            $table->string('state', 32)->default('awaiting_payment');
            $table->unsignedBigInteger('version')->default(1);
            $table->unsignedBigInteger('evidence_version')->default(0);
            $table->unsignedBigInteger('observation_cursor')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('lease_token', 32)->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('next_check_at');
            $table->string('last_error', 64)->nullable();
            $table->timestamp('updated_at');
            $table->index(['next_check_at', 'top_up_id'], 'usdt_settlement_due');
        });
        Schema::create('customer_usdt_top_up_inbox', function (Blueprint $table): void {
            $table->id();
            $table->uuid('top_up_id');
            $table->foreign('top_up_id')->references('id')->on('customer_usdt_top_ups')->restrictOnDelete();
            $table->char('tx_hash', 66);
            $table->string('state', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_check_at');
            $table->timestamp('created_at');
            $table->unique(['top_up_id', 'tx_hash'], 'usdt_inbox_identity');
            $table->index(['top_up_id', 'state', 'next_check_at', 'id'], 'usdt_inbox_due');
        });
        Schema::create('customer_usdt_top_up_evidence', function (Blueprint $table): void {
            $table->id();
            $table->uuid('top_up_id');
            $table->foreign('top_up_id')->references('id')->on('customer_usdt_top_ups')->restrictOnDelete();
            $table->foreignId('inbox_id')->constrained('customer_usdt_top_up_inbox')->restrictOnDelete();
            $table->unsignedBigInteger('version');
            $table->string('status', 16);
            $table->string('reason', 64);
            $table->json('chain_evidence');
            $table->timestamp('created_at');
            $table->unique(['top_up_id', 'version'], 'usdt_evidence_version');
        });
        Schema::create('customer_usdt_top_up_resolutions', function (Blueprint $table): void {
            $table->uuid('top_up_id')->primary();
            $table->foreign('top_up_id')->references('id')->on('customer_usdt_top_ups')->restrictOnDelete();
            $table->string('kind', 32);
            $table->foreignId('receipt_id')->nullable()->unique()->constrained('external_money_receipts')->restrictOnDelete();
            $table->foreignId('entry_id')->nullable()->unique()->constrained('customer_usdt_balance_entries')->restrictOnDelete();
            $table->foreignId('evidence_id')->nullable()->constrained('customer_usdt_top_up_evidence')->restrictOnDelete();
            $table->boolean('late')->default(false);
            $table->timestamp('created_at');
        });
        Schema::create('customer_usdt_top_up_fee_events', function (Blueprint $table): void {
            $table->uuid('top_up_id')->primary();
            $table->foreign('top_up_id')->references('top_up_id')->on('customer_usdt_top_up_resolutions')->restrictOnDelete();
            $table->foreignId('receipt_id')->unique()->constrained('external_money_receipts')->restrictOnDelete();
            $table->string('currency', 4)->default('USDT');
            $table->unsignedBigInteger('amount_micro');
            $table->string('debit_leg', 32)->default('settlement_asset');
            $table->string('credit_leg', 32)->default('funding_fee_revenue');
            $table->timestamp('created_at');
        });
        // Existing U6 targets retain their immutable snapshot and receive a
        // durable obligation; no historical status or amount is rewritten.
        DB::table('customer_usdt_top_ups')->orderBy('id')->chunk(100, function ($targets): void {
            foreach ($targets as $target) {
                DB::table('customer_usdt_top_up_settlement_work')->insert([
                    'top_up_id' => $target->id, 'next_check_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement("ALTER TABLE customer_usdt_top_up_settlement_work ADD CONSTRAINT usdt_settlement_state CHECK (state IN ('awaiting_payment','expired_unresolved','manual_review','settled','externally_refunded','resolved_by_compensation','rejected') AND version > 0)");
        DB::statement("ALTER TABLE customer_usdt_top_up_resolutions ADD CONSTRAINT usdt_resolution_kind CHECK (kind IN ('settled','externally_refunded','resolved_by_compensation','rejected') AND ((kind = 'settled' AND receipt_id IS NOT NULL AND entry_id IS NOT NULL AND evidence_id IS NOT NULL) OR (kind <> 'settled' AND entry_id IS NULL)))");
        DB::statement("ALTER TABLE customer_usdt_top_up_fee_events ADD CONSTRAINT usdt_fee_values CHECK (currency = 'USDT' AND amount_micro > 0 AND debit_leg = 'settlement_asset' AND credit_leg = 'funding_fee_revenue')");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_usdt_settlement_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'USDT settlement evidence/resolutions/accounting are immutable'; END $$;
CREATE TRIGGER usdt_evidence_immutable BEFORE UPDATE OR DELETE ON customer_usdt_top_up_evidence FOR EACH ROW EXECUTE FUNCTION guard_usdt_settlement_history();
CREATE TRIGGER usdt_resolution_immutable BEFORE UPDATE OR DELETE ON customer_usdt_top_up_resolutions FOR EACH ROW EXECUTE FUNCTION guard_usdt_settlement_history();
CREATE TRIGGER usdt_fee_immutable BEFORE UPDATE OR DELETE ON customer_usdt_top_up_fee_events FOR EACH ROW EXECUTE FUNCTION guard_usdt_settlement_history();
CREATE OR REPLACE FUNCTION guard_usdt_settlement_work() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'USDT settlement work cannot be deleted'; END IF;
 IF NEW.top_up_id <> OLD.top_up_id OR NEW.version <> OLD.version + 1
 OR NEW.evidence_version < OLD.evidence_version OR NEW.observation_cursor < OLD.observation_cursor
 OR (OLD.state IN ('settled','externally_refunded','resolved_by_compensation','rejected') AND NEW.state <> OLD.state) THEN
  RAISE EXCEPTION 'USDT settlement identity/version/terminal state is immutable';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER usdt_settlement_work_guard BEFORE UPDATE OR DELETE ON customer_usdt_top_up_settlement_work FOR EACH ROW EXECUTE FUNCTION guard_usdt_settlement_work();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_usdt_top_up_fee_events');
        Schema::dropIfExists('customer_usdt_top_up_resolutions');
        Schema::dropIfExists('customer_usdt_top_up_evidence');
        Schema::dropIfExists('customer_usdt_top_up_inbox');
        Schema::dropIfExists('customer_usdt_top_up_settlement_work');
        Schema::table('usdt_bsc_scan_checkpoints', function (Blueprint $table): void {
            $table->dropColumn('last_attempt_at');
        });
        Schema::table('customer_usdt_top_ups', function (Blueprint $table): void {
            $table->dropIndex('usdt_targets_receiver_attribution');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS guard_usdt_settlement_work()');
            DB::statement('DROP FUNCTION IF EXISTS guard_usdt_settlement_history()');
        }
    }
};
