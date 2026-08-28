<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key')->unique();
            $table->string('product_placeholder_image_path')->nullable();
            $table->timestamps();
        });

        DB::table('storefront_settings')->insert([
            'singleton_key' => 'storefront',
            'product_placeholder_image_path' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_settings');
    }
};
