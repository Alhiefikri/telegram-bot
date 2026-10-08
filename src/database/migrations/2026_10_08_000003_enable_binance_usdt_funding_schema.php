<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing installations were created with a BSC-only check. Replace
        // that check without rewriting any immutable intent/target snapshots.
        if (DB::getDriverName() === 'pgsql') {
            foreach (['customer_usdt_funding_intents', 'customer_usdt_top_ups'] as $table) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN receiver_id DROP NOT NULL");
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_money_check");
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_money_check CHECK (currency = 'USDT' AND rail IN ('bsc_usdt', 'binance_pay') AND amount_micro > 0 AND fee_micro >= 0 AND payable_micro >= amount_micro AND payable_micro - amount_micro = fee_micro AND policy_version > 0 AND terms_version > 0 AND ((rail = 'bsc_usdt' AND receiver_id IS NOT NULL) OR (rail = 'binance_pay' AND receiver_id IS NULL)))");
            }
        }
        if (DB::getDriverName() !== 'pgsql') {
            foreach (['customer_usdt_funding_intents', 'customer_usdt_top_ups'] as $table) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->unsignedBigInteger('receiver_id')->nullable()->change();
                });
            }
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS usdt_intents_one_pending_customer');
            DB::statement("CREATE UNIQUE INDEX usdt_intents_one_pending_customer ON customer_usdt_funding_intents(customer_id) WHERE state = 'pending'");
        }

        if (! Schema::hasColumn('customer_usdt_top_up_inbox', 'provider_reference')) {
            Schema::table('customer_usdt_top_up_inbox', function (Blueprint $table): void {
                $table->string('provider_reference', 191)->nullable();
                $table->index(['top_up_id', 'provider_reference'], 'usdt_inbox_provider_reference_index');
            });
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE customer_usdt_top_up_inbox ALTER COLUMN tx_hash DROP NOT NULL');
            DB::statement("ALTER TABLE customer_usdt_top_up_inbox ADD CONSTRAINT usdt_inbox_identity_check CHECK (tx_hash IS NOT NULL OR provider_reference IS NOT NULL)");
        } else {
            Schema::table('customer_usdt_top_up_inbox', function (Blueprint $table): void {
                $table->char('tx_hash', 66)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::table('customer_usdt_funding_intents')->where('rail', 'binance_pay')->exists()
            || DB::table('customer_usdt_top_up_inbox')->whereNull('tx_hash')->exists()) {
            throw new RuntimeException('Cannot roll back Binance funding schema while Binance funding history exists.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE customer_usdt_top_up_inbox DROP CONSTRAINT IF EXISTS usdt_inbox_identity_check');
            DB::statement('ALTER TABLE customer_usdt_top_up_inbox ALTER COLUMN tx_hash SET NOT NULL');
        }
        if (Schema::hasColumn('customer_usdt_top_up_inbox', 'provider_reference')) {
            Schema::table('customer_usdt_top_up_inbox', function (Blueprint $table): void {
                $table->dropIndex('usdt_inbox_provider_reference_index');
                $table->dropColumn('provider_reference');
            });
        }
    }
};
