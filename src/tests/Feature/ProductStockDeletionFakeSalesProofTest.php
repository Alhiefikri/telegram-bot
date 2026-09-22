<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Jobs\PublishDeletedStockFakeSalesProof;
use App\Jobs\SendProductStockNotifications;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\StockNotificationEvent;
use App\Models\StockNotificationRecipient;
use App\Models\StoreChannelSetting;
use App\Models\User;
use App\Services\ProductStockDeletionFakeSalesProofAggregator;
use App\Services\ProductStockItemManager;
use App\Services\StockAdjustmentService;
use App\Services\Telegram\FakeSalesProofPostPublisher;
use App\Services\Telegram\StockNotificationDeliveryPipeline;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\TelegramBotTestSupport;

class ProductStockDeletionFakeSalesProofTest extends TelegramBotTestSupport
{
    public function test_same_product_deletions_share_one_tiered_fee_aware_fake_proof_job(): void
    {
        Queue::fake();
        $this->fakeTelegramHttp();
        $this->configureChannel();

        $product = Product::factory()->withQuantityPriceTiers([
            ['minimum_quantity' => 2, 'price_amount' => 40_000, 'usdt_price_amount' => null],
        ])->create([
            'name' => 'Tiered deletion product',
            'price_amount' => 50_000,
            'currency' => 'IDR',
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'delete-tier-a']);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'delete-tier-b']);
        $items = ProductStockItem::query()->where('product_id', $product->id)->orderBy('id')->get();
        $admin = User::factory()->create();
        $manager = app(ProductStockItemManager::class);

        $manager->deleteAvailable($product, $items[0], $admin, '127.0.0.1');
        $manager->deleteAvailable($product->fresh(), $items[1], $admin, '127.0.0.1');

        $job = $this->captureDeletionJob($product->id);
        $this->assertNotNull($job->delay);
        $this->assertSame([30, 120], $job->backoff());
        Queue::assertNotPushed(SendProductStockNotifications::class);
        $this->assertSame(0, InventoryMovement::query()->where('product_id', $product->id)->where('type', InventoryMovementType::Sale)->count());
        $this->assertSame(2, InventoryMovement::query()->where('product_id', $product->id)->where('type', InventoryMovementType::Adjustment)->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'product_stock_item.deleted')->where('subject_id', (string) $product->id)->count());

        $job->handle(
            app(ProductStockDeletionFakeSalesProofAggregator::class),
            app(FakeSalesProofPostPublisher::class),
        );

        Queue::assertPushedOn(
            StockNotificationDeliveryPipeline::QUEUE,
            SendProductStockNotifications::class,
        );
        $photo = $this->telegramPhotoRequests()->sole()[0];
        $caption = (string) $this->telegramRequestField($photo, 'caption');
        $this->assertStringContainsString('Tiered deletion product × 2', $caption);
        $this->assertStringContainsString('🏷 Bulk pricing applied · 2+ units', $caption);
        $this->assertStringContainsString('Subtotal: Rp 80.000', $caption);
        $this->assertStringContainsString('Fee: Rp 800', $caption);
        $this->assertStringContainsString('Amount paid: Rp 80.800', $caption);
        $this->assertSame(0, $this->tableCount('orders'));
        $this->assertSame(0, $this->tableCount('payments'));
        $this->assertSame(0, $this->tableCount('sales_proof_posts'));
    }

    public function test_different_products_get_separate_jobs_and_partial_deletion_has_no_sold_out_event(): void
    {
        Queue::fake();
        $this->configureChannel();
        $first = $this->productWithStock('Partial product', ['partial-secret-a', 'partial-secret-b']);
        $second = $this->productWithStock('Sold-out product', ['sold-out-secret']);
        $admin = User::factory()->create();
        $manager = app(ProductStockItemManager::class);

        $manager->deleteAvailable($first, ProductStockItem::query()->where('product_id', $first->id)->orderBy('id')->firstOrFail(), $admin);
        $manager->deleteAvailable($second, ProductStockItem::query()->where('product_id', $second->id)->firstOrFail(), $admin);

        Queue::assertPushedTimes(PublishDeletedStockFakeSalesProof::class, 2);
        $this->assertSame(0, InventoryMovement::query()
            ->where('product_id', $first->id)
            ->where('became_unavailable', true)
            ->count());
        $this->assertSame(1, InventoryMovement::query()
            ->where('product_id', $second->id)
            ->where('became_unavailable', true)
            ->count());
        $this->assertSame(1, StockNotificationEvent::query()
            ->whereHas('movement', fn ($movement) => $movement->where('product_id', $second->id))
            ->count());
        $this->assertSame(0, StockNotificationEvent::query()
            ->whereHas('movement', fn ($movement) => $movement->where('product_id', $first->id))
            ->count());
    }

    public function test_failed_reserved_stock_deletion_does_not_enter_the_fake_proof_batch(): void
    {
        Queue::fake();
        $product = $this->productWithStock('Reserved product', ['reserved-secret'], reservedStock: 1);
        $admin = User::factory()->create();

        $exception = null;
        try {
            app(ProductStockItemManager::class)->deleteAvailable(
                $product,
                ProductStockItem::query()->where('product_id', $product->id)->firstOrFail(),
                $admin,
            );
        } catch (ValidationException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(ValidationException::class, $exception);

        Queue::assertNotPushed(PublishDeletedStockFakeSalesProof::class);
        $this->assertSame(0, InventoryMovement::query()->where('product_id', $product->id)->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'product_stock_item.deleted')->where('subject_id', (string) $product->id)->count());
    }

    public function test_sold_out_transition_sends_channel_and_all_customers_once_without_admin_or_checkout_button(): void
    {
        Queue::fake();
        $this->fakeTelegramHttp();
        $this->configureChannel();
        config()->set('services.telegram.admin_telegram_chat_id', '999999');
        $idCustomer = Customer::factory()->create(['telegram_id' => 710001, 'locale' => 'id']);
        $enCustomer = Customer::factory()->create(['telegram_id' => 710002, 'locale' => 'en']);
        $product = $this->productWithStock('Localized sold-out product', ['sold-out-fanout-secret']);
        $admin = User::factory()->create();
        $manager = app(ProductStockItemManager::class);
        $item = ProductStockItem::query()->where('product_id', $product->id)->firstOrFail();

        $manager->deleteAvailable($product, $item, $admin);
        $job = $this->captureDeletionJob($product->id);
        $job->handle(
            app(ProductStockDeletionFakeSalesProofAggregator::class),
            app(FakeSalesProofPostPublisher::class),
        );

        $movement = InventoryMovement::query()->where('product_id', $product->id)->latest('id')->firstOrFail();
        Queue::assertPushed(SendProductStockNotifications::class, fn (SendProductStockNotifications $queued): bool => $queued->movementId === $movement->id);
        $this->assertTrue($movement->became_unavailable);
        $this->assertSame(0, $product->fresh()->availableStock());
        $this->assertSame(1, StockNotificationEvent::query()->where('inventory_movement_id', $movement->id)->count());
        $pipeline = app(StockNotificationDeliveryPipeline::class);
        $first = $pipeline->deliver($movement->id, 100);
        $second = $pipeline->deliver($movement->id, 100);

        $this->assertSame(3, $first['processed']);
        $this->assertSame(0, $second['processed']);
        $channelMessages = $this->telegramMessageRequests('-100123');
        $this->assertCount(1, $channelMessages);
        $channelText = (string) $channelMessages->first()[0]['text'];
        $this->assertStringContainsString('SOLD OUT', $channelText);
        $this->assertStringContainsString('The last unit was just taken', $channelText);
        $this->assertStringContainsString('Cannot be ordered for now.', $channelText);
        $this->assertStringContainsString('Follow this channel for the next restock', $channelText);

        $idMessages = $this->telegramMessageRequests((string) $idCustomer->telegram_id);
        $enMessages = $this->telegramMessageRequests((string) $enCustomer->telegram_id);
        $this->assertCount(1, $idMessages);
        $this->assertCount(1, $enMessages);
        $this->assertStringContainsString('🔴 Stok Habis', (string) $idMessages->first()[0]['text']);
        $this->assertStringContainsString('🔴 Out of Stock', (string) $enMessages->first()[0]['text']);
        foreach ([$idMessages->first()[0], $enMessages->first()[0]] as $request) {
            $this->assertArrayNotHasKey('reply_markup', $request->data());
        }
        $this->assertCount(0, $this->telegramMessageRequests('999999'));
        $this->assertSame(1, InventoryMovement::query()->where('product_id', $product->id)->where('type', InventoryMovementType::Adjustment)->count());
        $this->assertSame(0, InventoryMovement::query()->where('product_id', $product->id)->where('type', InventoryMovementType::Sale)->count());
    }

    public function test_restock_before_flush_suppresses_stale_sold_out_delivery(): void
    {
        Queue::fake();
        $this->fakeTelegramHttp();
        $this->configureChannel();
        Customer::factory()->create(['telegram_id' => 710003, 'locale' => 'id']);
        $product = $this->productWithStock('Stale sold-out product', ['stale-secret']);
        $admin = User::factory()->create();
        $manager = app(ProductStockItemManager::class);

        $manager->deleteAvailable($product, ProductStockItem::query()->where('product_id', $product->id)->firstOrFail(), $admin);
        $job = $this->captureDeletionJob($product->id);
        app(StockAdjustmentService::class)->adjust(
            $product->fresh(),
            1,
            'restock before deletion proof flush',
            $admin,
            null,
            ['replacement-secret'],
        );

        $job->handle(
            app(ProductStockDeletionFakeSalesProofAggregator::class),
            app(FakeSalesProofPostPublisher::class),
        );
        $movement = InventoryMovement::query()->where('product_id', $product->id)->where('quantity', -1)->firstOrFail();
        $result = app(StockNotificationDeliveryPipeline::class)->deliver($movement->id, 100);

        $this->assertSame(0, $result['processed']);
        $this->assertCount(1, $this->telegramPhotoRequests());
        $this->assertCount(0, $this->telegramMessageRequests('-100123'));
        $this->assertCount(0, $this->telegramMessageRequests('710003'));
        $this->assertSame(0, StockNotificationRecipient::query()->whereHas('event', fn ($event) => $event->where('inventory_movement_id', $movement->id))->count());
    }

    public function test_restock_notifies_all_customers_even_without_a_store_channel(): void
    {
        Queue::fake();
        $this->fakeTelegramHttp();
        $first = Customer::factory()->create(['telegram_id' => 710004, 'locale' => 'id']);
        $second = Customer::factory()->create(['telegram_id' => 710005, 'locale' => 'en']);
        $product = Product::factory()->create([
            'name' => 'Channel-independent restock product',
            'stock' => 0,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        $admin = User::factory()->create();

        $movementId = app(StockAdjustmentService::class)->adjustWithAvailability(
            $product,
            2,
            'channel-independent restock',
            $admin,
            null,
            ['restock-independent-a', 'restock-independent-b'],
        )['movement_id'];
        $result = app(StockNotificationDeliveryPipeline::class)->deliver($movementId, 100);

        $this->assertSame(2, $result['processed']);
        $this->assertCount(0, $this->telegramMessageRequests('-100123'));
        $this->assertCount(1, $this->telegramMessageRequests((string) $first->telegram_id));
        $this->assertCount(1, $this->telegramMessageRequests((string) $second->telegram_id));
    }

    public function test_telegram_failure_after_delete_does_not_rollback_adjustment_or_audit(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => Http::response(['ok' => false], 500),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 123457]]),
        ]);
        $this->configureChannel();
        $product = $this->productWithStock('Failed proof product', ['failed-proof-secret']);
        $admin = User::factory()->create();

        app(ProductStockItemManager::class)->deleteAvailable(
            $product,
            ProductStockItem::query()->where('product_id', $product->id)->firstOrFail(),
            $admin,
        );
        Http::assertNothingSent();
        $job = $this->captureDeletionJob($product->id);

        try {
            $job->handle(
                app(ProductStockDeletionFakeSalesProofAggregator::class),
                app(FakeSalesProofPostPublisher::class),
            );
            $this->fail('A Telegram failure should leave the fake-proof batch retryable.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('retrying', $exception->getMessage());
        }

        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(1, InventoryMovement::query()->where('product_id', $product->id)->where('type', InventoryMovementType::Adjustment)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'product_stock_item.deleted')->where('subject_id', (string) $product->id)->count());
        Queue::assertPushed(SendProductStockNotifications::class);
        $this->assertSame(0, $this->tableCount('sales_proof_posts'));
    }

    private function configureChannel(): void
    {
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100123',
            'last_test_success' => true,
            'sales_proof_enabled' => true,
        ]);
    }

    /** @param list<string> $secrets */
    private function productWithStock(string $name, array $secrets, int $reservedStock = 0): Product
    {
        $product = Product::factory()->create([
            'name' => $name,
            'stock' => count($secrets),
            'reserved_stock' => $reservedStock,
            'is_active' => true,
            'price_amount' => 15_000,
        ]);
        foreach ($secrets as $secret) {
            ProductStockItem::create(['product_id' => $product->id, 'secret' => $secret]);
        }

        return $product;
    }

    private function captureDeletionJob(int $productId): PublishDeletedStockFakeSalesProof
    {
        $captured = null;
        Queue::assertPushed(PublishDeletedStockFakeSalesProof::class, function (PublishDeletedStockFakeSalesProof $job) use (&$captured, $productId): bool {
            if ($job->productId !== $productId) {
                return false;
            }

            $captured = $job;

            return true;
        });
        if (! $captured instanceof PublishDeletedStockFakeSalesProof) {
            $this->fail('Expected a deletion fake proof job.');
        }

        return $captured;
    }

    /** @return Collection<int, array{0:Request,1:mixed}> */
    private function telegramPhotoRequests(): Collection
    {
        return collect(Http::recorded())
            ->filter(fn (array $record): bool => str_ends_with($record[0]->url(), '/sendPhoto'))
            ->values();
    }

    /** @return Collection<int, array{0:Request,1:mixed}> */
    private function telegramMessageRequests(string $chatId): Collection
    {
        return collect(Http::recorded())
            ->filter(fn (array $record): bool => str_ends_with($record[0]->url(), '/sendMessage')
                && ($chatId === '' || (string) ($record[0]['chat_id'] ?? '') === $chatId))
            ->values();
    }

    private function tableCount(string $table): int
    {
        return (int) DB::table($table)->count();
    }
}
