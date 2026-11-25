<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_bsc_details', function (Blueprint $table): void {
            $table->string('submitted_tx_hash', 66)->nullable();
            $table->boolean('submitted_tx_pending')->default(false);
        });
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE INDEX bsc_detail_attribution_amount_idx ON payment_bsc_details (LOWER(receiver_address), LOWER(token_contract), token_decimals, expected_token_units)');
        }
        Schema::table('customer_usdt_funding_intents', function (Blueprint $table): void {
            $table->index(['rail', 'payable_micro'], 'usdt_funding_intent_rail_payable_idx');
        });
        Schema::table('customer_usdt_top_ups', function (Blueprint $table): void {
            $table->index(['rail', 'payable_micro'], 'usdt_top_up_rail_payable_idx');
        });
    }

    public function down(): void
    {
        Schema::table('customer_usdt_top_ups', function (Blueprint $table): void {
            $table->dropIndex('usdt_top_up_rail_payable_idx');
        });
        Schema::table('customer_usdt_funding_intents', function (Blueprint $table): void {
            $table->dropIndex('usdt_funding_intent_rail_payable_idx');
        });
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX bsc_detail_attribution_amount_idx');
        }
        Schema::table('payment_bsc_details', function (Blueprint $table): void {
            $table->dropColumn(['submitted_tx_hash', 'submitted_tx_pending']);
        });
    }
};
