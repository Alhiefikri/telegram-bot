<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Services\OrderCheckoutService;
use App\Services\OrderStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Phase5TripaySandboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.tripay', [
            'api_key' => 'sandbox-key',
            'private_key' => 'sandbox-private',
            'merchant_code' => 'T0001',
            'base_url' => 'https://tripay.co.id/api-sandbox',
            'callback_url' => 'https://example.test/api/webhook/tripay',
            'return_url' => 'https://example.test/thanks',
        ]);
    }

    public function test_create_transaction_sends_signed_tripay_request_and_persists_reference(): void
    {
        $this->fakeTripayCreate('TREF-123');

        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 3, quantity: 2), 'BRIVA', 'tripay');
        $payment = $order->payments()->first();

        Http::assertSent(function ($request) use ($payment) {
            $data = $request->data();

            return $request->url() === 'https://tripay.co.id/api-sandbox/transaction/create'
                && $request->hasHeader('Authorization', 'Bearer sandbox-key')
                && $data['method'] === 'BRIVA'
                && $data['merchant_ref'] === $payment->merchant_ref
                && $data['amount'] === 20000
                && $data['signature'] === hash_hmac('sha256', 'T0001'.$payment->merchant_ref.'20000', 'sandbox-private')
                && $data['order_items'][0]['quantity'] === 2;
        });
        $this->assertSame('TREF-123', $payment->fresh()->provider_reference);
    }

    public function test_valid_callback_logs_event_and_marks_payment_paid_once(): void
    {
        $this->fakeTripayCreate();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'BRIVA', 'tripay');
        $payment = $order->payments()->first();
        $payload = $this->tripayPayload($payment->merchant_ref, $payment->amount);

        $this->postTripayCallback($payload)->assertOk()->assertJson(['success' => true]);

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $event = PaymentEvent::first();
        $this->assertSame(PaymentEventType::Callback, $event->event_type);
        $this->assertSame('TREF-PAID', $event->provider_reference);
        $this->assertNotNull($event->processed_at);
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Sale)->count());

        $this->postTripayCallback($payload)->assertOk();

        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
        $this->assertSame(2, PaymentEvent::where('event_type', PaymentEventType::Callback)->count());
    }

    public function test_invalid_callback_signature_logs_raw_event_and_is_forbidden(): void
    {
        $this->fakeTripayCreate();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'BRIVA', 'tripay');
        $payment = $order->payments()->first();

        $this->postTripayCallback($this->tripayPayload($payment->merchant_ref, $payment->amount), 'bad-signature')->assertForbidden();

        $event = PaymentEvent::first();
        $this->assertSame('tripay', $event->provider);
        $this->assertSame(PaymentEventType::Callback, $event->event_type);
        $this->assertNull($event->processed_at);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_callback_rejects_mismatched_amount_without_processing_event(): void
    {
        $this->fakeTripayCreate();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'BRIVA', 'tripay');
        $payment = $order->payments()->first();

        $this->postTripayCallback($this->tripayPayload($payment->merchant_ref, $payment->amount + 1))->assertUnprocessable();

        $this->assertNull(PaymentEvent::first()->processed_at);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_reconciliation_command_fetches_detail_logs_event_and_applies_paid_status(): void
    {
        $this->fakeTripayCreate();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'BRIVA', 'tripay');
        $payment = $order->payments()->first();
        $payment->update(['provider_reference' => 'TREF-DETAIL']);
        Http::fake([
            'https://tripay.co.id/api-sandbox/transaction/detail*' => Http::response(['success' => true, 'data' => $this->tripayPayload($payment->merchant_ref, $payment->amount, 'TREF-DETAIL')], 200),
        ]);

        $this->artisan('tripay:reconcile', ['--chunk' => 1])->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url() === 'https://tripay.co.id/api-sandbox/transaction/detail?reference=TREF-DETAIL');
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(1, PaymentEvent::where('event_type', PaymentEventType::Reconciliation)->count());
    }

    public function test_reconciliation_resolves_late_paid_expired_payment_without_sale_movement(): void
    {
        $this->fakeTripayCreate();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 1), 'BRIVA', 'tripay', now()->subMinute());
        $payment = $order->payments()->first();
        $payment->update(['provider_reference' => 'TREF-LATE']);
        app(OrderStateMachine::class)->expirePendingOrder($order);
        Http::fake([
            'https://tripay.co.id/api-sandbox/transaction/detail*' => Http::response(['success' => true, 'data' => $this->tripayPayload($payment->merchant_ref, $payment->amount, 'TREF-LATE')], 200),
        ]);

        $this->artisan('tripay:reconcile', ['--chunk' => 1])->assertSuccessful();

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(OrderStatus::Expired, $order->fresh()->order_status);
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Release)->count());
        $this->assertSame(1, Product::first()->stock);
        $this->assertSame(0, Product::first()->reserved_stock);
    }

    public function test_refund_callback_updates_parent_order_payment_status(): void
    {
        $this->fakeTripayCreate();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 1), 'BRIVA', 'tripay');
        $payment = $order->payments()->first();
        $this->postTripayCallback($this->tripayPayload($payment->merchant_ref, $payment->amount))->assertOk();
        $payload = $this->tripayPayload($payment->merchant_ref, $payment->amount, 'TREF-REFUND');
        $payload['status'] = 'REFUND';

        $this->postTripayCallback($payload)->assertOk();

        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Refunded, $order->fresh()->payment_status);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->order_status);
    }

    public function test_replayed_paid_callback_does_not_reverse_refunded_payment(): void
    {
        $this->fakeTripayCreate();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 1), 'BRIVA', 'tripay');
        $payment = $order->payments()->first();
        $paidPayload = $this->tripayPayload($payment->merchant_ref, $payment->amount);
        $refundPayload = $paidPayload;
        $refundPayload['status'] = 'REFUND';

        $this->postTripayCallback($paidPayload)->assertOk();
        $this->postTripayCallback($refundPayload)->assertOk();
        $this->postTripayCallback($paidPayload)->assertOk();

        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Refunded, $order->fresh()->payment_status);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->order_status);
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
    }

    public function test_oversized_tripay_callback_is_rejected_before_logging(): void
    {
        config()->set('services.tripay.webhook_max_body_bytes', 8);

        $this->postTripayCallback(['merchant_ref' => 'PAY-LARGE', 'reference' => 'TREF-LARGE', 'status' => 'PAID', 'total_amount' => 10000])->assertStatus(413);

        $this->assertSame(0, PaymentEvent::count());
    }

    public function test_missing_identifiers_signed_callback_logs_null_payment_and_does_not_mutate(): void
    {
        $this->fakeTripayCreate();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'BRIVA', 'tripay');
        $payment = $order->payments()->first();
        $payload = $this->tripayPayload($payment->merchant_ref, $payment->amount);
        unset($payload['merchant_ref'], $payload['reference']);

        $this->postTripayCallback($payload)->assertNotFound();

        $this->assertNull(PaymentEvent::first()->payment_id);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
    }

    public function test_unknown_merchant_ref_signed_callback_logs_null_payment_without_mutation(): void
    {
        $this->fakeTripayCreate();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'BRIVA', 'tripay');
        $payment = $order->payments()->first();

        $this->postTripayCallback($this->tripayPayload('UNKNOWN', $payment->amount))->assertNotFound();

        $this->assertNull(PaymentEvent::first()->payment_id);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_callback_with_conflicting_known_identifiers_is_rejected_without_processing(): void
    {
        $first = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'BRIVA', 'manual')->payments()->first();
        $first->update(['provider' => 'tripay', 'provider_reference' => 'TREF-FIRST-CREATE']);
        $second = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'BRIVA', 'manual')->payments()->first();
        $second->update(['provider' => 'tripay', 'provider_reference' => 'TREF-CONFLICT']);

        $this->postTripayCallback($this->tripayPayload($first->merchant_ref, $first->amount, 'TREF-CONFLICT'))->assertUnprocessable();

        $event = PaymentEvent::first();
        $this->assertNull($event->processed_at);
        $this->assertNull($event->payment_id);
        $this->assertSame(PaymentStatus::Pending, $first->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $second->fresh()->status);
    }

    public function test_invalid_callback_event_header_is_rejected_and_logged(): void
    {
        $this->fakeTripayCreate();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'BRIVA', 'tripay');
        $payment = $order->payments()->first();

        $this->postTripayCallback($this->tripayPayload($payment->merchant_ref, $payment->amount), event: 'wrong_event')->assertForbidden();

        $this->assertNull(PaymentEvent::first()->processed_at);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_missing_private_key_fails_closed_for_create_and_callback(): void
    {
        config()->set('services.tripay.private_key', '');
        Http::fake();
        $this->expectException(\RuntimeException::class);
        app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'BRIVA', 'tripay');
    }

    public function test_missing_private_key_rejects_callback_after_logging(): void
    {
        $this->fakeTripayCreate();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'BRIVA', 'tripay');
        $payment = $order->payments()->first();
        config()->set('services.tripay.private_key', '');

        $this->postTripayCallback($this->tripayPayload($payment->merchant_ref, $payment->amount), 'anything')->assertForbidden();

        $this->assertNull(PaymentEvent::first()->processed_at);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_method_mismatch_and_unknown_status_reject_without_processing(): void
    {
        $this->fakeTripayCreate();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'BRIVA', 'tripay');
        $payment = $order->payments()->first();
        $payload = $this->tripayPayload($payment->merchant_ref, $payment->amount);
        $payload['payment_method_code'] = 'QRIS';

        $this->postTripayCallback($payload)->assertUnprocessable();

        $payload = $this->tripayPayload($payment->merchant_ref, $payment->amount, 'TREF-UNKNOWN');
        $payload['status'] = 'PENDING';
        $this->postTripayCallback($payload)->assertUnprocessable();

        $this->assertSame(0, PaymentEvent::whereNotNull('processed_at')->count());
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_bad_reconciliation_responses_do_not_process_and_command_continues(): void
    {
        $this->fakeTripayCreate();
        $first = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'BRIVA', 'tripay')->payments()->first();
        $first->update(['provider_reference' => 'BAD-DETAIL']);
        $second = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'BRIVA', 'tripay')->payments()->first();
        $second->update(['provider_reference' => 'GOOD-DETAIL']);
        Http::fake(function ($request) use ($second) {
            return str_contains($request->url(), 'BAD-DETAIL')
                ? Http::response(['success' => false], 200)
                : Http::response(['success' => true, 'data' => $this->tripayPayload($second->merchant_ref, $second->amount, 'GOOD-DETAIL')], 200);
        });

        $this->artisan('tripay:reconcile', ['--chunk' => 1])->assertSuccessful();

        $this->assertSame(PaymentStatus::Pending, $first->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $second->fresh()->status);
        $this->assertSame(2, PaymentEvent::where('event_type', PaymentEventType::Reconciliation)->count());
        $this->assertSame(1, PaymentEvent::where('event_type', PaymentEventType::Reconciliation)->whereNotNull('processed_at')->count());
    }

    public function test_manual_checkout_does_not_call_tripay_and_reconciliation_is_scheduled(): void
    {
        Http::fake();

        app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'manual', 'manual');

        Http::assertNothingSent();
        $this->artisan('schedule:list')->expectsOutputToContain('tripay:reconcile --chunk=100')->assertSuccessful();
    }

    public function test_tripay_checkout_fails_closed_when_create_response_is_unsuccessful_or_missing_reference(): void
    {
        foreach ([
            ['success' => false, 'data' => ['reference' => 'NOPE']],
            ['success' => true],
            ['success' => true, 'data' => []],
            ['success' => true, 'data' => ['reference' => '']],
        ] as $response) {
            Http::fake([
                'https://tripay.co.id/api-sandbox/transaction/create' => Http::response($response, 200),
            ]);

            try {
                app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'BRIVA', 'tripay');
                $this->fail('Expected invalid Tripay create response to fail closed.');
            } catch (\RuntimeException) {
                $this->assertSame(0, Payment::whereNotNull('provider_reference')->count());
            }
        }
    }

    public function test_tripay_checkout_fails_closed_on_non_successful_http_response(): void
    {
        Http::fake([
            'https://tripay.co.id/api-sandbox/transaction/create' => Http::response(['success' => false], 500),
        ]);

        try {
            app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'BRIVA', 'tripay');
            $this->fail('Expected non-2xx Tripay create response to fail closed.');
        } catch (RequestException) {
            $this->assertSame(0, Payment::whereNotNull('provider_reference')->count());
        }
    }

    public function test_tripay_create_exception_releases_or_marks_retryable_without_stranding_reserved_stock(): void
    {
        Http::fake([
            'https://tripay.co.id/api-sandbox/transaction/create' => Http::response(['success' => false], 500),
        ]);

        try {
            app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 3), 'BRIVA', 'tripay');
            $this->fail('Expected Tripay create failure to throw and clean up reserved stock.');
        } catch (RequestException) {
            $order = Order::firstOrFail();
            $payment = Payment::firstOrFail();
            $product = Product::firstOrFail();

            $this->assertSame(0, $product->fresh()->reserved_stock);
            $this->assertSame(OrderStatus::Cancelled, $order->fresh()->order_status);
            $this->assertSame(PaymentStatus::Failed, $order->fresh()->payment_status);
            $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
            $this->assertNull($payment->fresh()->provider_reference);
            $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Release)->count());
        }
    }

    private function postTripayCallback(array $payload, ?string $signature = null, string $event = 'payment_status')
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature ??= hash_hmac('sha256', $raw, 'sandbox-private');

        return $this->call('POST', '/api/webhook/tripay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CALLBACK_SIGNATURE' => $signature,
            'HTTP_X_CALLBACK_EVENT' => $event,
        ], $raw);
    }

    private function fakeTripayCreate(string $reference = 'TREF-CREATE'): void
    {
        Http::fake([
            'https://tripay.co.id/api-sandbox/transaction/create' => Http::response(['success' => true, 'data' => ['reference' => $reference]], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);
    }

    private function tripayPayload(string $merchantRef, int $amount, string $reference = 'TREF-PAID'): array
    {
        return [
            'reference' => $reference,
            'merchant_ref' => $merchantRef,
            'payment_method' => 'BRIVA',
            'payment_method_code' => 'BRIVA',
            'total_amount' => $amount,
            'status' => 'PAID',
            'paid_at' => now()->timestamp,
        ];
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
