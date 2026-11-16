<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->boolean('manual_unlimited')->default(false);
        });
    }

    public function down(): void
    {
        if (DB::table('order_items')->where('manual_unlimited', true)->exists()) {
            throw new RuntimeException('Manual unlimited fulfillment history must be retained.');
        }
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('manual_unlimited');
        });
    }
};
