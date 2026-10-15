<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\User;
use App\Services\Admin\AdminProductWorkspaceData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AdminProductWorkspaceQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_query_count_does_not_grow_per_supplier_source(): void
    {
        $product = Product::factory()->create();
        $this->addSources($product, 1);
        $data = app(AdminProductWorkspaceData::class);
        $data->for($product);
        [$single, $singleQueries] = $this->capture(fn () => $data->for($product));
        $this->addSources($product, 24, 1);
        [$many, $manyQueries] = $this->capture(fn () => $data->for($product));

        $this->assertCount(1, $single['sources']);
        $this->assertCount(25, $many['sources']);
        $this->assertLessThanOrEqual(count($singleQueries), count($manyQueries), 'Workspace must eager-load supplier/catalog relations, not query once per source.');
        $this->assertCount(1, array_filter($manyQueries, fn (array $query): bool => str_contains($query['query'], 'from "product_quantity_price_tiers"')));
    }

    public function test_catalog_query_count_stays_constant_for_a_full_page_of_distinct_suppliers(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $product = Product::factory()->create();
        $this->addSources($product, 1, map: false);
        $url = route('admin.products.workspace.catalog', $product);
        $this->getJson($url)->assertOk();
        [$single, $singleQueries] = $this->capture(fn () => $this->getJson($url));
        $this->addSources($product, 24, 1, false);
        [$many, $manyQueries] = $this->capture(fn () => $this->getJson($url));

        $single->assertOk()->assertJsonCount(1, 'items');
        $many->assertOk()->assertJsonCount(25, 'items');
        $this->assertLessThanOrEqual(count($singleQueries), count($manyQueries), 'Catalog supplier lookup must use one eager query for the whole page.');
    }

    public function test_json_refresh_omits_unchanged_choice_lists_but_initial_html_retains_every_choice(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $product = Product::factory()->create();
        $category = Category::factory()->create();
        $family = ProductFamily::factory()->create();
        $url = route('admin.products.workspace', $product);
        $this->get($url)->assertOk()->assertViewHas('workspace', fn (array $workspace): bool => in_array($category->id, array_column($workspace['category_options'], 'id'), true) && in_array($family->id, array_column($workspace['family_options'], 'id'), true));
        [$response, $queries] = $this->capture(fn () => $this->getJson($url));
        $response->assertOk()->assertJsonMissingPath('category_options')->assertJsonMissingPath('family_options');
        $this->assertSame([], array_values(array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'from "categories"'))));
    }

    public function test_workspace_reports_the_vnd_unit_cost_as_ceil_of_dong_times_the_stored_idr_rate(): void
    {
        $product = Product::factory()->create();
        $item = $this->vndSource($product, '0.692308');

        $source = app(AdminProductWorkspaceData::class)->for($product)['sources'][0];

        $this->assertSame('69000', $source['cost']['unit']);
        $this->assertSame(47770, $source['cost']['idr'], 'Cost must be ceil(69,000 VND x 0.692308 IDR/VND).');
        $this->assertFalse($source['cost']['implausible']);
        $this->assertLessThan(69000 * 18000, $source['cost']['idr'], 'A rupiah-per-USDT rate must never produce the stored unit cost.');
        $this->assertSame('0.692308', $source['supplier']['exchange_rate_idr']);
        $this->assertSame(47770, app(AdminProductWorkspaceData::class)->cost($item)['idr']);

        // The Admin workspace payload itself must carry that cost for the card.
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $this->getJson(route('admin.products.workspace', $product))
            ->assertOk()
            ->assertJsonPath('sources.0.cost.idr', 47770)
            ->assertJsonPath('sources.0.cost.implausible', false);
    }

    public function test_workspace_flags_a_unit_cost_that_came_from_a_rupiah_per_usdt_rate(): void
    {
        $product = Product::factory()->create();
        $this->vndSource($product, '18000');

        $cost = app(AdminProductWorkspaceData::class)->for($product)['sources'][0]['cost'];

        $this->assertSame(1_242_000_000, $cost['idr']);
        $this->assertTrue($cost['implausible']);
    }

    private function vndSource(Product $product, string $rate): SupplierCatalogItem
    {
        $supplier = Supplier::forceCreate(['name' => 'Nastele VND', 'provider' => 'nastele', 'api_key' => 'private-workspace-rate-key', 'currency' => 'VND', 'currency_confirmed' => true, 'exchange_rate_idr' => $rate, 'is_active' => true, 'version' => 1, 'reserved_balance_micros' => 0, 'low_balance_threshold_micros' => 0]);
        $item = SupplierCatalogItem::forceCreate(['supplier_id' => $supplier->id, 'external_id' => '43', 'name' => 'Nastele SKU', 'currency' => 'VND', 'unit_price_micros' => 69_000_000_000, 'stock' => 100, 'reserved_quantity' => 0, 'orderable' => true, 'requires_input' => false, 'price_tiers' => [], 'bulk_discounts' => []]);
        ProductSupplierSource::forceCreate(['product_id' => $product->id, 'supplier_catalog_item_id' => $item->id, 'priority' => 10, 'is_active' => true, 'version' => 1]);

        return $item;
    }

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

    private function addSources(Product $product, int $count, int $offset = 0, bool $map = true): void
    {
        for ($index = $offset; $index < $offset + $count; $index++) {
            $supplier = Supplier::forceCreate(['name' => 'Query supplier '.$index, 'provider' => 'prodseller', 'api_key' => 'private-query-test-key', 'currency' => 'USDT', 'currency_confirmed' => true, 'exchange_rate_idr' => 16000, 'is_active' => false, 'version' => 1, 'reserved_balance_micros' => 0, 'low_balance_threshold_micros' => 0]);
            $item = SupplierCatalogItem::forceCreate(['supplier_id' => $supplier->id, 'external_id' => 'query-'.$index, 'name' => 'Catalog '.$index, 'description' => str_repeat('Private catalog text ', 500), 'unit_price_micros' => 1000000, 'stock' => 10, 'reserved_quantity' => 0, 'orderable' => true, 'requires_input' => false, 'price_tiers' => [], 'bulk_discounts' => []]);
            if ($map) {
                ProductSupplierSource::forceCreate(['product_id' => $product->id, 'supplier_catalog_item_id' => $item->id, 'priority' => $index, 'is_active' => false, 'version' => 1]);
            }
        }
    }
}
