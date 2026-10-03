<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_account_units', function (Blueprint $table): void {
            $table->string('confirmation_state', 24)->nullable();
            $table->uuid('confirmation_token')->nullable();
            $table->timestamp('confirmation_lease_until')->nullable();
            $table->timestamp('confirmation_requested_at')->nullable();
            $table->timestamp('confirmation_next_attempt_at')->nullable();
            $table->string('confirmation_last_error', 80)->nullable();
            $table->index(['confirmation_state', 'confirmation_next_attempt_at'], 'supplier_confirmation_due');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_account_units', function (Blueprint $table): void {
            $table->dropIndex('supplier_confirmation_due');
            $table->dropColumn(['confirmation_state', 'confirmation_token', 'confirmation_lease_until', 'confirmation_requested_at', 'confirmation_next_attempt_at', 'confirmation_last_error']);
        });
    }
};
