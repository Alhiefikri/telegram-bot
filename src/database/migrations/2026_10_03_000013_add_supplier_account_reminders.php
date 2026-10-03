<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_account_units', function (Blueprint $table): void {
            $table->timestamp('reminder_materialized_at')->nullable();
            $table->index(['reminder_materialized_at', 'refund_deadline'], 'supplier_unit_reminder_due');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_account_units', function (Blueprint $table): void {
            $table->dropIndex('supplier_unit_reminder_due');
            $table->dropColumn('reminder_materialized_at');
        });
    }
};
