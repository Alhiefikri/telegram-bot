<?php

namespace Tests\Feature;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Jobs\SendSupplierHealthNotification;
use App\Models\Product;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierHealthEvent;
use App\Models\SupplierPricePolicy;
use App\Models\TelegramOutboundEffect;
use App\Services\Suppliers\SupplierCatalogSync;
use App\Services\Suppliers\SupplierHealthDeliveryPipeline;
use App\Services\Suppliers\SupplierHealthService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\NonTransactionalDatabaseTestCase;

final class SupplierPriceChangeNotificationTest extends NonTransactionalDatabaseTestCase
{
    private array $catalog = [];

    private string $currency = 'USDT';

    private array $supplierRequests = [];

    private array $messages = [];

    private array $httpTransactionLevels = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Cache::flush();
        Queue::fake();
        config([
            'services.telegram.admin_telegram_chat_id' => '790001',
            'services.telegram.token' => 'price-notification-test-token',
        ]);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $level = DB::connection()->transactionLevel();
            $this->httpTransactionLevels[] = $level;
            $this->assertSame(0, $level, 'Supplier reads and Telegram delivery must remain outside database transactions.');
            if (str_starts_with($request->url(), 'https://api.telegram.org/')) {
                $this->assertTrue(str_ends_with($request->url(), '/sendMessage'));
                $this->assertSame('790001', (string) $request['chat_id']);
                $this->assertSame('HTML', $request['parse_mode']);
                $this->messages[] = $request['text'];

                return Http::response(['ok' => true, 'result' => ['message_id' => 91000 + count($this->messages)]]);
            }
            $this->assertSame('GET', $request->method());
            $path = parse_url($request->url(), PHP_URL_PATH);
            $this->supplierRequests[] = $path;
            if (str_ends_with($path, '/balance') || str_ends_with($path, '/me')) {
                return Http::response(['balance' => '1000', 'currency' => $this->currency, 'chat_id' => 1]);
            }
            $this->assertTrue(str_ends_with($path, '/products'), 'Unexpected supplier request: '.$request->url());

