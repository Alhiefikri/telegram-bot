<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usdt_bsc_rail_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference', 96)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('receiver_id')->constrained('customer_usdt_deposit_receivers')->restrictOnDelete();
            $table->char('request_fingerprint', 64);
            $table->string('currency', 4);
            $table->bigInteger('amount_micro');
            $table->bigInteger('fee_micro');
            $table->bigInteger('payable_micro');
            $table->unsignedBigInteger('chain_id');
            $table->char('token_contract', 42);
            $table->unsignedSmallInteger('token_decimals');
            $table->char('receiver_address', 42);
            $table->string('custody_namespace', 64);
            $table->unsignedInteger('minimum_confirmations');
            $table->boolean('requires_finalized_block');
            $table->unsignedBigInteger('start_block');
            $table->unsignedBigInteger('starts_at_epoch');
            $table->unsignedBigInteger('expires_at_epoch');
            $table->timestamp('created_at');
            $table->index(['receiver_id', 'expires_at_epoch'], 'usdt_bsc_invoices_receiver_window');
        });

        Schema::create('usdt_bsc_scan_checkpoints', function (Blueprint $table): void {
            $table->foreignId('receiver_id')->primary()->constrained('customer_usdt_deposit_receivers')->restrictOnDelete();
            $table->unsignedBigInteger('first_block');
            $table->bigInteger('last_block')->nullable();
            $table->char('last_block_hash', 66)->nullable();
            $table->unsignedBigInteger('version')->default(0);
            $table->string('review_reason', 64)->nullable();
            $table->timestamp('updated_at');
        });

        // Observations are not receipt claims or credit authority. Retain each
        // block-hash variant so a reorg never silently rewrites old evidence.
        Schema::create('usdt_bsc_transfer_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('receiver_id')->constrained('customer_usdt_deposit_receivers')->restrictOnDelete();
            $table->unsignedBigInteger('chain_id');
            $table->char('tx_hash', 66);
            $table->unsignedBigInteger('log_index');
            $table->unsignedBigInteger('block_number');
            $table->char('block_hash', 66);
            $table->char('token_contract', 42);
            $table->char('receiver_address', 42);
            $table->string('amount_units', 78);
            $table->timestamp('observed_at');
            $table->unique(['chain_id', 'tx_hash', 'log_index', 'block_hash'], 'usdt_bsc_observations_identity');
            $table->index(['receiver_id', 'id'], 'usdt_bsc_observations_receiver_cursor');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE usdt_bsc_rail_invoices ADD CONSTRAINT usdt_bsc_invoice_values CHECK (currency = 'USDT' AND amount_micro > 0 AND fee_micro = 0 AND payable_micro = amount_micro AND chain_id = 56 AND token_decimals = 18 AND minimum_confirmations >= 3 AND requires_finalized_block AND starts_at_epoch >= 0 AND expires_at_epoch > starts_at_epoch)");
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION protect_usdt_bsc_rail_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    RAISE EXCEPTION 'USDT BSC invoice/observation evidence is immutable';
                END;
                $$;
                CREATE TRIGGER usdt_bsc_invoices_immutable BEFORE UPDATE OR DELETE ON usdt_bsc_rail_invoices FOR EACH ROW EXECUTE FUNCTION protect_usdt_bsc_rail_evidence();
                CREATE TRIGGER usdt_bsc_observations_immutable BEFORE UPDATE OR DELETE ON usdt_bsc_transfer_observations FOR EACH ROW EXECUTE FUNCTION protect_usdt_bsc_rail_evidence();
                CREATE OR REPLACE FUNCTION enforce_usdt_bsc_invoice_receiver() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM customer_usdt_deposit_receivers r
                        JOIN usdt_receiver_address_claims c ON c.id = r.address_claim_id
                        WHERE r.id = NEW.receiver_id AND r.customer_id = NEW.customer_id
                          AND r.chain_id = NEW.chain_id AND r.token_contract = NEW.token_contract
                          AND r.token_decimals = NEW.token_decimals AND r.address_normalized = NEW.receiver_address
                          AND r.custody_namespace = NEW.custody_namespace AND r.verified_at IS NOT NULL
                          AND r.verification_status IN ('verified', 'manual_verified') AND r.retired_at IS NULL
                          AND c.channel = 'customer_deposit'
                    ) THEN
                        RAISE EXCEPTION 'BSC invoice receiver binding is not qualified';
                    END IF;
                    RETURN NEW;
                END;
                $$;
                CREATE TRIGGER usdt_bsc_invoice_receiver_guard BEFORE INSERT ON usdt_bsc_rail_invoices FOR EACH ROW EXECUTE FUNCTION enforce_usdt_bsc_invoice_receiver();
                SQL);
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach (['usdt_bsc_rail_invoices', 'usdt_bsc_transfer_observations'] as $table) {
                foreach (['UPDATE', 'DELETE'] as $operation) {
                    DB::unprepared("CREATE TRIGGER {$table}_immutable_{$operation} BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'USDT BSC invoice/observation evidence is immutable'); END");
                }
            }
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER usdt_bsc_invoice_receiver_guard BEFORE INSERT ON usdt_bsc_rail_invoices
                WHEN NEW.currency != 'USDT' OR NEW.amount_micro <= 0 OR NEW.fee_micro != 0
                    OR NEW.payable_micro != NEW.amount_micro OR NEW.chain_id != 56 OR NEW.token_decimals != 18
                    OR NEW.minimum_confirmations < 3 OR NEW.requires_finalized_block != 1
                    OR NEW.starts_at_epoch < 0 OR NEW.expires_at_epoch <= NEW.starts_at_epoch OR NOT EXISTS (
                        SELECT 1 FROM customer_usdt_deposit_receivers r
                        JOIN usdt_receiver_address_claims c ON c.id = r.address_claim_id
                        WHERE r.id = NEW.receiver_id AND r.customer_id = NEW.customer_id
                          AND r.chain_id = NEW.chain_id AND r.token_contract = NEW.token_contract
                          AND r.token_decimals = NEW.token_decimals AND r.address_normalized = NEW.receiver_address
                          AND r.custody_namespace = NEW.custody_namespace AND r.verified_at IS NOT NULL
                          AND r.verification_status IN ('verified', 'manual_verified') AND r.retired_at IS NULL
                          AND c.channel = 'customer_deposit'
                    )
                BEGIN SELECT RAISE(ABORT, 'BSC invoice receiver or values are not qualified'); END
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('usdt_bsc_transfer_observations');
        Schema::dropIfExists('usdt_bsc_scan_checkpoints');
        Schema::dropIfExists('usdt_bsc_rail_invoices');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS protect_usdt_bsc_rail_evidence()');
            DB::statement('DROP FUNCTION IF EXISTS enforce_usdt_bsc_invoice_receiver()');
        }
    }
};
