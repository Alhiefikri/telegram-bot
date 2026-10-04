<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductQuantityPriceTier;
use App\Models\ProductStockItem;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierPricePolicy;
use App\Services\Suppliers\SupplierPricing;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SupplierPricingTest extends TestCase
{
    #[DataProvider('automaticPrices')]
    public function test_automatic_modes_round_up_using_whole_idr(string $mode, int $basisPoints, int $profit, int $rounding, int $expected): void
    {
        $policy = $this->policy(['mode' => $mode, 'markup_basis_points' => $basisPoints, 'profit_idr' => $profit, 'buffer_idr' => 199, 'rounding_idr' => $rounding]);
        $this->assertSame($expected, app(SupplierPricing::class)->retailPrice($policy, 10001));
    }

    public static function automaticPrices(): array
    {
        return [
            'percentage fraction rounds up' => ['percent', 1250, 0, 1, 11451],
            'fixed rounds to hundred' => ['fixed', 0, 1000, 100, 11200],
            'hybrid fixed floor' => ['hybrid', 100, 2000, 500, 12500],
            'hybrid percentage wins' => ['hybrid', 2500, 1000, 1000, 13000],
        ];
    }

    public function test_manual_policy_never_fabricates_an_automatic_retail_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(SupplierPricing::class)->retailPrice($this->policy(), 10000);
    }

    public function test_supplier_quantity_tiers_discounts_and_ceiling_are_exact(): void
    {
        $item = new SupplierCatalogItem(['unit_price_micros' => 1000001, 'price_tiers' => [
            ['min' => 3, 'max' => 5, 'unit_price_micros' => 900001],
        ], 'bulk_discounts' => [
            ['min' => 4, 'percent_basis_points' => 1000],
            ['min' => 10, 'percent_basis_points' => 2000],
        ]]);
        $pricing = app(SupplierPricing::class);
        $this->assertSame(2000002, $pricing->costMicros($item, 2));
        $this->assertSame(2700003, $pricing->costMicros($item, 3));
        $this->assertSame(3240004, $pricing->costMicros($item, 4));
        $this->assertSame(5400006, $pricing->costMicros($item, 6));
        $this->assertSame(8000008, $pricing->costMicros($item, 10));
        $this->assertSame(26600, $pricing->maximumCostIdr($this->policy(['minimum_profit_idr' => 1000, 'buffer_idr' => 700]), 30000, 2));
    }

    public function test_fixed_profit_does_not_calculate_an_unused_percentage(): void
    {
        $policy = $this->policy([
            'mode' => 'fixed', 'markup_basis_points' => 100000,
            'profit_idr' => 5000, 'buffer_idr' => 1000,
        ]);

        $this->assertSame(PHP_INT_MAX - 4000, app(SupplierPricing::class)->retailPrice($policy, PHP_INT_MAX - 10000));
    }

    public function test_percentage_accepts_a_representable_price_without_intermediate_overflow(): void
    {
        $policy = $this->policy(['mode' => 'percent', 'markup_basis_points' => 10000]);

        $this->assertSame(2_000_000_000_000_002, app(SupplierPricing::class)->retailPrice($policy, 1_000_000_000_000_001));
    }

    #[DataProvider('pricingBoundaries')]
    public function test_markup_profit_buffer_and_rounding_boundaries(array $settings, int $cost, int $expected): void
    {
        $this->assertSame($expected, app(SupplierPricing::class)->retailPrice($this->policy($settings), $cost));
    }

    public static function pricingBoundaries(): array
    {
        return [
            'zero markup still includes buffer' => [['mode' => 'percent', 'buffer_idr' => 500], 20000, 20500],
            'one percent means one hundred basis points' => [['mode' => 'percent', 'markup_basis_points' => 100], 20000, 20200],
            'smallest markup rounds fractional rupiah up' => [['mode' => 'percent', 'markup_basis_points' => 1], 1, 2],
            'percentage is applied to modal, not buffer' => [['mode' => 'percent', 'markup_basis_points' => 1000, 'buffer_idr' => 1000], 20000, 23000],
            'percent ignores fixed profit' => [['mode' => 'percent', 'markup_basis_points' => 1000, 'profit_idr' => 1000000], 20000, 22000],
            'fixed ignores saved percentage' => [['mode' => 'fixed', 'markup_basis_points' => 100000, 'profit_idr' => 3000], 20000, 23000],
            'hybrid equal amounts are not added together' => [['mode' => 'hybrid', 'markup_basis_points' => 1000, 'profit_idr' => 2000], 20000, 22000],
            'rounding retains an exact multiple' => [['mode' => 'percent', 'markup_basis_points' => 1000, 'rounding_idr' => 1000], 20000, 22000],
            'rounding moves up after percentage ceiling' => [['mode' => 'percent', 'markup_basis_points' => 1000, 'rounding_idr' => 1000], 20001, 23000],
            'fraction survives quotient split' => [['mode' => 'percent', 'markup_basis_points' => 1], 10001, 10003],
            'free supplier account still receives fixed profit' => [['mode' => 'hybrid', 'profit_idr' => 500, 'rounding_idr' => 1000], 0, 1000],
            'maximum supported markup is one thousand percent' => [['mode' => 'percent', 'markup_basis_points' => 100000], 20000, 220000],
        ];
    }

    public function test_buffer_and_minimum_profit_are_enforced_for_every_unit_at_the_exact_boundary(): void
    {
        [$product, $supplier, $item] = $this->resources();
        $policy = $this->policy(['minimum_profit_idr' => 1000, 'buffer_idr' => 500]);
        $pricing = app(SupplierPricing::class);

        $this->assertTrue($pricing->isEligible($product, $supplier, $item, $policy, 2, 35000));
        $this->assertFalse($pricing->isEligible($product, $supplier, $item, $policy, 2, 34999));
        $this->assertSame(0, $pricing->maximumCostIdr($policy, 2999, 2));
    }

    public function test_minimum_profit_is_a_purchase_guard_not_an_extra_automatic_markup(): void
    {
        [$product, $supplier, $item] = $this->resources();
        $policy = $this->policy(['mode' => 'percent', 'markup_basis_points' => 500, 'minimum_profit_idr' => 1000, 'buffer_idr' => 100]);
        $pricing = app(SupplierPricing::class);

        $this->assertSame(16900, $pricing->retailPrice($policy, 16000));
        $this->assertFalse($pricing->isEligible($product, $supplier, $item, $policy, 1, 16900));
        $this->assertTrue($pricing->isEligible($product, $supplier, $item, $policy, 1, 17100));
    }

    public function test_price_overflow_fails_closed_without_float_math(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(SupplierPricing::class)->retailPrice($this->policy(['mode' => 'percent', 'markup_basis_points' => 10000]), PHP_INT_MAX);
    }

    public function test_capacity_stops_before_an_unprofitable_retail_tier_in_mixed_inventory(): void
    {
        [$product, $supplier, $item] = $this->resources();
        $product->stock = 2;
        $product->setRelation('quantityPriceTiers', new Collection([
            new ProductQuantityPriceTier(['minimum_quantity' => 5, 'price_amount' => 15000]),
        ]));
        $this->assertSame(2, app(SupplierPricing::class)->capacity($product, $supplier, $item, $this->policy(['minimum_profit_idr' => 1000])));
    }

    public function test_capacity_subtracts_wallet_and_quantity_commitments(): void
    {
        [$product, $supplier, $item] = $this->resources();
        $supplier->balance_micros = 10000000;
        $supplier->reserved_balance_micros = 6500000;
        $item->reserved_quantity = 8;
        $this->assertSame(2, app(SupplierPricing::class)->capacity($product, $supplier, $item, $this->policy()));
        $item->reserved_quantity = 0;
        $this->assertSame(3, app(SupplierPricing::class)->capacity($product, $supplier, $item, $this->policy()));
    }

    public function test_purchase_guard_rejects_expired_currency_and_activation_data_even_with_stock(): void
    {
        [$product, $supplier, $item] = $this->resources();
        $pricing = app(SupplierPricing::class);
        $policy = $this->policy();
        $this->assertTrue($pricing->isEligible($product, $supplier, $item, $policy, 1, 20000));
        $supplier->sync_expires_at = now();
        $this->assertFalse($pricing->isEligible($product, $supplier, $item, $policy, 1, 20000));
        $supplier->sync_expires_at = now()->addMinutes(5);
        $item->currency = 'USD';
        $this->assertFalse($pricing->isEligible($product, $supplier, $item, $policy, 1, 20000));
        $item->currency = 'USDT';
        $item->requires_input = true;
        $this->assertFalse($pricing->isEligible($product, $supplier, $item, $policy, 1, 20000));
    }

    public function test_free_supplier_units_still_require_the_minimum_profit_floor(): void
    {
        [$product, $supplier, $item] = $this->resources();
        $item->unit_price_micros = 0;
        $policy = $this->policy(['minimum_profit_idr' => 1000]);
        $this->assertFalse(app(SupplierPricing::class)->isEligible($product, $supplier, $item, $policy, 1, 999));
        $this->assertTrue(app(SupplierPricing::class)->isEligible($product, $supplier, $item, $policy, 1, 1000));
    }

    public function test_supplier_delivery_history_never_turns_numeric_local_stock_into_credential_inventory(): void
    {
        $product = new Product;
        $product->forceFill(['stock' => 2, 'reserved_stock' => 0]);
        $delivered = new ProductStockItem;
        $delivered->forceFill(['supplier_purchase_id' => 1, 'order_item_id' => 9, 'retired_at' => now()]);
        $product->setRelation('stockItems', new Collection([$delivered]));
        $this->assertFalse($product->isCredentialManaged());
        $this->assertSame(2, $product->localAvailableStock());
        $local = new ProductStockItem;
        $local->forceFill(['supplier_purchase_id' => null, 'order_item_id' => null, 'retired_at' => null]);
        $product->setRelation('stockItems', new Collection([$delivered, $local]));
        $this->assertTrue($product->isCredentialManaged());
        $this->assertSame(1, $product->localAvailableStock());
    }

    private function policy(array $extra = []): SupplierPricePolicy
    {
        return new SupplierPricePolicy(array_merge(['product_id' => 1, 'mode' => 'manual', 'markup_basis_points' => 0, 'profit_idr' => 0, 'minimum_profit_idr' => 0, 'buffer_idr' => 0, 'rounding_idr' => 1], $extra));
    }

    private function resources(): array
    {
        $product = new Product(['price_amount' => 20000]);
        $product->forceFill(['id' => 1, 'stock' => 0, 'reserved_stock' => 0, 'local_stock_items_exists' => false]);
        $product->setRelation('quantityPriceTiers', new Collection);
        $supplier = new Supplier(['currency' => 'USDT', 'currency_confirmed' => true, 'exchange_rate_idr' => 16000, 'is_active' => true]);
        $supplier->forceFill(['id' => 1, 'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5), 'balance_micros' => 100000000, 'reserved_balance_micros' => 0]);
        $item = new SupplierCatalogItem(['supplier_id' => 1, 'currency' => 'USDT', 'unit_price_micros' => 1000000, 'stock' => 10, 'orderable' => true, 'requires_input' => false, 'synced_at' => now()]);
        $item->forceFill(['reserved_quantity' => 0]);

        return [$product, $supplier, $item];
    }
}
