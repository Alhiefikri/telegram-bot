<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_balance_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('balance')->default(0);
            $table->timestamps();
        });

        Schema::create('store_balance_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_balance_account_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('balance_after');
            $table->string('source_type', 64);
            $table->string('source_id', 64);
            $table->string('idempotency_key', 191);
            $table->timestamp('created_at');

            $table->unique(['customer_balance_account_id', 'idempotency_key'], 'store_balance_entries_idempotency_unique');
            $table->unique(['source_type', 'source_id', 'kind'], 'store_balance_entries_source_unique');
            $table->index(['customer_balance_account_id', 'id'], 'store_balance_entries_account_cursor_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE customer_balance_accounts ADD CONSTRAINT customer_balance_accounts_balance_nonnegative CHECK (balance >= 0)');
            DB::statement('ALTER TABLE store_balance_entries ADD CONSTRAINT store_balance_entries_amount_positive CHECK (amount > 0)');
            DB::statement('ALTER TABLE store_balance_entries ADD CONSTRAINT store_balance_entries_balance_after_nonnegative CHECK (balance_after >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_balance_entries');
        Schema::dropIfExists('customer_balance_accounts');
    }
};
