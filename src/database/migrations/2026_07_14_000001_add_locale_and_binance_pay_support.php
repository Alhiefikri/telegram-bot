<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->string('locale', 2)->nullable()->index()->after('phone_number'));
        Schema::table('products', fn (Blueprint $table) => $table->unsignedBigInteger('usdt_price_amount')->nullable()->after('price_amount'));
        Schema::table('manual_payment_settings', fn (Blueprint $table) => $table->boolean('binance_pay_enabled')->default(false)->after('is_active'));
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('payment_proof_file_id')->nullable()->after('provider_payment_number_hash');
            $table->timestamp('payment_proof_submitted_at')->nullable()->after('payment_proof_file_id');
            $table->string('payment_proof_admin_chat_id')->nullable()->after('payment_proof_submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn(['payment_proof_file_id', 'payment_proof_submitted_at', 'payment_proof_admin_chat_id']));
        Schema::table('manual_payment_settings', fn (Blueprint $table) => $table->dropColumn('binance_pay_enabled'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('usdt_price_amount'));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('locale'));
    }
};
