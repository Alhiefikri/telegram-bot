<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catalog icons are owned by each Product (`icon` plus `custom_emoji_id`)
     * instead of a name-pattern brand registry, so the seeded brand list that
     * never matched the real catalog is retired.
     */
    public function up(): void
    {
        Schema::dropIfExists('catalog_brand_icons');
    }

    public function down(): void
    {
        Schema::create('catalog_brand_icons', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->json('patterns');
            $table->string('fallback_emoji', 32);
            $table->string('custom_emoji_id')->nullable();
            $table->integer('sort_order')->default(0)->index();
            $table->timestamps();
        });
    }
};
