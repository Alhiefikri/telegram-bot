<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\ProductPriceChangeEvent;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierPricePolicy;
use App\Models\User;
use App\Services\Admin\AdminSupplierMargins;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class AdminSupplierMarginTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_and_batch_preview_reselect_supplier_and_save_only_base_idr(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $product = self::mappedProduct('Produk <private>', 18_500);
        $product->update(['usdt_price_amount' => 2_000_000, 'reseller_price_amount' => 17_000, 'reseller_usdt_price_amount' => 1_500_000]);
        $product->quantityPriceTiers()->create(['minimum_quantity' => 2, 'price_amount' => 17_500, 'usdt_price_amount' => 1_800_000]);
        $priority = self::source($product, 19_021, 5);
        $backup = self::source($product, 22_000, 20);
        $other = self::mappedProduct('Rugi', 14_000);
        $auto = self::mappedProduct('Mode otomatis', 12_000, 'percent');

        $response = $this->get(route('admin.suppliers.margins.index', ['action' => 1]));
        $response->assertOk()->assertSee('Pakai saran')->assertDontSee('<private>', false)->assertDontSee('private-margin-key');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $rows = $response->viewData('rows')->keyBy('id');
        $this->assertSame(500, $rows[$product->id]['delta']);
        $this->assertSame(-3_500, $rows[$product->id]['fallback_delta']);
        $this->assertSame(21_500, $rows[$product->id]['suggestion']);
        $this->assertFalse($rows[$auto->id]['editable']);
        $this->assertSame($auto->id, $response->viewData('rows')->first()['id']);

        $this->get(route('admin.suppliers.margins.index', ['supplier' => $backup->supplier_id]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$product->id]);

        $preview = $this->post(route('admin.suppliers.margins.review'), ['edits' => [
            ['id' => $product->id, 'version' => $rows[$product->id]['version'], 'price' => '21500'],
            ['id' => $other->id, 'version' => $rows[$other->id]['version'], 'price' => '20500'],
        ]])->assertOk();
        $this->assertSame(18_500, $product->fresh()->price_amount);
        $this->assertSame(2_479, $preview->viewData('changes')[$product->id]['new_delta']);
        $this->assertSame($priority->supplier->name, $preview->viewData('changes')[$product->id]['new_reference']['name']);
        $this->assertDatabaseCount('product_price_change_events', 0);

        $token = $preview->viewData('token');
        $this->post(route('admin.suppliers.margins.save'), ['token' => $token])->assertRedirect();
        $this->assertDatabaseHas('products', [
            'id' => $product->id, 'price_amount' => 21_500, 'usdt_price_amount' => 2_000_000,
            'reseller_price_amount' => 17_000, 'reseller_usdt_price_amount' => 1_500_000,
        ]);
        $this->assertSame(20_500, $other->fresh()->price_amount);
        $this->assertSame(17_500, $product->quantityPriceTiers()->sole()->price_amount);
        $this->assertDatabaseCount('product_price_change_events', 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.updated', 'subject_id' => (string) $product->id]);
        $this->assertGreaterThan(0, $product->fresh()->supplierAvailableStock());
        $this->postJson(route('admin.suppliers.margins.save'), ['token' => $token])->assertConflict();
        $this->assertSame(2, ProductPriceChangeEvent::count());
    }

    public function test_stale_source_or_changed_price_rejects_entire_batch_and_stale_rows_have_no_suggestion(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $one = self::mappedProduct();
        $two = self::mappedProduct();
        $rows = $this->rows();
        $preview = $this->post(route('admin.suppliers.margins.review'), ['edits' => [
            ['id' => $one->id, 'version' => $rows[$one->id]['version'], 'price' => 20_000],
            ['id' => $two->id, 'version' => $rows[$two->id]['version'], 'price' => 20_000],
        ]])->assertOk();
        $supplier = $two->supplierSources()->sole()->catalogItem->supplier;
        $supplier->forceFill(['sync_expires_at' => now()->subSecond()])->save();
        $this->postJson(route('admin.suppliers.margins.save'), ['token' => $preview->viewData('token')])->assertConflict();
        $this->assertSame(18_500, $one->fresh()->price_amount);
        $this->assertSame(18_500, $two->fresh()->price_amount);
        $this->assertDatabaseCount('product_price_change_events', 0);
        $row = $this->rows()[$two->id];
        $this->assertTrue($row['stale']);
        $this->assertNull($row['suggestion']);
        $this->assertNull($row['cost']);

        $one->update(['price_amount' => 19_000]);
        $this->postJson(route('admin.suppliers.margins.review'), ['edits' => [
            ['id' => $one->id, 'version' => $rows[$one->id]['version'], 'price' => 20_000],
        ]])->assertConflict();
    }

    public function test_authorization_payload_tier_guards_and_token_expiry(): void
    {
        $product = self::mappedProduct();
        $product->quantityPriceTiers()->create(['minimum_quantity' => 2, 'price_amount' => 17_500]);
        $auto = self::mappedProduct(mode: 'fixed');
        $index = route('admin.suppliers.margins.index');
        $review = route('admin.suppliers.margins.review');
        $save = route('admin.suppliers.margins.save');
        $this->get($index)->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create(['role' => UserRole::Finance]));
        $this->get($index)->assertForbidden();
        $this->postJson($review)->assertForbidden();
        $this->postJson($save)->assertForbidden();

        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->actingAs($owner);
        $rows = $this->rows();
        $this->get($index.'?cursor=W10')->assertUnprocessable();
        $edit = ['id' => $product->id, 'version' => $rows[$product->id]['version'], 'price' => 20_000];
        $this->from($index)->post($review, ['edits' => [$edit, $edit]])->assertRedirect($index)->assertSessionHasErrors('edits.0.id');
        $this->from($index)->post($review, ['edits' => [$edit + ['reseller_price_amount' => 1]]])->assertRedirect($index)->assertSessionHasErrors('edits.0');
        $this->from($index)->post($review, ['edits' => [array_replace($edit, ['price' => 17_500])]])->assertRedirect($index)->assertSessionHasErrors('edits');
        $this->postJson($review, ['edits' => [
            ['id' => $auto->id, 'version' => $rows[$auto->id]['version'], 'price' => 20_000],
        ]])->assertConflict();
        $token = $this->post($review, ['edits' => [$edit]])->assertOk()->viewData('token');
        $this->postJson($save, ['token' => $token.'tampered'])->assertConflict();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->postJson($save, ['token' => $token])->assertConflict();
        $this->actingAs($owner);
        $this->travel(11)->minutes();
        $this->postJson($save, ['token' => $token])->assertConflict();
        $this->assertSame(18_500, $product->fresh()->price_amount);
    }

    public function test_page_and_review_queries_are_constant_for_a_full_page_and_cursor_is_stable(): void
    {
        $first = self::mappedProduct();
        $margins = app(AdminSupplierMargins::class);
        $filters = ['target' => 2_000, 'rounding' => 500];
        $page = fn () => $margins->page($filters);
        $edits = fn ($rows) => $rows->map(fn ($row) => ['id' => $row['id'], 'version' => $row['version'], 'price' => 20_000])->all();
        $review = fn ($rows) => $margins->review($edits($rows), 2_000, 500);
        [[, $one], $singleQueries] = $this->measure($page);
        [, $singleReviewQueries] = $this->measure(fn () => $review($one));
        for ($i = 0; $i < 25; $i++) {
            $last = self::mappedProduct(price: 15_000);
        }
        [[$result, $rows], $pageQueries] = $this->measure($page);
        [$changes, $reviewQueries] = $this->measure(fn () => $review($rows));
        $this->assertCount(25, $rows);
        $this->assertCount(25, $changes);
        $this->assertSame($singleQueries, $pageQueries);
        $this->assertSame($singleReviewQueries, $reviewQueries);
        $this->assertNotSame($first->id, $rows->first()['id'], 'Losses sort first within each page.');

        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $next = $this->get(route('admin.suppliers.margins.index', $filters + ['cursor' => $result->nextCursor()->encode()]))
            ->assertOk()->viewData('rows');
        $this->assertSame([$last->id], $next->pluck('id')->all());
    }

    public static function mappedProduct(string $name = 'Margin product', int $price = 18_500, string $mode = 'manual'): Product
    {
        $product = Product::factory()->create(['name' => $name, 'price_amount' => $price, 'stock' => 0, 'is_active' => true]);
        SupplierPricePolicy::create(['product_id' => $product->id, 'mode' => $mode, 'minimum_profit_idr' => 0, 'rounding_idr' => 1]);
        self::source($product, 18_000);

        return $product;
    }

    private static function source(Product $product, int $cost, int $priority = 10): SupplierCatalogItem
    {
        $supplier = Supplier::forceCreate([
            'name' => 'Supplier '.$cost, 'provider' => 'nastele', 'api_key' => 'private-margin-key',
            'currency' => 'VND', 'currency_confirmed' => true, 'exchange_rate_idr' => '0.5', 'is_active' => true,
            'balance_micros' => 1_000_000_000_000, 'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(8),
        ]);
        $item = SupplierCatalogItem::forceCreate([
            'supplier_id' => $supplier->id, 'external_id' => 'test', 'name' => 'Private SKU',
            'currency' => 'VND', 'unit_price_micros' => $cost * 2_000_000, 'stock' => 10,
            'orderable' => true, 'requires_input' => false, 'synced_at' => now(),
        ]);
        ProductSupplierSource::create([
            'product_id' => $product->id, 'supplier_catalog_item_id' => $item->id, 'priority' => $priority, 'is_active' => true,
        ]);

        return $item;
    }

    private function rows()
    {
        return app(AdminSupplierMargins::class)->page(['target' => 2_000, 'rounding' => 500])[1]->keyBy('id');
    }

    private function measure(callable $action): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            return [$action(), count(DB::getQueryLog())];
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
