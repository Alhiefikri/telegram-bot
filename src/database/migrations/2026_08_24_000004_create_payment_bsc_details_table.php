<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const AMOUNT_INDEX = 'payments_pending_bsc_amount_unique';

    private const DEFAULT_VERIFICATION_STATUS = 'awaiting_transfer';

    public function up(): void
    {
        Schema::create('payment_bsc_details', function (Blueprint $table): void {
            $table->uuid('payment_id')->primary();
            $table->foreignId('receiver_id')->constrained('bsc_payment_receivers')->restrictOnDelete();
            $table->char('receiver_address', 42);
            $table->unsignedBigInteger('chain_id');
            $table->char('token_contract', 42);
            $table->unsignedTinyInteger('token_decimals');
            $table->unsignedBigInteger('base_amount_micro');
            $table->unsignedBigInteger('payable_amount_micro');
            $table->decimal('expected_token_units', 78, 0);
            $table->string('verification_status', 32)->default(self::DEFAULT_VERIFICATION_STATUS)->index();
            $table->unsignedBigInteger('tx_submission_version')->default(0);
            $table->unsignedTinyInteger('tx_attempts')->default(0);
            $table->timestamp('verification_grace_until')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->char('current_tx_hash', 66)->nullable();
            $table->unsignedBigInteger('current_confirmations')->nullable();
            $table->unsignedBigInteger('current_block_number')->nullable();
            $table->char('current_from_address', 42)->nullable();
            $table->json('evidence_payload')->nullable();
            $table->timestamps();

            $table->foreign('payment_id')
                ->references('id')
                ->on('payments')
                ->cascadeOnDelete();
            $table->index(['verification_status', 'last_verified_at'], 'payment_bsc_details_verification_lookup');
        });

        $predicate = "provider = 'manual' AND method = 'USDT_BEP20' AND status = 'pending'";

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::AMOUNT_INDEX.' ON payments (amount) WHERE '.$predicate,
            );
        } else {
            Schema::table('payments', function (Blueprint $table): void {
                $table->unique('amount', self::AMOUNT_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS '.self::AMOUNT_INDEX);
        } else {
            Schema::table('payments', function (Blueprint $table): void {
                $table->dropUnique(self::AMOUNT_INDEX);
            });
        }

        Schema::dropIfExists('payment_bsc_details');
    }
};
