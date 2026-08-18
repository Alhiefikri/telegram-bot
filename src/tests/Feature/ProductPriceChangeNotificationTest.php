<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Jobs\SendProductPriceChangeNotifications;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductPriceChangeEvent;
use App\Models\ProductPriceChangeNotificationRecipient;
use App\Models\StoreChannelSetting;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\Telegram\ProductPriceChangeNotificationService;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Storefront\StorefrontUrl;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ProductPriceChangeNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_committed_decrease_sends_localized_price_drop_messages_with_product_cta(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 123]])]);

        Customer::factory()->create(['telegram_id' => 7001, 'locale' => 'id']);
        Customer::factory()->create(['telegram_id' => 7002, 'locale' => 'en']);
        Customer::factory()->create(['telegram_id' => 7003, 'locale' => 'ms']);
        $product = Product::factory()->create([
            'name' => 'ChatGPT Premium',
            'price_amount' => 35000,
            'usdt_price_amount' => 2_500_000,
            'stock' => 3,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $event = ProductPriceChangeEvent::create([
            'event_key' => (string) str()->uuid(),
            'product_id' => $product->id,
            'product_name' => $product->name,
            'old_price_amount' => 35000,
            'new_price_amount' => 30000,
            'old_usdt_price_amount' => 2_500_000,
            'new_usdt_price_amount' => 2_250_000,
        ]);

        app(ProductPriceChangeNotificationService::class)->processBatch($event->id);

        $idRequest = $this->telegramSendFor(7001);
        $enRequest = $this->telegramSendFor(7002);
        $msRequest = $this->telegramSendFor(7003);

        $this->assertStringContainsString('🏷️ Harga Turun', (string) $idRequest['text']);
        $this->assertStringContainsString('Kabar bagus buat kamu.', (string) $idRequest['text']);
        $this->assertStringContainsString('Rp35.000 → Rp30.000', (string) $idRequest['text']);
        $this->assertStringContainsString('2.5 USDT → 2.25 USDT', (string) $idRequest['text']);
        $this->assertStringContainsString('Lebih hemat Rp5.000 + 0.25 USDT.', (string) $idRequest['text']);
        $this->assertSame('🛒 Lihat Produk', $idRequest['reply_markup']['inline_keyboard'][0][0]['text'] ?? null);
        $this->assertSame('product:'.$product->id, $idRequest['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? null);

        $this->assertStringContainsString('🏷️ Price Drop', (string) $enRequest['text']);
        $this->assertStringContainsString('Rp35,000 → Rp30,000', (string) $enRequest['text']);
        $this->assertStringContainsString('You save Rp5,000 + 0.25 USDT.', (string) $enRequest['text']);
        $this->assertSame('🛒 View Product', $enRequest['reply_markup']['inline_keyboard'][0][0]['text'] ?? null);
        $this->assertStringContainsString('🏷️ Harga Turun', (string) $msRequest['text']);
        $this->assertStringContainsString('Berita baik untuk anda.', (string) $msRequest['text']);
        $this->assertStringContainsString('Rp35.000 → Rp30.000', (string) $msRequest['text']);
        $this->assertStringContainsString('2.5 USDT → 2.25 USDT', (string) $msRequest['text']);
        $this->assertStringContainsString('Anda jimat Rp5.000 + 0.25 USDT.', (string) $msRequest['text']);
        $this->assertStringContainsString('Harga baharu sedia digunakan untuk checkout seterusnya.', (string) $msRequest['text']);
        $this->assertStringContainsString('Perlukan bantuan? Balas /support.', (string) $msRequest['text']);
        $this->assertSame('🛒 Lihat Produk', $msRequest['reply_markup']['inline_keyboard'][0][0]['text'] ?? null);
        $this->assertStringNotContainsString('Price Drop', (string) $msRequest['text']);
        $this->assertStringNotContainsString('You save', (string) $msRequest['text']);
    }

    public function test_committed_decrease_also_publishes_the_english_price_drop_to_the_store_channel_once(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 123]])]);
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'channel_username' => '@lhie_store',
            'telegram_chat_id' => '-100123',
        ]);
        $product = Product::factory()->create(['name' => 'CHATGPT PLUS PRIVATE + CODEX']);
        $event = ProductPriceChangeEvent::create([
            'event_key' => (string) str()->uuid(),
            'product_id' => $product->id,
            'product_name' => $product->name,
            'old_price_amount' => 35000,
            'new_price_amount' => 30000,
            'old_usdt_price_amount' => 2_500_000,
            'new_usdt_price_amount' => 2_300_000,
        ]);

        app(ProductPriceChangeNotificationService::class)->processBatch($event->id);
        app(ProductPriceChangeNotificationService::class)->processBatch($event->id);

        $storeChannelMessages = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '-100123');

        $this->assertCount(1, $storeChannelMessages);
        $this->assertSame(
            "🏷️ Price Drop\n━━━━━━━━━━━━━━\nGood news for you.\n📦 CHATGPT PLUS PRIVATE + CODEX\n\n💵 QRIS\nRp35,000 → Rp30,000\n\n💎 Binance Pay\n2.5 USDT → 2.3 USDT\n\nYou save Rp5,000 + 0.2 USDT.\nThe new price is ready for your next checkout.\n\nNeed help? Reply with /support.",
            $storeChannelMessages->first()[0]['text'],
        );
        $this->assertSame(
            [
                'inline_keyboard' => [[[
                    'text' => '🛒 View Product',
                    'url' => 'https://t.me/lhiestore_bot?start=product_'.$product->id,
                ]]],
            ],
            $storeChannelMessages->first()[0]['reply_markup'],
        );
    }

    public function test_usdt_only_decrease_shows_the_usdt_savings_without_an_idr_savings_amount(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 123]])]);
        Customer::factory()->create(['telegram_id' => 7010, 'locale' => 'en']);
        $product = Product::factory()->create(['name' => 'USDT Discount Product']);
        $event = ProductPriceChangeEvent::create([
            'event_key' => (string) str()->uuid(),
            'product_id' => $product->id,
            'product_name' => $product->name,
            'old_price_amount' => 35000,
            'new_price_amount' => 35000,
            'old_usdt_price_amount' => 2_500_000,
            'new_usdt_price_amount' => 2_300_000,
        ]);

        app(ProductPriceChangeNotificationService::class)->processBatch($event->id);

        $request = $this->telegramSendFor(7010);
        $this->assertStringContainsString('🏷️ Price Drop', (string) $request['text']);
        $this->assertStringContainsString('You save 0.2 USDT.', (string) $request['text']);
        $this->assertStringNotContainsString('You save Rp', (string) $request['text']);
    }

    public function test_transient_store_channel_failure_is_not_retried_or_recovered(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        Queue::fake();
        $sendAttempts = 0;
        Http::fake([
            'https://api.telegram.org/*/sendMessage' => function () use (&$sendAttempts) {
                $sendAttempts++;

                throw new RuntimeException('temporary Telegram Store Channel failure');
            },
        ]);
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100123',
        ]);
        $product = Product::factory()->create(['name' => 'No Retry Channel Product']);
        $event = ProductPriceChangeEvent::create([
            'event_key' => (string) str()->uuid(),
            'product_id' => $product->id,
            'product_name' => $product->name,
            'old_price_amount' => 35000,
            'new_price_amount' => 30000,
            'old_usdt_price_amount' => 2_500_000,
            'new_usdt_price_amount' => 2_300_000,
        ]);

        $firstBatch = app(ProductPriceChangeNotificationService::class)->processBatch($event->id);
        $secondBatch = app(ProductPriceChangeNotificationService::class)->processBatch($event->id);

        $this->assertSame(0, $firstBatch->transientFailures);
        $this->assertSame(1, $firstBatch->terminalFailures);
        $this->assertFalse($firstBatch->hasMore);
        $this->assertFalse($secondBatch->hasMore);
        $this->assertSame(1, $sendAttempts);
        $this->assertNull($event->fresh()->store_channel_sent_at);
        $this->assertSame(1, $event->fresh()->store_channel_attempts);

        $this->artisan('telegram:recover-outbound')
            ->expectsOutput('telegram:recover-outbound completed cleanly: dispatched=0')
            ->assertSuccessful();
        Queue::assertNotPushed(SendProductPriceChangeNotifications::class);
    }

    public function test_recover_outbound_redispatches_an_unsent_configured_store_channel_announcement(): void
    {
        Queue::fake();
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100123',
        ]);
        $product = Product::factory()->create();
        $event = ProductPriceChangeEvent::create([
            'event_key' => (string) str()->uuid(),
            'product_id' => $product->id,
            'product_name' => $product->name,
            'old_price_amount' => 30000,
            'new_price_amount' => 25000,
            'old_usdt_price_amount' => null,
            'new_usdt_price_amount' => null,
            'recipients_materialized_at' => now(),
        ]);

        $this->artisan('telegram:recover-outbound')
            ->expectsOutput('telegram:recover-outbound completed cleanly: dispatched=1')
            ->assertSuccessful();

        Queue::assertPushedOn('telegram-broadcast', SendProductPriceChangeNotifications::class);
        Queue::assertPushed(SendProductPriceChangeNotifications::class, fn (SendProductPriceChangeNotifications $job): bool => $job->eventId === $event->id);
    }

    public function test_backlog_finalization_migration_prevents_historical_price_notifications_from_recovery(): void
    {
        Queue::fake();
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100123',
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 9011]);
        $product = Product::factory()->create();
        $event = ProductPriceChangeEvent::create([
            'event_key' => (string) str()->uuid(),
            'product_id' => $product->id,
            'product_name' => $product->name,
            'old_price_amount' => 30000,
            'new_price_amount' => 25000,
            'old_usdt_price_amount' => null,
            'new_usdt_price_amount' => null,
        ]);
        $recipient = ProductPriceChangeNotificationRecipient::create([
            'event_id' => $event->id,
            'customer_id' => $customer->id,
            'telegram_id' => (string) $customer->telegram_id,
            'locale' => 'id',
        ]);

        $migration = require database_path('migrations/2026_08_11_000005_finalize_existing_product_price_notifications.php');
        $migration->up();

        $this->assertNotNull($event->fresh()->recipients_materialized_at);
        $this->assertSame(ProductPriceChangeNotificationService::MAX_STORE_CHANNEL_ATTEMPTS, $event->fresh()->store_channel_attempts);
        $this->assertSame(ProductPriceChangeNotificationService::MAX_RECIPIENT_ATTEMPTS, $recipient->fresh()->attempts);
        $this->artisan('telegram:recover-outbound')
            ->expectsOutput('telegram:recover-outbound completed cleanly: dispatched=0')
            ->assertSuccessful();

        Queue::assertNotPushed(SendProductPriceChangeNotifications::class);
    }

    public function test_admin_price_update_persists_one_event_and_dispatches_after_commit_without_mutating_order_snapshots(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create([
            'name' => 'Claude Pro',
            'price_amount' => 50000,
            'usdt_price_amount' => 3_000_000,
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        $order = Order::factory()->create([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'total_amount' => 50000,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit_price' => 50000,
            'subtotal' => 50000,
        ]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'amount' => 3_000_000,
            'currency' => 'USDT',
            'status' => PaymentStatus::Pending,
        ]);

        $this->actingAs($admin)
            ->from('/admin?tab=produk')
            ->patch(route('admin.products.update', $product), [
                'name' => 'Claude Pro',
                'price_amount' => '55000',
                'usdt_price_amount' => '3.25',
                'description' => $product->description,
                'is_active' => '1',
            ])
            ->assertRedirect('/admin?tab=produk#product-'.$product->id);

        $event = ProductPriceChangeEvent::firstOrFail();
        $this->assertSame(50000, $event->old_price_amount);
        $this->assertSame(55000, $event->new_price_amount);
        $this->assertSame(3_000_000, $event->old_usdt_price_amount);
        $this->assertSame(3_250_000, $event->new_usdt_price_amount);

        Queue::assertPushedOn('telegram-broadcast', SendProductPriceChangeNotifications::class);
        $this->assertSame(50000, $order->fresh()->total_amount);
        $this->assertSame(50000, $order->orderItems()->firstOrFail()->unit_price);
        $this->assertSame(3_000_000, $order->payments()->firstOrFail()->amount);
    }

    public function test_increase_mixed_nullable_and_suppressed_updates_have_correct_delivery_semantics(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 123]])]);
        Customer::factory()->create(['telegram_id' => 8001, 'locale' => 'en']);
        Customer::factory()->create(['telegram_id' => 8002, 'locale' => 'ms']);

        $product = Product::factory()->create(['name' => 'Mixed Price', 'stock' => 1, 'reserved_stock' => 0]);
        $increase = ProductPriceChangeEvent::create([
            'event_key' => (string) str()->uuid(),
            'product_id' => $product->id,
            'product_name' => $product->name,
            'old_price_amount' => 30000,
            'new_price_amount' => 35000,
            'old_usdt_price_amount' => null,
            'new_usdt_price_amount' => 2_000_000,
        ]);

        app(ProductPriceChangeNotificationService::class)->processBatch($increase->id);
        app(ProductPriceChangeNotificationService::class)->processBatch($increase->id);

        $request = $this->telegramSendFor(8001);
        $msRequest = $this->telegramSendFor(8002);
        $this->assertStringContainsString('📢 Price Update', (string) $request['text']);
        $this->assertStringContainsString('Now available at 2 USDT', (string) $request['text']);
        $this->assertStringContainsString('Existing orders keep their previous price.', (string) $request['text']);
        $this->assertStringNotContainsString('You save', (string) $request['text']);
        $this->assertStringNotContainsString('null', strtolower((string) $request['text']));
        $this->assertStringContainsString('📢 Kemas Kini Harga', (string) $msRequest['text']);
        $this->assertStringContainsString('Kini tersedia pada 2 USDT', (string) $msRequest['text']);
        $this->assertStringContainsString('Harga baharu digunakan untuk checkout akan datang.', (string) $msRequest['text']);
        $this->assertStringContainsString('Pesanan sedia ada kekal dengan harga sebelumnya.', (string) $msRequest['text']);
        $this->assertStringNotContainsString('Price Update', (string) $msRequest['text']);
        $this->assertStringNotContainsString('null', strtolower((string) $msRequest['text']));
        $this->assertCount(2, Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')));
        $this->assertSame(2, ProductPriceChangeNotificationRecipient::where('event_id', $increase->id)->whereNotNull('sent_at')->count());
    }

    public function test_transient_failure_is_retried_without_resending_successful_recipients(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        $failEnglishRecipient = true;
        Http::fake([
            'https://api.telegram.org/*/sendMessage' => function ($request) use (&$failEnglishRecipient) {
                return $failEnglishRecipient && (string) $request['chat_id'] === '9002'
                    ? throw new RuntimeException('https://api.telegram.org/botbot-token/sendMessage failed')
                    : Http::response(['ok' => true, 'result' => ['message_id' => 123]]);
            },
        ]);
        Customer::factory()->create(['telegram_id' => 9001, 'locale' => 'id']);
        Customer::factory()->create(['telegram_id' => 9002, 'locale' => 'en']);
        $product = Product::factory()->create();
        $event = ProductPriceChangeEvent::create([
            'event_key' => (string) str()->uuid(),
            'product_id' => $product->id,
            'product_name' => $product->name,
            'old_price_amount' => 30000,
            'new_price_amount' => 25000,
            'old_usdt_price_amount' => null,
            'new_usdt_price_amount' => null,
        ]);

        $firstBatch = app(ProductPriceChangeNotificationService::class)->processBatch($event->id);
        $this->assertTrue($firstBatch->hasMore);
        $this->assertSame(1, $firstBatch->transientFailures);

        $this->assertSame(1, ProductPriceChangeNotificationRecipient::where('event_id', $event->id)->whereNotNull('sent_at')->count());
        $failedRecipient = ProductPriceChangeNotificationRecipient::where('event_id', $event->id)->whereNotNull('failed_at')->sole();
        $this->assertStringNotContainsString('bot-token', (string) $failedRecipient->last_error);
        $this->assertStringContainsString('[redacted-telegram-bot-api-url]', (string) $failedRecipient->last_error);

        $sendCountBeforeRetry = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage'))->count();
        $failEnglishRecipient = false;
        $failedRecipient->forceFill(['failed_at' => now()->subMinute()])->save();
        TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_PRICE_CHANGE_NOTIFICATION)
            ->where('source_id', (string) $failedRecipient->id)
            ->sole()
            ->forceFill(['next_attempt_at' => now()->subSecond()])
            ->save();
        $secondBatch = app(ProductPriceChangeNotificationService::class)->processBatch($event->id);

        $retrySends = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage'));
        $newRetrySends = $retrySends->slice($sendCountBeforeRetry)->values();
        $this->assertCount(1, $newRetrySends);
        $this->assertSame('9002', (string) $newRetrySends->first()[0]['chat_id']);
        $this->assertSame(2, ProductPriceChangeNotificationRecipient::where('event_id', $event->id)->whereNotNull('sent_at')->count());
        $this->assertFalse($secondBatch->hasMore);
    }

    public function test_forbidden_recipient_is_terminal_and_does_not_fail_or_retry_the_event(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        Http::fake([
            'https://api.telegram.org/*/sendMessage' => Http::response([
                'ok' => false,
                'error_code' => 403,
                'description' => 'Forbidden: bot was blocked by the user',
            ], 403),
        ]);
        Customer::factory()->create(['telegram_id' => 9020, 'locale' => 'id']);
        $product = Product::factory()->create();
        $event = ProductPriceChangeEvent::create([
            'event_key' => (string) str()->uuid(),
            'product_id' => $product->id,
            'product_name' => $product->name,
            'old_price_amount' => 30000,
            'new_price_amount' => 25000,
            'old_usdt_price_amount' => null,
            'new_usdt_price_amount' => null,
        ]);

        $result = app(ProductPriceChangeNotificationService::class)->processBatch($event->id);

        $recipient = ProductPriceChangeNotificationRecipient::where('event_id', $event->id)->sole();
        $this->assertSame(ProductPriceChangeNotificationService::MAX_RECIPIENT_ATTEMPTS, $recipient->attempts);
        $this->assertNotNull($recipient->failed_at);
        $this->assertStringContainsString('403', (string) $recipient->last_error);
        $this->assertFalse($result->hasMore);
        $this->assertSame(1, $result->terminalFailures);

        app(ProductPriceChangeNotificationService::class)->processBatch($event->id);
        $this->assertCount(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')));
    }

    public function test_job_processes_a_bounded_batch_and_dispatches_continuation_on_broadcast_queue(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 123]])]);
        Queue::fake();
        Customer::factory()->count(26)->sequence(fn ($sequence) => [
            'telegram_id' => 9100 + $sequence->index,
            'locale' => 'id',
        ])->create();
        $product = Product::factory()->create();
        $event = ProductPriceChangeEvent::create([
            'event_key' => (string) str()->uuid(),
            'product_id' => $product->id,
            'product_name' => $product->name,
            'old_price_amount' => 30000,
            'new_price_amount' => 25000,
            'old_usdt_price_amount' => null,
            'new_usdt_price_amount' => null,
        ]);

        (new SendProductPriceChangeNotifications($event->id, now()->toISOString()))
            ->handle(app(ProductPriceChangeNotificationService::class));

        $this->assertSame(25, ProductPriceChangeNotificationRecipient::where('event_id', $event->id)->whereNotNull('sent_at')->count());
        Queue::assertPushedOn('telegram-broadcast', SendProductPriceChangeNotifications::class);
    }

    public function test_recipient_at_attempt_cap_is_not_retried_or_redispatched_forever(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 123]])]);
        Queue::fake();
        $customer = Customer::factory()->create(['telegram_id' => 9010, 'locale' => 'en']);
        $product = Product::factory()->create();
        $event = ProductPriceChangeEvent::create([
            'event_key' => (string) str()->uuid(),
            'product_id' => $product->id,
            'product_name' => $product->name,
            'old_price_amount' => 30000,
            'new_price_amount' => 25000,
            'old_usdt_price_amount' => null,
            'new_usdt_price_amount' => null,
            'recipients_materialized_at' => now(),
        ]);
        ProductPriceChangeNotificationRecipient::create([
            'event_id' => $event->id,
            'customer_id' => $customer->id,
            'telegram_id' => (string) $customer->telegram_id,
            'locale' => 'en',
            'attempts' => 5,
            'failed_at' => now(),
            'last_error' => 'blocked',
        ]);

        app(ProductPriceChangeNotificationService::class)->processBatch($event->id);

        Http::assertNothingSent();
        $this->artisan('telegram:recover-outbound')
            ->expectsOutput('telegram:recover-outbound completed cleanly: dispatched=0')
            ->assertSuccessful();
        Queue::assertNotPushed(SendProductPriceChangeNotifications::class);
    }

    public function test_recover_outbound_redispatches_price_change_events_with_failed_or_missing_recipients(): void
    {
        Queue::fake();
        $product = Product::factory()->create();
        $event = ProductPriceChangeEvent::create([
            'event_key' => (string) str()->uuid(),
            'product_id' => $product->id,
            'product_name' => $product->name,
            'old_price_amount' => 30000,
            'new_price_amount' => 25000,
            'old_usdt_price_amount' => null,
            'new_usdt_price_amount' => null,
        ]);

        $this->artisan('telegram:recover-outbound')
            ->expectsOutput('telegram:recover-outbound completed cleanly: dispatched=1')
            ->assertSuccessful();

        Queue::assertPushedOn('telegram-broadcast', SendProductPriceChangeNotifications::class);
        Queue::assertPushed(SendProductPriceChangeNotifications::class, fn (SendProductPriceChangeNotifications $job): bool => $job->eventId === $event->id);
    }

    public function test_no_event_is_created_for_noop_inactive_or_unavailable_product_update(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        foreach ([
            Product::factory()->create(['price_amount' => 10000, 'stock' => 1, 'reserved_stock' => 0, 'is_active' => true]),
            Product::factory()->create(['price_amount' => 10000, 'stock' => 1, 'reserved_stock' => 0, 'is_active' => false]),
            Product::factory()->create(['price_amount' => 10000, 'stock' => 1, 'reserved_stock' => 1, 'is_active' => true]),
        ] as $index => $product) {
            $this->actingAs($admin)
                ->from('/admin?tab=produk')
                ->patch(route('admin.products.update', $product), [
                    'name' => $product->name.' updated',
                    'price_amount' => $index === 0 ? '10000' : '12000',
                    'description' => $product->description,
                    'is_active' => $product->is_active ? '1' : '0',
                ])
                ->assertRedirect('/admin?tab=produk#product-'.$product->id);
        }

        $this->assertSame(0, ProductPriceChangeEvent::count());
        Queue::assertNotPushed(SendProductPriceChangeNotifications::class);
    }

    public function test_rolled_back_price_update_creates_no_deliverable_event_or_job(): void
    {
        Queue::fake();
        $product = Product::factory()->create([
            'name' => 'Rollback Product',
            'price_amount' => 40000,
            'stock' => 1,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        $service = app(ProductPriceChangeNotificationService::class);

        try {
            DB::transaction(function () use ($product, $service): void {
                $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                $oldValues = $locked->only(['price_amount', 'usdt_price_amount']);
                $locked->update(['price_amount' => 35000]);
                $newValues = $locked->only(['price_amount', 'usdt_price_amount']);
                $service->createEventForCommittedUpdate($locked, $oldValues, $newValues);

                throw new Exception('force rollback');
            });
        } catch (Exception $exception) {
            $this->assertSame('force rollback', $exception->getMessage());
        }

        $this->assertSame(0, ProductPriceChangeEvent::count());
        Queue::assertNotPushed(SendProductPriceChangeNotifications::class);
    }

    public function test_uploaded_replacement_image_is_removed_when_product_update_transaction_rolls_back(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/old.webp', 'old');
        Queue::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create([
            'name' => 'Image Rollback Product',
            'image_path' => 'products/old.webp',
            'price_amount' => 40000,
            'stock' => 1,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        $this->app->instance(ProductPriceChangeNotificationService::class, new class(app(TelegramBotClient::class), app(TelegramLocale::class), app(StorefrontUrl::class)) extends ProductPriceChangeNotificationService
        {
            public function createEventForCommittedUpdate(Product $product, array $oldValues, array $newValues): ?ProductPriceChangeEvent
            {
                throw new RuntimeException('force rollback after image path update');
            }
        });

        $this->actingAs($admin)
            ->from('/admin?tab=produk')
            ->patch(route('admin.products.update', $product), [
                'name' => $product->name,
                'price_amount' => '35000',
                'description' => $product->description,
                'is_active' => '1',
                'image' => UploadedFile::fake()->image('replacement.png', 800, 800),
            ])
            ->assertServerError();

        $product->refresh();
        $this->assertSame('products/old.webp', $product->image_path);
        Storage::disk('public')->assertExists('products/old.webp');
        $this->assertCount(1, Storage::disk('public')->files('products'));
    }

    private function telegramSendFor(int $chatId)
    {
        $matches = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === (string) $chatId);

        $this->assertCount(1, $matches, "Expected one Telegram send for {$chatId}.");

        return $matches->first()[0];
    }
}
