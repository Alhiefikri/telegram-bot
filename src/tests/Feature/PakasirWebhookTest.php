<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Jobs\PublishSalesProofPost;
use App\Jobs\SendTelegramReceipt;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\SalesProofPost;
use App\Models\StoreChannelSetting;
use App\Services\OrderCheckoutService;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Services\Telegram\SalesProofPostFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PakasirWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.pakasir', [
            'slug' => 'warung-ai',
            'api_key' => 'pakasir-secret',
            'base_url' => 'https://app.pakasir.com',
            'redirect_url' => null,
            'qris_only' => true,
        ]);
    }

    public function test_verified_completed_callback_marks_paid_logs_event_and_dispatches_receipt_once(): void
    {
        Queue::fake();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();
        $this->fakeProviderDetail($payment->merchant_ref, $payment->amount);
        $payload = $this->payload($payment->merchant_ref, $payment->amount);

        $this->postJson('/api/webhook/pakasir', $payload)->assertOk();

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
        $event = PaymentEvent::firstOrFail();
        $this->assertSame('pakasir', $event->provider);
        $this->assertSame(PaymentEventType::Callback, $event->event_type);
        $this->assertSame($payment->merchant_ref, $event->merchant_ref);
        $this->assertSame('completed', $event->provider_status);
        $this->assertSame($payload, $event->raw_payload);
        $this->assertNotNull($event->processed_at);
        Queue::assertPushed(SendTelegramReceipt::class, 1);
        Queue::assertPushed(PublishSalesProofPost::class, 1);

        $this->postJson('/api/webhook/pakasir', $this->payload($payment->merchant_ref, $payment->amount))->assertUnprocessable();

        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
        $this->assertSame(2, PaymentEvent::count());
        $this->assertSame(1, PaymentEvent::whereNotNull('processed_at')->count());
        Queue::assertPushed(SendTelegramReceipt::class, 1);
        Queue::assertPushed(PublishSalesProofPost::class, 1);
    }

    public function test_sales_proof_post_is_sent_once_and_privacy_safe_for_pakasir_paid_order(): void
    {
        config()->set('queue.default', 'sync');
        StoreChannelSetting::create(['singleton_key' => StoreChannelSetting::SINGLETON_KEY, 'sales_proof_enabled' => true, 'telegram_chat_id' => '-100123']);
        Http::fake([
            'https://app.pakasir.com/api/transactiondetail*' => Http::response(['transaction' => ['status' => 'completed', 'amount' => 50000, 'order_id' => '*']]),
            'https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 77]]),
        ]);
        config()->set('services.telegram.token', 'bot-token');
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'qris', 'pakasir');
        $order->customer()->update(['username' => 'AdamBuyer']);
        $payment = $order->payments()->firstOrFail();
        $this->fakeProviderDetail($payment->merchant_ref, $payment->amount);

        $this->postJson('/api/webhook/pakasir', $this->payload($payment->merchant_ref, $payment->amount))->assertOk();
        app()->call([new PublishSalesProofPost($order->id), 'handle']);
        $this->postJson('/api/webhook/pakasir', $this->payload($payment->merchant_ref, $payment->amount))->assertUnprocessable();

        $this->assertSame(1, SalesProofPost::where('status', SalesProofPost::STATUS_SENT)->count());
        $this->assertSame('77', SalesProofPost::firstOrFail()->telegram_message_id);
        $copy = app(SalesProofPostFormatter::class)->format($order->fresh(['customer', 'orderItems']));
        $this->assertStringContainsString('Ad***', $copy);
        $this->assertStringNotContainsString($order->invoice_number, $copy);
        $this->assertStringNotContainsString((string) $order->total_amount, $copy);
    }

    public function test_wrong_amount_wrong_project_unknown_order_api_mismatch_and_api_failure_fail_closed(): void
    {
        Queue::fake();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 6), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();

        $this->postJson('/api/webhook/pakasir', $this->payload($payment->merchant_ref, $payment->amount + 1))->assertUnprocessable();
        $this->postJson('/api/webhook/pakasir', $this->payload($payment->merchant_ref, $payment->amount, ['project' => 'wrong']))->assertUnprocessable();
        $this->postJson('/api/webhook/pakasir', $this->payload('UNKNOWN', $payment->amount))->assertUnprocessable();

        $this->fakeDetail($payment->merchant_ref, $payment->amount, ['status' => 'pending']);
        $this->postJson('/api/webhook/pakasir', $this->payload($payment->merchant_ref, $payment->amount))->assertUnprocessable();

        Http::fake(['https://app.pakasir.com/api/transactiondetail*' => Http::response(['message' => 'down'], 500)]);
        $this->postJson('/api/webhook/pakasir', $this->payload($payment->merchant_ref, $payment->amount))->assertUnprocessable();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
        $this->assertSame(5, PaymentEvent::count());
        $this->assertSame(0, PaymentEvent::whereNotNull('processed_at')->count());
        $this->assertNull(PaymentEvent::where('merchant_ref', 'UNKNOWN')->firstOrFail()->payment_id);
        Queue::assertNothingPushed();
    }

    public function test_callback_rejects_nested_transaction_when_top_level_envelope_is_mismatched(): void
    {
        Queue::fake();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();
        $this->fakeProviderDetail($payment->merchant_ref, $payment->amount);

        $this->postJson('/api/webhook/pakasir', [
            'amount' => $payment->amount,
            'order_id' => 'UNKNOWN',
            'project' => 'wrong-project',
            'status' => 'pending',
            'transaction' => [
                'amount' => $payment->amount,
                'order_id' => $payment->merchant_ref,
                'project' => 'warung-ai',
                'status' => 'completed',
            ],
        ])->assertUnprocessable();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $event = PaymentEvent::firstOrFail();
        $this->assertNull($event->payment_id);
        $this->assertSame('UNKNOWN', $event->merchant_ref);
        $this->assertSame('pending', $event->provider_status);
        $this->assertNull($event->processed_at);
        Queue::assertNothingPushed();
    }

    public function test_completed_callback_with_missing_api_key_fails_closed_after_logging(): void
    {
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();
        config()->set('services.pakasir.api_key', '');

        $this->postJson('/api/webhook/pakasir', $this->payload($payment->merchant_ref, $payment->amount))->assertUnprocessable();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertNull(PaymentEvent::firstOrFail()->processed_at);
    }

    public function test_completed_callback_with_missing_project_slug_fails_closed_after_logging(): void
    {
        Http::fake();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();
        config()->set('services.pakasir.slug', '');

        $this->postJson('/api/webhook/pakasir', $this->payload($payment->merchant_ref, $payment->amount, ['project' => null]))->assertUnprocessable();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertNull(PaymentEvent::firstOrFail()->processed_at);
        Http::assertNothingSent();
    }

    public function test_reconcile_command_processes_completed_pakasir_payment_and_logs_reconciliation(): void
    {
        Queue::fake();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();
        $this->fakeDetail($payment->merchant_ref, $payment->amount);

        $this->artisan('pakasir:reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $event = PaymentEvent::firstOrFail();
        $this->assertSame(PaymentEventType::Reconciliation, $event->event_type);
        $this->assertSame($payment->merchant_ref, $event->merchant_ref);
        $this->assertSame('completed', $event->provider_status);
        $this->assertSame('completed', $event->raw_payload['transaction']['status']);
        $this->assertNotNull($event->processed_at);
    }

    public function test_pakasir_reconciliation_is_scheduled_when_configured(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('pakasir:reconcile --chunk=100')
            ->assertSuccessful();
    }

    public function test_reconcile_command_skips_mismatches_without_mutating_order(): void
    {
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();
        $this->fakeDetail($payment->merchant_ref, $payment->amount, ['amount' => $payment->amount + 1]);

        $this->artisan('pakasir:reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertNull(PaymentEvent::firstOrFail()->processed_at);
    }

    public function test_reconcile_command_reports_api_failures_without_mutating_order(): void
    {
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();
        Http::fake(['https://app.pakasir.com/api/transactiondetail*' => Http::response(['message' => 'down'], 500)]);

        $this->artisan('pakasir:reconcile')
            ->expectsOutputToContain('pakasir:reconcile completed with partial failures: processed=0 skipped=0 failed=1')
            ->assertFailed();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertSame(0, PaymentEvent::count());
    }

    public function test_reconcile_command_fails_safely_when_api_key_is_missing(): void
    {
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();
        config()->set('services.pakasir.api_key', '');
        Http::fake();

        $this->artisan('pakasir:reconcile')->assertFailed();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, PaymentEvent::count());
        Http::assertNothingSent();
    }

    public function test_expire_pending_orders_sends_telegram_expired_notice(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'first_name' => 'Buyer']);
        $product = Product::factory()->create(['name' => 'Ready API KEY', 'stock' => 2, 'reserved_stock' => 0, 'price_amount' => 7000]);
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'status' => CartStatus::Active, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1]);
        $order = app(OrderCheckoutService::class)->checkout($cart, 'hosted', 'pakasir', now()->subMinute());

        $this->artisan('orders:expire-pending')->assertSuccessful();

        $this->assertSame(OrderStatus::Expired, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Expired, $order->payments()->firstOrFail()->fresh()->status);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '12345'
            && str_contains($request['text'], '⏳ Order kedaluwarsa')
            && str_contains($request['text'], $order->invoice_number)
            && str_contains($request['text'], 'Ready API KEY')
            && str_contains($request['text'], 'Rp7.000'));
    }

    private function payload(string $orderId, int $amount, array $overrides = []): array
    {
        return array_merge([
            'amount' => $amount,
            'order_id' => $orderId,
            'project' => 'warung-ai',
            'status' => 'completed',
            'payment_method' => 'qris',
            'completed_at' => now()->toISOString(),
        ], $overrides);
    }

    private function fakeDetail(string $orderId, int $amount, array $overrides = []): void
    {
        Http::fake([
            'https://app.pakasir.com/api/transactiondetail*' => Http::response([
                'transaction' => array_merge([
                    'project' => 'warung-ai',
                    'amount' => $amount,
                    'order_id' => $orderId,
                    'status' => 'completed',
                ], $overrides),
            ], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);
    }

    private function fakeProviderDetail(string $orderId, int $amount, array $overrides = []): void
    {
        $this->app->bind(PakasirProviderAdapter::class, fn () => new class($orderId, $amount, $overrides) implements PakasirProviderAdapter
        {
            public function __construct(
                private readonly string $orderId,
                private readonly int $amount,
                private readonly array $overrides,
            ) {}

            public function hostedPaymentUrl(Payment $payment): string
            {
                return 'https://fake-pakasir.test/pay/'.$payment->merchant_ref;
            }

            public function transactionDetail(Payment $payment): array
            {
                return [
                    'transaction' => array_merge([
                        'project' => 'warung-ai',
                        'amount' => $this->amount,
                        'order_id' => $this->orderId,
                        'status' => 'completed',
                    ], $this->overrides),
                ];
            }
        });
    }

    private function cartWithProduct(int $stock = 10, int $quantity = 1): Cart
    {
        $customer = Customer::factory()->create(['first_name' => 'Buyer', 'phone_number' => '08123456789']);
        $product = Product::factory()->create(['stock' => $stock, 'reserved_stock' => 0, 'price_amount' => 10000]);
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'status' => CartStatus::Active, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $quantity]);

        return $cart;
    }
}
