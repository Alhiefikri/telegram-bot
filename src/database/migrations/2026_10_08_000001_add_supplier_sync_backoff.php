<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->timestamp('sync_next_attempt_at')->nullable();
            $table->unsignedInteger('sync_failures')->default(0);
        });
        Schema::table('supplier_health_states', function (Blueprint $table): void {
            $table->timestamp('error_recovered_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_health_states', fn (Blueprint $table) => $table->dropColumn('error_recovered_at'));
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn(['sync_next_attempt_at', 'sync_failures']));
    }
};
