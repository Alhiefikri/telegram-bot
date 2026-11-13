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
            $table->boolean('unique_credential_email')->default(false);
            $table->unsignedInteger('deferred_local_quantity')->default(0);
        });
    }

    public function down(): void
    {
        if (DB::table('order_items')->where('deferred_local_quantity', '>', 0)->exists()) {
            throw new RuntimeException('Deferred local fulfillment history must be retained.');
        }
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn(['unique_credential_email', 'deferred_local_quantity']);
        });
    }
};
