<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_purchases', function (Blueprint $table): void {
            // Existing raw evidence is not retroactively trusted.
            $table->text('trusted_delivery_payload')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_purchases', function (Blueprint $table): void {
            $table->dropColumn('trusted_delivery_payload');
        });
    }
};
