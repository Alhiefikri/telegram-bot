<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shared-address BSC top-up rail.
 *
 * One Operator-controlled public BSC address serves every shared top-up
 * invoice. Attribution comes from an exact unique payable amount plus the
 * Customer-submitted transaction hash, not from per-Customer addresses. The
 * address itself is claimed in the permanent channel registry so it can never
 * also serve checkout or a Customer-exclusive deposit binding.
 *
 * The legacy per-Customer bsc_usdt rail stays readable for history; new
 * funding intents use bsc_shared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usdt_shared_top_up_receivers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('address_claim_id')
                ->unique('usdt_shared_top_up_claim_unique')
                ->constrained('usdt_receiver_address_claims', 'id', 'usdt_shared_top_up_claim_fk')
                ->restrictOnDelete();
            $table->string('label', 120);
            $table->unsignedBigInteger('chain_id');
            $table->char('address', 42);
            $table->char('address_normalized', 42);
            $table->char('token_contract', 42);
            $table->unsignedSmallInteger('token_decimals');
            $table->boolean('is_active')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['chain_id', 'address_normalized'], 'usdt_shared_top_up_address_unique');
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX usdt_shared_top_up_active_unique '
                .'ON usdt_shared_top_up_receivers (is_active) WHERE is_active = true',
            );
        }

        foreach (['customer_usdt_funding_intents', 'customer_usdt_top_ups'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->unsignedBigInteger('shared_receiver_id')->nullable();
                $table->foreign('shared_receiver_id', $tableName.'_shared_receiver_fk')
                    ->references('id')
                    ->on('usdt_shared_top_up_receivers')
                    ->restrictOnDelete();
            });

            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE {$tableName} DROP CONSTRAINT IF EXISTS {$tableName}_money_check");
                DB::statement(<<<SQL
ALTER TABLE {$tableName} ADD CONSTRAINT {$tableName}_money_check CHECK (
    currency = 'USDT'
    AND rail IN ('bsc_usdt', 'bsc_shared', 'binance_pay')
    AND amount_micro > 0
    AND fee_micro >= 0
    AND payable_micro >= amount_micro
    AND payable_micro - amount_micro = fee_micro
    AND policy_version > 0
    AND terms_version > 0
    AND (
        (rail = 'bsc_usdt' AND receiver_id IS NOT NULL AND shared_receiver_id IS NULL)
        OR (rail = 'bsc_shared' AND receiver_id IS NULL AND shared_receiver_id IS NOT NULL)
        OR (rail = 'binance_pay' AND receiver_id IS NULL AND shared_receiver_id IS NULL)
    )
)
SQL);
            }
        }

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            // SQLite rebuilds the intents table when the FK column is added,
            // and Laravel's rebuild drops the partial predicate. Recreate the
            // one-pending-intent-per-Customer index explicitly.
            DB::statement('DROP INDEX IF EXISTS usdt_intents_one_pending_customer');
            DB::statement("CREATE UNIQUE INDEX usdt_intents_one_pending_customer ON customer_usdt_funding_intents(customer_id) WHERE state = 'pending'");
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE customer_usdt_funding_reservations DROP CONSTRAINT IF EXISTS customer_usdt_funding_reservations_rail_check');
            DB::statement("ALTER TABLE customer_usdt_funding_reservations ADD CONSTRAINT customer_usdt_funding_reservations_rail_check CHECK (rail IN ('binance_pay', 'bsc_usdt', 'bsc_shared'))");
        }

        Schema::table('customer_usdt_top_ups', function (Blueprint $table): void {
            $table->index(['rail', 'amount_micro', 'created_at'], 'usdt_top_ups_shared_amount_index');
        });
        Schema::table('customer_usdt_funding_intents', function (Blueprint $table): void {
            $table->index(['rail', 'state', 'expires_at'], 'usdt_intents_shared_amount_index');
        });
    }

    public function down(): void
    {
        if (DB::table('customer_usdt_funding_intents')->where('rail', 'bsc_shared')->exists()
            || DB::table('customer_usdt_top_ups')->where('rail', 'bsc_shared')->exists()) {
            throw new RuntimeException('Cannot roll back shared BSC top-up schema while bsc_shared funding history exists.');
        }

        Schema::table('customer_usdt_top_ups', function (Blueprint $table): void {
            $table->dropIndex('usdt_top_ups_shared_amount_index');
        });
        Schema::table('customer_usdt_funding_intents', function (Blueprint $table): void {
            $table->dropIndex('usdt_intents_shared_amount_index');
        });

        foreach (['customer_usdt_funding_intents', 'customer_usdt_top_ups'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropForeign($tableName.'_shared_receiver_fk');
                $table->dropColumn('shared_receiver_id');
            });

            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE {$tableName} DROP CONSTRAINT IF EXISTS {$tableName}_money_check");
                DB::statement("ALTER TABLE {$tableName} ADD CONSTRAINT {$tableName}_money_check CHECK (currency = 'USDT' AND rail IN ('bsc_usdt', 'binance_pay') AND amount_micro > 0 AND fee_micro >= 0 AND payable_micro >= amount_micro AND payable_micro - amount_micro = fee_micro AND policy_version > 0 AND terms_version > 0 AND ((rail = 'bsc_usdt' AND receiver_id IS NOT NULL) OR (rail = 'binance_pay' AND receiver_id IS NULL)))");
            }
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE customer_usdt_funding_reservations DROP CONSTRAINT IF EXISTS customer_usdt_funding_reservations_rail_check');
            DB::statement("ALTER TABLE customer_usdt_funding_reservations ADD CONSTRAINT customer_usdt_funding_reservations_rail_check CHECK (rail IN ('binance_pay', 'bsc_usdt'))");
        }

        DB::statement('DROP INDEX IF EXISTS usdt_shared_top_up_active_unique');
        Schema::dropIfExists('usdt_shared_top_up_receivers');
    }
};
