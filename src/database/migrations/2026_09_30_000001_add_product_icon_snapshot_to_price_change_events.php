<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_price_change_events', function (Blueprint $table): void {
            $table->string('product_icon', 32)->nullable()->after('product_name');
            $table->string('product_custom_emoji_id')->nullable()->after('product_icon');
        });

        DB::table('product_price_change_events')->update([
            'product_icon' => DB::raw('(select products.icon from products where products.id = product_price_change_events.product_id)'),
            'product_custom_emoji_id' => DB::raw('(select products.custom_emoji_id from products where products.id = product_price_change_events.product_id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('product_price_change_events', function (Blueprint $table): void {
            $table->dropColumn(['product_icon', 'product_custom_emoji_id']);
        });
    }
};
