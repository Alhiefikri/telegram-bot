<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            // Historical reporting snapshots; intentionally not a foreign key.
            $table->unsignedBigInteger('family_id')->nullable()->after('product_name');
            $table->string('family_name')->nullable()->after('family_id');
            $table->index(['family_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropIndex(['family_id', 'created_at']);
            $table->dropColumn(['family_id', 'family_name']);
        });
    }
};
