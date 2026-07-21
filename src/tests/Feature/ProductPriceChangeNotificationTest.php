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
use App\Models\User;
use App\Services\Telegram\ProductPriceChangeNotificationService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

        app(ProductPriceChangeNotificationService::class)->processEvent($event->id);

        $idRequest = $this->telegramSendFor(7001);
        $enRequest = $this->telegramSendFor(7002);

        $this->assertStringContainsString('🎉 Harganya Turun!', (string) $idRequest['text']);
        $this->assertStringContainsString('Kabar bagus buat kamu', (string) $idRequest['text']);
        $this->assertStringContainsString('Rp35.000 → Rp30.000', (string) $idRequest['text']);
        $this->assertStringContainsString('2.5 USDT → 2.25 USDT', (string) $idRequest['text']);
        $this->assertStringContainsString('Lebih hemat Rp5.000', (string) $idRequest['text']);
        $this->assertSame('🛒 Lihat Produk', $idRequest['reply_markup']['inline_keyboard'][0][0]['text'] ?? null);
        $this->assertSame('product:'.$product->id, $idRequest['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? null);

        $this->assertStringContainsString('🎉 Price Drop!', (string) $enRequest['text']);
        $this->assertStringContainsString('Rp35,000 → Rp30,000', (string) $enRequest['text']);
        $this->assertStringContainsString('You save Rp5,000', (string) $enRequest['text']);
        $this->assertSame('🛒 View Product', $enRequest['reply_markup']['inline_keyboard'][0][0]['text'] ?? null);
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
            ->assertRedirect(route('admin.dashboard', ['tab' => 'produk']));

        $event = ProductPriceChangeEvent::firstOrFail();
        $this->assertSame(50000, $event->old_price_amount);
        $this->assertSame(55000, $event->new_price_amount);
        $this->assertSame(3_000_000, $event->old_usdt_price_amount);
        $this->assertSame(3_250_000, $event->new_usdt_price_amount);

        Queue::assertPushedOn('notifications', SendProductPriceChangeNotifications::class);
        $this->assertSame(50000, $order->fresh()->total_amount);
        $this->assertSame(50000, $order->orderItems()->firstOrFail()->unit_price);
        $this->assertSame(3_000_000, $order->payments()->firstOrFail()->amount);
    }

    public function test_increase_mixed_nullable_and_suppressed_updates_have_correct_delivery_semantics(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 123]])]);
        Customer::factory()->create(['telegram_id' => 8001, 'locale' => 'en']);

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

        app(ProductPriceChangeNotificationService::class)->processEvent($increase->id);
        app(ProductPriceChangeNotificationService::class)->processEvent($increase->id);

        $request = $this->telegramSendFor(8001);
        $this->assertStringContainsString('📢 Price Update', (string) $request['text']);
        $this->assertStringContainsString('Now available at 2 USDT', (string) $request['text']);
        $this->assertStringContainsString('Existing orders keep their previous price.', (string) $request['text']);
        $this->assertStringNotContainsString('You save', (string) $request['text']);
        $this->assertStringNotContainsString('null', strtolower((string) $request['text']));
        $this->assertCount(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')));
        $this->assertSame(1, ProductPriceChangeNotificationRecipient::where('event_id', $increase->id)->whereNotNull('sent_at')->count());
    }

    public function test_failed_recipient_marks_failure_and_normal_retry_skips_already_sent_recipients(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        $failEnglishRecipient = true;
        Http::fake([
            'https://api.telegram.org/*/sendMessage' => function ($request) use (&$failEnglishRecipient) {
                return $failEnglishRecipient && (string) $request['chat_id'] === '9002'
                    ? Http::response(['ok' => false, 'description' => 'Bad Gateway'], 502)
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

        try {
            app(ProductPriceChangeNotificationService::class)->processEvent($event->id);
            $this->fail('Expected processEvent to fail after recording failed recipient state.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('failed for 1 recipient', $exception->getMessage());
        }

        $this->assertSame(1, ProductPriceChangeNotificationRecipient::where('event_id', $event->id)->whereNotNull('sent_at')->count());
        $this->assertSame(1, ProductPriceChangeNotificationRecipient::where('event_id', $event->id)->whereNotNull('failed_at')->count());

        $sendCountBeforeRetry = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage'))->count();
        $failEnglishRecipient = false;
        app(ProductPriceChangeNotificationService::class)->processEvent($event->id);

        $retrySends = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage'));
        $newRetrySends = $retrySends->slice($sendCountBeforeRetry)->values();
        $this->assertCount(1, $newRetrySends);
        $this->assertSame('9002', (string) $newRetrySends->first()[0]['chat_id']);
        $this->assertSame(2, ProductPriceChangeNotificationRecipient::where('event_id', $event->id)->whereNotNull('sent_at')->count());
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
                ->assertRedirect(route('admin.dashboard', ['tab' => 'produk']));
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

    private function telegramSendFor(int $chatId)
    {
        $matches = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === (string) $chatId);

        $this->assertCount(1, $matches, "Expected one Telegram send for {$chatId}.");

        return $matches->first()[0];
    }
}
