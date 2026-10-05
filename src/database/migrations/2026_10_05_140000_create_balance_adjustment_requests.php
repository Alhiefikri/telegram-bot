<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balance_adjustment_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('telegram_id', 20);
            $table->string('username')->nullable();
            $table->string('session_binding', 64);
            $table->string('credential_binding', 64);
            $table->string('mode', 24)->default('single_owner');
            $table->string('currency', 3)->default('IDR');
            $table->unsignedBigInteger('amount');
            $table->string('reason', 500);
            $table->string('category', 32);
            $table->string('source_id', 64)->nullable();
            $table->string('receipt_fingerprint', 64)->nullable();
            $table->string('fingerprint', 64);
            $table->unsignedInteger('version')->default(1);
            $table->string('state', 24)->default('pending');
            $table->foreignId('ledger_entry_id')->nullable()->unique()->constrained('store_balance_entries')->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['owner_user_id', 'state', 'id'], 'balance_adjustments_owner_cursor');
        });
        Schema::create('balance_adjustment_accounting_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->foreign('request_id')->references('id')->on('balance_adjustment_requests')->restrictOnDelete();
            $table->foreignId('ledger_entry_id')->unique()->constrained('store_balance_entries')->restrictOnDelete();
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount');
            $table->string('debit_account', 64);
            $table->string('credit_account', 64);
            $table->string('source_fingerprint', 64);
            $table->timestamp('created_at');
        });
        Schema::create('balance_adjustment_auth_states', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->restrictOnDelete();
            $table->json('failed_at');
            $table->timestamp('locked_until')->nullable();
            $table->uuid('episode')->nullable();
            $table->uuid('verified_request_id')->nullable();
            $table->string('verified_fingerprint', 64)->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_until')->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE balance_adjustment_requests ADD CONSTRAINT balance_adjustment_money_check CHECK (amount > 0 AND currency = 'IDR' AND mode = 'single_owner' AND version = 1)");
            DB::statement("ALTER TABLE balance_adjustment_requests ADD CONSTRAINT balance_adjustment_state_check CHECK ((state = 'applied' AND ledger_entry_id IS NOT NULL AND resolved_at IS NOT NULL) OR (state IN ('pending','approved','rejected','cancelled','expired') AND ledger_entry_id IS NULL))");
            DB::statement('ALTER TABLE balance_adjustment_accounting_events ADD CONSTRAINT balance_adjustment_accounting_positive CHECK (amount > 0)');
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_balance_adjustment_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_TABLE_NAME = 'store_balance_entries' THEN
        IF OLD.kind <> 'admin_adjustment_credit' AND NOT EXISTS (SELECT 1 FROM balance_adjustment_requests WHERE ledger_entry_id = OLD.id) THEN
            IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
            RETURN NEW;
        END IF;
    END IF;
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Balance adjustment history is immutable'; END IF;
    IF TG_TABLE_NAME = 'balance_adjustment_accounting_events' OR TG_TABLE_NAME = 'store_balance_entries' OR TG_TABLE_NAME = 'audit_logs' THEN
        RAISE EXCEPTION 'Money posting history is immutable';
    END IF;
    IF OLD.state NOT IN ('pending', 'approved') OR
       (to_jsonb(NEW) - ARRAY['state','ledger_entry_id','resolved_at','updated_at']) IS DISTINCT FROM
       (to_jsonb(OLD) - ARRAY['state','ledger_entry_id','resolved_at','updated_at']) THEN
        RAISE EXCEPTION 'Balance adjustment identity is immutable';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER balance_adjustment_audit_immutable BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW WHEN (OLD.action LIKE 'balance.adjustment.%') EXECUTE FUNCTION protect_balance_adjustment_history();
CREATE TRIGGER balance_adjustments_immutable BEFORE UPDATE OR DELETE ON balance_adjustment_requests FOR EACH ROW EXECUTE FUNCTION protect_balance_adjustment_history();
CREATE TRIGGER balance_adjustment_accounting_immutable BEFORE UPDATE OR DELETE ON balance_adjustment_accounting_events FOR EACH ROW EXECUTE FUNCTION protect_balance_adjustment_history();
CREATE TRIGGER balance_adjustment_entries_immutable BEFORE UPDATE OR DELETE ON store_balance_entries FOR EACH ROW EXECUTE FUNCTION protect_balance_adjustment_history();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS balance_adjustment_entries_immutable ON store_balance_entries');
            DB::statement('DROP TRIGGER IF EXISTS balance_adjustment_audit_immutable ON audit_logs');
        }
        Schema::dropIfExists('balance_adjustment_auth_states');
        Schema::dropIfExists('balance_adjustment_accounting_events');
        Schema::dropIfExists('balance_adjustment_requests');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS protect_balance_adjustment_history()');
        }
    }
};
