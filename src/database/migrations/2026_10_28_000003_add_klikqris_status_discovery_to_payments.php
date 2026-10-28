<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->unsignedSmallInteger('klikqris_status_failure_count')->default(0);
            $table->timestamp('klikqris_status_next_check_at')->nullable();
            $table->string('klikqris_status_last_error', 64)->nullable();
            $table->index(['provider', 'klikqris_status_last_error'], 'payments_klikqris_status_failure_index');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex('payments_klikqris_status_failure_index');
            $table->dropColumn([
                'klikqris_status_failure_count',
                'klikqris_status_next_check_at',
                'klikqris_status_last_error',
            ]);
        });
    }
};
