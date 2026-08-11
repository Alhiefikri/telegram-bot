<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\ProcessProviderCancellationAttempt;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\ManualFulfillmentReview;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProviderCancellationAttempt;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\OrderStateMachine;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Services\Pakasir\PakasirQrisTransaction;
use App\Services\ProviderCancellationAttempts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProviderCancellationAttemptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.pakasir', [
            'slug' => 'warung-ai',
            'api_key' => 'pakasir-secret',
            'base_url' => 'https://app.pakasir.com',
            'redirect_url' => 'https://example.test/payment/return',
            'qris_only' => true,
        ]);
    }

    public function test_released_pakasir_payment_creates_a_durable_attempt_and_dispatches_only_after_commit(): void
    {
        Queue::fake();
        $payment = $this->pakasirPayment();

        app(OrderStateMachine::class)->applyPendingCancellation($payment->order);

        $attempt = ProviderCancellationAttempt::query()->sole();
        $this->assertSame(ProviderCancellationAttempt::STATE_PENDING, $attempt->state);
        $this->assertSame(0, $attempt->attempt_count);
        Queue::assertPushed(ProcessProviderCancellationAttempt::class, fn (ProcessProviderCancellationAttempt $job): bool => $job->attemptId === $attempt->id);
    }

    public function test_expired_pakasir_payment_does_not_create_cancellation_attempt(): void
    {
        Queue::fake();
        $payment = $this->pakasirPayment();
        $payment->update(['provider_expires_at' => now()->subMinute()]);

        app(OrderStateMachine::class)->applyPendingCancellation($payment->order);

        $this->assertDatabaseCount('provider_cancellation_attempts', 0);
        Queue::assertNotPushed(ProcessProviderCancellationAttempt::class);
    }

    public function test_unsupported_klikqris_cancellation_is_observable_without_a_provider_job(): void
    {
        Queue::fake();
        $payment = $this->payment('klikqris');

        app(OrderStateMachine::class)->applyPendingCancellation($payment->order);

        $this->assertDatabaseHas('provider_cancellation_attempts', [
            'payment_id' => $payment->id,
            'state' => ProviderCancellationAttempt::STATE_UNSUPPORTED,
        ]);
        Queue::assertNotPushed(ProcessProviderCancellationAttempt::class);
    }

    public function test_provider_reported_payment_becomes_late_payment_review_without_releasing_again(): void
    {
        Queue::fake();
        $payment = $this->pakasirPayment();
        app(OrderStateMachine::class)->applyPendingCancellation($payment->order);
        $attempt = ProviderCancellationAttempt::query()->sole();

        $this->app->bind(PakasirProviderAdapter::class, fn () => new class implements PakasirProviderAdapter
        {
            public function hostedPaymentUrl(Payment $payment): string
            {
                return '';
            }

            public function createQrisTransaction(Payment $payment): PakasirQrisTransaction
            {
                throw new \BadMethodCallException;
            }

            public function transactionDetail(Payment $payment): array
            {
                return [];
            }

            public function cancelTransaction(Payment $payment): array
            {
                return ['transaction' => ['status' => 'completed']];
            }
        });

        app(ProviderCancellationAttempts::class)->process($attempt->id);

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $payment->order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $payment->order->fresh()->payment_status);
        $this->assertDatabaseHas('provider_cancellation_attempts', ['id' => $attempt->id, 'state' => ProviderCancellationAttempt::STATE_ALREADY_PAID]);
        $this->assertDatabaseHas('manual_fulfillment_reviews', ['payment_id' => $payment->id, 'reason' => ManualFulfillmentReview::REASON_LATE_PAYMENT]);
    }

    private function pakasirPayment(): Payment
    {
        return $this->payment('pakasir');
    }

    private function payment(string $provider): Payment
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0, 'price_amount' => 10_000]);
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'status' => CartStatus::Active, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1]);

        $order = app(OrderCheckoutService::class)->checkout($cart, $provider === 'pakasir'
            ? CheckoutPaymentIntents::pakasir()
            : CheckoutPaymentIntents::manualQris());
        $payment = $order->payments()->sole();

        if ($provider === 'klikqris') {
            $payment->update(['provider' => 'klikqris']);
        }

        return $payment->fresh();
    }
}
