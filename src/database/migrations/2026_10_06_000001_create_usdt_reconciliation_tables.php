<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive USDT reconciliation schema (#547, work package U2):
 *
 * - usdt_treasury_movements: append-only operator/auditor record of custody
 *   movements that are not customer funding receipts (sweeps, external
 *   returns, gas paid from custody, operator reserve moves). Bookkeeping
 *   evidence only: recording a movement never signs, sends or credits money.
 * - usdt_balance_accounting_aggregates plus ..._claims: asynchronous,
 *   idempotent consumption of one accounting event by one named consumer.
 *   Each event carries a unique claim, and each aggregate row covers one
 *   balanced leg pair, so no single global clearing row is locked or summed
 *   on the posting path.
 * - usdt_balance_reconciliation_runs: append-only, sanitized evidence of one
 *   bounded reconciliation run for alerting (#558) and restore replay.
 *
 * Nothing here reads or writes IDR money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usdt_treasury_movements', function (Blueprint $table): void {
            $table->id();
            $table->string('claim_key', 64)->unique('usdt_treasury_movements_claim_key_unique');
            $table->string('currency', 4);
            $table->string('direction', 3);
            $table->bigInteger('amount_micro');
            $table->string('reference', 191);
            $table->string('reason', 191);
            $table->string('actor', 191);
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');

            $table->index(['currency', 'direction', 'id'], 'usdt_treasury_movements_currency_direction_index');
        });

        Schema::create('usdt_balance_accounting_aggregates', function (Blueprint $table): void {
            $table->id();
            $table->string('consumer', 64);
            $table->string('currency', 4);
            $table->string('debit_account', 64);
            $table->string('credit_account', 64);
            $table->unsignedBigInteger('entries_count');
            $table->bigInteger('amount_micro');
            $table->unsignedBigInteger('first_event_id');
            $table->unsignedBigInteger('last_event_id');
            $table->timestamps();

            $table->unique(
                ['consumer', 'currency', 'debit_account', 'credit_account'],
                'usdt_balance_accounting_aggregates_leg_unique',
            );
        });

        Schema::create('usdt_balance_accounting_aggregate_claims', function (Blueprint $table): void {
            $table->id();
            $table->string('consumer', 64);
            $table->string('currency', 4);
            $table->unsignedBigInteger('customer_usdt_balance_accounting_event_id');
            $table->timestamp('created_at');

            $table->unique(
                ['consumer', 'customer_usdt_balance_accounting_event_id'],
                'usdt_balance_accounting_claim_unique',
            );
            $table->foreign(
                'customer_usdt_balance_accounting_event_id',
                'usdt_balance_accounting_claim_event_fk',
            )->references('id')->on('customer_usdt_balance_accounting_events')->restrictOnDelete();
        });

        Schema::create('usdt_balance_reconciliation_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 16);
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('cursor_after')->nullable();
            $table->unsignedBigInteger('receipt_cursor_after')->nullable();
            $table->unsignedBigInteger('credit_cursor_after')->nullable();
            $table->unsignedSmallInteger('limit');
            $table->timestamp('checked_at');
            $table->timestamp('created_at');
            $table->string('verdict', 24);
            $table->unsignedInteger('accounts_inspected')->default(0);
            $table->unsignedInteger('account_finding_count')->default(0);
            $table->unsignedInteger('drift_frozen_count')->default(0);
            $table->unsignedInteger('receipt_finding_count')->default(0);
            $table->bigInteger('liability_micro')->default(0);
            $table->bigInteger('unresolved_deposit_micro')->default(0);
            $table->string('custody_state', 16)->nullable();
            $table->bigInteger('custody_assets_micro')->nullable();
            $table->bigInteger('movement_in_micro')->default(0);
            $table->bigInteger('movement_out_micro')->default(0);
            $table->unsignedInteger('movement_count')->default(0);
            $table->unsignedInteger('aggregate_pending_events')->default(0);
            $table->string('actor', 191)->nullable();
            $table->json('findings')->nullable();

            $table->index(['verdict', 'id'], 'usdt_balance_reconciliation_runs_verdict_index');
            $table->index(['checked_at', 'id'], 'usdt_balance_reconciliation_runs_checked_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE usdt_treasury_movements ADD CONSTRAINT usdt_treasury_movements_currency_check CHECK (currency = 'USDT')");
            DB::statement("ALTER TABLE usdt_treasury_movements ADD CONSTRAINT usdt_treasury_movements_direction_check CHECK (direction IN ('in', 'out'))");
            DB::statement('ALTER TABLE usdt_treasury_movements ADD CONSTRAINT usdt_treasury_movements_amount_positive CHECK (amount_micro > 0)');
            DB::statement("ALTER TABLE usdt_balance_accounting_aggregates ADD CONSTRAINT usdt_balance_accounting_aggregates_currency_check CHECK (currency = 'USDT')");
            DB::statement('ALTER TABLE usdt_balance_accounting_aggregates ADD CONSTRAINT usdt_balance_accounting_aggregates_count_positive CHECK (entries_count > 0)');
            DB::statement('ALTER TABLE usdt_balance_accounting_aggregates ADD CONSTRAINT usdt_balance_accounting_aggregates_amount_positive CHECK (amount_micro > 0)');
            DB::statement("ALTER TABLE usdt_balance_accounting_aggregate_claims ADD CONSTRAINT usdt_balance_accounting_claim_currency_check CHECK (currency = 'USDT')");
            DB::statement("ALTER TABLE usdt_balance_reconciliation_runs ADD CONSTRAINT usdt_balance_reconciliation_runs_scope_check CHECK (scope IN ('full', 'customer'))");
            DB::statement("ALTER TABLE usdt_balance_reconciliation_runs ADD CONSTRAINT usdt_balance_reconciliation_runs_custody_state_check CHECK (custody_state IS NULL OR custody_state IN ('available', 'unavailable', 'unsupported', 'missing'))");
            DB::statement('ALTER TABLE usdt_balance_reconciliation_runs ADD CONSTRAINT usdt_balance_reconciliation_runs_liability_nonnegative CHECK (liability_micro >= 0)');
            DB::statement('ALTER TABLE usdt_balance_reconciliation_runs ADD CONSTRAINT usdt_balance_reconciliation_runs_unresolved_nonnegative CHECK (unresolved_deposit_micro >= 0)');
            DB::statement('ALTER TABLE usdt_balance_reconciliation_runs ADD CONSTRAINT usdt_balance_reconciliation_runs_custody_nonnegative CHECK (custody_assets_micro IS NULL OR custody_assets_micro >= 0)');
            DB::statement('ALTER TABLE usdt_balance_reconciliation_runs ADD CONSTRAINT usdt_balance_reconciliation_runs_movements_nonnegative CHECK (movement_in_micro >= 0 AND movement_out_micro >= 0)');
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_usdt_reconciliation_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'USDT treasury movements and reconciliation runs are append-only';
END $$;
SQL);
            DB::statement('CREATE TRIGGER usdt_treasury_movements_immutable BEFORE UPDATE OR DELETE ON usdt_treasury_movements FOR EACH ROW EXECUTE FUNCTION protect_usdt_reconciliation_history()');
            DB::statement('CREATE TRIGGER usdt_balance_reconciliation_runs_immutable BEFORE UPDATE OR DELETE ON usdt_balance_reconciliation_runs FOR EACH ROW EXECUTE FUNCTION protect_usdt_reconciliation_history()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS usdt_treasury_movements_immutable ON usdt_treasury_movements');
            DB::statement('DROP TRIGGER IF EXISTS usdt_balance_reconciliation_runs_immutable ON usdt_balance_reconciliation_runs');
            DB::statement('DROP FUNCTION IF EXISTS protect_usdt_reconciliation_history()');
        }

        Schema::dropIfExists('usdt_balance_reconciliation_runs');
        Schema::dropIfExists('usdt_balance_accounting_aggregate_claims');
        Schema::dropIfExists('usdt_balance_accounting_aggregates');
        Schema::dropIfExists('usdt_treasury_movements');
    }
};
