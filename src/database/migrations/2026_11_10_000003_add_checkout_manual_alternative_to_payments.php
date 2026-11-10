<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $amountIndexSql = null;
        if (DB::getDriverName() === 'sqlite') {
            $index = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'payments_pending_binance_auto_amount_unique'");
            $amountIndexSql = isset($index->sql) && stripos($index->sql, ' WHERE ') !== false
                ? $index->sql
                : "CREATE UNIQUE INDEX payments_pending_binance_auto_amount_unique ON payments (amount) WHERE provider = 'manual' AND method = 'BINANCE_PAY_ID' AND status = 'pending' AND binance_auto_verification_enabled = 1";
        }
        Schema::table('payments', function (Blueprint $table): void {
            $table->timestamp('manual_alternative_at')->nullable();
            $table->uuid('manual_alternative_retirement_token')->nullable();
            $table->timestamp('manual_alternative_retirement_started_at')->nullable();
            $table->uuid('manual_alternative_for_payment_id')->nullable()->unique();
            $table->foreign('manual_alternative_for_payment_id', 'payments_manual_alternative_source_fk')->references('id')->on('payments');
        });
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS payments_pending_binance_auto_amount_unique');
            DB::statement($amountIndexSql);
        }
    }

    public function down(): void
    {
        if (DB::table('payments')
            ->whereNotNull('manual_alternative_at')->orWhereNotNull('manual_alternative_for_payment_id')
            ->orWhereNotNull('manual_alternative_retirement_token')->exists()) {
            throw new RuntimeException('Cannot remove checkout manual-alternative provenance while switch or retirement history exists.');
        }

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign('payments_manual_alternative_source_fk');
            $table->dropColumn(['manual_alternative_at', 'manual_alternative_for_payment_id', 'manual_alternative_retirement_token', 'manual_alternative_retirement_started_at']);
        });
    }
};
