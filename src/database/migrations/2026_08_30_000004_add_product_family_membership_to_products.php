<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('product_family_id')
                ->nullable()
                ->after('category_id')
                ->constrained('product_families')
                ->nullOnDelete();
            $table->unsignedInteger('family_sort_order')->default(0)->after('product_family_id');
            $table->string('family_option_label')->nullable()->after('family_sort_order');
            $table->index(['product_family_id', 'family_sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign(['product_family_id']);
            $table->dropIndex(['product_family_id', 'family_sort_order']);
            $table->dropColumn(['product_family_id', 'family_sort_order', 'family_option_label']);
        });
    }
};
