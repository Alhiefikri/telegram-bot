<?php

namespace Tests\Feature\Telegram;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Telegram\SalesProofStockSignal;
use Illuminate\Support\Facades\DB;
use Tests\Support\TelegramBotTestSupport;

class SalesProofStockSignalTest extends TelegramBotTestSupport
{
    public function test_single_item_order_announces_stock_at_the_threshold(): void
    {
        $product = Product::factory()->create(['is_active' => true, 'stock' => 6, 'reserved_stock' => 1]);

        $this->assertSame(5, $this->signalFor($product));
    }

    public function test_single_item_order_announces_the_last_unit(): void
    {
        $product = Product::factory()->create(['is_active' => true, 'stock' => 1, 'reserved_stock' => 0]);

        $this->assertSame(1, $this->signalFor($product));
    }

    public function test_signal_stays_quiet_above_the_threshold(): void
    {
        $product = Product::factory()->create(['is_active' => true, 'stock' => 6, 'reserved_stock' => 0]);

        $this->assertNull($this->signalFor($product));
    }

    public function test_signal_stays_quiet_when_no_unit_is_left(): void
    {
        $product = Product::factory()->create(['is_active' => true, 'stock' => 4, 'reserved_stock' => 4]);

        $this->assertNull($this->signalFor($product));
    }

    public function test_signal_stays_quiet_for_a_product_hidden_from_the_catalog(): void
    {
        $product = Product::factory()->create(['is_active' => false, 'stock' => 2, 'reserved_stock' => 0]);

        $this->assertNull($this->signalFor($product));
    }

    public function test_multi_item_order_never_announces_one_product_number(): void
    {
        $scarce = Product::factory()->create(['is_active' => true, 'stock' => 2, 'reserved_stock' => 0]);
        $order = $this->orderWith($scarce);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_name' => 'Second product']);

        $this->assertNull(app(SalesProofStockSignal::class)->forOrder($order->fresh()));
    }

    public function test_signal_stays_quiet_when_the_order_item_has_no_product_reference(): void
    {
        $order = Order::factory()->create();
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => null, 'product_name' => 'Removed product']);

        $this->assertNull(app(SalesProofStockSignal::class)->forOrder($order->fresh()));
    }

    public function test_signal_reads_availability_with_one_query_for_eager_loaded_orders(): void
    {
        $product = Product::factory()->create(['is_active' => true, 'stock' => 3, 'reserved_stock' => 0]);
        $order = $this->orderWith($product)->load('orderItems');

        $queries = $this->queriesFor(fn () => app(SalesProofStockSignal::class)->forOrder($order));

        $this->assertCount(1, $queries, json_encode($queries, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('product_stock_items', strtolower((string) $queries[0]['query']));
    }

    public function test_signal_never_queries_the_database_for_multi_item_orders(): void
    {
        $product = Product::factory()->create(['is_active' => true, 'stock' => 3, 'reserved_stock' => 0]);
        $order = $this->orderWith($product);
        OrderItem::factory()->count(3)->create(['order_id' => $order->id]);
        $order->load('orderItems');

        $queries = $this->queriesFor(fn () => app(SalesProofStockSignal::class)->forOrder($order));

        $this->assertCount(0, $queries, json_encode($queries, JSON_THROW_ON_ERROR));
    }

    private function signalFor(Product $product): ?int
    {
        return app(SalesProofStockSignal::class)->forOrder($this->orderWith($product));
    }

    private function orderWith(Product $product): Order
    {
        $order = Order::factory()->create();
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
        ]);

        return $order->fresh();
    }

    /** @return array<int, array{query: string, bindings: array<int, mixed>, time: float}> */
    private function queriesFor(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $callback();

            return DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
    }
}
