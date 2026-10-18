<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A durable, fail-closed runtime gate for the Customer API. The seeded row is
     * OFF; no route, key or financial capability is exposed until the Owner
     * explicitly enables it from the Admin Dashboard.
     */
    public function up(): void
    {
        Schema::create('customer_api_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key', 64)->unique('customer_api_settings_singleton_unique');
            $table->boolean('enabled')->default(false);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();
        });

        DB::table('customer_api_settings')->insert([
            'singleton_key' => 'customer_api',
            'enabled' => false,
            'version' => 0,
            'changed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_api_settings');
    }
};
