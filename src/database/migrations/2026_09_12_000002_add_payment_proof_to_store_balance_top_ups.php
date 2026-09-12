<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_balance_top_ups', function (Blueprint $table): void {
            $table->string('payment_proof_file_id', 191)->nullable()->after('provider_create_last_error');
            $table->timestamp('payment_proof_submitted_at')->nullable()->after('payment_proof_file_id');
            $table->string('payment_proof_admin_chat_id', 64)->nullable()->after('payment_proof_submitted_at');
            $table->timestamp('payment_proof_forwarded_at')->nullable()->after('payment_proof_admin_chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('store_balance_top_ups', function (Blueprint $table): void {
            $table->dropColumn([
                'payment_proof_file_id',
                'payment_proof_submitted_at',
                'payment_proof_admin_chat_id',
                'payment_proof_forwarded_at',
            ]);
        });
    }
};
