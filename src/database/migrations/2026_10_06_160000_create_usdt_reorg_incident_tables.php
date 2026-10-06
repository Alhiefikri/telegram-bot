<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usdt_reorg_monitor', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('cursor')->default(0);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamp('updated_at');
        });
        DB::table('usdt_reorg_monitor')->insert(['id' => 1, 'updated_at' => now()]);
        Schema::create('usdt_reorg_receiver_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('receiver_id')->constrained('customer_usdt_deposit_receivers')->restrictOnDelete();
            $table->unsignedBigInteger('checkpoint_version');
            $table->string('reason', 64);
            $table->json('evidence');
            $table->timestamp('created_at');
            $table->unique(['receiver_id', 'checkpoint_version'], 'usdt_reorg_receiver_version');
        });
        Schema::create('usdt_reorg_incidents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('top_up_id')->unique();
            $table->foreign('top_up_id')->references('id')->on('customer_usdt_top_ups')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained('customer_usdt_balance_accounts')->restrictOnDelete();
            $table->foreignId('receipt_id')->constrained('external_money_receipts')->restrictOnDelete();
            $table->foreignId('entry_id')->constrained('customer_usdt_balance_entries')->restrictOnDelete();
            $table->foreignId('receiver_id')->constrained('customer_usdt_deposit_receivers')->restrictOnDelete();
            $table->string('reason', 64);
            $table->unsignedBigInteger('exposure_micro');
            $table->unsignedBigInteger('available_exposure_micro');
            $table->unsignedBigInteger('spent_exposure_micro');
            $table->unsignedBigInteger('account_version');
            $table->unsignedBigInteger('checkpoint_version')->nullable();
            $table->json('evidence');
            $table->timestamp('created_at');
            $table->index(['account_id', 'id'], 'usdt_reorg_account_incidents');
            $table->index(['customer_id', 'id'], 'usdt_reorg_customer_incidents');
        });
        Schema::create('usdt_reorg_recovery_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('incident_id')->constrained('usdt_reorg_incidents')->restrictOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('nonce');
            $table->string('reason', 500);
            $table->char('fingerprint', 64);
            $table->char('credential_binding', 64);
            $table->char('session_binding', 64);
            $table->timestamp('expires_at');
            $table->timestamp('created_at');
            $table->unique(['owner_user_id', 'nonce'], 'usdt_reorg_request_nonce');
        });
        Schema::create('usdt_reorg_recovery_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->unique()->constrained('usdt_reorg_incidents')->restrictOnDelete();
            $table->uuid('request_id')->unique();
            $table->foreign('request_id')->references('id')->on('usdt_reorg_recovery_requests')->restrictOnDelete();
            $table->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 32)->default('authorized_unresolved');
            $table->timestamp('created_at');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE usdt_reorg_incidents ADD CONSTRAINT usdt_reorg_exposure CHECK (exposure_micro > 0 AND available_exposure_micro + spent_exposure_micro = exposure_micro)');
            DB::statement("ALTER TABLE usdt_reorg_recovery_cases ADD CONSTRAINT usdt_reorg_case_status CHECK (status = 'authorized_unresolved')");
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_usdt_reorg_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'USDT reorg evidence and authorization are immutable'; END $$;
CREATE TRIGGER usdt_reorg_receiver_immutable BEFORE UPDATE OR DELETE ON usdt_reorg_receiver_evidence FOR EACH ROW EXECUTE FUNCTION guard_usdt_reorg_history();
CREATE TRIGGER usdt_reorg_incident_immutable BEFORE UPDATE OR DELETE ON usdt_reorg_incidents FOR EACH ROW EXECUTE FUNCTION guard_usdt_reorg_history();
CREATE TRIGGER usdt_reorg_request_immutable BEFORE UPDATE OR DELETE ON usdt_reorg_recovery_requests FOR EACH ROW EXECUTE FUNCTION guard_usdt_reorg_history();
CREATE TRIGGER usdt_reorg_case_immutable BEFORE UPDATE OR DELETE ON usdt_reorg_recovery_cases FOR EACH ROW EXECUTE FUNCTION guard_usdt_reorg_history();
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('usdt_reorg_recovery_cases');
        Schema::dropIfExists('usdt_reorg_recovery_requests');
        Schema::dropIfExists('usdt_reorg_incidents');
        Schema::dropIfExists('usdt_reorg_receiver_evidence');
        Schema::dropIfExists('usdt_reorg_monitor');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS guard_usdt_reorg_history()');
        }
    }
};
