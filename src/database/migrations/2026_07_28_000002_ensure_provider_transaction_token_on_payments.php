<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('payments', 'provider_transaction_token')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table): void {
            $table->text('provider_transaction_token')->nullable()->after('provider_payment_number_hash');
        });
    }

    public function down(): void
    {
        // Compatibility migration only; the original Task 1 migration owns this column.
    }
};
