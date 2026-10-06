<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O2 #558 operational control tables.
 *
 * - `balance_operation_events` is an append-only, bounded-cardinality metric
 *   ledger: one row per observed operation outcome (metric + outcome + bucket
 *   come from closed PHP enums, never from customer, chain or provider input).
 *   UPDATE is rejected for every role; DELETE is reserved for the bounded
 *   retention prune in `usdt-balance:metrics --prune-days`.
 * - `balance_operations_alert_episodes` is the durable dedupe/state projection
 *   for operational alerts. Delivery itself reuses the Telegram Outbound
 *   Effect ledger; this table only remembers whether an episode is open.
 * - `balance_restore_gates` is the singleton post-restore gate: while open, new
 *   USDT funding and spending stay blocked until external evidence replay and
 *   a clean reconciliation run are recorded. It never moves money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balance_operation_events', function (Blueprint $table): void {
            $table->id();
            $table->string('metric', 48);
            $table->string('outcome', 48);
            $table->string('bucket', 32)->nullable();
            $table->unsignedInteger('value')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();
            $table->index(['metric', 'occurred_at'], 'balance_operation_events_metric_index');
            $table->index('occurred_at', 'balance_operation_events_age_index');
        });

        Schema::create('balance_operations_alert_episodes', function (Blueprint $table): void {
            $table->id();
            $table->string('alert_key', 64)->unique();
            $table->string('severity', 16);
            $table->string('state', 16)->default('open');
            $table->string('summary', 500);
            $table->char('fingerprint', 64);
            $table->json('evidence')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->unsignedInteger('open_count')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['state', 'severity'], 'balance_alert_episodes_state_index');
        });

        Schema::create('balance_restore_gates', function (Blueprint $table): void {
            $table->unsignedSmallInteger('id')->primary();
            $table->string('state', 16)->default('cleared');
            $table->timestamp('window_start')->nullable();
            $table->timestamp('window_end')->nullable();
            $table->string('reason', 191)->nullable();
            $table->string('opened_by', 191)->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('replay_completed_at')->nullable();
            $table->string('replay_by', 191)->nullable();
            $table->json('replay_evidence')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->string('cleared_by', 191)->nullable();
            $table->json('cleared_evidence')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            // O2 #558 adds the balance receipt `delivery` pause scope to the
            // existing Owner pause table. The historical CHECK predates it.
            DB::statement('ALTER TABLE customer_usdt_funding_pauses DROP CONSTRAINT customer_usdt_funding_pauses_scope_check');
            DB::statement("ALTER TABLE customer_usdt_funding_pauses ADD CONSTRAINT customer_usdt_funding_pauses_scope_check CHECK (scope IN ('funding', 'spending', 'rail_binance_pay', 'rail_bsc_usdt', 'delivery'))");
            DB::statement("ALTER TABLE balance_operations_alert_episodes ADD CONSTRAINT balance_alert_episode_values CHECK (severity IN ('warning','critical') AND state IN ('open','resolved') AND occurrences > 0 AND char_length(summary) BETWEEN 1 AND 500)");
            DB::statement("ALTER TABLE balance_restore_gates ADD CONSTRAINT balance_restore_gate_values CHECK (state IN ('open','cleared') AND ((state = 'open' AND opened_at IS NOT NULL AND window_start IS NOT NULL AND window_end IS NOT NULL AND window_end >= window_start) OR state = 'cleared') AND (cleared_at IS NULL OR state = 'cleared'))");
            DB::statement('CREATE OR REPLACE FUNCTION protect_balance_operation_events() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION \'balance operation events are append-only except the bounded retention prune\';
END;
$$ LANGUAGE plpgsql');
            DB::statement('CREATE TRIGGER balance_operation_events_immutable BEFORE UPDATE ON balance_operation_events FOR EACH ROW EXECUTE FUNCTION protect_balance_operation_events()');
        }

        DB::table('balance_restore_gates')->insert([
            'id' => 1,
            'state' => 'cleared',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE customer_usdt_funding_pauses DROP CONSTRAINT customer_usdt_funding_pauses_scope_check');
            DB::statement("ALTER TABLE customer_usdt_funding_pauses ADD CONSTRAINT customer_usdt_funding_pauses_scope_check CHECK (scope IN ('funding', 'spending', 'rail_binance_pay', 'rail_bsc_usdt'))");
            DB::statement('DROP TRIGGER IF EXISTS balance_operation_events_immutable ON balance_operation_events');
            DB::statement('DROP FUNCTION IF EXISTS protect_balance_operation_events()');
        }

        Schema::dropIfExists('balance_restore_gates');
        Schema::dropIfExists('balance_operations_alert_episodes');
        Schema::dropIfExists('balance_operation_events');
    }
};
