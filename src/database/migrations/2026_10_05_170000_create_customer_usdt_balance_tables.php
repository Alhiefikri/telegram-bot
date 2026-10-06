<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_usdt_balance_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained()->restrictOnDelete();
            $table->string('currency', 4)->default('USDT');
            $table->bigInteger('balance_micro')->default(0);
            $table->unsignedBigInteger('version')->default(0);
            $table->unsignedBigInteger('last_entry_id')->nullable();
            $table->string('frozen_reason', 191)->nullable();
            $table->timestamps();
        });

        Schema::create('customer_usdt_balance_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_usdt_balance_account_id')
                ->constrained('customer_usdt_balance_accounts', 'id', 'usdt_balance_entries_account_fk')
                ->restrictOnDelete();
            $table->unsignedBigInteger('account_sequence');
            $table->string('kind', 32);
            $table->bigInteger('amount_micro');
            $table->bigInteger('balance_after_micro');
            $table->string('source_type', 64);
            $table->string('source_id', 64);
            $table->string('idempotency_key', 191);
            $table->string('request_fingerprint', 64);
            $table->unsignedBigInteger('original_entry_id')->nullable();
            $table->foreign('original_entry_id')->references('id')->on('customer_usdt_balance_entries')->restrictOnDelete();
            $table->foreignUuid('refund_order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->timestamp('created_at');

            $table->unique(['customer_usdt_balance_account_id', 'account_sequence'], 'usdt_balance_entries_sequence_unique');
            $table->unique(['customer_usdt_balance_account_id', 'idempotency_key'], 'usdt_balance_entries_idempotency_unique');
            $table->unique(['source_type', 'source_id', 'kind'], 'usdt_balance_entries_source_unique');
            $table->index(['customer_usdt_balance_account_id', 'id'], 'usdt_balance_entries_account_cursor_index');
        });

        Schema::create('customer_usdt_balance_accounting_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_usdt_balance_entry_id')
                ->unique('usdt_balance_accounting_entry_unique')
                ->constrained('customer_usdt_balance_entries', 'id', 'usdt_balance_accounting_entry_fk')
                ->restrictOnDelete();
            $table->string('currency', 4);
            $table->bigInteger('amount_micro');
            $table->string('debit_account', 64);
            $table->string('credit_account', 64);
            $table->string('source_fingerprint', 64);
            $table->timestamp('created_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE customer_usdt_balance_accounts ADD CONSTRAINT customer_usdt_balance_accounts_balance_nonnegative CHECK (balance_micro >= 0)');
            DB::statement("ALTER TABLE customer_usdt_balance_accounts ADD CONSTRAINT customer_usdt_balance_accounts_currency_check CHECK (currency = 'USDT')");
            DB::statement("ALTER TABLE customer_usdt_balance_entries ADD CONSTRAINT customer_usdt_balance_entries_kind_check CHECK (kind IN ('funding_credit', 'purchase_debit', 'refund_credit', 'reversal_debit'))");
            DB::statement('ALTER TABLE customer_usdt_balance_entries ADD CONSTRAINT customer_usdt_balance_entries_amount_positive CHECK (amount_micro > 0)');
            DB::statement('ALTER TABLE customer_usdt_balance_entries ADD CONSTRAINT customer_usdt_balance_entries_balance_after_nonnegative CHECK (balance_after_micro >= 0)');
            DB::statement('ALTER TABLE customer_usdt_balance_accounting_events ADD CONSTRAINT customer_usdt_balance_accounting_events_amount_positive CHECK (amount_micro > 0)');
            DB::statement("ALTER TABLE customer_usdt_balance_accounting_events ADD CONSTRAINT customer_usdt_balance_accounting_events_currency_check CHECK (currency = 'USDT')");
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_customer_usdt_balance_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Customer USDT balance ledger history is immutable';
END $$;
SQL);
            DB::statement('CREATE TRIGGER customer_usdt_balance_entries_immutable BEFORE UPDATE OR DELETE ON customer_usdt_balance_entries FOR EACH ROW EXECUTE FUNCTION protect_customer_usdt_balance_history()');
            DB::statement('CREATE TRIGGER customer_usdt_balance_accounting_immutable BEFORE UPDATE OR DELETE ON customer_usdt_balance_accounting_events FOR EACH ROW EXECUTE FUNCTION protect_customer_usdt_balance_history()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS customer_usdt_balance_entries_immutable ON customer_usdt_balance_entries');
            DB::statement('DROP TRIGGER IF EXISTS customer_usdt_balance_accounting_immutable ON customer_usdt_balance_accounting_events');
            DB::statement('DROP FUNCTION IF EXISTS protect_customer_usdt_balance_history()');
        }

        Schema::dropIfExists('customer_usdt_balance_accounting_events');
        Schema::dropIfExists('customer_usdt_balance_entries');
        Schema::dropIfExists('customer_usdt_balance_accounts');
    }
};
