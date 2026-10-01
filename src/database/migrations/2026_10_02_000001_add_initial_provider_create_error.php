<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['payments', 'store_balance_top_ups'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('provider_create_initial_error', 64)->nullable()->after('provider_create_last_error');
            });
        }
    }

    public function down(): void
    {
        foreach (['payments', 'store_balance_top_ups'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('provider_create_initial_error');
            });
        }
    }
};
