<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_balance_funding_intents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('telegram_navigation_root_id')
                ->nullable()
                ->constrained('telegram_navigation_roots')
                ->nullOnDelete();
            $table->string('chat_id', 64);
            $table->unsignedBigInteger('canonical_message_id');
            $table->unsignedBigInteger('policy_version');
            $table->unsignedBigInteger('amount');
            $table->string('nonce', 64)->unique();
            $table->string('status', 24)->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();

            $table->index(
                ['customer_id', 'status', 'expires_at'],
                'store_balance_funding_intents_current_index',
            );
            $table->index(
                ['customer_id', 'chat_id', 'canonical_message_id'],
                'store_balance_funding_intents_root_index',
            );
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                "CREATE UNIQUE INDEX store_balance_funding_intents_one_pending_customer
                 ON store_balance_funding_intents (customer_id)
                 WHERE status = 'pending'",
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS store_balance_funding_intents_one_pending_customer');
        }

        Schema::dropIfExists('store_balance_funding_intents');
    }
};
