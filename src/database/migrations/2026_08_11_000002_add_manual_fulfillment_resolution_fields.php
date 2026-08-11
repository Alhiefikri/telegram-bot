<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manual_fulfillment_reviews', function (Blueprint $table) {
            $table->text('resolution_reason')->nullable()->after('reason');
            $table->string('refund_provider_reference')->nullable()->after('provider_reference');
            $table->string('refund_provider_result')->nullable()->after('refund_provider_reference');
            $table->timestamp('refund_requested_at')->nullable()->after('refund_provider_result');
        });

        Schema::table('product_stock_items', function (Blueprint $table) {
            $table->timestamp('retired_at')->nullable()->after('order_item_id');
            $table->string('retired_reason')->nullable()->after('retired_at');
            $table->index(['product_id', 'retired_at', 'order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::table('product_stock_items', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'retired_at', 'order_item_id']);
            $table->dropColumn(['retired_at', 'retired_reason']);
        });

        Schema::table('manual_fulfillment_reviews', function (Blueprint $table) {
            $table->dropColumn(['resolution_reason', 'refund_provider_reference', 'refund_provider_result', 'refund_requested_at']);
        });
    }
};
