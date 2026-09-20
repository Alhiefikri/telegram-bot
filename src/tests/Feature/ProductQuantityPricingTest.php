<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductQuantityPriceTier;
use App\Services\Pricing\ProductQuantityPricing;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class ProductQuantityPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_quantity_tier_schema_relationship_and_snapshot_column_are_additive(): void
    {
        $this->assertTrue(Schema::hasTable('product_quantity_price_tiers'));
        $this->assertTrue(Schema::hasColumns('product_quantity_price_tiers', [
            'id',
            'product_id',
            'minimum_quantity',
            'price_amount',
            'usdt_price_amount',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('order_items', ['quantity_price_tier_minimum']));

        $product = Product::factory()->withQuantityPriceTiers([
            ['minimum_quantity' => 10, 'price_amount' => 23_000, 'usdt_price_amount' => 460_000],
            ['minimum_quantity' => 2, 'price_amount' => 24_000, 'usdt_price_amount' => 480_000],
        ])->create(['price_amount' => 25_000, 'usdt_price_amount' => 500_000]);

        $this->assertSame([2, 10], $product->quantityPriceTiers->pluck('minimum_quantity')->all());
        $this->assertSame($product->id, $product->quantityPriceTiers->first()->product->id);

        $orderItem = OrderItem::factory()->create(['product_id' => $product->id]);

        $this->assertNull($orderItem->fresh()->quantity_price_tier_minimum);
    }

    public function test_quantity_tier_mutations_invalidate_storefront_response_cache(): void
    {
        Cache::put('storefront.response.version', 100, now()->addHour());
        $product = Product::factory()->create();

        $tier = ProductQuantityPriceTier::factory()->create([
            'product_id' => $product->id,
            'minimum_quantity' => 2,
        ]);
        $afterCreate = (int) Cache::get('storefront.response.version');

        $tier->update(['price_amount' => 1_000]);
        $afterUpdate = (int) Cache::get('storefront.response.version');

        $tier->delete();

        $this->assertGreaterThan(100, $afterCreate);
        $this->assertGreaterThan($afterCreate, $afterUpdate);
        $this->assertGreaterThan($afterUpdate, (int) Cache::get('storefront.response.version'));
    }

    public function test_duplicate_product_thresholds_cannot_persist(): void
    {
        $product = Product::factory()->create();
        ProductQuantityPriceTier::factory()->create(['product_id' => $product->id]);

        $this->expectException(QueryException::class);

        ProductQuantityPriceTier::factory()->create([
            'product_id' => $product->id,
            'minimum_quantity' => 2,
        ]);
    }

    public function test_tier_row_constraints_reject_unsupported_values(): void
    {
        $product = Product::factory()->create();

        foreach ([
            ['minimum_quantity' => 1, 'price_amount' => 5_000],
            ['minimum_quantity' => 2, 'price_amount' => 0],
            ['minimum_quantity' => 2, 'price_amount' => 5_000, 'usdt_price_amount' => 0],
        ] as $attributes) {
            try {
                ProductQuantityPriceTier::create(['product_id' => $product->id, ...$attributes]);
                $this->fail('Unsupported Quantity Price Tier values must not persist.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('Quantity Price Tier', $exception->getMessage());
            }
        }
    }

    public function test_no_tier_quotes_preserve_base_idr_and_usdt_amounts(): void
    {
        $product = Product::factory()->create([
            'price_amount' => 25_000,
            'usdt_price_amount' => 500_000,
        ]);

        $quote = app(ProductQuantityPricing::class)->quote($product, 3);

        $this->assertSame($product->id, $quote->productId);
        $this->assertSame(3, $quote->quantity);
        $this->assertSame(25_000, $quote->baseIdrUnitAmount);
        $this->assertSame(25_000, $quote->effectiveIdrUnitAmount);
        $this->assertSame(75_000, $quote->idrSubtotal);
        $this->assertSame(500_000, $quote->baseUsdtUnitAmount);
        $this->assertSame(500_000, $quote->effectiveUsdtUnitAmount);
        $this->assertSame(1_500_000, $quote->usdtSubtotal);
        $this->assertNull($quote->appliedTierMinimum);
        $this->assertNull($quote->nextTier);
        $this->assertSame([], $quote->orderedTiers);
    }

    public function test_greatest_reached_threshold_drives_both_currencies_and_next_tier(): void
    {
        $product = Product::factory()->withQuantityPriceTiers([
            ['minimum_quantity' => 2, 'price_amount' => 24_000, 'usdt_price_amount' => 480_000],
            ['minimum_quantity' => 10, 'price_amount' => 23_000, 'usdt_price_amount' => 460_000],
            ['minimum_quantity' => 20, 'price_amount' => 21_000, 'usdt_price_amount' => 420_000],
        ])->create(['price_amount' => 25_000, 'usdt_price_amount' => 500_000]);
        $pricing = app(ProductQuantityPricing::class);

        $quotes = [
            1 => [25_000, 500_000, null, 2],
            2 => [24_000, 480_000, 2, 10],
            9 => [24_000, 480_000, 2, 10],
            10 => [23_000, 460_000, 10, 20],
            19 => [23_000, 460_000, 10, 20],
            20 => [21_000, 420_000, 20, null],
            25 => [21_000, 420_000, 20, null],
        ];

        foreach ($quotes as $quantity => [$idr, $usdt, $applied, $next]) {
            $quote = $pricing->quote($product, $quantity);

            $this->assertSame($idr, $quote->effectiveIdrUnitAmount);
            $this->assertSame($idr * $quantity, $quote->idrSubtotal);
            $this->assertSame($usdt, $quote->effectiveUsdtUnitAmount);
            $this->assertSame($usdt * $quantity, $quote->usdtSubtotal);
            $this->assertSame($applied, $quote->appliedTierMinimum);
            $this->assertSame($next, $quote->nextTierMinimumQuantity());
        }
    }

    public function test_product_without_base_usdt_requires_null_usdt_tier_amounts(): void
    {
        $product = Product::factory()->withQuantityPriceTiers([
            ['minimum_quantity' => 2, 'price_amount' => 24_000, 'usdt_price_amount' => null],
        ])->create(['price_amount' => 25_000, 'usdt_price_amount' => null]);

        $quote = app(ProductQuantityPricing::class)->quote($product, 2);

        $this->assertSame(24_000, $quote->effectiveIdrUnitAmount);
        $this->assertNull($quote->baseUsdtUnitAmount);
        $this->assertNull($quote->effectiveUsdtUnitAmount);
        $this->assertNull($quote->usdtSubtotal);
        $this->assertNull($quote->orderedTiers[0]->usdtPriceAmount);
    }

    public function test_corrupt_persisted_schedules_fail_closed(): void
    {
        $product = Product::factory()->create([
            'price_amount' => 25_000,
            'usdt_price_amount' => 500_000,
        ]);
        $now = now();

        DB::table('product_quantity_price_tiers')->insert([
            ['product_id' => $product->id, 'minimum_quantity' => 2, 'price_amount' => 24_000, 'usdt_price_amount' => 480_000, 'created_at' => $now, 'updated_at' => $now],
            ['product_id' => $product->id, 'minimum_quantity' => 10, 'price_amount' => 24_500, 'usdt_price_amount' => 460_000, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('strictly lower');

        app(ProductQuantityPricing::class)->quote($product, 10);
    }

    public function test_incomplete_currency_schedules_fail_closed(): void
    {
        $product = Product::factory()->create([
            'price_amount' => 25_000,
            'usdt_price_amount' => 500_000,
        ]);
        $now = now();
        DB::table('product_quantity_price_tiers')->insert([
            'product_id' => $product->id,
            'minimum_quantity' => 2,
            'price_amount' => 24_000,
            'usdt_price_amount' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('complete');

        app(ProductQuantityPricing::class)->quote($product, 2);
    }

    public function test_more_than_five_persisted_tiers_fail_closed_without_unbounded_loading(): void
    {
        $product = Product::factory()->create(['price_amount' => 100_000]);
        $now = now();
        $rows = [];
        foreach (range(2, 7) as $minimumQuantity) {
            $rows[] = [
                'product_id' => $product->id,
                'minimum_quantity' => $minimumQuantity,
                'price_amount' => 100_000 - ($minimumQuantity * 1_000),
                'usdt_price_amount' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('product_quantity_price_tiers')->insert($rows);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('more than five');

        app(ProductQuantityPricing::class)->quote($product, 2);
    }

    public function test_quantity_and_subtotal_overflow_are_rejected(): void
    {
        $product = Product::factory()->create(['price_amount' => PHP_INT_MAX]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IDR Product subtotal');

        app(ProductQuantityPricing::class)->quote($product, 2);
    }

    public function test_usdt_subtotal_overflow_is_rejected(): void
    {
        $product = Product::factory()->create([
            'price_amount' => 1,
            'usdt_price_amount' => PHP_INT_MAX,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('USDT Product subtotal');

        app(ProductQuantityPricing::class)->quote($product, 2);
    }

    public function test_non_positive_quantity_is_rejected(): void
    {
        $product = Product::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        app(ProductQuantityPricing::class)->quote($product, 0);
    }
}