            return Http::response(['currency' => $this->currency, 'products' => $this->catalog]);
        });
    }

    public function test_baseline_new_unmapped_and_unchanged_catalog_observations_are_quiet(): void
    {
        $supplier = $this->supplier();
        [, $item] = $this->mapped($supplier, 'mapped', 'Mapped baseline');
        $this->catalog = [$this->row('mapped', '1'), $this->row('unmapped', '1')];
        $this->sync($supplier);
        $this->assertNotNull($item->fresh()->synced_at);
        $this->assertQuiet();

        $this->catalog = [$this->row('mapped', '1'), $this->row('unmapped', '2'), $this->row('new', '3')];
        $this->sync($supplier);
        $this->sync($supplier);

        $this->assertSame(3, SupplierCatalogItem::query()->count());
        $this->assertQuiet();
        $this->assertCount(6, $this->supplierRequests);
    }

    public function test_increases_and_decreases_share_one_durable_private_batch_and_recurring_changes_are_not_suppressed(): void
    {
        $supplier = $this->supplier();
        [$up] = $this->mapped($supplier, 'up', 'Mapped increase', price: 40000);
        [$down] = $this->mapped($supplier, 'down', 'Mapped decrease', price: 40000);
        $this->catalog = [$this->row('up', '1'), $this->row('down', '1.5')];
        $this->sync($supplier);
        $this->assertQuiet();
        $this->catalog = [$this->row('up', '1.1'), $this->row('down', '1.2')];
        $this->sync($supplier);

        $this->assertSame(1, SupplierHealthEvent::query()->count());
        $effect = $this->effects()->sole();
        $event = SupplierHealthEvent::query()->sole();
        $this->assertSame(SupplierHealthEvent::class, $effect->source_type);
        $this->assertSame((string) $event->id, (string) $effect->source_id);
        $this->assertSame('790001', (string) $effect->telegram_chat_id);
        $message = $this->text($event->message);
        foreach (['Mapped increase', 'Mapped decrease', 'naik', 'turun', '1.000000', '1.100000', '1.500000', '1.200000'] as $fragment) {
            $this->assertStringContainsString($fragment, $message);
        }
        $this->assertSame([], $this->messages, 'Sync must persist an obligation, not send Telegram inline.');
        Queue::assertPushed(SendSupplierHealthNotification::class);
        $snapshot = $event->message;
        $up->update(['name' => 'Renamed after sync']);
        $down->update(['price_amount' => 45000]);

        Queue::fake();
        $this->assertSame(1, app(SupplierHealthService::class)->dispatchDue());
        Queue::assertPushed(SendSupplierHealthNotification::class, fn ($job) => $job->effectId === (string) $effect->id);
        $job = Queue::pushed(SendSupplierHealthNotification::class)->sole();
        $job->handle(app(SupplierHealthDeliveryPipeline::class));
        $job->handle(app(SupplierHealthDeliveryPipeline::class));
        $this->assertSame([$snapshot], $this->messages);
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
        $this->assertSame(0, app(SupplierHealthService::class)->dispatchDue());
        $this->assertCount(4, $this->supplierRequests, 'Recovery and delivery must not reread the supplier.');

        $this->sync($supplier);
        $this->assertSame(1, $this->effects()->count());
        $this->catalog = [$this->row('up', '1'), $this->row('down', '1.5')];
        $this->sync($supplier);
        $this->assertSame(2, $this->effects()->count());
        $this->catalog = [$this->row('up', '1.1'), $this->row('down', '1.2')];
        $this->sync($supplier);
        $this->assertSame(3, $this->effects()->count(), 'A later identical transition is a new real price change, not a permanent dedupe key.');
        $this->deliverPending();
        $this->assertCount(3, $this->messages);
        $this->assertSame([0], array_values(array_unique($this->httpTransactionLevels)));
    }

    public static function inactiveMappings(): iterable
    {
        yield 'inactive source' => [false, true, false];
        yield 'inactive product' => [true, false, false];
        yield 'deleted product' => [true, true, true];
    }

    #[DataProvider('inactiveMappings')]
    public function test_ineligible_changes_are_quiet_but_observations_advance(bool $sourceActive, bool $productActive, bool $deleted): void
    {
        $supplier = $this->supplier();
        [$product, $item] = $this->mapped($supplier, 'filtered', 'Filtered product', stock: 4);
        $source = ProductSupplierSource::query()->sole();
        $source->update(['is_active' => $sourceActive]);
        $product->update(['is_active' => $productActive]);
        if ($deleted) {
            $product->delete();
        }
        $this->catalog = [$this->row('filtered', '1')];
        $this->sync($supplier);
        $this->assertQuiet();
        $policyBefore = $product->supplierPricePolicy()->sole()->getRawOriginal();
        $capacityBefore = $product->fresh()->supplier_available_stock;
        $baseline = $item->fresh()->synced_at;
        $this->travel(1)->minutes();
        $this->catalog = [$this->row('filtered', '1.1')];
        $this->sync($supplier);

        $this->assertQuiet();
        $this->assertSame(1100000, $item->fresh()->unit_price_micros);
        $this->assertTrue($item->fresh()->synced_at->greaterThan($baseline));
        $this->assertTrue($supplier->fresh()->sync_expires_at->isFuture());
        $this->assertSame(20000, $product->fresh()->price_amount);
        $this->assertSame(4, $product->fresh()->stock);
        $this->assertSame($capacityBefore, $product->fresh()->supplier_available_stock);
        $this->assertSame($policyBefore, $product->supplierPricePolicy()->sole()->getRawOriginal());

        $source->update(['is_active' => true]);
        $product->update(['is_active' => true]);
        if ($deleted) {
            $product->restore();
        }
        $this->sync($supplier);
        $this->assertQuiet();
        $this->catalog = [$this->row('filtered', '1.2')];
        $this->sync($supplier);
        $this->assertSame(1, $this->effects()->count());
        $message = $this->text(SupplierHealthEvent::query()->sole()->message);
        $this->assertStringContainsString('1.100000 -> 1.200000', $message);
    }

    public function test_mixed_mappings_filter_each_product_even_when_the_changed_sku_is_shared(): void
    {
        $supplier = $this->supplier();
        [$active, $shared] = $this->mapped($supplier, 'shared', 'Eligible shared product');
        foreach (['inactive source', 'inactive product', 'deleted product'] as $state) {
            $product = Product::factory()->create(['name' => 'Excluded '.$state, 'is_active' => $state !== 'inactive product']);
            ProductSupplierSource::query()->create([
                'product_id' => $product->id, 'supplier_catalog_item_id' => $shared->id,
                'priority' => 1, 'is_active' => $state !== 'inactive source',
            ]);
            if ($state === 'deleted product') {
                $product->delete();
            }
        }
        [$unchanged] = $this->mapped($supplier, 'unchanged', 'Eligible unchanged product');
        $this->catalog = [$this->row('shared', '1'), $this->row('unchanged', '1')];
        $this->sync($supplier);
        $this->catalog = [$this->row('shared', '1.1'), $this->row('unchanged', '1')];
        $this->sync($supplier);

        $this->assertSame(1, SupplierHealthEvent::query()->count());
        $this->assertSame(1, $this->effects()->count());
        $message = $this->text(SupplierHealthEvent::query()->sole()->message);
        $this->assertSame(1, substr_count($message, 'Produk #'.$active->id.':'));
        $this->assertStringNotContainsString('Excluded', $message);
        $this->assertStringNotContainsString($unchanged->name, $message);
    }

    public function test_changed_inactive_source_does_not_create_a_header_only_alert_for_an_unchanged_active_source(): void
    {
        $supplier = $this->supplier();
        [$product] = $this->mapped($supplier, 'inactive', 'Inactive change');
        ProductSupplierSource::query()->where('product_id', $product->id)->update(['is_active' => false]);
        $this->mapped($supplier, 'active', 'Unchanged active product');
        $this->catalog = [$this->row('inactive', '1'), $this->row('active', '1')];
        $this->sync($supplier);
        $this->catalog = [$this->row('inactive', '1.1'), $this->row('active', '1')];
        $this->sync($supplier);
        $this->assertQuiet();

        $supplier->forceFill(['balance_micros' => 0, 'low_balance_threshold_micros' => 1000000])->save();
        $this->assertSame(1, app(SupplierHealthService::class)->materialize());
        $this->assertSame(1, $this->effects()->count());
        $this->assertStringContainsString('Saldo tersedia:', SupplierHealthEvent::query()->sole()->message);
    }

    public static function availabilityCases(): iterable
    {
        yield 'only affected source' => [0, false, false];
        yield 'local stock remains' => [2, false, true];
        yield 'alternative supplier remains' => [0, true, true];
    }

    #[DataProvider('availabilityCases')]
    public function test_minimum_profit_warning_does_not_confuse_one_source_with_total_product_availability(int $localStock, bool $fallback, bool $stillAvailable): void
    {
        $supplier = $this->supplier();
        [$product] = $this->mapped($supplier, 'margin', 'Margin example', stock: $localStock);
        if ($fallback) {
            $this->fallback($product);
        }
        $this->catalog = [$this->row('margin', '1')];
        $this->sync($supplier);
        $this->assertGreaterThan(0, $product->fresh()->availableStock());
        $policyBefore = $product->supplierPricePolicy()->sole()->getRawOriginal();
        $this->catalog = [$this->row('margin', '1.25')];
        $this->sync($supplier);
        $this->deliverPending();

        $this->assertCount(1, $this->messages);
        $message = $this->text($this->messages[0]);
        foreach (['Margin example', 'qty 1', '20.000', '1.000', 'minimum'] as $fragment) {
            $this->assertStringContainsString($fragment, $fragment === 'minimum' ? mb_strtolower($message) : $message);
        }
        $this->assertMatchesRegularExpression('/Profit qty 1[^\n]*Rp0\b/u', $message);
        $this->assertStringContainsString('di bawah', mb_strtolower($message));
        $this->assertStringContainsString('Ketersediaan total', $message);
        $available = $product->fresh()->availableStock();
        $this->assertSame($stillAvailable, $available > 0);
        $status = $stillAvailable ? '(?:masih\s+)?tersedia' : 'tidak\s+tersedia';
        $this->assertMatchesRegularExpression('/Ketersediaan total[^\n]*\b'.$available.'\s*unit;\s*'.$status.'/iu', $message);
        $this->assertStringContainsString('bukan jaminan', $message);
        $this->assertSame(20000, $product->fresh()->price_amount);
        $this->assertSame($policyBefore, $product->supplierPricePolicy()->sole()->getRawOriginal(), 'Notifications must not introduce a retail or policy mutation.');
    }

    public static function unsafeTargets(): iterable
    {
        yield 'missing' => [''];
        yield 'group chat' => ['-100790001'];
        yield 'zero' => ['0'];
    }

    #[DataProvider('unsafeTargets')]
    public function test_missing_or_non_private_target_never_creates_or_sends_price_alerts(string $target): void
    {
        $supplier = $this->supplier();
        $this->mapped($supplier, 'safe-target', 'Recipient safety');
        $this->catalog = [$this->row('safe-target', '1')];
        $this->sync($supplier);
        config(['services.telegram.admin_telegram_chat_id' => $target]);
        $this->catalog = [$this->row('safe-target', '1.1')];
        $this->sync($supplier);
        $this->assertQuiet();
        config(['services.telegram.admin_telegram_chat_id' => '790001']);
        $this->sync($supplier);
        $this->assertQuiet();
        $this->catalog = [$this->row('safe-target', '1')];
        $this->sync($supplier);
        $this->deliverPending();
        $this->assertCount(1, $this->messages);
    }

    public function test_delivery_does_not_redirect_a_private_snapshot_when_configuration_changes_to_a_group(): void
    {
        $supplier = $this->supplier();
        $this->mapped($supplier, 'redirect', 'Never redirect');
        $this->catalog = [$this->row('redirect', '1')];
        $this->sync($supplier);
        $this->catalog = [$this->row('redirect', '1.1')];
        $this->sync($supplier);
        $effect = $this->effects()->sole();
        config(['services.telegram.admin_telegram_chat_id' => '-100790001']);
        (new SendSupplierHealthNotification((string) $effect->id))->handle(app(SupplierHealthDeliveryPipeline::class));

        $this->assertSame([], $this->messages);
        $this->assertSame('790001', (string) $effect->fresh()->telegram_chat_id);
        $this->assertSame('admin_target_missing', $effect->fresh()->last_error_code);
    }

    public function test_supplier_product_and_sku_names_are_html_escaped_and_credentials_are_never_disclosed(): void
    {
        $supplierName = '<b>Supplier & "private"</b>';
        $productName = '<i>Store & "name"</i>';
        $skuName = '<a href="https://evil.invalid">SKU & source</a>';
        $supplier = $this->supplier(['name' => $supplierName, 'api_key' => 'SECRET-PRICE-API-KEY', 'webhook_secret' => 'SECRET-PRICE-WEBHOOK']);
        $this->mapped($supplier, 'escape', $productName);
        $this->catalog = [$this->row('escape', '1', $skuName)];
        $this->sync($supplier);
        $this->catalog = [$this->row('escape', '1.1', $skuName)];
        $this->sync($supplier);
        $this->deliverPending();

        $this->assertCount(1, $this->messages);
        $message = $this->messages[0];
        foreach ([$supplierName, $productName, $skuName] as $name) {
            $this->assertStringContainsString(htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $message);
            $this->assertStringNotContainsString($name, $message);
        }
        foreach (['SECRET-PRICE-API-KEY', 'SECRET-PRICE-WEBHOOK', 'price-notification-test-token'] as $secret) {
            $this->assertStringNotContainsString($secret, $message);
        }
    }

    public function test_long_change_batches_split_with_every_product_preserved_and_no_additional_supplier_reads(): void
    {
        $supplier = $this->supplier();
        $names = [];
        for ($index = 1; $index <= 30; $index++) {
            $id = sprintf('split-%03d', $index);
            $name = sprintf('Entry-%03d & 🛒 %s', $index, str_repeat('long product ', 5));
            $names[] = $name;
            $this->mapped($supplier, $id, $name);
            $this->catalog[] = $this->row($id, '1');
        }
        $this->sync($supplier);
        foreach ($this->catalog as &$row) {
            $row['price'] = '1.1';
        }
        unset($row);
        $this->sync($supplier);
        $this->assertGreaterThan(1, $this->effects()->count());
        $this->assertSame(SupplierHealthEvent::query()->count(), $this->effects()->count());
        $this->deliverPending();

        $this->assertSame($this->effects()->count(), count($this->messages));
        foreach ($this->messages as $message) {
            $this->assertLessThanOrEqual(4096, intdiv(strlen(mb_convert_encoding($message, 'UTF-16LE', 'UTF-8')), 2));
        }
        $combined = $this->text(implode("\n", $this->messages));
        foreach ($names as $name) {
            $this->assertSame(1, substr_count($combined, $name), 'Every changed mapped product must appear exactly once, without truncation.');
        }
        $this->assertCount(4, $this->supplierRequests);
        $before = $this->effects()->count();
        $this->sync($supplier);
        $this->assertSame($before, $this->effects()->count());
    }

    public function test_price_snapshot_and_outbound_obligation_rollback_with_the_catalog_transaction(): void
    {
        $supplier = $this->supplier();
        [, $item] = $this->mapped($supplier, 'rollback', 'Atomic change');
        $this->catalog = [$this->row('rollback', '1')];
        $this->sync($supplier);
        Queue::fake();
        $observedLevel = null;
        TelegramOutboundEffect::created(function (TelegramOutboundEffect $effect) use (&$observedLevel): void {
            if ($effect->effect_type === TelegramOutboundEffectType::SupplierHealth->value) {
                $observedLevel = DB::connection()->transactionLevel();
                $this->assertSame(1, SupplierHealthEvent::query()->count());
                Queue::assertNothingPushed();
                throw new RuntimeException('Deliberate price notification rollback');
            }
        });
        $this->catalog = [$this->row('rollback', '1.1')];
        try {
            app(SupplierCatalogSync::class)->sync($supplier);
            $this->fail('The deliberate notification rollback must escape the sync.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Deliberate price notification rollback', $exception->getMessage());
        }

        $this->assertGreaterThan(0, $observedLevel);
        $this->assertSame(1000000, $item->fresh()->unit_price_micros);
        $this->assertQuiet();
    }

    public function test_effective_tier_and_discount_changes_alert_without_a_base_price_change_but_equivalent_schedules_are_quiet(): void
    {
        $supplier = $this->supplier();
        [, $item] = $this->mapped($supplier, 'tiers', 'Tier and discount example');
        $row = $this->row('tiers', '1');
        $row['price_tiers'] = [['min' => 10, 'max' => null, 'price' => '0.9']];
        $row['bulk_discounts'] = [['min_qty' => 10, 'discount_pct' => '10']];
        $this->catalog = [$row];
        $this->sync($supplier);
        $this->assertQuiet();

        $row['price_tiers'][0]['price'] = '0.8';
        $this->catalog = [$row];
        $this->sync($supplier);
        $this->assertSame(1, $this->effects()->count());
        $message = $this->text(SupplierHealthEvent::query()->sole()->message);
        $this->assertStringContainsString('qty 10', $message);
        $this->assertStringContainsString('8.100000', $message);
        $this->assertStringContainsString('7.200000', $message);
        $this->assertStringNotContainsString('Harga dasar naik', $message);
        $this->assertStringNotContainsString('Harga dasar turun', $message);
        $this->assertSame(1000000, $item->fresh()->unit_price_micros);

        $row['bulk_discounts'][0]['discount_pct'] = '20';
        $this->catalog = [$row];
        $this->sync($supplier);
        $this->assertSame(2, $this->effects()->count());
        $this->assertStringContainsString('6.400000', $this->text(SupplierHealthEvent::query()->pluck('message')->implode("\n")));
        $row['bulk_discounts'][] = ['min_qty' => 20, 'discount_pct' => '5'];
        $this->catalog = [$row];
        $this->sync($supplier);
        $this->sync($supplier);
        $this->assertSame(2, $this->effects()->count(), 'A weaker additional discount must not alert when the effective best price is unchanged.');
        $this->deliverPending();
        $this->assertCount(2, $this->messages);
    }

    public function test_existing_automatic_pricing_is_preserved_and_message_uses_the_actual_current_retail(): void
    {
        $supplier = $this->supplier();
        [$product] = $this->mapped($supplier, 'automatic', 'Existing automatic pricing');
        $policy = $product->supplierPricePolicy()->sole();
        $policy->update(['mode' => 'fixed', 'profit_idr' => 2000]);
        $this->catalog = [$this->row('automatic', '1')];
        $this->sync($supplier);
        $this->assertSame(18000, $product->fresh()->price_amount);
        $policyBefore = $policy->fresh()->getRawOriginal();
        $this->catalog = [$this->row('automatic', '1.1')];
        $this->sync($supplier);
        $this->deliverPending();

        $this->assertSame(19600, $product->fresh()->price_amount);
        $this->assertSame($policyBefore, $policy->fresh()->getRawOriginal());
        $this->assertCount(1, $this->messages);
        $message = $this->text($this->messages[0]);
        $this->assertMatchesRegularExpression('/Retail normal qty 1[^\n]*19\.600/u', $message);
        $this->assertMatchesRegularExpression('/Profit qty 1[^\n]*2\.000/u', $message);
        $this->assertStringContainsString('fixed', $message);
    }

    private function supplier(array $attributes = []): Supplier
    {
        return Supplier::forceCreate(array_replace([
            'name' => 'Private price connection', 'provider' => 'prodseller', 'api_key' => 'fixture-price-key',
            'currency' => 'USDT', 'currency_confirmed' => true, 'exchange_rate_idr' => 16000,
            'balance_micros' => 1000000000, 'reserved_balance_micros' => 0,
            'low_balance_threshold_micros' => 0, 'is_active' => true,
        ], $attributes));
    }

    private function mapped(Supplier $supplier, string $externalId, string $name, int $stock = 0, int $price = 20000): array
    {
        $product = Product::factory()->create(['name' => $name, 'stock' => $stock, 'reserved_stock' => 0, 'price_amount' => $price]);
        $item = SupplierCatalogItem::query()->create([
            'supplier_id' => $supplier->id, 'external_id' => $externalId, 'name' => 'Supplier SKU '.$externalId,
            'currency' => $supplier->currency, 'unit_price_micros' => 1000000, 'stock' => 20,
            'orderable' => true, 'requires_input' => false, 'price_tiers' => [], 'bulk_discounts' => [],
        ]);
        ProductSupplierSource::query()->create([
            'product_id' => $product->id, 'supplier_catalog_item_id' => $item->id, 'priority' => 1, 'is_active' => true,
        ]);
        SupplierPricePolicy::query()->create([
            'product_id' => $product->id, 'mode' => 'manual', 'minimum_profit_idr' => 1000, 'rounding_idr' => 1,
        ]);

        return [$product, $item];
    }

    private function fallback(Product $product): void
    {
        $supplier = $this->supplier(['name' => 'Alternative connection', 'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5), 'balance_observed_at' => now()]);
        $item = SupplierCatalogItem::query()->create([
            'supplier_id' => $supplier->id, 'external_id' => 'fallback', 'name' => 'Alternative SKU',
            'currency' => 'USDT', 'unit_price_micros' => 1000000, 'stock' => 20,
            'orderable' => true, 'requires_input' => false, 'synced_at' => now(),
        ]);
        ProductSupplierSource::query()->create([
            'product_id' => $product->id, 'supplier_catalog_item_id' => $item->id, 'priority' => 2, 'is_active' => true,
        ]);
    }

    private function row(string $id, string $price, ?string $name = null): array
    {
        return ['id' => $id, 'name' => $name ?? 'Supplier SKU '.$id, 'price' => $price, 'stock' => 20, 'requiresEmailActivation' => false, 'delivery' => ['type' => 'instant']];
    }

    private function sync(Supplier $supplier): void
    {
        $before = count($this->supplierRequests);
        $this->assertTrue(app(SupplierCatalogSync::class)->sync($supplier));
        $this->assertSame(2, count($this->supplierRequests) - $before, 'Price monitoring must reuse the ordinary wallet and catalog reads, regardless of mapped product count.');
    }

    private function effects(): Builder
    {
        return TelegramOutboundEffect::query()->where('effect_type', TelegramOutboundEffectType::SupplierHealth->value);
    }

    private function deliverPending(): void
    {
        foreach ($this->effects()->where('state', TelegramOutboundEffectState::Pending->value)->orderBy('id')->get() as $effect) {
            (new SendSupplierHealthNotification((string) $effect->id))->handle(app(SupplierHealthDeliveryPipeline::class));
            $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
        }
    }

    private function assertQuiet(): void
    {
        $this->assertSame(0, SupplierHealthEvent::query()->count());
        $this->assertSame(0, $this->effects()->count());
        $this->assertSame([], $this->messages);
        Queue::assertNotPushed(SendSupplierHealthNotification::class);
    }

    private function text(string $message): string
    {
        return html_entity_decode(strip_tags($message), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
