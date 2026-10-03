<?php

namespace Tests\Support;

use App\Enums\CredentialFormat;
use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductStockItem;
use App\Models\Supplier;
use App\Models\SupplierAccountUnit;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierFulfillment;
use App\Models\SupplierPurchase;
use Illuminate\Support\Str;

trait BuildsBuyMailWarrantyFixture
{
    protected function fixture(): array
    {
        $customer = Customer::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id, 'order_status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Paid, 'total_amount' => 16000, 'currency' => 'IDR']);
        Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Paid, 'paid_at' => now(), 'amount' => 16000, 'currency' => 'IDR', 'provider' => 'manual']);
        $item = OrderItem::factory()->create(['order_id' => $order->id, 'quantity' => 2, 'local_quantity' => 0, 'supplier_quantity' => 2, 'subtotal' => 16000, 'unit_price' => 8000]);
        InventoryMovement::create(['order_id' => $order->id, 'product_id' => $item->product_id, 'type' => InventoryMovementType::Sale, 'quantity' => -2]);
        $supplier = Supplier::forceCreate(['name' => 'Private source', 'provider' => 'buymail', 'api_key' => 'synthetic-test-key', 'currency' => 'IDR', 'exchange_rate_idr' => 1, 'currency_confirmed' => true, 'is_active' => true, 'account_metadata' => ['refunds_enabled' => true], 'balance_micros' => 100_000_000_000, 'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5)]);
        $sku = SupplierCatalogItem::forceCreate(['supplier_id' => $supplier->id, 'external_id' => 'gmail', 'name' => 'Gmail', 'currency' => 'IDR', 'orderable' => true, 'requires_input' => false, 'unit_price_micros' => 4_800_000_000, 'stock' => 10, 'synced_at' => now()]);
        $fulfillment = SupplierFulfillment::create(['order_id' => $order->id, 'order_item_id' => $item->id, 'quantity' => 2, 'state' => 'delivered', 'maximum_cost_idr' => 15000, 'sources_snapshot' => []]);
        $purchase = SupplierPurchase::create(['supplier_fulfillment_id' => $fulfillment->id, 'supplier_id' => $supplier->id, 'supplier_catalog_item_id' => $sku->id, 'reference' => '00000000-0000-4000-8000-000000000001', 'supplier_order_id' => 'ORD1', 'quantity' => 2, 'observed_quantity' => 2, 'expected_cost_micros' => 9_600_000_000, 'actual_cost_micros' => 9_600_000_000, 'exchange_rate_idr' => 1, 'state' => 'delivered', 'committed_at' => now()]);
        $stock = ProductStockItem::create(['product_id' => $item->product_id, 'order_item_id' => $item->id, 'supplier_purchase_id' => $purchase->id, 'supplier_unit_index' => 1, 'credential_format' => CredentialFormat::StructuredAccount, 'credential_payload' => ['version' => 1, 'email' => 'fixture@gmail.com', 'password' => 'synthetic-password', 'access_method' => 'none', 'access_value' => null, 'tutorial_override' => null]]);
        $unit = SupplierAccountUnit::create(['public_id' => (string) Str::uuid(), 'supplier_id' => $supplier->id, 'supplier_purchase_id' => $purchase->id, 'product_stock_item_id' => $stock->id, 'order_id' => $order->id, 'order_item_id' => $item->id, 'external_id' => 'PR1', 'provider_state' => 'active', 'refund_deadline' => now()->addHours(2), 'cost_idr' => 4800, 'retail_amount' => 8000, 'retail_currency' => 'IDR', 'unit_number' => 1]);

        return [$customer, $unit];
    }
}
