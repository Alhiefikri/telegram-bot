<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->timestamp('payment_proof_forwarding_started_at')->nullable()->after('payment_proof_submitted_at');
            $table->uuid('payment_proof_forwarding_token')->nullable()->after('payment_proof_forwarding_started_at');
            $table->timestamp('payment_proof_forwarded_at')->nullable()->after('payment_proof_forwarding_token');
            $table->index(['provider', 'status', 'payment_proof_forwarded_at', 'payment_proof_forwarding_started_at', 'created_at'], 'payments_proof_forwarding_index');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex('payments_proof_forwarding_index');
            $table->dropColumn(['payment_proof_forwarding_started_at', 'payment_proof_forwarding_token', 'payment_proof_forwarded_at']);
        });
    }
};
