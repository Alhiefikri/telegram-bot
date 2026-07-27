<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->index(['status', 'paid_at'], 'payments_status_paid_at_index');
            $table->index(['order_id', 'status', 'paid_at', 'id'], 'payments_order_status_paid_at_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex('payments_status_paid_at_index');
            $table->dropIndex('payments_order_status_paid_at_id_index');
        });
    }
};
