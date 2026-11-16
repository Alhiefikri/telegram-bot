<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_fulfillments', function (Blueprint $table): void {
            $table->timestamp('manual_takeover_at')->nullable();
            $table->foreignId('manual_takeover_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_fulfillments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('manual_takeover_by');
            $table->dropColumn('manual_takeover_at');
        });
    }
};
