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
                ['provider', 'status', 'settled_at', 'id'],
                'store_balance_top_ups_settlement_index',
            );
        });

        Schema::table('store_balance_top_up_events', function (Blueprint $table): void {
            $table->index(
                ['provider', 'provider_reference'],
                'store_balance_top_up_events_reference_index',
            );
            $table->index(
                ['provider', 'event_type', 'processed_at', 'last_received_at'],
                'store_balance_top_up_events_state_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('store_balance_top_up_events', function (Blueprint $table): void {
            $table->dropIndex('store_balance_top_up_events_reference_index');
            $table->dropIndex('store_balance_top_up_events_state_index');
        });

        Schema::table('store_balance_top_ups', function (Blueprint $table): void {
            $table->dropIndex('store_balance_top_ups_settlement_index');
        });
    }
};
