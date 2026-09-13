<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\InventoryMovementType;
use App\Enums\TelegramOutboundEffectState;
use App\Exceptions\InsufficientStock;
use App\Jobs\SendProductStockNotifications;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\StockNotificationEvent;
use App\Models\StockNotificationRecipient;
use App\Models\StoreChannelSetting;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\CartService;
use App\Services\OrderCheckoutService;
use App\Services\OrderStateMachine;
use App\Services\ProductStockItemManager;
use App\Services\StockAdjustmentService;
use App\Services\Telegram\StockNotificationDeliveryPipeline;
use App\Services\Telegram\TelegramIntentSession;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Telegram\TelegramProductNotificationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\TelegramBotTestSupport;

class ProductInventoryRaceTest extends TelegramBotTestSupport
{
    #[Group('postgres-release')]
    public function test_two_buyers_competing_for_last_stock_can_open_detail_but_only_one_direct_purchase_reserves_stock(): void
    {
        $this->fakeTelegramHttp();
        $buyerA = Customer::factory()->create(['telegram_id' => 390001, 'locale' => 'id']);
        $buyerB = Customer::factory()->create(['telegram_id' => 390002, 'locale' => 'id']);
        $product = $this->stockedProduct(stock: 1, secrets: ['last-secret']);

        $tokenA = $this->openProductDetail($buyerA, $product, 39001, 88001);
        $tokenB = $this->openProductDetail($buyerB, $product, 39002, 88002);
        $this->payProductDetail($buyerA, $product, $tokenA, 39003, 88001);
        $this->payProductDetail($buyerB, $product, $tokenB, 39004, 88002);

        $this->assertSame(1, CartItem::sum('quantity'));
        $this->assertSame(1, Cart::where('status', CartStatus::CheckedOut)->count());
        $this->assertSame(0, Cart::where('status', CartStatus::Active)->count());
        $this->assertSame(1, Order::count());
        $this->assertSame(1, $product->fresh()->reserved_stock);
        $this->assertSame(1, ProductStockItem::where('product_id', $product->id)->whereNotNull('order_item_id')->count());
        $this->assertStockUnavailableMessageCount(0);
        $this->assertCheckoutStockChangedMessageCount(0);
        $this->assertInventoryInvariants($product);
    }

