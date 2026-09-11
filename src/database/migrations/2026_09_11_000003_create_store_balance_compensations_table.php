<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_balance_compensations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_balance_top_up_id')
                ->unique()
                ->constrained('store_balance_top_ups')
                ->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('ledger_entry_id')
                ->unique()
                ->constrained('store_balance_entries')
                ->restrictOnDelete();
            $table->string('resolution_type', 32);
            $table->string('idempotency_key', 191)->unique();
            $table->string('provider_reference', 191);
            $table->text('reason');
            $table->unsignedBigInteger('amount');
            $table->timestamp('resolved_at');
            $table->timestamp('created_at');

            $table->index(['customer_id', 'created_at'], 'store_balance_compensations_customer_created_index');
            $table->index(['resolution_type', 'created_at'], 'store_balance_compensations_type_created_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE store_balance_compensations ADD CONSTRAINT store_balance_compensations_amount_positive CHECK (amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_balance_compensations');
    }
};
