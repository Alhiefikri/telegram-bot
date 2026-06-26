<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Jobs\SendTelegramReceipt;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Services\OrderCheckoutService;
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
        $this->fakeDetail($payment->merchant_ref, $payment->amount);

        $this->postJson('/api/webhook/pakasir', $this->payload($payment->merchant_ref, $payment->amount))->assertOk();

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
        $event = PaymentEvent::firstOrFail();
        $this->assertSame('pakasir', $event->provider);
        $this->assertSame(PaymentEventType::Callback, $event->event_type);
        $this->assertSame($payment->merchant_ref, $event->merchant_ref);
        $this->assertSame('completed', $event->provider_status);
        $this->assertNotNull($event->processed_at);
        Queue::assertPushed(SendTelegramReceipt::class, 1);

        $this->postJson('/api/webhook/pakasir', $this->payload($payment->merchant_ref, $payment->amount))->assertUnprocessable();

        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
        $this->assertSame(2, PaymentEvent::count());
        $this->assertSame(1, PaymentEvent::whereNotNull('processed_at')->count());
        Queue::assertPushed(SendTelegramReceipt::class, 1);
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

    private function cartWithProduct(int $stock = 10, int $quantity = 1): Cart
    {
        $customer = Customer::factory()->create(['first_name' => 'Buyer', 'phone_number' => '08123456789']);
        $product = Product::factory()->create(['stock' => $stock, 'reserved_stock' => 0, 'price_amount' => 10000]);
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'status' => CartStatus::Active, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $quantity]);

        return $cart;
    }
}