    public function test_duplicate_direct_payment_callback_creates_one_order_and_one_cart_item(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 390003, 'locale' => 'id']);
        $product = $this->stockedProduct(stock: 2, secrets: ['secret-a', 'secret-b']);

        $token = $this->openProductDetail($customer, $product, 39003, 88003);
        $this->payProductDetail($customer, $product, $token, 39004, 88003);
        $this->payProductDetail($customer, $product, $token, 39005, 88003);

        $this->assertSame(1, CartItem::count());
        $this->assertSame(1, CartItem::firstOrFail()->quantity);
        $this->assertSame(1, Order::count());
        $this->assertInventoryInvariants($product);
    }

    public function test_competing_direct_purchases_may_exceed_stock_but_checkout_does_not_oversell(): void
    {
        $this->fakeTelegramHttp();
        $buyerA = Customer::factory()->create(['telegram_id' => 391001, 'locale' => 'id']);
        $buyerB = Customer::factory()->create(['telegram_id' => 391002, 'locale' => 'id']);
        $product = $this->stockedProduct(stock: 2, secrets: ['secret-1', 'secret-2']);

        $tokenA = $this->openProductDetail($buyerA, $product, 39101, 88010);
        $tokenA = $this->increaseProductDetail($buyerA, $product, $tokenA, 39102, 88010);
        $tokenB = $this->openProductDetail($buyerB, $product, 39103, 88011);
        $this->payProductDetail($buyerA, $product, $tokenA, 39104, 88010);
        $this->payProductDetail($buyerB, $product, $tokenB, 39105, 88011);

        $this->assertSame(2, CartItem::sum('quantity'));
        $this->assertSame(1, Order::count());
        $this->assertSame(2, $product->fresh()->reserved_stock);
        $this->assertSame(2, ProductStockItem::where('product_id', $product->id)->whereNotNull('order_item_id')->count());
        $this->assertStockUnavailableMessageCount(0);
        $this->assertCheckoutStockChangedMessageCount(0);
        $this->assertInventoryInvariants($product);
    }

    public function test_paused_product_rejects_buy_and_quantity_callbacks_without_cart_mutation(): void
    {
        $this->fakeTelegramHttp();
        $product = $this->stockedProduct(stock: 1, secrets: ['paused-secret']);
        $product->forceFill(['is_active' => false])->save();

        $this->postTelegramWebhook($this->callbackUpdate(39201, 'paused-buy', 'buy:'.$product->id, ['id' => 392001], 392001))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(39202, 'paused-qty', 'qty:'.$product->id.':1', ['id' => 392001], 392001))->assertOk();

        $this->assertSame(0, Cart::count());
        $this->assertSame(0, CartItem::count());
        $this->assertInventoryInvariants($product);
    }

    public function test_duplicate_direct_payment_keeps_single_order_and_single_credential_claim(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 393001, 'locale' => 'id']);
        $product = $this->stockedProduct(stock: 1, secrets: ['checkout-secret']);

        $token = $this->openProductDetail($customer, $product, 39301, 88030);
        $this->payProductDetail($customer, $product, $token, 39302, 88030);
        $this->payProductDetail($customer, $product, $token, 39303, 88030);

        $this->assertSame(1, Order::count());
        $this->assertSame(1, OrderItem::count());
        $this->assertSame(1, ProductStockItem::where('product_id', $product->id)->whereNotNull('order_item_id')->count());
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Reservation)->count());
        $this->assertInventoryInvariants($product);
    }

    public function test_stock_ready_notification_requires_active_product_with_available_stock_and_hides_secrets(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 399001, 'locale' => 'id']);
        $product = $this->stockedProduct(stock: 1, reservedStock: 1, secrets: ['notify-secret']);

        app(TelegramProductNotificationService::class)->notifyStockAdded($product, 1);
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], '✅ Stok Tersedia'));

        $product->forceFill(['reserved_stock' => 0])->save();
        app(TelegramProductNotificationService::class)->notifyStockAdded($product, 1);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], '✅ Stok Tersedia')
            && str_contains((string) json_encode($request->data()), 'product:'.$product->id)
            && ! str_contains((string) $request['text'], 'notify-secret')
            && ! str_contains((string) json_encode($request->data()), 'notify-secret'));
        $this->assertInventoryInvariants($product);
    }

    public function test_duplicate_stock_notification_job_execution_sends_only_once(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 399002]);
        $product = $this->stockedProduct(stock: 0, secrets: []);
        $admin = User::factory()->create();
        $adjustment = app(StockAdjustmentService::class)->adjustWithAvailability(
            $product,
            1,
            'restock for notification idempotency',
            $admin,
            '127.0.0.1',
            ['idempotent-secret'],
        );
        $job = new SendProductStockNotifications($adjustment['movement_id']);

        $job->handle(app(TelegramProductNotificationService::class));
        $job->handle(app(TelegramProductNotificationService::class));

        $sendMessages = collect(Http::recorded())
            ->filter(fn (array $request): bool => str_ends_with($request[0]->url(), '/sendMessage'));

        $this->assertCount(1, $sendMessages);
        $this->assertNotNull(InventoryMovement::findOrFail($adjustment['movement_id'])->stock_notification_sent_at);
    }

    public function test_stock_notification_job_sends_one_effect_and_requeues_remaining_delivery(): void
    {
        Queue::fake();
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 399020, 'locale' => 'id']);
        Customer::factory()->create(['telegram_id' => 399021, 'locale' => 'en']);
        $product = $this->stockedProduct(stock: 0, secrets: []);
        $admin = User::factory()->create();
        $movementId = app(StockAdjustmentService::class)->adjustWithAvailability(
            $product,
            1,
            'bounded stock notification',
            $admin,
            '127.0.0.1',
            ['bounded-secret'],
        )['movement_id'];

        $job = new SendProductStockNotifications($movementId);
        $job->handle(app(StockNotificationDeliveryPipeline::class));

        $sendMessages = collect(Http::recorded())
            ->filter(fn (array $request): bool => str_ends_with($request[0]->url(), '/sendMessage'));
        $this->assertCount(1, $sendMessages);
        Queue::assertPushedOn(
            StockNotificationDeliveryPipeline::QUEUE,
            SendProductStockNotifications::class,
            fn (SendProductStockNotifications $queued): bool => $queued->movementId === $movementId,
        );

        $job->handle(app(StockNotificationDeliveryPipeline::class));

        $sendMessages = collect(Http::recorded())
            ->filter(fn (array $request): bool => str_ends_with($request[0]->url(), '/sendMessage'));
        $this->assertCount(2, $sendMessages);
    }

    public function test_sold_out_channel_notification_waits_for_confirmed_payment_and_does_not_create_an_order(): void
    {
        Queue::fake();
        $this->fakeTelegramHttp();
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100123',
            'last_test_success' => true,
        ]);
        $product = $this->stockedProduct(stock: 1, secrets: ['sold-out-secret']);
        $product->forceFill(['name' => 'ChatGPT Plus Private'])->save();
        $customer = Customer::factory()->create(['telegram_id' => 399003, 'locale' => 'id']);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->setItemQuantity($cart, $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($cart);

        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '-100123'
            && str_contains((string) ($request['text'] ?? ''), 'SOLD OUT'));
        Queue::assertNotPushed(SendProductStockNotifications::class);

        app(OrderStateMachine::class)->markPaymentPaid($order->payments()->sole());

        $sale = InventoryMovement::query()
            ->where('order_id', $order->id)
            ->where('type', InventoryMovementType::Sale)
            ->sole();
        Queue::assertPushedOn(StockNotificationDeliveryPipeline::QUEUE, SendProductStockNotifications::class, fn (SendProductStockNotifications $job): bool => $job->movementId === $sale->id);

        (new SendProductStockNotifications($sale->id))->handle(app(TelegramProductNotificationService::class));

        $this->assertSame(1, Order::count());
        $this->assertSame(0, $product->fresh()->stock);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && (string) $request['chat_id'] === '-100123'
            && str_contains((string) $request['text'], 'ChatGPT')
            && str_contains((string) $request['text'], 'SOLD OUT')
            && str_contains((string) $request['text'], 'The last unit was just taken')
            && str_contains((string) $request['text'], 'Thank you for your trust')
            && str_contains((string) $request['text'], 'Cannot be ordered for now.')
            && str_contains((string) $request['text'], 'Follow this channel for the next restock')
            && ! str_contains((string) $request['text'], 'available again')
            && ! str_contains((string) $request['text'], 'TERJUAL HABIS')
            && ! str_contains((string) $request['text'], 'Stok terakhir')
            && ! str_contains((string) $request['text'], 'invoice'));
    }

    public function test_sold_out_notification_waits_for_the_last_confirmed_payment_when_other_orders_are_reserved(): void
    {
        Queue::fake();
        $this->fakeTelegramHttp();
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100123',
            'last_test_success' => true,
        ]);
        $product = $this->stockedProduct(stock: 2, secrets: ['reserved-a', 'reserved-b']);
        $firstCustomer = Customer::factory()->create(['telegram_id' => 399004]);
        $secondCustomer = Customer::factory()->create(['telegram_id' => 399005]);

        $cartService = app(CartService::class);
        $checkout = app(OrderCheckoutService::class);
        $firstCart = $cartService->activeCartFor($firstCustomer);
        $secondCart = $cartService->activeCartFor($secondCustomer);
        $cartService->setItemQuantity($firstCart, $product, 1);
        $cartService->setItemQuantity($secondCart, $product, 1);
        $firstOrder = $checkout->checkout($firstCart);
        $secondOrder = $checkout->checkout($secondCart);

        app(OrderStateMachine::class)->markPaymentPaid($firstOrder->payments()->sole());

        Queue::assertNotPushed(SendProductStockNotifications::class);
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame(1, $product->fresh()->reserved_stock);

        app(OrderStateMachine::class)->markPaymentPaid($secondOrder->payments()->sole());

        Queue::assertPushedTimes(SendProductStockNotifications::class, 1);
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
    }

    public function test_duplicate_sold_out_notification_job_execution_sends_only_once(): void
    {
        $this->fakeTelegramHttp();
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100123',
            'last_test_success' => true,
        ]);
        $product = $this->stockedProduct(stock: 1, secrets: ['sold-out-idempotent']);
        $customer = Customer::factory()->create(['telegram_id' => 399006]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->setItemQuantity($cart, $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($cart);
        app(OrderStateMachine::class)->markPaymentPaid($order->payments()->sole());
        $sale = InventoryMovement::query()
            ->where('order_id', $order->id)
            ->where('type', InventoryMovementType::Sale)
            ->sole();
        $job = new SendProductStockNotifications($sale->id);

        $job->handle(app(TelegramProductNotificationService::class));
        $job->handle(app(TelegramProductNotificationService::class));

        $sendMessages = collect(Http::recorded())
            ->filter(fn (array $request): bool => str_ends_with($request[0]->url(), '/sendMessage')
                && (string) ($request[0]['chat_id'] ?? '') === '-100123'
                && str_contains((string) ($request[0]['text'] ?? ''), 'SOLD OUT'));

        $this->assertCount(1, $sendMessages);
        $this->assertNotNull($sale->fresh()->stock_notification_sent_at);
    }

    public function test_stock_notification_snapshots_recipients_and_marks_ambiguous_delivery_unknown(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 991001, 'locale' => 'ms']);
        $product = $this->stockedProduct(stock: 0, secrets: []);
        $admin = User::factory()->create();
        $movementId = app(StockAdjustmentService::class)->adjustWithAvailability($product, 1, 'durable restock', $admin, '127.0.0.1', ['durable-secret'])['movement_id'];

        Http::fakeSequence()->pushStatus(500)->push(['ok' => true]);
        $pipeline = app(StockNotificationDeliveryPipeline::class);
        $first = $pipeline->deliver($movementId);

        $event = StockNotificationEvent::where('inventory_movement_id', $movementId)->sole();
        $recipient = StockNotificationRecipient::where('stock_notification_event_id', $event->id)->where('telegram_id', '991001')->sole();
        $this->assertNotNull($event->recipients_snapshotted_at);
        $this->assertSame('ms', $recipient->locale);
        $this->assertSame(1, $recipient->attempts);
        $this->assertNull($recipient->failed_at);
        $this->assertStringContainsString('✅ Stok Tersedia Semula', (string) $recipient->text);
        $this->assertStringContainsString('Stok tersedia semula', (string) $recipient->text);
        $this->assertFalse($first['has_more']);
        $effect = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_STOCK_NOTIFICATION)
            ->where('source_id', (string) $recipient->id)
            ->sole();
        $this->assertSame(TelegramOutboundEffectState::Unknown, $effect->state);

        $customer->update(['locale' => 'en']);
        $second = $pipeline->deliver($movementId);
        $recipient->refresh();
        $this->assertSame('ms', $recipient->locale);
        $this->assertSame(1, $recipient->attempts);
        $this->assertNull($recipient->sent_at);
        $this->assertStringContainsString('Stok tersedia semula', (string) $recipient->text);
        $this->assertFalse($second['has_more']);
        $this->assertCount(1, Http::recorded());
    }

    public function test_lease_recovery_synchronizes_stock_recipient_and_movement_projection_without_redispatching(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Customer::factory()->create(['telegram_id' => 991006, 'locale' => 'ms']);
        $product = $this->stockedProduct(stock: 0, secrets: []);
        $admin = User::factory()->create();
        $movementId = app(StockAdjustmentService::class)->adjustWithAvailability(
            $product,
            1,
            'lease projection recovery',
            $admin,
            '127.0.0.1',
            ['lease-projection-secret'],
        )['movement_id'];

        $pipeline = app(StockNotificationDeliveryPipeline::class);
        $pipeline->materialize($movementId);
        $recipient = StockNotificationRecipient::query()->sole();
        $recipient->forceFill([
            'failed_at' => now()->subMinute(),
            'last_error' => 'stale delivery failure',
        ])->save();

        $effect = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_STOCK_NOTIFICATION)
            ->where('source_id', (string) $recipient->id)
            ->sole();
        $ledger = app(TelegramOutboundEffectLedgerProtocol::class);
        $claimed = $ledger->claim((string) $effect->getKey());
        $this->assertNotNull($claimed);
        $claimed->forceFill(['lease_until' => now()->subSecond()])->save();

        $this->assertSame(0, $recipient->fresh()->attempts);
        $this->assertNull(InventoryMovement::findOrFail($movementId)->stock_notification_sent_at);

        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])
            ->expectsOutputToContain('telegram:recover-outbound completed cleanly: dispatched=0')
            ->assertSuccessful();

        $recoveredEffect = $effect->fresh();
        $recoveredRecipient = $recipient->fresh();
        $this->assertSame(TelegramOutboundEffectState::Unknown, $recoveredEffect->state);
        $this->assertSame(1, $recoveredRecipient->attempts);
        $this->assertSame($recoveredEffect->last_error, $recoveredRecipient->last_error);
        $this->assertNull($recoveredRecipient->sent_at);
        $this->assertNull($recoveredRecipient->failed_at);
        $this->assertNull($recoveredRecipient->terminal_failed_at);
        $this->assertNull(InventoryMovement::findOrFail($movementId)->stock_notification_sent_at);
        Queue::assertNotPushed(SendProductStockNotifications::class);
        Http::assertNothingSent();

        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])
            ->expectsOutputToContain('telegram:recover-outbound completed cleanly: dispatched=0')
            ->assertSuccessful();
        $this->assertSame(1, $recipient->fresh()->attempts);
        $this->assertSame(TelegramOutboundEffectState::Unknown, $effect->fresh()->state);
        Queue::assertNotPushed(SendProductStockNotifications::class);
    }

    public function test_legacy_stock_notification_recipients_are_finalized_before_delivery(): void
    {
        $this->fakeTelegramHttp();
        $product = $this->stockedProduct(stock: 1, secrets: ['legacy-secret']);
        $movement = InventoryMovement::create([
            'product_id' => $product->id,
            'type' => InventoryMovementType::Adjustment,
            'quantity' => 1,
            'stock_before' => 0,
            'available_before' => 0,
            'notes' => 'legacy rollout',
            'created_at' => now(),
        ]);
        $movementId = $movement->id;
        $event = StockNotificationEvent::create([
            'inventory_movement_id' => $movementId,
            'kind' => InventoryMovementType::Adjustment->value,
            'recipients_snapshotted_at' => now(),
        ]);
        $recipient = StockNotificationRecipient::create([
            'stock_notification_event_id' => $event->id,
            'recipient_type' => 'customer',
            'telegram_id' => '991004',
            'locale' => null,
            'text' => 'Stok masuk',
            'reply_markup' => [],
        ]);

        $migration = require database_path('migrations/2026_08_17_000002_finalize_legacy_stock_notification_recipients.php');
        $migration->up();

        $result = app(StockNotificationDeliveryPipeline::class)->deliver($movementId);
        $recipient->refresh();

        $this->assertSame(0, $result['processed']);
        $this->assertSame(5, $recipient->attempts);
        $this->assertNotNull($recipient->failed_at);
        $this->assertNotNull($recipient->terminal_failed_at);
        $this->assertNull($recipient->sent_at);
        $this->assertStringContainsString('locale rollout', (string) $recipient->last_error);
        $this->assertCount(0, Http::recorded());
    }

    public function test_stock_notification_snapshots_each_customer_locale_and_keeps_store_channel_english(): void
    {
        $this->fakeTelegramHttp();
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100123',
            'last_test_success' => true,
        ]);
        $customers = [
            Customer::factory()->create(['telegram_id' => 991010, 'locale' => 'id']),
            Customer::factory()->create(['telegram_id' => 991011, 'locale' => 'en']),
            Customer::factory()->create(['telegram_id' => 991012, 'locale' => 'ms']),
        ];
        $product = $this->stockedProduct(stock: 0, secrets: []);
        $admin = User::factory()->create();
        $movementId = app(StockAdjustmentService::class)->adjustWithAvailability($product, 1, 'localized rollout', $admin, '127.0.0.1', ['localized-secret'])['movement_id'];

        app(StockNotificationDeliveryPipeline::class)->deliver($movementId);

        foreach ([
            [$customers[0], '✅ Stok Tersedia'],
            [$customers[1], '✅ Back in Stock'],
            [$customers[2], '✅ Stok Tersedia Semula'],
        ] as [$customer, $title]) {
            $recipient = StockNotificationRecipient::query()->where('customer_id', $customer->id)->sole();

            $this->assertSame($customer->locale, $recipient->locale);
            $this->assertStringContainsString($title, (string) $recipient->text);
            Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
                && (string) ($request['chat_id'] ?? '') === (string) $customer->telegram_id
                && str_contains((string) ($request['text'] ?? ''), $title));
        }

        $channelRecipient = StockNotificationRecipient::query()->where('recipient_type', 'store_channel')->sole();
        $this->assertNull($channelRecipient->locale);
        $this->assertStringContainsString('📦 ', (string) $channelRecipient->text);
        $this->assertStringContainsString('➕ Added: 1', (string) $channelRecipient->text);
        $this->assertStringContainsString('📦 Current stock: 1', (string) $channelRecipient->text);
        $this->assertStringContainsString('💵 Unit price: Rp. 15.000', (string) $channelRecipient->text);
        $this->assertStringNotContainsString('Stok masuk', (string) $channelRecipient->text);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '-100123'
            && str_contains((string) ($request['text'] ?? ''), '➕ Added: 1')
            && str_contains((string) ($request['text'] ?? ''), '📦 Current stock: 1')
            && ! str_contains((string) ($request['text'] ?? ''), 'Stok masuk'));
    }

    public function test_permanent_stock_notification_failure_is_recorded_for_operator_follow_up(): void
    {
        Customer::factory()->create(['telegram_id' => 991002, 'locale' => 'ms']);
        $product = $this->stockedProduct(stock: 0, secrets: []);
        $admin = User::factory()->create();
        $movementId = app(StockAdjustmentService::class)->adjustWithAvailability($product, 1, 'lease recovery', $admin, '127.0.0.1', ['lease-secret'])['movement_id'];
        $pipeline = app(StockNotificationDeliveryPipeline::class);

        Http::fake(['*' => Http::response(['ok' => false], 403)]);
        $pipeline->deliver($movementId, 1);
        $event = StockNotificationEvent::where('inventory_movement_id', $movementId)->sole();
        $recipient = StockNotificationRecipient::where('stock_notification_event_id', $event->id)->where('telegram_id', '991002')->sole();
        $this->assertSame('ms', $recipient->locale);
        $this->assertStringContainsString('✅ Stok Tersedia Semula', (string) $recipient->text);
        $this->assertNotNull($recipient->terminal_failed_at);
        $this->assertNotEmpty($recipient->last_error);

        $second = $pipeline->deliver($movementId, 1);

        $this->assertSame(0, $second['processed']);
        $this->assertCount(1, Http::recorded());
    }

    public function test_duplicate_restock_delivery_execution_sends_each_localized_recipient_once(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 991003, 'locale' => 'ms']);
        $product = $this->stockedProduct(stock: 0, secrets: []);
        $admin = User::factory()->create();
        $movementId = app(StockAdjustmentService::class)->adjustWithAvailability($product, 1, 'duplicate restock', $admin, '127.0.0.1', ['duplicate-secret'])['movement_id'];
        $pipeline = app(StockNotificationDeliveryPipeline::class);

        $first = $pipeline->deliver($movementId);
        $second = $pipeline->deliver($movementId);

        $customerMessages = collect(Http::recorded())
            ->filter(fn (array $request): bool => str_ends_with($request[0]->url(), '/sendMessage')
                && (string) ($request[0]['chat_id'] ?? '') === '991003');
        $recipient = StockNotificationRecipient::query()->where('telegram_id', '991003')->sole();

        $this->assertSame(1, $first['processed']);
        $this->assertSame(0, $second['processed']);
        $this->assertCount(1, $customerMessages);
        $this->assertSame('ms', $recipient->locale);
        $this->assertNotNull($recipient->sent_at);
        $effect = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_STOCK_NOTIFICATION)
            ->where('source_id', (string) $recipient->id)
            ->sole();
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->state);
        $this->assertSame('123457', $effect->telegram_message_id);
        $this->assertNull($effect->claim_token);
        $this->assertNull($effect->lease_until);
        $this->assertFalse($first['has_more']);
        $this->assertFalse($second['has_more']);
    }

    #[DataProvider('invalidTelegramResponses')]
    public function test_stock_notification_rejects_invalid_telegram_responses(array $response): void
    {
        Customer::factory()->create(['telegram_id' => 991005, 'locale' => 'id']);
        $product = $this->stockedProduct(stock: 0, secrets: []);
        $admin = User::factory()->create();
        $movementId = app(StockAdjustmentService::class)->adjustWithAvailability($product, 1, 'missing message id', $admin, '127.0.0.1', ['missing-id-secret'])['movement_id'];

        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response($response)]);

        $result = app(StockNotificationDeliveryPipeline::class)->deliver($movementId);
        $recipient = StockNotificationRecipient::query()->where('telegram_id', '991005')->sole();
        $effect = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_STOCK_NOTIFICATION)
            ->where('source_id', (string) $recipient->id)
            ->sole();

        $this->assertSame(1, $result['processed']);
        $this->assertSame(1, $result['transient_failures']);
        $this->assertTrue($result['has_more']);
        $this->assertSame(TelegramOutboundEffectState::Failed, $effect->state);
        $this->assertSame('telegram_response_missing_message_id', $effect->last_error_code);
        $this->assertNull($effect->telegram_message_id);
        $this->assertNull($effect->claim_token);
        $this->assertNull($effect->lease_until);
        $this->assertNull($recipient->sent_at);
        $this->assertNull(InventoryMovement::findOrFail($movementId)->stock_notification_sent_at);
    }

    public function test_restock_channel_notification_includes_added_stock_available_stock_and_current_prices(): void
    {
        $this->fakeTelegramHttp();
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100123',
            'last_test_success' => true,
        ]);
        Customer::factory()->create(['telegram_id' => 399007, 'locale' => 'id']);
        $product = $this->stockedProduct(stock: 0, secrets: []);
        $product->forceFill([
            'name' => 'ChatGPT Plus Private',
            'price_amount' => 35000,
            'usdt_price_amount' => 1_250_000,
        ])->save();
        $admin = User::factory()->create();
        $adjustment = app(StockAdjustmentService::class)->adjustWithAvailability(
            $product->fresh(),
            3,
            'channel restock notification',
            $admin,
            '127.0.0.1',
            ['restock-1', 'restock-2', 'restock-3'],
        );

        (new SendProductStockNotifications($adjustment['movement_id']))->handle(app(TelegramProductNotificationService::class));

        $this->assertSame(0, Order::count());
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && (string) $request['chat_id'] === '-100123'
            && str_contains((string) $request['text'], 'ChatGPT Plus Private')
            && str_contains((string) $request['text'], '📦 ChatGPT Plus Private')
            && str_contains((string) $request['text'], '➕ Added: 3')
            && str_contains((string) $request['text'], '📦 Current stock: 3')
            && str_contains((string) $request['text'], '💵 Unit price: Rp. 35.000 / $1.25')
            && str_contains((string) json_encode($request->data()), 'View Product')
            && ! str_contains((string) $request['text'], 'Stok masuk'));

        $secondAdjustment = app(StockAdjustmentService::class)->adjustWithAvailability(
            $product->fresh(),
            2,
            'second channel restock notification',
            $admin,
            '127.0.0.1',
            ['restock-4', 'restock-5'],
        );

        (new SendProductStockNotifications($secondAdjustment['movement_id']))->handle(app(TelegramProductNotificationService::class));

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && (string) $request['chat_id'] === '-100123'
            && str_contains((string) $request['text'], '📦 ChatGPT Plus Private')
            && str_contains((string) $request['text'], '➕ Added: 2')
            && str_contains((string) $request['text'], '📦 Current stock: 5')
            && str_contains((string) $request['text'], '💵 Unit price: Rp. 35.000 / $1.25'));

        $customerMessages = collect(Http::recorded())
            ->filter(fn (array $request): bool => str_ends_with($request[0]->url(), '/sendMessage')
                && (string) ($request[0]['chat_id'] ?? '') === '399007');
        $this->assertCount(1, $customerMessages);
        $this->assertSame(2, StockNotificationEvent::query()->count());
    }

    public function test_restock_channel_notification_uses_the_configured_telegram_image_with_current_prices(): void
    {
        $this->fakeTelegramHttp();
        Storage::fake('public');
        Storage::disk('public')->put('telegram-products/netflix.webp', 'telegram-poster');
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100123',
            'last_test_success' => true,
        ]);
        Customer::factory()->create(['telegram_id' => 399008, 'locale' => 'id']);
        $product = $this->stockedProduct(stock: 0, secrets: []);
        $product->forceFill([
            'name' => 'Netflix Premium 4K UHD Full Admin Account (1 Month)',
            'price_amount' => 13000,
            'usdt_price_amount' => 1_000_000,
            'telegram_image_path' => 'telegram-products/netflix.webp',
        ])->save();
        $admin = User::factory()->create();
        $adjustment = app(StockAdjustmentService::class)->adjustWithAvailability(
            $product->fresh(),
            5,
            'restock image notification',
            $admin,
            '127.0.0.1',
            ['image-restock-1', 'image-restock-2', 'image-restock-3', 'image-restock-4', 'image-restock-5'],
        );

        (new SendProductStockNotifications($adjustment['movement_id']))->handle(app(TelegramProductNotificationService::class));

        $channelPhotos = collect(Http::recorded())
            ->filter(fn (array $request): bool => str_ends_with($request[0]->url(), '/sendPhoto')
                && (string) $this->telegramRequestField($request[0], 'chat_id') === '-100123');
        $this->assertCount(1, $channelPhotos);
        $photoRequest = $channelPhotos->first()[0];
        $photo = collect($photoRequest->data())->first(
            fn ($part): bool => is_array($part) && ($part['name'] ?? null) === 'photo',
        );

        $this->assertSame('netflix.webp', $photo['filename'] ?? null);
        $this->assertSame('telegram-poster', $photo['contents'] ?? null);
        $caption = (string) $this->telegramRequestField($photoRequest, 'caption');
        $this->assertStringContainsString('➕ Added: 5', $caption);
        $this->assertStringContainsString('📦 Current stock: 5', $caption);
        $this->assertStringContainsString('💵 Unit price: Rp. 13.000 / $1', $caption);
        $channelRecipient = StockNotificationRecipient::query()
            ->where('recipient_type', 'store_channel')
            ->sole();
        $this->assertSame('telegram-products/netflix.webp', $channelRecipient->photo_path);
        $effect = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_STOCK_NOTIFICATION)
            ->where('source_id', (string) $channelRecipient->id)
            ->sole();
        $this->assertSame('sendPhoto', $effect->telegram_method);
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '-100123');
    }

    public function test_restock_channel_notification_falls_back_to_text_when_photo_file_is_missing(): void
    {
        $this->fakeTelegramHttp();
        Storage::fake('public');
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100123',
            'last_test_success' => true,
        ]);
        $product = $this->stockedProduct(stock: 0, secrets: []);
        $product->forceFill([
            'name' => 'Missing Restock Poster',
            'telegram_image_path' => 'telegram-products/missing.webp',
        ])->save();
        $admin = User::factory()->create();
        $adjustment = app(StockAdjustmentService::class)->adjustWithAvailability(
            $product->fresh(),
            1,
            'missing restock image',
            $admin,
            '127.0.0.1',
            ['missing-poster-stock'],
        );

        (new SendProductStockNotifications($adjustment['movement_id']))->handle(app(TelegramProductNotificationService::class));

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '-100123'
            && str_contains((string) ($request['text'] ?? ''), '➕ Added: 1')
            && str_contains((string) ($request['text'] ?? ''), '📦 Current stock: 1'));
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto')
            && (string) $this->telegramRequestField($request, 'chat_id') === '-100123');
    }

    public function test_managed_product_availability_uses_lower_numeric_stock_or_unclaimed_credentials(): void
    {
        $numericHigher = $this->stockedProduct(stock: 5, secrets: ['credential-a', 'credential-b']);
        $credentialsHigher = $this->stockedProduct(stock: 1, secrets: ['credential-c', 'credential-d', 'credential-e']);

        $this->assertSame(2, $numericHigher->fresh()->availableStock());
        $this->assertSame(1, $credentialsHigher->fresh()->availableStock());
    }

    public function test_cart_rejects_managed_product_when_numeric_stock_exceeds_credentials(): void
    {
        $product = $this->stockedProduct(stock: 2, secrets: ['only-credential']);
        $firstCustomer = Customer::factory()->create();
        $secondCustomer = Customer::factory()->create();

        $firstCart = app(CartService::class)->activeCartFor($firstCustomer);
        app(CartService::class)->setItemQuantity($firstCart, $product, 1);
        app(OrderCheckoutService::class)->checkout($firstCart);

        $secondCart = app(CartService::class)->activeCartFor($secondCustomer);

        $this->expectException(InsufficientStock::class);
        app(CartService::class)->setItemQuantity($secondCart, $product->fresh(), 1);
    }

    public function test_managed_stock_adjustment_requires_matching_credential_rows(): void
    {
        $product = $this->stockedProduct(stock: 1, secrets: ['managed-credential']);

        $this->expectException(\InvalidArgumentException::class);
        app(StockAdjustmentService::class)->adjust($product, 1, 'numeric-only mismatch');
    }

    public function test_admin_adds_stock_twice_without_losing_submitted_stock_lines(): void
    {
        $product = $this->stockedProduct(stock: 0, secrets: []);
        $admin = User::factory()->create();
        $service = app(StockAdjustmentService::class);

        $service->adjust($product, 2, 'first add', $admin, '127.0.0.1', ['a-1', 'a-2']);
        $service->adjust($product->fresh(), 2, 'second add', $admin, '127.0.0.1', ['b-1', 'b-2']);

        $this->assertSame(4, $product->fresh()->stock);
        $this->assertSame(4, ProductStockItem::where('product_id', $product->id)->whereNull('order_item_id')->count());
        $this->assertSame(4, InventoryMovement::where('product_id', $product->id)->where('type', InventoryMovementType::Adjustment)->sum('quantity'));
        $this->assertSame(2, AuditLog::where('action', 'product.stock_adjusted')->where('subject_id', (string) $product->id)->count());
        $this->assertInventoryInvariants($product);
    }

    public function test_admin_deleting_different_available_stock_items_decrements_once_per_item(): void
    {
        $product = $this->stockedProduct(stock: 2, secrets: ['delete-a', 'delete-b']);
        $admin = User::factory()->create();
        $items = ProductStockItem::where('product_id', $product->id)->orderBy('id')->get();
        $manager = app(ProductStockItemManager::class);

        $manager->deleteAvailable($product, $items[0], $admin, '127.0.0.1');
        $manager->deleteAvailable($product->fresh(), $items[1], $admin, '127.0.0.1');

        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(0, ProductStockItem::where('product_id', $product->id)->count());
        $this->assertSame(-2, InventoryMovement::where('product_id', $product->id)->where('type', InventoryMovementType::Adjustment)->sum('quantity'));
        $this->assertInventoryInvariants($product);
    }

    public function test_admin_deleting_same_stock_item_decrements_and_audits_once(): void
    {
        $product = $this->stockedProduct(stock: 1, secrets: ['delete-once']);
        $admin = User::factory()->create();
        $item = ProductStockItem::where('product_id', $product->id)->firstOrFail();
        $manager = app(ProductStockItemManager::class);

        $manager->deleteAvailable($product, $item, $admin, '127.0.0.1');

        try {
            $manager->deleteAvailable($product->fresh(), $item, $admin, '127.0.0.1');
            $this->fail('Second delete should not succeed.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(1, InventoryMovement::where('product_id', $product->id)->where('type', InventoryMovementType::Adjustment)->count());
        $this->assertSame(1, AuditLog::where('action', 'product_stock_item.deleted')->where('subject_id', (string) $product->id)->count());
        $this->assertInventoryInvariants($product);
    }

    public function test_buyer_claim_winning_before_admin_delete_or_edit_leaves_single_claimed_stock_item(): void
    {
        $product = $this->stockedProduct(stock: 1, secrets: ['claimed-secret']);
        $customer = Customer::factory()->create();
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->setItemQuantity($cart, $product, 1);
        app(OrderCheckoutService::class)->checkout($cart);
        $admin = User::factory()->create();
        $claimedItem = ProductStockItem::where('product_id', $product->id)->firstOrFail();
        $manager = app(ProductStockItemManager::class);

        foreach (['delete', 'edit'] as $operation) {
            try {
                if ($operation === 'delete') {
                    $manager->deleteAvailable($product->fresh(), $claimedItem, $admin, '127.0.0.1');
                } else {
                    $manager->updateAvailableSecret($product->fresh(), $claimedItem, 'changed-secret', $admin, '127.0.0.1');
                }
                $this->fail("Claimed stock item {$operation} should not succeed.");
            } catch (ModelNotFoundException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(1, ProductStockItem::where('product_id', $product->id)->whereNotNull('order_item_id')->count());
        $this->assertSame(0, InventoryMovement::where('product_id', $product->id)->where('type', InventoryMovementType::Adjustment)->count());
        $this->assertInventoryInvariants($product);
    }

    public function test_order_release_serializes_with_admin_stock_mutations_and_unclaims_credentials(): void
    {
        $product = $this->stockedProduct(stock: 2, secrets: ['claimed-secret', 'available-secret']);
        $customer = Customer::factory()->create();
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->setItemQuantity($cart, $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($cart);
        $admin = User::factory()->create();
        $availableItem = ProductStockItem::where('product_id', $product->id)->whereNull('order_item_id')->firstOrFail();

        app(ProductStockItemManager::class)->deleteAvailable($product->fresh(), $availableItem, $admin, '127.0.0.1');
        app(OrderStateMachine::class)->cancelPendingOrder($order->fresh());
        app(StockAdjustmentService::class)->adjust($product->fresh(), 1, 'restock after release', $admin, '127.0.0.1', ['new-secret']);

        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(2, ProductStockItem::where('product_id', $product->id)->whereNull('order_item_id')->count());
        $this->assertSame(0, ProductStockItem::where('product_id', $product->id)->whereNotNull('order_item_id')->count());
        $this->assertInventoryInvariants($product);
    }

    private function openProductDetail(Customer $customer, Product $product, int $updateId, int $messageId): string
    {
        $this->postTelegramWebhook($this->callbackUpdate(
            $updateId,
            'product-'.$updateId,
            'product:'.$product->id,
            ['id' => $customer->telegram_id],
            $customer->telegram_id,
            'private',
            $messageId,
        ))->assertOk();

        $selection = app(TelegramIntentSession::class)->get($customer);
        $this->assertNotNull($selection);

        return (string) $selection['interactionToken'];
    }

    private function increaseProductDetail(Customer $customer, Product $product, string $token, int $updateId, int $messageId): string
    {
        $this->postTelegramWebhook($this->callbackUpdate(
            $updateId,
            'increase-'.$updateId,
            'detail:qty:+:'.$product->id.':'.$token,
            ['id' => $customer->telegram_id],
            $customer->telegram_id,
            'private',
            $messageId,
        ))->assertOk();

        $selection = app(TelegramIntentSession::class)->get($customer);
        $this->assertNotNull($selection);

        return (string) $selection['interactionToken'];
    }

    private function payProductDetail(Customer $customer, Product $product, string $token, int $updateId, int $messageId): void
    {
        $this->postTelegramWebhook($this->callbackUpdate(
            $updateId,
            'pay-'.$updateId,
            'detail:pay:qris:'.$product->id.':'.$token,
            ['id' => $customer->telegram_id],
            $customer->telegram_id,
            'private',
            $messageId,
        ))->assertOk();
    }

    /** @param list<string> $secrets */
    private function stockedProduct(int $stock, array $secrets, int $reservedStock = 0): Product
    {
        $product = Product::factory()->create([
            'stock' => $stock,
            'reserved_stock' => $reservedStock,
            'is_active' => true,
            'price_amount' => 15000,
        ]);

        foreach ($secrets as $secret) {
            ProductStockItem::create(['product_id' => $product->id, 'secret' => $secret]);
        }

        return $product;
    }

    private function assertInventoryInvariants(Product $product): void
    {
        $product = $product->fresh();
        $this->assertNotNull($product);
        $numericAvailable = max(0, $product->stock - $product->reserved_stock);

        $this->assertGreaterThanOrEqual(0, $product->stock);
        $this->assertGreaterThanOrEqual(0, $product->reserved_stock);
        $this->assertGreaterThanOrEqual($product->reserved_stock, $product->stock);

        $stockItems = ProductStockItem::where('product_id', $product->id)->get();
        $expectedAvailable = $stockItems->isNotEmpty()
            ? min($numericAvailable, $stockItems->whereNull('order_item_id')->count())
            : $numericAvailable;
        $this->assertSame($expectedAvailable, $product->availableStock());
        $this->assertSame(0, $stockItems->whereNotNull('order_item_id')->filter(fn (ProductStockItem $item): bool => $item->order_item_id === null)->count());
        $this->assertSame(0, $stockItems->whereNull('order_item_id')->filter(fn (ProductStockItem $item): bool => $item->order_item_id !== null)->count());

        $claimedSecrets = $stockItems->whereNotNull('order_item_id')->pluck('secret')->all();
        $this->assertSame(count($claimedSecrets), count(array_unique($claimedSecrets)));
    }

    private function assertPaymentChoiceMessageCount(int $expected): void
    {
        $count = 0;
        Http::assertSent(function ($request) use (&$count): bool {
            if (str_ends_with($request->url(), '/sendMessage') && str_contains((string) $request['text'], 'Sudah cocok? Lanjutkan checkout di bawah.')) {
                $count++;
            }

            return true;
        });
        $this->assertSame($expected, $count);
    }

    private function assertStockUnavailableMessageCount(int $expected): void
    {
        $count = 0;
        Http::assertSent(function ($request) use (&$count): bool {
            if (str_ends_with($request->url(), '/sendMessage') && str_contains((string) $request['text'], 'Produk belum bisa masuk keranjang. Stoknya berubah atau sudah tidak tersedia.')) {
                $count++;
            }

            return true;
        });
        $this->assertSame($expected, $count);
    }

    private function assertCheckoutStockChangedMessageCount(int $expected): void
    {
        $count = 0;
        Http::assertSent(function ($request) use (&$count): bool {
            if (str_ends_with($request->url(), '/sendMessage')
                && str_contains((string) $request['text'], 'Checkout belum berhasil.')
                && str_contains((string) $request['text'], 'stok berubah')) {
                $count++;
            }

            return true;
        });
        $this->assertSame($expected, $count);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidTelegramResponses(): iterable
    {
        yield 'ok false with numeric result' => [['ok' => false, 'result' => ['message_id' => 901]]];
        yield 'zero' => [['ok' => true, 'result' => ['message_id' => 0]]];
        yield 'negative' => [['ok' => true, 'result' => ['message_id' => -1]]];
        yield 'fractional' => [['ok' => true, 'result' => ['message_id' => 1.5]]];
        yield 'empty' => [['ok' => true, 'result' => ['message_id' => '']]];
        yield 'non numeric' => [['ok' => true, 'result' => ['message_id' => 'invalid']]];
    }
}
