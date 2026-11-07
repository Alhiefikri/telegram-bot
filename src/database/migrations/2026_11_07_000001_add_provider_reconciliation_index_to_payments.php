<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The reconciliation sweeps filter one provider and a small status set,
     * then keyset-page by created_at/id. This composite index keeps that scan
     * bounded as more automatic providers share the payments table.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->index(
                ['provider', 'status', 'created_at', 'id'],
                'payments_provider_status_created_id_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex('payments_provider_status_created_id_index');
        });
    }
};
