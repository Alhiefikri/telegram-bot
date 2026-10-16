<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientStock;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductQuantityPriceTier;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierFulfillment;
use App\Models\SupplierPricePolicy;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\Pricing\ProductQuantityPricing;
use App\Services\Pricing\ResellerPricingContext;
use App\Services\Suppliers\SupplierAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Product-owned reseller overrides in the shared pricing owner: independent per
 * currency, final per unit, never stacked with a quantity tier, and only ever
 * applied inside a trusted reseller context.
 */
class ProductResellerPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_retail_quotes_ignore_product_owned_reseller_overrides_for_both_currencies(): void
    {
        $product = $this->overriddenProduct();

        $quote = app(ProductQuantityPricing::class)->quote($product, 2);

        $this->assertSame(24_000, $quote->effectiveIdrUnitAmount);
        $this->assertSame(48_000, $quote->idrSubtotal);
        $this->assertSame(480_000, $quote->effectiveUsdtUnitAmount);
        $this->assertSame(960_000, $quote->usdtSubtotal);
        $this->assertSame(2, $quote->appliedTierMinimum);
        $this->assertSame(2, $quote->appliedUsdtTierMinimum);
        $this->assertNull($quote->resellerIdrUnitAmount);
        $this->assertNull($quote->resellerUsdtUnitAmount);
        $this->assertFalse($quote->hasResellerOverride());
    }

    public function test_reseller_context_applies_each_currency_override_independently(): void
    {
        $context = ResellerPricingContext::forAuthenticatedApiRequest(619001);
        $pricing = app(ProductQuantityPricing::class);

        $idrOnly = $this->overriddenProduct();
        $idrOnly->forceFill(['reseller_usdt_price_amount' => null])->save();
        $quote = $pricing->quote($idrOnly, 2, $context);

        $this->assertSame(20_000, $quote->effectiveIdrUnitAmount);
        $this->assertSame(40_000, $quote->idrSubtotal);
        $this->assertTrue($quote->hasIdrResellerOverride());
        $this->assertFalse($quote->hasUsdtResellerOverride());
        $this->assertSame(480_000, $quote->effectiveUsdtUnitAmount);
        $this->assertSame(960_000, $quote->usdtSubtotal);
        $this->assertSame(2, $quote->appliedUsdtTierMinimum);

        $usdtOnly = $this->overriddenProduct();
        $usdtOnly->forceFill(['reseller_price_amount' => null])->save();
        $quote = $pricing->quote($usdtOnly, 2, $context);

        $this->assertSame(400_000, $quote->effectiveUsdtUnitAmount);
        $this->assertSame(800_000, $quote->usdtSubtotal);
        $this->assertTrue($quote->hasUsdtResellerOverride());
        $this->assertFalse($quote->hasIdrResellerOverride());
        $this->assertSame(24_000, $quote->effectiveIdrUnitAmount);
        $this->assertSame(48_000, $quote->idrSubtotal);
        $this->assertSame(2, $quote->appliedTierMinimum);
    }

    public function test_null_override_keeps_the_normal_quantity_tier_quote_and_clearing_restores_it(): void
    {
        $context = ResellerPricingContext::forAuthenticatedApiRequest(619002);
        $pricing = app(ProductQuantityPricing::class);
        $product = $this->overriddenProduct();

        $overridden = $pricing->quote($product, 2, $context);
        $retail = $pricing->quote($product, 2);

        $this->assertSame(20_000, $overridden->effectiveIdrUnitAmount);
        $this->assertNull($overridden->appliedTierMinimum);
        $this->assertSame(24_000, $retail->effectiveIdrUnitAmount);
        $this->assertSame(2, $retail->appliedTierMinimum);

        $product->update(['reseller_price_amount' => null, 'reseller_usdt_price_amount' => null]);

        $cleared = $pricing->quote($product->refresh(), 2, $context);

        $this->assertSame($retail->effectiveIdrUnitAmount, $cleared->effectiveIdrUnitAmount);
        $this->assertSame($retail->idrSubtotal, $cleared->idrSubtotal);
        $this->assertSame($retail->effectiveUsdtUnitAmount, $cleared->effectiveUsdtUnitAmount);
        $this->assertSame($retail->usdtSubtotal, $cleared->usdtSubtotal);
        $this->assertSame(2, $cleared->appliedTierMinimum);
        $this->assertSame(2, $cleared->appliedUsdtTierMinimum);
        $this->assertFalse($cleared->hasResellerOverride());
    }

    public function test_reseller_override_never_stacks_with_a_reached_quantity_tier(): void
    {
        $product = $this->overriddenProduct();

        $quote = app(ProductQuantityPricing::class)->quote($product, 2, ResellerPricingContext::forAuthenticatedApiRequest(619003));

        $this->assertFalse($quote->hasAppliedTier());
        $this->assertNull($quote->appliedTierMinimum);
        $this->assertNull($quote->appliedUsdtTierMinimum);
        $this->assertSame(20_000, $quote->effectiveIdrUnitAmount);
        $this->assertSame(40_000, $quote->idrSubtotal);
        $this->assertSame(400_000, $quote->effectiveUsdtUnitAmount);
        $this->assertSame(800_000, $quote->usdtSubtotal);
        $this->assertNotSame($quote->idrSubtotal, $product->quantityPriceTiers()->sole()->price_amount * 2);
    }

    public function test_reseller_usdt_override_never_manufactures_usd_eligibility_without_a_base_price(): void
    {
        $context = ResellerPricingContext::forAuthenticatedApiRequest(619004);
        $product = Product::factory()->create(['price_amount' => 25_000, 'usdt_price_amount' => null]);
        DB::table('products')->where('id', $product->id)->update([
            'reseller_price_amount' => 20_000,
            'reseller_usdt_price_amount' => 400_000,
        ]);

        $quote = app(ProductQuantityPricing::class)->quote($product->refresh(), 2, $context);

        $this->assertNull($quote->baseUsdtUnitAmount);
        $this->assertNull($quote->effectiveUsdtUnitAmount);
        $this->assertNull($quote->usdtSubtotal);
        $this->assertNull($quote->resellerUsdtUnitAmount);
        $this->assertFalse($quote->hasUsdtResellerOverride());
        $this->assertTrue($quote->hasIdrResellerOverride());
        $this->assertSame(40_000, $quote->idrSubtotal);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the reseller USDT price requires a base USDT price');

        app(ProductQuantityPricing::class)->validateResellerOverrides(null, null, 400_000);
    }

    public function test_corrupt_non_positive_reseller_amounts_fail_closed(): void
    {
        $context = ResellerPricingContext::forAuthenticatedApiRequest(619005);
        $pricing = app(ProductQuantityPricing::class);
        $product = $this->overriddenProduct();

        DB::table('products')->where('id', $product->id)->update(['reseller_price_amount' => 0]);

        try {
            $pricing->quote($product->refresh(), 2, $context);
            $this->fail('A zero reseller IDR amount must not quote.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('the reseller IDR price must be positive', $exception->getMessage());
        }

        DB::table('products')->where('id', $product->id)->update([
            'reseller_price_amount' => null,
            'reseller_usdt_price_amount' => -1,
        ]);

        try {
            $pricing->quote($product->refresh(), 2, $context);
            $this->fail('A negative reseller USDT amount must not quote.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('the reseller USDT price must be positive', $exception->getMessage());
        }
    }

    public function test_reseller_subtotal_overflow_is_rejected(): void
    {
        $context = ResellerPricingContext::forAuthenticatedApiRequest(619006);
        $pricing = app(ProductQuantityPricing::class);
        $product = $this->overriddenProduct();

        $product->update(['reseller_price_amount' => PHP_INT_MAX]);

        try {
            $pricing->quote($product->refresh(), 2, $context);
            $this->fail('An overflowing reseller IDR subtotal must not quote.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('IDR Product subtotal exceeds the supported integer range', $exception->getMessage());
        }

        $product->update(['reseller_price_amount' => null, 'reseller_usdt_price_amount' => PHP_INT_MAX]);

        try {
            $pricing->quote($product->refresh(), 2, $context);
            $this->fail('An overflowing reseller USDT subtotal must not quote.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('USDT Product subtotal exceeds the supported integer range', $exception->getMessage());
        }
    }

    public function test_batch_quote_reads_stay_constant_for_one_twenty_and_hundred_products(): void
    {
        $pricing = app(ProductQuantityPricing::class);
        $context = ResellerPricingContext::forAuthenticatedApiRequest(619007);
        $products = Product::factory()->count(100)->create([
            'price_amount' => 25_000,
            'usdt_price_amount' => 500_000,
            'reseller_price_amount' => 20_000,
        ]);
        foreach ($products as $product) {
            ProductQuantityPriceTier::factory()->create([
                'product_id' => $product->id,
                'minimum_quantity' => 2,
                'price_amount' => 24_000,
                'usdt_price_amount' => 480_000,
            ]);
        }

        $shapes = [];
        foreach ([1, 20, 100] as $size) {
            [$page, $pageQueries] = $this->capture(fn () => Product::query()
                ->with('quantityPriceTiers')
                ->orderBy('id')
                ->limit($size)
                ->get());
            [$quotes, $quoteQueries] = $this->capture(fn () => $pricing->quoteMany($page, 2, $context));

            $this->assertCount($size, $quotes);
            $this->assertSame(0, count($quoteQueries), "Quoting {$size} prepared Products must not issue a statement per Product.");
            $this->assertSame(2, count($pageQueries), "Loading {$size} Products with tiers must stay two statements.");
            $this->assertSame(1, count(array_filter($pageQueries, fn (array $query): bool => str_contains($query['query'], 'product_quantity_price_tiers'))));

            foreach ($quotes as $quote) {
                $this->assertSame(20_000, $quote->effectiveIdrUnitAmount);
                $this->assertSame(40_000, $quote->idrSubtotal);
                $this->assertNull($quote->appliedTierMinimum);
            }

            $shapes[] = ['page' => count($pageQueries), 'quote' => count($quoteQueries)];
        }

        $this->assertSame([['page' => 2, 'quote' => 0]], array_values(array_unique($shapes, SORT_REGULAR)));
    }

    public function test_batch_quote_refuses_an_unprepared_page_instead_of_querying_per_product(): void
    {
        Product::factory()->withQuantityPriceTiers([
            ['minimum_quantity' => 2, 'price_amount' => 24_000, 'usdt_price_amount' => 480_000],
        ])->create(['price_amount' => 25_000, 'usdt_price_amount' => 500_000]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('quantityPriceTiers relation to be eager loaded');

        app(ProductQuantityPricing::class)->quoteMany(
            Product::query()->orderBy('id')->limit(1)->get(),
            2,
            ResellerPricingContext::forAuthenticatedApiRequest(619008),
        );
    }

    public function test_trusted_reseller_context_charges_the_quoted_total_and_retail_checkout_stays_unchanged(): void
    {
        $product = $this->overriddenProduct();
        $customer = Customer::factory()->create(['telegram_id' => 619009]);
        $context = ResellerPricingContext::forAuthenticatedApiRequest($customer->id);
        $quote = app(ProductQuantityPricing::class)->quote($product, 2, $context);

        $resellerOrder = app(OrderCheckoutService::class)->checkout(
            $this->cartFor($customer, $product, 2),
            CheckoutPaymentIntents::manualQris(),
            resellerContext: $context,
        );
        $resellerItem = $resellerOrder->orderItems()->sole();

        $this->assertSame(40_000, $quote->idrSubtotal);
        $this->assertSame($quote->idrSubtotal, $resellerOrder->total_amount);
        $this->assertSame($quote->idrSubtotal, $resellerItem->subtotal);
        $this->assertSame($quote->effectiveIdrUnitAmount, $resellerItem->unit_price);
        $this->assertNull($resellerItem->quantity_price_tier_minimum);

        $retailCustomer = Customer::factory()->create(['telegram_id' => 619010]);
        $retailQuote = app(ProductQuantityPricing::class)->quote($product, 2);
        $retailOrder = app(OrderCheckoutService::class)->checkout(
            $this->cartFor($retailCustomer, $product, 2),
            CheckoutPaymentIntents::manualQris(),
        );
        $retailItem = $retailOrder->orderItems()->sole();

        $this->assertSame(48_000, $retailQuote->idrSubtotal);
        $this->assertSame($retailQuote->idrSubtotal, $retailOrder->total_amount);
        $this->assertSame($retailQuote->idrSubtotal, $retailItem->subtotal);
        $this->assertSame(2, $retailItem->quantity_price_tier_minimum);
    }

    public function test_reseller_price_cannot_bypass_the_supplier_profit_guard(): void
    {
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0, 'price_amount' => 5_000]);
        $supplier = Supplier::forceCreate([
            'name' => 'Private reseller guard supplier',
            'provider' => 'prodseller',
            'api_key' => 'fixture-secret',
            'currency' => 'USDT',
            'currency_confirmed' => true,
            'exchange_rate_idr' => 1000,
            'balance_micros' => 10_000_000,
            'is_active' => true,
            'synced_at' => now(),
            'sync_expires_at' => now()->addMinutes(5),
        ]);
        $sku = SupplierCatalogItem::create([
            'supplier_id' => $supplier->id,
            'external_id' => 'reseller-guard-sku',
            'name' => 'Private supplier SKU',
            'unit_price_micros' => 1_000_000,
            'stock' => 10,
            'orderable' => true,
            'requires_input' => false,
            'synced_at' => now(),
            'price_tiers' => [],
            'bulk_discounts' => [],
        ]);
        SupplierPricePolicy::create(['product_id' => $product->id, 'mode' => 'manual', 'minimum_profit_idr' => 500, 'rounding_idr' => 1]);
        ProductSupplierSource::create(['product_id' => $product->id, 'supplier_catalog_item_id' => $sku->id, 'is_active' => true, 'priority' => 0]);
        app(SupplierAvailability::class)->refreshProducts([$product->id]);
        $product->update(['reseller_price_amount' => 1_200]);
        $customer = Customer::factory()->create(['telegram_id' => 619011]);
        $cart = $this->cartFor($customer, $product, 1);

        try {
            app(OrderCheckoutService::class)->checkout(
                $cart,
                CheckoutPaymentIntents::manualQris(),
                resellerContext: ResellerPricingContext::forAuthenticatedApiRequest($customer->id),
            );
            $this->fail('A reseller price below the supplier profit guard must not buy external units.');
        } catch (InsufficientStock $exception) {
            $this->assertSame('Ordered product is not available.', $exception->getMessage());
        }

        $this->assertSame(0, Order::count());
        $this->assertSame(0, SupplierFulfillment::count());
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);

        $retailOrder = app(OrderCheckoutService::class)->checkout($cart->refresh(), CheckoutPaymentIntents::manualQris());

        $this->assertSame(5_000, $retailOrder->total_amount);
        $this->assertSame(1, SupplierFulfillment::count());
        $this->assertSame(1_000_000, $supplier->fresh()->reserved_balance_micros);
    }

    public function test_model_guard_rejects_invalid_reseller_overrides_before_they_persist(): void
    {
        $product = Product::factory()->create(['price_amount' => 25_000, 'usdt_price_amount' => null]);

        try {
            $product->forceFill(['reseller_price_amount' => 0])->save();
            $this->fail('A non-positive reseller IDR override must not persist.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('must be positive', $exception->getMessage());
        }

        try {
            $product->forceFill(['reseller_price_amount' => null, 'reseller_usdt_price_amount' => 400_000])->save();
            $this->fail('A reseller USDT override without a base USDT price must not persist.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('requires a base USDT price', $exception->getMessage());
        }

        $this->assertNull($product->fresh()->reseller_price_amount);
        $this->assertNull($product->fresh()->reseller_usdt_price_amount);
    }

    private function overriddenProduct(): Product
    {
        return Product::factory()->withQuantityPriceTiers([
            ['minimum_quantity' => 2, 'price_amount' => 24_000, 'usdt_price_amount' => 480_000],
        ])->create([
            'price_amount' => 25_000,
            'usdt_price_amount' => 500_000,
            'reseller_price_amount' => 20_000,
            'reseller_usdt_price_amount' => 400_000,
        ]);
    }

    private function cartFor(Customer $customer, Product $product, int $quantity): Cart
    {
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $quantity]);

        return $cart;
    }

    /**
     * @return array{0: mixed, 1: list<array{query: string}>}
     */
    private function capture(callable $action): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $result = $action();

            return [$result, DB::getQueryLog()];
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
