<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usdt_top_up_review_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('top_up_id');
            $table->foreign('top_up_id')->references('id')->on('customer_usdt_top_ups')->restrictOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('decision', 32);
            $table->unsignedBigInteger('amount_micro');
            $table->char('transaction_hash', 66);
            $table->char('refund_transaction_hash', 66)->nullable();
            $table->char('refund_address', 42)->nullable();
            $table->text('reason');
            $table->char('fingerprint', 64);
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('work_version');
            $table->unsignedBigInteger('checkpoint_version')->nullable();
            $table->char('target_fingerprint', 64);
            $table->char('session_binding', 64);
            $table->char('credential_binding', 64);
            $table->json('chain_evidence');
            $table->json('refund_evidence')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('created_at');
            $table->index(['top_up_id', 'created_at']);
        });
        Schema::create('usdt_top_up_external_return_authorizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('top_up_id');
            $table->foreign('top_up_id')->references('id')->on('customer_usdt_top_ups')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->unsignedBigInteger('amount_micro');
            $table->unsignedBigInteger('chain_id');
            $table->char('token_contract', 42);
            $table->char('destination_address', 42);
            $table->char('nonce', 64);
            $table->timestamp('created_at');
            $table->unique(['customer_id', 'nonce']);
        });
        Schema::table('usdt_top_up_review_requests', function (Blueprint $table): void {
            $table->uuid('refund_authorization_id')->nullable();
            $table->foreign('refund_authorization_id')->references('id')->on('usdt_top_up_external_return_authorizations')->restrictOnDelete();
        });
        Schema::table('customer_usdt_top_up_resolutions', function (Blueprint $table): void {
            $table->uuid('review_request_id')->nullable()->unique();
            $table->foreign('review_request_id')->references('id')->on('usdt_top_up_review_requests')->restrictOnDelete();
            $table->unsignedBigInteger('resolved_amount_micro')->nullable();
            $table->foreignId('refund_receipt_id')->nullable()->unique()->constrained('external_money_receipts')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE customer_usdt_top_up_resolutions DROP CONSTRAINT usdt_resolution_kind');
            DB::statement("ALTER TABLE customer_usdt_top_up_resolutions ADD CONSTRAINT usdt_resolution_kind CHECK (kind IN ('settled','externally_refunded','resolved_by_compensation','rejected') AND ((kind = 'settled' AND receipt_id IS NOT NULL AND entry_id IS NOT NULL AND evidence_id IS NOT NULL) OR (kind = 'resolved_by_compensation' AND ((review_request_id IS NULL AND entry_id IS NULL) OR (review_request_id IS NOT NULL AND receipt_id IS NOT NULL AND entry_id IS NOT NULL AND evidence_id IS NOT NULL))) OR (kind = 'externally_refunded' AND entry_id IS NULL) OR (kind = 'rejected' AND entry_id IS NULL)))");
            DB::statement("ALTER TABLE usdt_top_up_review_requests ADD CONSTRAINT usdt_review_values CHECK (decision IN ('settled','resolved_by_compensation','externally_refunded') AND amount_micro > 0 AND version = 1 AND (decision <> 'externally_refunded' OR (refund_transaction_hash IS NOT NULL AND refund_address IS NOT NULL AND refund_evidence IS NOT NULL AND refund_authorization_id IS NOT NULL)))");
            DB::statement('CREATE TRIGGER usdt_external_return_immutable BEFORE UPDATE OR DELETE ON usdt_top_up_external_return_authorizations FOR EACH ROW EXECUTE FUNCTION guard_usdt_settlement_history()');
            DB::statement('CREATE TRIGGER usdt_review_immutable BEFORE UPDATE OR DELETE ON usdt_top_up_review_requests FOR EACH ROW EXECUTE FUNCTION guard_usdt_settlement_history()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE customer_usdt_top_up_resolutions DROP CONSTRAINT usdt_resolution_kind');
        }
        Schema::table('customer_usdt_top_up_resolutions', function (Blueprint $table): void {
            $table->dropForeign(['review_request_id']);
            $table->dropForeign(['refund_receipt_id']);
            $table->dropColumn(['review_request_id', 'resolved_amount_micro', 'refund_receipt_id']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE customer_usdt_top_up_resolutions ADD CONSTRAINT usdt_resolution_kind CHECK (kind IN ('settled','externally_refunded','resolved_by_compensation','rejected') AND ((kind = 'settled' AND receipt_id IS NOT NULL AND entry_id IS NOT NULL AND evidence_id IS NOT NULL) OR (kind <> 'settled' AND entry_id IS NULL)))");
        }
        Schema::dropIfExists('usdt_top_up_review_requests');
        Schema::dropIfExists('usdt_top_up_external_return_authorizations');
    }
};
