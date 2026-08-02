<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->repairPaymentColumns();
        $this->repairSettingColumns();

        DB::table('payments')
            ->whereNull('binance_verification_mode')
            ->update(['binance_verification_mode' => 'amount_unique']);
        DB::table('manual_payment_settings')
            ->whereNull('binance_verification_mode')
            ->update(['binance_verification_mode' => 'amount_unique']);

        DB::statement('CREATE INDEX IF NOT EXISTS payments_binance_order_lookup ON payments (provider, method, status, binance_order_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS payments_binance_pending_verification_lookup ON payments (provider, method, binance_verification_mode, status, binance_verification_grace_until)');
    }

    public function down(): void
    {
        // Forward-only repair: these columns may belong to the previously applied migration.
    }

    private function repairPaymentColumns(): void
    {
        if (! Schema::hasColumn('payments', 'binance_verification_mode')) {
            Schema::table('payments', fn (Blueprint $table) => $table->string('binance_verification_mode', 32)->default('amount_unique'));
        }
        if (! Schema::hasColumn('payments', 'binance_receiver_type')) {
            Schema::table('payments', fn (Blueprint $table) => $table->string('binance_receiver_type', 16)->nullable());
        }
        if (! Schema::hasColumn('payments', 'binance_receiver_value')) {
            Schema::table('payments', fn (Blueprint $table) => $table->string('binance_receiver_value', 128)->nullable());
        }
        if (! Schema::hasColumn('payments', 'binance_order_id')) {
            Schema::table('payments', fn (Blueprint $table) => $table->string('binance_order_id')->nullable());
        }
        if (! Schema::hasColumn('payments', 'binance_order_id_attempts')) {
            Schema::table('payments', fn (Blueprint $table) => $table->unsignedTinyInteger('binance_order_id_attempts')->default(0));
        }
        if (! Schema::hasColumn('payments', 'binance_order_id_submitted_at')) {
            Schema::table('payments', fn (Blueprint $table) => $table->timestamp('binance_order_id_submitted_at')->nullable());
        }
        if (! Schema::hasColumn('payments', 'binance_verification_grace_until')) {
            Schema::table('payments', fn (Blueprint $table) => $table->timestamp('binance_verification_grace_until')->nullable());
        }
        if (! Schema::hasColumn('payments', 'binance_order_id_submission_version')) {
            Schema::table('payments', fn (Blueprint $table) => $table->unsignedBigInteger('binance_order_id_submission_version')->default(0));
        }
        if (! Schema::hasColumn('payments', 'binance_verification_status')) {
            Schema::table('payments', fn (Blueprint $table) => $table->string('binance_verification_status', 32)->default('awaiting_submission'));
        }
        if (! Schema::hasColumn('payments', 'binance_last_verified_at')) {
            Schema::table('payments', fn (Blueprint $table) => $table->timestamp('binance_last_verified_at')->nullable());
        }
    }

    private function repairSettingColumns(): void
    {
        if (! Schema::hasColumn('manual_payment_settings', 'binance_verification_mode')) {
            Schema::table('manual_payment_settings', fn (Blueprint $table) => $table->string('binance_verification_mode', 32)->default('amount_unique'));
        }
        if (! Schema::hasColumn('manual_payment_settings', 'binance_order_id_compatibility_ready_at')) {
            Schema::table('manual_payment_settings', fn (Blueprint $table) => $table->timestamp('binance_order_id_compatibility_ready_at')->nullable());
        }
    }
};
