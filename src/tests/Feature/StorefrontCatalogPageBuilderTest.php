<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Storefront\StorefrontCatalogPageBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StorefrontProductScenarios;
use Tests\TestCase;

class StorefrontCatalogPageBuilderTest extends TestCase
{
    use RefreshDatabase;
    use StorefrontProductScenarios;

    public function test_shop_context_trims_search_and_exposes_canonical_shop_urls(): void
    {
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'is_active' => true,
        ]);
        $this->availableProduct(['name' => 'Chat GPT Plus', 'description' => 'Premium model access.']);
        $this->availableProduct(['name' => 'Canva Pro', 'description' => 'Design tools.']);

        $page = app(StorefrontCatalogPageBuilder::class)->shop('  chat  ');

        $this->assertTrue($page->isShop());
        $this->assertSame('chat', $page->search);
        $this->assertSame(route('storefront.shop.index'), $page->searchAction);
        $this->assertSame(route('storefront.shop.index').'#products', $page->clearSearchUrl);
        $this->assertSame(route('storefront.shop.index', ['q' => 'chat']), $page->canonicalUrl);
        $this->assertSame(['Chat GPT Plus'], $page->products->map(fn ($entry) => $entry->presentation->name)->all());
        $this->assertSame(
            route('storefront.shop.checkout.product', ['product' => $page->products->first()->presentation->id]),
            $page->products->first()->presentation->checkoutUrl,
        );
    }

    public function test_home_context_exposes_landing_actions_and_empty_state_inputs(): void
    {
        $page = app(StorefrontCatalogPageBuilder::class)->home('  ');

        $this->assertTrue($page->isHome());
        $this->assertSame('', $page->search);
        $this->assertSame(url('/#products'), $page->searchAction);
        $this->assertSame(url('/#products'), $page->clearSearchUrl);
        $this->assertSame('Katalog publik sedang disiapkan.', $page->emptyProductsTitle);
        $this->assertNull($page->popularEmptyMessage);
    }

    public function test_shop_context_exposes_catalog_empty_state_inputs(): void
    {
        $page = app(StorefrontCatalogPageBuilder::class)->shop(null);

        $this->assertSame('Belum ada produk siap dibeli.', $page->emptyProductsTitle);
        $this->assertSame('Produk aktif akan muncul otomatis setelah admin mempublikasikan stok.', $page->emptyProductsBody);
        $this->assertNull($page->popularEmptyMessage);
    }

    public function test_products_preserve_visibility_and_storefront_order(): void
    {
        $inactiveCategory = Category::factory()->create(['is_active' => false]);
        $this->unavailableProduct(['name' => 'Akun Kosong']);
        $this->availableProduct(['name' => 'Zeta Ready', 'stock' => 1]);
        Product::factory()->create(['name' => 'Hidden Product', 'is_active' => false]);
        Product::factory()->create(['category_id' => $inactiveCategory->id, 'name' => 'Hidden Category Product', 'is_active' => true]);

        $products = app(StorefrontCatalogPageBuilder::class)->home(null)->products;

        $this->assertSame(['Zeta Ready', 'Akun Kosong'], $products->map(fn ($entry) => $entry->presentation->name)->all());
    }

    public function test_catalog_products_include_paid_completed_sold_quantity(): void
    {
        $product = $this->availableProduct(['name' => 'ChatGPT Plus Terjual']);
        $paidOrder = Order::factory()->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $failedOrder = Order::factory()->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Failed,
        ]);

        OrderItem::factory()->create(['order_id' => $paidOrder->id, 'product_id' => $product->id, 'quantity' => 4]);
        OrderItem::factory()->create(['order_id' => $failedOrder->id, 'product_id' => $product->id, 'quantity' => 9]);

        $presentation = app(StorefrontCatalogPageBuilder::class)->home(null)->products->first()->presentation;

        $this->assertSame(4, $presentation->soldQuantity);
        $this->assertSame('Terjual: 4', $presentation->soldLabel);
    }

    public function test_category_filters_expose_all_public_categories_in_storefront_order(): void
    {
        Category::factory()->create(['name' => 'Hidden', 'slug' => 'hidden', 'sort_order' => 0, 'is_active' => false]);

        $categories = collect(range(1, 9))->map(fn (int $number) => Category::factory()->create([
            'name' => sprintf('Category %02d', $number),
            'slug' => sprintf('category-%02d', $number),
            'sort_order' => $number,
            'is_active' => true,
        ]));

        $this->availableProduct(['category_id' => $categories[0]->id, 'name' => 'Alpha Ready']);
        $this->availableProduct(['category_id' => $categories[2]->id, 'name' => 'Charlie Ready']);
        $this->availableProduct(['category_id' => $categories[8]->id, 'name' => 'Outside Category Limit']);

        $page = app(StorefrontCatalogPageBuilder::class)->home(null);

        $this->assertTrue($page->hasPublicCategories);
        $this->assertSame($categories->pluck('name')->all(), $page->categoryFilters->pluck('name')->all());
        $this->assertSame($categories[8]->slug, $page->categoryFilters->last()->slug);
        $this->assertStringContainsString('category=category-09', $page->categoryFilters->last()->url);
    }

    public function test_products_order_credential_managed_products_by_available_product_invariant(): void
    {
        $this->credentialManagedProductWithClaimedCredentials(
            secrets: ['claimed@example.test|pass'],
            attributes: [
                'name' => 'A Managed Numeric Only',
                'stock' => 5,
            ],
        );
        $this->availableProductWithCredentials(
            secrets: ['ready@example.test|pass'],
            attributes: [
                'name' => 'Z Managed Ready',
                'stock' => 5,
            ],
        );
        $this->unavailableProduct(['name' => 'B Numeric Empty']);

        $products = app(StorefrontCatalogPageBuilder::class)->home(null)->products;

        $this->assertSame(['Z Managed Ready', 'A Managed Numeric Only', 'B Numeric Empty'], $products->map(fn ($entry) => $entry->presentation->name)->all());
    }

    public function test_popular_products_require_paid_completed_sales_and_rank_by_quantity(): void
    {
        $alpha = $this->availableProduct(['name' => 'Alpha Ready', 'stock' => 2]);
        $bravo = $this->availableProduct(['name' => 'Bravo Ready', 'stock' => 2]);
        $charlie = $this->availableProduct(['name' => 'Charlie Ready', 'stock' => 2]);
        $delta = $this->availableProduct(['name' => 'Delta Ready', 'stock' => 2]);
        $echo = $this->unavailableProduct(['name' => 'Echo Empty']);
        $paidOrder = Order::factory()->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);

        OrderItem::factory()->create(['order_id' => $paidOrder->id, 'product_id' => $alpha->id, 'quantity' => 2]);
        OrderItem::factory()->create(['order_id' => $paidOrder->id, 'product_id' => $bravo->id, 'quantity' => 5]);
        OrderItem::factory()->create(['order_id' => $paidOrder->id, 'product_id' => $charlie->id, 'quantity' => 3]);
        OrderItem::factory()->create(['order_id' => $paidOrder->id, 'product_id' => $echo->id, 'quantity' => 9]);

        $page = app(StorefrontCatalogPageBuilder::class)->home(null);
        $this->assertSame([
            'Alpha Ready' => 2,
            'Bravo Ready' => 5,
            'Charlie Ready' => 3,
            'Delta Ready' => 0,
            'Echo Empty' => 9,
        ], $page->products->mapWithKeys(fn ($entry) => [$entry->presentation->name => $entry->presentation->soldQuantity])->all());

        $this->assertSame(['Bravo Ready', 'Charlie Ready', 'Alpha Ready'], $page->popularProducts->map(fn ($entry) => $entry->presentation->name)->all());
        $this->assertSame([
            'Alpha Ready' => true,
            'Bravo Ready' => true,
            'Charlie Ready' => true,
            'Delta Ready' => false,
            'Echo Empty' => false,
        ], $page->products->mapWithKeys(fn ($entry) => [$entry->presentation->name => $entry->isPopular])->all());
    }

    public function test_empty_popularity_keeps_available_family_and_standalone_discovery_entries(): void
    {
        $family = ProductFamily::factory()->create([
            'name' => 'Family Discovery',
            'slug' => 'family-discovery',
        ]);
        $this->availableProduct([
            'product_family_id' => $family->id,
            'name' => 'Family Discovery Option',
        ]);
        $this->availableProduct(['name' => 'Standalone Discovery']);

        $page = app(StorefrontCatalogPageBuilder::class)->home(null);

        $this->assertTrue($page->popularProducts->isEmpty());
        $this->assertSame(
            ['Family Discovery Option', 'Standalone Discovery'],
            $page->entries()->map(
                fn ($entry): string => $entry->isFamily()
                    ? $entry->family->displayName
                    : $entry->product->presentation->name,
            )->all(),
        );
    }

    public function test_storefront_controller_normalizes_array_search_parameters(): void
    {
        $this->availableProduct(['name' => 'Chat GPT Plus', 'description' => 'Premium model access.']);

        $this->get('/?q[]=chat')
            ->assertOk()
            ->assertSee('value=""', false);
    }

    public function test_catalog_presentations_expose_projection_facts_without_checkout_payload(): void
    {
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'is_active' => true,
        ]);
        $product = $this->availableProduct([
            'name' => 'One USDT Product',
            'description' => 'Projection-owned catalog details.',
            'usdt_price_amount' => 1_500_000,
        ]);

        $presentation = app(StorefrontCatalogPageBuilder::class)
            ->home(null)
            ->products
            ->first()
            ->presentation;

        $this->assertSame($product->id, $presentation->id);
        $this->assertSame($product->name, $presentation->name);
        $this->assertSame($product->description, $presentation->description);
        $this->assertSame($product->price_amount, $presentation->priceAmount);
        $this->assertSame($product->usdt_price_amount, $presentation->usdtPriceAmount);
        $this->assertSame($product->stock, $presentation->stockAvailable);
        $this->assertSame('Terjual: 0', $presentation->soldLabel);
        $this->assertTrue($presentation->isAvailable);
        $this->assertSame(
            route('storefront.shop.checkout.product', ['product' => $product->id]),
            $presentation->checkoutUrl,
        );
        $this->assertFalse(property_exists($presentation, 'checkoutChoices'));
        $this->assertFalse(property_exists($presentation, 'checkoutReadiness'));
        $this->assertFalse(property_exists($presentation, 'isBuyable'));
    }
}
