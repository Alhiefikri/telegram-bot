<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_balance_top_ups', function (Blueprint $table): void {
            $table->index(
                ['provider', 'status', 'provider_create_state', 'created_at', 'id'],
                'store_balance_top_ups_reconcile_cursor_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('store_balance_top_ups', function (Blueprint $table): void {
            $table->dropIndex('store_balance_top_ups_reconcile_cursor_index');
        });
    }
};
