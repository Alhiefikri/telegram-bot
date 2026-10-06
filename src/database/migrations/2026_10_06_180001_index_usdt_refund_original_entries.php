<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_usdt_balance_entries', function (Blueprint $table): void {
            $table->index(['original_entry_id', 'kind'], 'usdt_balance_entries_original_kind_index');
        });
    }

    public function down(): void
    {
        Schema::table('customer_usdt_balance_entries', function (Blueprint $table): void {
            $table->dropIndex('usdt_balance_entries_original_kind_index');
        });
    }
};
