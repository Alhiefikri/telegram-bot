<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usdt_transfer_recovery_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('receiver_id')->constrained('bsc_payment_receivers')->restrictOnDelete();
            $table->bigInteger('amount_micro');
            $table->string('transaction_hash', 66);
            $table->text('reason');
            $table->json('evidence');
            $table->string('fingerprint', 64);
            $table->string('credential_binding', 64);
            $table->string('session_binding', 64);
            $table->timestamp('expires_at');
            $table->timestamp('created_at');
        });
        Schema::create('usdt_transfer_recoveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->unique()->constrained('usdt_transfer_recovery_requests')->restrictOnDelete();
            $table->foreignId('receipt_id')->unique()->constrained('external_money_receipts')->restrictOnDelete();
            $table->foreignId('entry_id')->unique()->constrained('customer_usdt_balance_entries')->restrictOnDelete();
            $table->timestamp('created_at');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE usdt_transfer_recovery_requests ADD CONSTRAINT usdt_transfer_recovery_amount_positive CHECK (amount_micro > 0)');
            foreach (['usdt_transfer_recovery_requests', 'usdt_transfer_recoveries'] as $table) {
                DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION protect_customer_usdt_balance_history()");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('usdt_transfer_recoveries');
        Schema::dropIfExists('usdt_transfer_recovery_requests');
    }
};
