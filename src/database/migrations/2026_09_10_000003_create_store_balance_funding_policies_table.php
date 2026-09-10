<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_balance_funding_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key')->unique();
            $table->unsignedBigInteger('minimum_amount');
            $table->json('preset_amounts');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamps();
        });

        DB::table('store_balance_funding_policies')->insert([
            'singleton_key' => 'store_balance_funding_policy',
            'minimum_amount' => 5_000,
            'preset_amounts' => json_encode([5_000, 10_000, 25_000, 50_000, 100_000, 250_000], JSON_THROW_ON_ERROR),
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('store_balance_funding_policies');
    }
};
