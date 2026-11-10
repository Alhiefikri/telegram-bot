<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_balance_top_ups', function (Blueprint $table): void {
            $table->timestamp('manual_alternative_requested_at')->nullable();
            $table->string('manual_alternative_switch_token', 64)->nullable();
            $table->timestamp('manual_alternative_switch_started_at')->nullable();
            $table->timestamp('manual_alternative_at')->nullable();
            $table->string('manual_alternative_decision', 24)->nullable();
            $table->string('settled_provider', 32)->nullable();
            $table->timestamp('manual_alternative_provider_paid_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('store_balance_top_ups')
            ->whereNotNull('manual_alternative_at')
            ->orWhereNotNull('settled_provider')
            ->orWhereNotNull('manual_alternative_switch_token')
            ->exists()) {
            throw new RuntimeException('Cannot remove Store Balance manual alternative provenance while payment history or an active switch exists.');
        }

        Schema::table('store_balance_top_ups', function (Blueprint $table): void {
            $table->dropColumn([
                'manual_alternative_requested_at',
                'manual_alternative_at',
                'manual_alternative_decision',
                'manual_alternative_switch_token',
                'manual_alternative_switch_started_at',
                'settled_provider',
                'manual_alternative_provider_paid_at',
            ]);
        });
    }
};
