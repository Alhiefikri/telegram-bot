<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Adds indexes for columns that are filtered, joined, or sorted on hot paths but
 * were previously unindexed. PostgreSQL does not auto-index foreign-key columns,
 * so order_items/product_stock_items foreign keys drove sequential scans on every
 * catalog render, order transition, and retention sweep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->index('order_id', 'order_items_order_id_index');
            $table->index('product_id', 'order_items_product_id_index');
        });

        Schema::table('product_stock_items', function (Blueprint $table): void {
            $table->index('order_item_id', 'product_stock_items_order_item_id_index');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->index('created_at', 'orders_created_at_index');
        });

        Schema::table('payment_events', function (Blueprint $table): void {
            $table->index('received_at', 'payment_events_received_at_index');
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index('created_at', 'audit_logs_created_at_index');
        });

        Schema::table('sales_proof_posts', function (Blueprint $table): void {
            $table->index('status', 'sales_proof_posts_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropIndex('order_items_order_id_index');
            $table->dropIndex('order_items_product_id_index');
        });

        Schema::table('product_stock_items', function (Blueprint $table): void {
            $table->dropIndex('product_stock_items_order_item_id_index');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_created_at_index');
        });

        Schema::table('payment_events', function (Blueprint $table): void {
            $table->dropIndex('payment_events_received_at_index');
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('audit_logs_created_at_index');
        });

        Schema::table('sales_proof_posts', function (Blueprint $table): void {
            $table->dropIndex('sales_proof_posts_status_index');
        });
    }
};
