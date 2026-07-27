<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('usage_guide_title', 160)->nullable()->after('delivery_note');
            $table->text('usage_guide_url')->nullable()->after('usage_guide_title');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('usage_guide_title', 160)->nullable()->after('delivery_note_snapshotted');
            $table->text('usage_guide_url')->nullable()->after('usage_guide_title');
            $table->boolean('usage_guide_snapshotted')->default(false)->after('usage_guide_url');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['usage_guide_title', 'usage_guide_url', 'usage_guide_snapshotted']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['usage_guide_title', 'usage_guide_url']);
        });
    }
};
