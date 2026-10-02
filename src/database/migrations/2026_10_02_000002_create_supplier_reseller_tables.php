<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('provider', 20);
            $table->text('api_key');
            $table->string('currency', 4);
            $table->boolean('currency_confirmed')->default(false);
            $table->unsignedInteger('exchange_rate_idr');
            $table->unsignedBigInteger('balance_micros')->nullable();
            $table->unsignedBigInteger('reserved_balance_micros')->default(0);
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('synced_at')->nullable();
            $table->uuid('sync_token')->nullable();
            $table->timestamp('sync_expires_at')->nullable();
            $table->timestamp('sync_attempted_at')->nullable();
            $table->timestamp('sync_lease_expires_at')->nullable();
            $table->string('last_error', 80)->nullable();
            $table->unsignedBigInteger('low_balance_threshold_micros')->default(1000000);
            $table->timestamp('low_balance_alerted_at')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'synced_at', 'id'], 'supplier_sync_due');
            $table->index(['is_active', 'sync_attempted_at', 'id'], 'supplier_sync_fairness');
        });
        Schema::create('supplier_catalog_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('external_id', 150);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('currency', 4)->nullable();
            $table->unsignedBigInteger('unit_price_micros')->nullable();
            $table->unsignedInteger('stock')->nullable();
            $table->unsignedInteger('reserved_quantity')->default(0);
            $table->boolean('orderable')->default(false);
            $table->boolean('requires_input')->default(false);
            $table->json('price_tiers')->nullable();
            $table->json('bulk_discounts')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['supplier_id', 'external_id'], 'supplier_catalog_external_unique');
            $table->index(['supplier_id', 'orderable', 'id'], 'supplier_catalog_orderable');
        });
        Schema::create('product_supplier_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_catalog_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['product_id', 'supplier_catalog_item_id'], 'product_supplier_source_unique');
            $table->index(['product_id', 'is_active', 'priority', 'id'], 'product_supplier_source_priority');
            $table->index(['supplier_catalog_item_id', 'product_id'], 'supplier_source_catalog_products');
        });
        Schema::create('supplier_price_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->restrictOnDelete();
            $table->string('mode', 10)->default('manual');
            $table->unsignedInteger('markup_basis_points')->default(0);
            $table->unsignedInteger('profit_idr')->default(0);
            $table->unsignedInteger('minimum_profit_idr')->default(0);
            $table->unsignedInteger('buffer_idr')->default(0);
            $table->unsignedInteger('rounding_idr')->default(500);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('supplier_fulfillments', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('state', 24)->default('reserved');
            $table->unsignedBigInteger('maximum_cost_idr');
            $table->text('sources_snapshot');
            $table->foreignId('reserved_supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('reserved_catalog_item_id')->nullable()->constrained('supplier_catalog_items')->restrictOnDelete();
            $table->unsignedBigInteger('reserved_cost_micros')->default(0);
            $table->unsignedInteger('reserved_quantity')->default(0);
            $table->unsignedBigInteger('current_purchase_id')->nullable()->index();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('last_error', 80)->nullable();
            $table->timestamps();
            $table->index(['state', 'next_attempt_at', 'id'], 'supplier_fulfillment_due');
            $table->index(['order_id', 'state'], 'supplier_fulfillment_order_state');
        });
        Schema::create('supplier_purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_fulfillment_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_catalog_item_id')->constrained()->restrictOnDelete();
            $table->uuid('reference')->unique();
            $table->string('supplier_order_id', 150)->nullable();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('observed_quantity')->nullable();
            $table->unsignedBigInteger('expected_cost_micros');
            $table->unsignedBigInteger('actual_cost_micros')->nullable();
            $table->unsignedInteger('exchange_rate_idr');
            $table->string('state', 24)->default('reserved');
            $table->uuid('claim_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->unsignedInteger('replay_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('delivery_payload')->nullable();
            $table->string('last_error', 80)->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();
            $table->unique(['supplier_id', 'supplier_order_id'], 'supplier_purchase_order_unique');
            $table->index(['state', 'next_attempt_at', 'id'], 'supplier_purchase_due');
            $table->index(['supplier_fulfillment_id', 'id'], 'supplier_purchase_fulfillment');
            $table->index(['supplier_id', 'state', 'id'], 'supplier_purchase_account_state');
            $table->index(['state', 'lease_expires_at', 'id'], 'supplier_purchase_expired_claim');
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedInteger('supplier_available_stock')->default(0);
            $table->timestamp('supplier_available_until')->nullable();
        });
        Schema::table('order_items', function (Blueprint $table): void {
            $table->unsignedInteger('local_quantity')->nullable();
            $table->unsignedInteger('supplier_quantity')->default(0);
        });
        Schema::table('product_stock_items', function (Blueprint $table): void {
            $table->foreignId('supplier_purchase_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('supplier_unit_index')->nullable();
            $table->unique(['supplier_purchase_id', 'supplier_unit_index'], 'supplier_delivered_unit_unique');
            $table->index(['product_id', 'supplier_purchase_id', 'order_item_id', 'retired_at'], 'product_local_credential_capacity');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE suppliers ADD CONSTRAINT supplier_money_guard CHECK ((balance_micros IS NULL OR balance_micros >= 0) AND reserved_balance_micros >= 0 AND exchange_rate_idr > 0 AND version > 0 AND currency IN ('USD', 'USDT'))");
            DB::statement('ALTER TABLE supplier_catalog_items ADD CONSTRAINT supplier_catalog_capacity_guard CHECK ((stock IS NULL OR stock >= 0) AND reserved_quantity >= 0 AND (unit_price_micros IS NULL OR unit_price_micros >= 0))');
            DB::statement("ALTER TABLE supplier_price_policies ADD CONSTRAINT supplier_price_policy_guard CHECK (mode IN ('manual', 'percent', 'fixed', 'hybrid') AND markup_basis_points BETWEEN 0 AND 100000 AND profit_idr >= 0 AND minimum_profit_idr >= 0 AND buffer_idr >= 0 AND rounding_idr IN (1, 100, 500, 1000) AND version > 0)");
            DB::statement('ALTER TABLE supplier_fulfillments ADD CONSTRAINT supplier_fulfillment_money_guard CHECK (quantity > 0 AND maximum_cost_idr >= 0 AND reserved_cost_micros >= 0 AND reserved_quantity >= 0)');
            DB::statement('ALTER TABLE supplier_purchases ADD CONSTRAINT supplier_purchase_money_guard CHECK (quantity > 0 AND expected_cost_micros >= 0 AND (actual_cost_micros IS NULL OR actual_cost_micros >= 0) AND (observed_quantity IS NULL OR observed_quantity >= 0) AND exchange_rate_idr > 0)');
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_item_source_quantity_guard CHECK ((local_quantity IS NULL AND supplier_quantity = 0) OR (local_quantity >= 0 AND supplier_quantity >= 0 AND local_quantity + supplier_quantity = quantity))');
            DB::statement('ALTER TABLE products ADD CONSTRAINT product_supplier_capacity_guard CHECK (supplier_available_stock >= 0)');
        }
    }

    public function down(): void
    {
        Schema::table('product_stock_items', function (Blueprint $table): void {
            $table->dropIndex('product_local_credential_capacity');
            $table->dropUnique('supplier_delivered_unit_unique');
            $table->dropColumn('supplier_unit_index');
            $table->dropConstrainedForeignId('supplier_purchase_id');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_item_source_quantity_guard');
            DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS product_supplier_capacity_guard');
        }
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn(['local_quantity', 'supplier_quantity']));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['supplier_available_stock', 'supplier_available_until']));
        Schema::dropIfExists('supplier_purchases');
        Schema::dropIfExists('supplier_fulfillments');
        Schema::dropIfExists('supplier_price_policies');
        Schema::dropIfExists('product_supplier_sources');
        Schema::dropIfExists('supplier_catalog_items');
        Schema::dropIfExists('suppliers');
    }
};
