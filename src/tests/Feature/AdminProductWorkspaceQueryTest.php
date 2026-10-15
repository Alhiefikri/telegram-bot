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
