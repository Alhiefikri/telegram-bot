<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const AMOUNT_INDEX = 'payments_pending_binance_auto_amount_unique';

    private const ORDER_ID_INDEX = 'payments_binance_order_lookup';

    private const PENDING_INDEX = 'payments_binance_pending_verification_lookup';

    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('binance_verification_mode', 32)->default('amount_unique')->after('binance_auto_verification_enabled');
            $table->string('binance_receiver_type', 16)->nullable()->after('binance_verification_mode');
            $table->string('binance_receiver_value', 128)->nullable()->after('binance_receiver_type');
            $table->string('binance_order_id')->nullable()->after('binance_auto_verification_enabled');
            $table->unsignedTinyInteger('binance_order_id_attempts')->default(0)->after('binance_order_id');
            $table->unsignedBigInteger('binance_order_id_submission_version')->default(0)->after('binance_order_id_attempts');
            $table->timestamp('binance_order_id_submitted_at')->nullable()->after('binance_order_id_attempts');
            $table->timestamp('binance_verification_grace_until')->nullable()->after('binance_order_id_submitted_at');
            $table->string('binance_verification_status', 32)->default('awaiting_submission')->after('binance_verification_grace_until');
            $table->timestamp('binance_last_verified_at')->nullable()->after('binance_verification_status');
            $table->index(['provider', 'method', 'status', 'binance_order_id'], self::ORDER_ID_INDEX);
            $table->index(['provider', 'method', 'binance_verification_mode', 'status', 'binance_verification_grace_until'], self::PENDING_INDEX);
        });

        Schema::table('manual_payment_settings', function (Blueprint $table): void {
            $table->string('binance_verification_mode', 32)->default('amount_unique')->after('binance_payment_ttl_minutes');
            $table->timestamp('binance_order_id_compatibility_ready_at')->nullable()->after('binance_payment_ttl_minutes');
        });
    }

    public function down(): void
    {
        $hasDuplicatePendingAmounts = DB::table('payments')
            ->where('provider', 'manual')
            ->where('method', 'BINANCE_PAY_ID')
            ->where('status', 'pending')
            ->where('binance_auto_verification_enabled', true)
            ->select('amount')
            ->groupBy('amount')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicatePendingAmounts) {
            throw new RuntimeException('Cannot roll back Binance Order ID migration while duplicate pending Binance amounts exist.');
        }

        DB::statement('DROP INDEX IF EXISTS '.self::PENDING_INDEX);
        DB::statement('DROP INDEX IF EXISTS '.self::ORDER_ID_INDEX);

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn([
                'binance_order_id',
                'binance_order_id_attempts',
                'binance_order_id_submission_version',
                'binance_order_id_submitted_at',
                'binance_verification_grace_until',
                'binance_verification_mode',
                'binance_receiver_type',
                'binance_receiver_value',
                'binance_verification_status',
                'binance_last_verified_at',
            ]);
        });

        Schema::table('manual_payment_settings', function (Blueprint $table): void {
            $table->dropColumn(['binance_order_id_compatibility_ready_at', 'binance_verification_mode']);
        });

        $this->restoreLegacyAmountIndex();
    }

    private function restoreLegacyAmountIndex(): void
    {
        $predicate = DB::getDriverName() === 'pgsql'
            ? "provider = 'manual' AND method = 'BINANCE_PAY_ID' AND status = 'pending' AND binance_auto_verification_enabled IS TRUE"
            : "provider = 'manual' AND method = 'BINANCE_PAY_ID' AND status = 'pending' AND binance_auto_verification_enabled = 1";

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.self::AMOUNT_INDEX.' ON payments (amount) WHERE '.$predicate);
    }
};
