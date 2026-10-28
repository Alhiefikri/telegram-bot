<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierPricePolicy;
use App\Services\Suppliers\SupplierAvailability;
use App\Services\Telegram\TelegramCatalogIntents;
use App\Storefront\StorefrontResponseCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NonTransactionalDatabaseTestCase;

final class SupplierCatalogCacheTest extends NonTransactionalDatabaseTestCase
{
    private Product $product;

    private Supplier $supplier;

    private SupplierCatalogItem $item;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->withoutVite();
        $this->travelTo(now()->startOfSecond());
        config()->set('services.telegram.token', 'isolated-catalog-fixture');
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 671, 'chat' => ['id' => 671, 'type' => 'private']],
        ])]);
        $this->customer = Customer::factory()->create(['telegram_id' => 671, 'locale' => 'id']);
        $this->product = Product::factory()->create([
            'name' => 'Cache Freshness Product', 'stock' => 0, 'reserved_stock' => 0,
            'price_amount' => 20000, 'is_active' => true,
        ]);
        $this->supplier = Supplier::query()->create([
            'name' => 'Cache supplier', 'provider' => 'prodseller', 'api_key' => 'fixture-only',
            'currency' => 'USDT', 'currency_confirmed' => true, 'exchange_rate_idr' => 16000,
            'is_active' => true,
        ]);
        $this->supplier->forceFill([
            'balance_micros' => 10000000, 'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5),
        ])->save();
        $this->item = SupplierCatalogItem::query()->create([
            'supplier_id' => $this->supplier->id, 'external_id' => 'sku-671', 'name' => 'Cache SKU',
            'unit_price_micros' => 1000000, 'stock' => 20, 'orderable' => true,
            'requires_input' => false, 'synced_at' => now(),
        ]);
        ProductSupplierSource::query()->create([
            'product_id' => $this->product->id, 'supplier_catalog_item_id' => $this->item->id,
            'priority' => 1, 'is_active' => true,
        ]);
        SupplierPricePolicy::query()->create([
            'product_id' => $this->product->id, 'mode' => 'manual', 'rounding_idr' => 1,
        ]);
        $this->refreshAvailability();
    }

    public function test_identical_capacity_refresh_reuses_both_catalogs_without_extending_cached_deadline(): void
    {
        $deadline = $this->product->fresh()->supplier_available_until;
        $this->assertStock(10);
        $versions = $this->versions();
        for ($i = 0; $i < 3; $i++) {
            $this->travel(1)->minutes();
            $this->supplier->forceFill(['synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5)])->save();
            $this->refreshAvailability();
            $this->assertSame($versions, $this->versions());
            $this->assertSame(0, $this->catalogProductQueries());
        }

        $this->travelTo($deadline);
        $this->assertGreaterThan(0, $this->catalogProductQueries(), 'The original cache deadline must still expire.');
        $this->assertStock(10);
        $this->travelTo($this->product->fresh()->supplier_available_until);
        $this->assertStock(0);
        $this->assertFalse(Product::query()->availableForPurchase()->whereKey($this->product->id)->exists());
    }

    public function test_shortening_expiry_rebuilds_both_catalogs_and_obeys_the_new_boundary(): void
    {
        $this->assertStock(10);
        $versions = $this->versions();
        $this->supplier->forceFill(['sync_expires_at' => now()->addMinute()])->save();
        $this->refreshAvailability();
        $this->assertNotSame($versions, $this->versions());
        $this->assertStock(10);
        $this->travel(1)->minutes();
        $this->assertStock(0);
    }

    #[DataProvider('unsafeSources')]
    public function test_ineligible_source_immediately_removes_supplier_stock_from_both_catalogs(string $change): void
    {
        $this->assertStock(10);
        match ($change) {
            'stale' => $this->supplier->forceFill(['sync_expires_at' => now()])->save(),
            'disabled' => $this->supplier->update(['is_active' => false]),
            'mapping' => $this->product->supplierSources()->sole()->update(['is_active' => false]),
            'manual' => $this->item->update(['requires_input' => true]),
            'currency' => $this->supplier->update(['currency_confirmed' => false]),
        };
        $this->refreshAvailability();
        $this->assertStock(0);
        $this->assertFalse(Product::query()->availableForPurchase()->whereKey($this->product->id)->exists());
    }

    public static function unsafeSources(): array
    {
        return array_map(static fn (string $change): array => [$change], ['stale', 'disabled', 'mapping', 'manual', 'currency']);
    }

    public function test_refresh_after_expiry_replaces_cached_sold_out_state(): void
    {
        $this->assertStock(10);
        $this->travel(5)->minutes();
        $this->assertStock(0);
        $this->supplier->forceFill(['synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5)])->save();
        $this->refreshAvailability();
        $this->assertStock(10);
    }

    public function test_business_changes_remain_visible_after_a_safe_refresh(): void
    {
        $this->assertStock(10);
        $this->travel(1)->minutes();
        $this->supplier->forceFill(['sync_expires_at' => now()->addMinutes(5)])->save();
        $this->refreshAvailability();
        $this->item->update(['stock' => 4]);
        $this->refreshAvailability();
        $this->assertStock(4);
        $this->item->update(['stock' => 0]);
        $this->refreshAvailability();
        $this->assertStock(0);
        $this->item->update(['stock' => 7]);
        $this->refreshAvailability();
        $this->assertStock(7);
        $this->product->refresh()->forceFill(['stock' => 2])->save();
        $this->assertStock(9);
        $this->product->forceFill(['reserved_stock' => 1, 'price_amount' => 25000, 'name' => 'Updated Cache Product'])->save();
        $this->assertStock(8);
        $this->assertStringContainsString('Updated Cache Product', app(StorefrontResponseCache::class)->get(''));
        $this->assertStringContainsString('25.000', app(StorefrontResponseCache::class)->get(''));
        $this->assertStringContainsString('Updated Cache Product', json_encode($this->telegramCatalog(), JSON_THROW_ON_ERROR));
        $this->product->update(['is_active' => false]);
        $this->assertStringNotContainsString('Updated Cache Product', app(StorefrontResponseCache::class)->get(''));
        $this->assertStringNotContainsString('Updated Cache Product', json_encode($this->telegramCatalog(), JSON_THROW_ON_ERROR));
        $this->product->update(['is_active' => true]);
        $this->assertStock(8);
    }

    public function test_deadline_extension_does_not_hide_business_fields_in_the_same_save(): void
    {
        $this->assertStock(10);
        $this->product->refresh()->forceFill([
            'supplier_available_until' => now()->addMinutes(6),
            'stock' => 2, 'price_amount' => 25000, 'name' => 'Updated Cache Product',
        ])->save();
        $this->assertStock(12);
        $this->assertStringContainsString('Updated Cache Product', app(StorefrontResponseCache::class)->get(''));
        $this->assertStringContainsString('25.000', app(StorefrontResponseCache::class)->get(''));
        $this->assertStringContainsString('Updated Cache Product', json_encode($this->telegramCatalog(), JSON_THROW_ON_ERROR));
    }

    public function test_quantity_tier_changes_keep_supplier_profit_capacity_authoritative_in_both_catalogs(): void
    {
        $this->assertStock(10);
        $tier = $this->product->quantityPriceTiers()->create(['minimum_quantity' => 2, 'price_amount' => 15000]);
        $this->refreshAvailability();
        $this->assertStock(1);
        $tier->update(['price_amount' => 18000]);
        $this->refreshAvailability();
        $this->assertStock(10);
        $this->get(route('storefront.shop.checkout.product', ['product' => $this->product]))
            ->assertOk()->assertSee('18000');
        app(TelegramCatalogIntents::class)->sendProductDetail($this->customer, '671', $this->product->fresh());
        $request = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            || str_ends_with($request->url(), '/editMessageText'))->last()[0];
        $this->assertStringContainsString('18.000', $request['text']);
        $tier->delete();
        $this->refreshAvailability();
        $this->assertStock(10);
    }

    public function test_extension_committed_after_previous_expiry_invalidates_both_catalogs(): void
    {
        $this->assertStock(10);
        $versions = $this->versions();
        DB::beginTransaction();
        $this->product->fresh()->forceFill(['supplier_available_until' => now()->addMinutes(10)])->save();
        $this->assertSame($versions, $this->versions());
        $this->travel(5)->minutes();
        DB::commit();
        $this->assertNotSame($versions, $this->versions());
        $this->assertStock(10);
    }

    public function test_multiple_saves_cannot_hide_a_shortened_deadline_or_business_change_before_commit(): void
    {
        $this->assertStock(10);
        $versions = $this->versions();
        DB::transaction(function () use ($versions): void {
            $product = $this->product->fresh();
            $product->update(['price_amount' => 25000]);
            $product->forceFill(['supplier_available_until' => now()->addMinutes(6)])->save();
            $this->assertSame($versions, $this->versions());
        });
        $this->assertStringContainsString('25.000', app(StorefrontResponseCache::class)->get(''));
        $this->assertNotSame($versions, $this->versions());

        $versions = $this->versions();
        DB::transaction(function () use ($versions): void {
            $product = $this->product->fresh();
            $product->forceFill(['supplier_available_until' => now()->addMinute()])->save();
            $product->forceFill(['supplier_available_until' => now()->addMinutes(2)])->save();
            $this->assertSame($versions, $this->versions());
        });
        $this->assertNotSame($versions, $this->versions());
        $this->assertStock(10);
        $this->travel(2)->minutes();
        $this->assertStock(0);
    }

    public function test_rollback_preserves_both_cached_catalogs_and_original_deadline(): void
    {
        $this->assertStock(10);
        $versions = $this->versions();
        DB::beginTransaction();
        $this->product->fresh()->forceFill(['supplier_available_until' => now()->addMinute(), 'price_amount' => 25000])->save();
        $this->assertSame($versions, $this->versions());
        DB::rollBack();
        $this->assertSame($versions, $this->versions());
        $this->assertSame(0, $this->catalogProductQueries());
        $this->travel(1)->minutes();
        $this->assertStock(10);
    }

    private function refreshAvailability(): void
    {
        app(SupplierAvailability::class)->refreshProducts([$this->product->id]);
    }

    private function versions(): array
    {
        return [Cache::get('storefront.response.version'), Cache::get('telegram.catalog.projection.version')];
    }

    private function telegramCatalog(): array
    {
        app(TelegramCatalogIntents::class)->sendCatalogPage($this->customer, '671');
        $request = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            || str_ends_with($request->url(), '/editMessageText'))->last()[0];
        $markup = $request['reply_markup'];

        return is_string($markup) ? json_decode($markup, true, flags: JSON_THROW_ON_ERROR) : $markup;
    }

    private function assertStock(int $stock): void
    {
        $html = app(StorefrontResponseCache::class)->get('');
        $this->assertStringContainsString($stock > 0 ? 'Stok: '.$stock : 'Stok kosong', $html);
        $markup = $this->telegramCatalog();
        $buttons = collect($markup['inline_keyboard'])->flatten(1);
        $button = $buttons->first(fn (array $button): bool => str_contains($button['text'], 'Cache') && isset($button['callback_data']));
        $this->assertNotNull($button);
        $this->assertSame($stock > 0 ? 'primary' : 'danger', $button['style']);
        if ($stock > 0) {
            $this->assertStringContainsString((string) $stock, $button['text']);
        }
    }

    private function catalogProductQueries(): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(StorefrontResponseCache::class)->get('');
        $this->telegramCatalog();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return count(array_filter($queries, static fn (array $query): bool => str_contains(strtolower($query['query']), '"products"')));
    }
}
