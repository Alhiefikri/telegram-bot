<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usdt_top_up_review_requests', function (Blueprint $table): void {
            $table->char('transaction_hash', 66)->nullable()->change();
            $table->string('provider_reference', 191)->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE usdt_top_up_review_requests DROP CONSTRAINT usdt_review_values');
            DB::statement("ALTER TABLE usdt_top_up_review_requests ADD CONSTRAINT usdt_review_values CHECK (amount_micro > 0 AND version = 1 AND ((provider_reference IS NOT NULL AND transaction_hash IS NULL AND decision IN ('settled','rejected') AND refund_transaction_hash IS NULL AND refund_address IS NULL AND refund_evidence IS NULL AND refund_authorization_id IS NULL) OR (provider_reference IS NULL AND transaction_hash IS NOT NULL AND decision IN ('settled','resolved_by_compensation','externally_refunded') AND (decision <> 'externally_refunded' OR (refund_transaction_hash IS NOT NULL AND refund_address IS NOT NULL AND refund_evidence IS NOT NULL AND refund_authorization_id IS NOT NULL)))))");
            DB::statement('ALTER TABLE customer_usdt_funding_reservations DROP CONSTRAINT customer_usdt_funding_reservations_state_check');
            DB::statement("ALTER TABLE customer_usdt_funding_reservations ADD CONSTRAINT customer_usdt_funding_reservations_state_check CHECK ((state IN ('reserved','expired_unresolved') AND resolved_at IS NULL AND resolution_kind IS NULL AND settled_entry_id IS NULL) OR (state = 'settled' AND resolved_at IS NOT NULL AND resolution_kind = 'settled' AND settled_entry_id IS NOT NULL) OR (state = 'released' AND resolved_at IS NOT NULL AND resolution_kind IN ('never_exposed','provider_declined','externally_returned','compensated','rejected') AND settled_entry_id IS NULL))");
        }
    }

    public function down(): void
    {
        if (DB::table('usdt_top_up_review_requests')->whereNotNull('provider_reference')->exists()) {
            throw new RuntimeException('Binance review history cannot be removed.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE usdt_top_up_review_requests DROP CONSTRAINT usdt_review_values');
            DB::statement("ALTER TABLE usdt_top_up_review_requests ADD CONSTRAINT usdt_review_values CHECK (decision IN ('settled','resolved_by_compensation','externally_refunded') AND amount_micro > 0 AND version = 1 AND (decision <> 'externally_refunded' OR (refund_transaction_hash IS NOT NULL AND refund_address IS NOT NULL AND refund_evidence IS NOT NULL AND refund_authorization_id IS NOT NULL)))");
            DB::statement('ALTER TABLE customer_usdt_funding_reservations DROP CONSTRAINT customer_usdt_funding_reservations_state_check');
            DB::statement("ALTER TABLE customer_usdt_funding_reservations ADD CONSTRAINT customer_usdt_funding_reservations_state_check CHECK ((state IN ('reserved','expired_unresolved') AND resolved_at IS NULL AND resolution_kind IS NULL AND settled_entry_id IS NULL) OR (state = 'settled' AND resolved_at IS NOT NULL AND resolution_kind = 'settled' AND settled_entry_id IS NOT NULL) OR (state = 'released' AND resolved_at IS NOT NULL AND resolution_kind IN ('never_exposed','provider_declined','externally_returned','compensated') AND settled_entry_id IS NULL))");
        }
        Schema::table('usdt_top_up_review_requests', function (Blueprint $table): void {
            $table->dropColumn('provider_reference');
            $table->char('transaction_hash', 66)->nullable(false)->change();
        });
    }
};
