<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Storefront\StorefrontCatalogPageBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\StorefrontProductScenarios;
use Tests\TestCase;

class StorefrontCatalogPageBuilderTest extends TestCase
{
    use RefreshDatabase;
    use StorefrontProductScenarios;

    public function test_shop_context_trims_search_and_filters_public_product_entries(): void
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
        $this->assertSame(url('/#products'), $page->searchAction);
        $this->assertSame(url('/#products'), $page->clearSearchUrl);
        $this->assertSame(['Chat GPT Plus'], $page->products->map(fn ($entry) => $entry->presentation->name)->all());
        $this->assertTrue($page->products->first()->presentation->canOpenModal);
    }

    public function test_home_context_exposes_landing_actions_and_empty_state_inputs(): void
    {
        $page = app(StorefrontCatalogPageBuilder::class)->home('  ');

        $this->assertTrue($page->isHome());
        $this->assertSame('', $page->search);
        $this->assertSame(url('/#products'), $page->searchAction);
        $this->assertSame(url('/#products'), $page->clearSearchUrl);
        $this->assertSame('Katalog publik sedang disiapkan.', $page->emptyProductsTitle);
        $this->assertSame('Belum ada data penjualan berbayar selesai untuk menampilkan label produk terlaris.', $page->popularEmptyMessage);
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

    public function test_category_filters_preserve_public_limit_order_and_only_include_categories_with_products(): void
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
        $this->assertSame(['Category 01', 'Category 03'], $page->categoryFilters->pluck('name')->all());
    }

    public function test_featured_product_requires_explicit_rank_and_skips_unavailable_entries(): void
    {
        $this->availableProduct(['name' => 'Legacy Ready']);
        $this->unavailableProduct(['name' => 'Ranked Empty', 'featured_rank' => 1]);
        Product::factory()->create([
            'name' => 'Ranked Inactive',
            'stock' => 5,
            'reserved_stock' => 0,
            'is_active' => false,
            'featured_rank' => 2,
        ]);
        $this->availableProduct(['name' => 'Ranked Ready', 'featured_rank' => 3]);

        $featuredProduct = app(StorefrontCatalogPageBuilder::class)->home(null)->featuredProduct;

        $this->assertNotNull($featuredProduct);
        $this->assertSame('Ranked Ready', $featuredProduct->presentation->name);
        $this->assertTrue($featuredProduct->presentation->isAvailable);
    }

    public function test_featured_product_is_available_public_product_only(): void
    {

        $this->unavailableProduct(['name' => 'Akun Habis']);
        Product::factory()->create(['name' => 'Produk Nonaktif Siap', 'stock' => 5, 'reserved_stock' => 0, 'is_active' => false]);
        $this->availableProduct(['name' => 'Canva Ready', 'stock' => 5, 'featured_rank' => 1]);

        $featuredProduct = app(StorefrontCatalogPageBuilder::class)->home(null)->featuredProduct;

        $this->assertNotNull($featuredProduct);
        $this->assertSame('Canva Ready', $featuredProduct->presentation->name);
        $this->assertTrue($featuredProduct->presentation->isAvailable);
    }

    public function test_featured_product_skips_credential_managed_product_without_unclaimed_credentials(): void
    {
        $this->credentialManagedProductWithClaimedCredentials(
            secrets: ['claimed@example.test|pass'],
            attributes: [
                'name' => 'Akun Managed Kosong',
                'stock' => 5,
            ],
        );

        $this->availableProduct([
            'name' => 'Canva Ready',
            'stock' => 2,
            'featured_rank' => 1,
        ]);
        $featuredProduct = app(StorefrontCatalogPageBuilder::class)->home(null)->featuredProduct;

        $this->assertNotNull($featuredProduct);
        $this->assertSame('Canva Ready', $featuredProduct->presentation->name);
    }

    public function test_featured_product_uses_explicit_rank_and_is_independent_of_catalog_order(): void
    {
        $this->unavailableProduct(['name' => 'Akun Habis']);
        $this->availableProduct(['name' => 'Canva Ready', 'stock' => 5, 'featured_rank' => 1]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $page = app(StorefrontCatalogPageBuilder::class)->home(null);
        $productListingQueries = collect(DB::getQueryLog())
            ->filter(fn (array $entry): bool => str_contains($entry['query'], 'sold_quantity'))
            ->count();
        DB::disableQueryLog();

        $this->assertSame(2, $productListingQueries);
        $this->assertNotNull($page->featuredProduct);
        $this->assertSame('Canva Ready', $page->featuredProduct->presentation->name);
    }

    public function test_featured_product_stays_global_when_search_filters_it_out(): void
    {
        $this->availableProduct(['name' => 'Canva Ready', 'stock' => 5, 'featured_rank' => 1]);
        $this->availableProduct(['name' => 'Chat GPT Plus', 'stock' => 5]);

        $page = app(StorefrontCatalogPageBuilder::class)->home('chat');

        $this->assertSame(['Chat GPT Plus'], $page->products->map(fn ($entry) => $entry->presentation->name)->all());
        $this->assertNotNull($page->featuredProduct);
        $this->assertSame('Canva Ready', $page->featuredProduct->presentation->name);
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

    public function test_storefront_controller_normalizes_array_search_parameters(): void
    {
        $this->availableProduct(['name' => 'Chat GPT Plus', 'description' => 'Premium model access.']);

        $this->get('/?q[]=chat')
            ->assertOk()
            ->assertSee('value=""', false);
    }

    public function test_binance_payment_choice_is_available_without_catalog_n_plus_one_queries(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'binance_pay_enabled' => true, 'is_active' => true]);
        $this->availableProduct(['name' => 'One USDT Product', 'usdt_price_amount' => 1_500_000]);

        $singleProductQueries = $this->catalogQueryCount();
        collect(range(2, 6))->each(fn (int $number) => $this->availableProduct([
            'name' => 'USDT Product '.$number,
            'usdt_price_amount' => 1_500_000,
        ]));

        $page = app(StorefrontCatalogPageBuilder::class)->home(null);
        $manyProductQueries = $this->catalogQueryCount();

        $this->assertSame($singleProductQueries, $manyProductQueries);
        $this->assertNotNull($page->products->first()->presentation->checkoutChoice('website_binance_pay'));
    }

    private function catalogQueryCount(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(StorefrontCatalogPageBuilder::class)->home(null);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
