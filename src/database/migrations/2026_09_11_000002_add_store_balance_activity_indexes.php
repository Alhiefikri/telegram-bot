<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_balance_entries', function (Blueprint $table): void {
            $table->index(
                ['customer_balance_account_id', 'created_at', 'id'],
                'store_balance_entries_activity_cursor_index',
            );
            $table->index(
                ['customer_balance_account_id', 'kind', 'source_type', 'source_id'],
                'store_balance_entries_activity_source_index',
            );
        });

        Schema::table('store_balance_top_ups', function (Blueprint $table): void {
            $table->index(
                ['customer_id', 'created_at', 'id'],
                'store_balance_top_ups_activity_cursor_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('store_balance_top_ups', function (Blueprint $table): void {
            $table->dropIndex('store_balance_top_ups_activity_cursor_index');
        });

        Schema::table('store_balance_entries', function (Blueprint $table): void {
            $table->dropIndex('store_balance_entries_activity_source_index');
            $table->dropIndex('store_balance_entries_activity_cursor_index');
        });
    }
};
