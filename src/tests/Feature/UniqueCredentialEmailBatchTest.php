<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\Credentials\UniqueCredentialEmailPolicy;
use App\Services\ProductCredentialAllocator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\NonTransactionalDatabaseTestCase;

final class UniqueCredentialEmailBatchTest extends NonTransactionalDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_manual_batch_validation_has_a_bounded_query_budget_not_one_query_per_account(): void
    {
        [$product, $item] = $this->fixture();
        $candidates = [];
        for ($i = 0; $i < 50; $i++) {
            $candidates[] = new ProductStockItem(['product_id' => $product->id, 'secret' => "batch-{$i}@example.test|password"]);
        }
        DB::enableQueryLog();
        try {
            DB::transaction(function () use ($product, $item, $candidates): void {
                Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
                app(UniqueCredentialEmailPolicy::class)->assertCanAssign($item, $candidates);
            });
            $queries = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertLessThanOrEqual(10, $queries, 'A 50-account submission must not perform one Order lookup for every email.');
    }

    public function test_allocation_crosses_stock_and_history_page_boundaries_without_n_plus_one_queries(): void
    {
        [$product, $item] = $this->fixture();
        $historical = Order::factory()->create(['customer_id' => $item->order->customer_id, 'payment_status' => PaymentStatus::Paid, 'order_status' => OrderStatus::Completed]);
        $oldItem = OrderItem::factory()->create(['order_id' => $historical->id, 'product_id' => $product->id, 'quantity' => 205]);
        for ($i = 0; $i < 205; $i++) {
            ProductStockItem::create(['product_id' => $product->id, 'order_item_id' => $oldItem->id, 'secret' => "history-{$i}@example.test|old-password"]);
        }
        for ($i = 0; $i < 205; $i++) {
            ProductStockItem::create(['product_id' => $product->id, 'secret' => "HISTORY-{$i}@example.test|changed-password"]);
        }
        $eligible = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'eligible-after-pages@example.test|password']);
        DB::enableQueryLog();
        try {
            DB::transaction(fn () => app(ProductCredentialAllocator::class)->allocate($item, $product, 1, allowDeferred: true));
            $queries = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame($item->id, $eligible->fresh()->order_item_id);
        $this->assertSame(0, $item->fresh()->deferred_local_quantity);
        $this->assertSame(1, $item->stockItems()->count());
        $this->assertSame(205, ProductStockItem::where('product_id', $product->id)->whereNull('order_item_id')->count());
        $this->assertLessThanOrEqual(25, $queries, 'History must be eager-loaded per page, never per credential.');
    }

    public function test_duplicate_at_the_end_of_a_large_manual_batch_is_rejected_with_its_original_invoice(): void
    {
        [$product, $item] = $this->fixture();
        ProductStockItem::create(['product_id' => $product->id, 'order_item_id' => $item->id, 'secret' => 'existing@example.test|never-display-this-password']);
        $candidates = [];
        for ($i = 0; $i < 100; $i++) {
            $candidates[] = new ProductStockItem(['product_id' => $product->id, 'secret' => "fresh-{$i}@example.test|new-password"]);
        }
        $candidates[] = new ProductStockItem(['product_id' => $product->id, 'secret' => ' EXISTING@example.test |changed-password']);
        try {
            app(UniqueCredentialEmailPolicy::class)->assertCanAssign($item, $candidates);
            $this->fail('An email duplicate at the end of the batch was accepted.');
        } catch (ValidationException $exception) {
            $message = $exception->errors()['credentials'][0];
            $this->assertStringContainsString($item->order->invoice_number, $message);
            $this->assertStringContainsString('existing@example.test', $message);
            $this->assertStringNotContainsString('never-display-this-password', $message);
            $this->assertStringNotContainsString('changed-password', $message);
        }
        $this->assertSame(1, $item->stockItems()->count());
    }

    public function test_unreadable_historical_credential_defers_allocation_instead_of_ignoring_history(): void
    {
        [$product, $item] = $this->fixture();
        $oldOrder = Order::factory()->create(['customer_id' => $item->order->customer_id]);
        $oldItem = OrderItem::factory()->create(['order_id' => $oldOrder->id, 'product_id' => $product->id]);
        $broken = ProductStockItem::create(['product_id' => $product->id, 'order_item_id' => $oldItem->id, 'secret' => 'known@example.test|old-password']);
        DB::table('product_stock_items')->where('id', $broken->id)->update(['secret' => 'corrupt-ciphertext']);
        $available = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'fresh@example.test|password']);

        DB::transaction(fn () => app(ProductCredentialAllocator::class)->allocate($item, $product, 1, allowDeferred: true));

        $this->assertSame(1, $item->fresh()->deferred_local_quantity);
        $this->assertNull($available->fresh()->order_item_id);
        $this->assertSame(0, $item->stockItems()->count());
        $this->assertSame(0, $product->fresh()->reserved_stock);
    }

    private function fixture(): array
    {
        $product = Product::factory()->create(['unique_credential_email' => true, 'stock' => 206, 'reserved_stock' => 0]);
        $customer = Customer::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $item = OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'local_quantity' => 1, 'supplier_quantity' => 0, 'unique_credential_email' => true]);

        return [$product, $item];
    }
}
