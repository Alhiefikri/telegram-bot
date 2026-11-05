<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('balance_adjustment_requests', function (Blueprint $table): void {
            $table->foreignId('usdt_ledger_entry_id')->nullable()->unique()->constrained('customer_usdt_balance_entries')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE balance_adjustment_requests DROP CONSTRAINT balance_adjustment_money_check');
            DB::statement('ALTER TABLE balance_adjustment_requests ALTER COLUMN currency TYPE VARCHAR(4)');
            DB::statement("ALTER TABLE balance_adjustment_requests ADD CONSTRAINT balance_adjustment_money_check CHECK (amount > 0 AND currency IN ('IDR', 'USDT') AND (currency <> 'USDT' OR (category = 'owner_attested' AND source_id IS NULL AND receipt_fingerprint IS NULL)) AND mode = 'single_owner' AND version = 1)");
            DB::statement('ALTER TABLE balance_adjustment_requests DROP CONSTRAINT balance_adjustment_state_check');
            DB::statement("ALTER TABLE balance_adjustment_requests ADD CONSTRAINT balance_adjustment_state_check CHECK ((state = 'applied' AND resolved_at IS NOT NULL AND ((currency = 'IDR' AND ledger_entry_id IS NOT NULL AND usdt_ledger_entry_id IS NULL) OR (currency = 'USDT' AND usdt_ledger_entry_id IS NOT NULL AND ledger_entry_id IS NULL))) OR (state IN ('pending','approved','rejected','cancelled','expired') AND ledger_entry_id IS NULL AND usdt_ledger_entry_id IS NULL))");
            DB::statement('ALTER TABLE customer_usdt_balance_entries DROP CONSTRAINT customer_usdt_balance_entries_kind_check');
            DB::statement("ALTER TABLE customer_usdt_balance_entries ADD CONSTRAINT customer_usdt_balance_entries_kind_check CHECK (kind IN ('funding_credit', 'owner_adjustment_credit', 'purchase_debit', 'refund_credit', 'reversal_debit'))");
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
       (to_jsonb(NEW) - ARRAY['state','ledger_entry_id','usdt_ledger_entry_id','resolved_at','updated_at']) IS DISTINCT FROM
       (to_jsonb(OLD) - ARRAY['state','ledger_entry_id','usdt_ledger_entry_id','resolved_at','updated_at']) THEN
        RAISE EXCEPTION 'Balance adjustment identity is immutable';
    END IF;
    RETURN NEW;
END $$;
SQL);
        }
    }

    public function down(): void
    {
        if (DB::table('balance_adjustment_requests')->where('currency', 'USDT')->orWhereNotNull('usdt_ledger_entry_id')->exists()
            || DB::table('customer_usdt_balance_entries')->where('kind', 'owner_adjustment_credit')->exists()) {
            throw new RuntimeException('Cannot roll back owner USDT adjustments while adjustment history exists.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE balance_adjustment_requests DROP CONSTRAINT balance_adjustment_money_check');
            DB::statement("ALTER TABLE balance_adjustment_requests ADD CONSTRAINT balance_adjustment_money_check CHECK (amount > 0 AND currency = 'IDR' AND mode = 'single_owner' AND version = 1)");
            DB::statement('ALTER TABLE balance_adjustment_requests ALTER COLUMN currency TYPE VARCHAR(3)');
            DB::statement('ALTER TABLE balance_adjustment_requests DROP CONSTRAINT balance_adjustment_state_check');
            DB::statement("ALTER TABLE balance_adjustment_requests ADD CONSTRAINT balance_adjustment_state_check CHECK ((state = 'applied' AND ledger_entry_id IS NOT NULL AND resolved_at IS NOT NULL) OR (state IN ('pending','approved','rejected','cancelled','expired') AND ledger_entry_id IS NULL))");
            DB::statement('ALTER TABLE customer_usdt_balance_entries DROP CONSTRAINT customer_usdt_balance_entries_kind_check');
            DB::statement("ALTER TABLE customer_usdt_balance_entries ADD CONSTRAINT customer_usdt_balance_entries_kind_check CHECK (kind IN ('funding_credit', 'purchase_debit', 'refund_credit', 'reversal_debit'))");
        }
        Schema::table('balance_adjustment_requests', function (Blueprint $table): void {
            $table->dropUnique(['usdt_ledger_entry_id']);
        });
        Schema::table('balance_adjustment_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('usdt_ledger_entry_id');
        });
    }
};
