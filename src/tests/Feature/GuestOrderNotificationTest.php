<?php

namespace Tests\Feature;

use App\Enums\GuestOrderNotificationEvent;
use App\Enums\OrderStatus;
use App\Exceptions\GuestOrderNotificationDeliveryException;
use App\Jobs\SendGuestOrderNotification;
use App\Mail\GuestOrderMilestoneMail;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderNotificationEvent;
use App\Services\GuestOrderNotificationService;
use App\Services\OrderStateMachine;
use App\Storefront\StorefrontShopCheckout;
use App\Storefront\StorefrontShopCheckoutInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\StorefrontCheckoutScenarios;
use Tests\TestCase;

class GuestOrderNotificationTest extends TestCase
{
    use RefreshDatabase;
    use StorefrontCheckoutScenarios;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.storefront.telegram_reservation_floor', 0);
    }

    public function test_guest_checkout_without_email_keeps_the_no_email_path(): void
    {
        Queue::fake();
        $this->manualPaymentModeIsActive();
        $product = $this->availableProduct(['stock' => 1]);

        $response = $this->post(route('storefront.shop.checkout'), [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'No Email Buyer',
        ]);

        $response->assertRedirect();

        $order = Order::query()->sole();

        $this->assertNull($order->notification_email);
        $this->assertSame(0, OrderNotificationEvent::query()->count());
        $this->assertNull(Customer::query()->sole()->email);
        Queue::assertNotPushed(SendGuestOrderNotification::class);
    }

    public function test_checkout_normalizes_and_encrypts_email_without_populating_customer_identity(): void
    {
        Queue::fake();
        $this->manualPaymentModeIsActive();
        $product = $this->availableProduct(['stock' => 1]);

        $response = $this->post(route('storefront.shop.checkout'), [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'Email Buyer',
            'email' => ' Buyer@Example.TEST ',
        ]);

        $response->assertRedirect();
        $order = Order::query()->sole();
        $this->assertSame('buyer@example.test', $order->notification_email);
        $this->assertNull(Customer::query()->sole()->email);
        $this->assertSame(GuestOrderNotificationEvent::OrderCreated, OrderNotificationEvent::query()->sole()->event_type);

        $rawEmail = DB::table('orders')->where('id', $order->id)->value('notification_email');
        $this->assertIsString($rawEmail);
        $this->assertStringNotContainsString('buyer@example.test', $rawEmail);
    }

    public function test_invalid_checkout_email_is_rejected_without_creating_an_order(): void
    {
        $this->manualPaymentModeIsActive();
        $product = $this->availableProduct(['stock' => 1]);

        $this->post(route('storefront.shop.checkout'), [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'Invalid Email Buyer',
            'email' => 'not-an-email',
        ])->assertSessionHasErrors('email');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, OrderNotificationEvent::query()->count());
    }

    public function test_reused_checkout_key_records_one_order_created_event(): void
    {
        Queue::fake();
        $this->manualPaymentModeIsActive();
        $product = $this->availableProduct(['stock' => 2]);
        $input = new StorefrontShopCheckoutInput(
            productId: $product->id,
            quantity: 1,
            buyerName: 'Repeat Buyer',
            email: 'repeat@example.test',
        );

        app(StorefrontShopCheckout::class)->start($input, 'repeat-checkout-session');
        app(StorefrontShopCheckout::class)->start($input, 'repeat-checkout-session');

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, OrderNotificationEvent::query()->count());
        Queue::assertPushed(SendGuestOrderNotification::class, 1);
    }

    public function test_paid_and_completed_milestones_are_deduplicated_and_mail_contains_only_safe_data(): void
    {
        Queue::fake();
        Mail::fake();
        $this->manualPaymentModeIsActive();
        $credential = 'account@example.test|password-secret|mfa-secret';
        $product = $this->availableProductWithCredentials(
            secrets: [$credential],
            attributes: [
                'name' => 'Safe Summary Product',
                'stock' => 1,
            ],
        );
        $result = app(StorefrontShopCheckout::class)->start(new StorefrontShopCheckoutInput(
            productId: $product->id,
            quantity: 1,
            buyerName: 'Fixture Buyer',
            email: 'buyer@example.test',
        ), 'milestone-session');
        $order = Order::query()->sole();
        $recoveryKey = $result->recoveryKey;
        $this->assertNotNull($recoveryKey);
        $created = OrderNotificationEvent::query()->sole();

        $createdJob = new SendGuestOrderNotification($created->id);
        $createdJob->handle(app(GuestOrderNotificationService::class));
        $createdJob->handle(app(GuestOrderNotificationService::class));

        Mail::assertSent(GuestOrderMilestoneMail::class, function (GuestOrderMilestoneMail $mail) use ($order, $credential, $recoveryKey): bool {
            $rendered = $mail->render();

            return str_contains($rendered, $order->invoice_number)
                && str_contains($rendered, route('storefront.order-access.create'))
                && str_contains($rendered, route('storefront.shop.orders.show', $order))
                && ! str_contains($rendered, $credential)
                && ! str_contains($rendered, $recoveryKey)
                && ! str_contains($rendered, 'Recovery Key')
                && ! str_contains($rendered, 'password :')
                && ! str_contains($rendered, 'MFA');
        });
        Mail::assertSentTimes(GuestOrderMilestoneMail::class, 1);
        $this->assertSame(OrderNotificationEvent::STATUS_SENT, $created->fresh()->status);

        app(OrderStateMachine::class)->applyManualCompletion($order);

        $types = OrderNotificationEvent::query()
            ->orderBy('id')
            ->get()
            ->map(fn (OrderNotificationEvent $event): string => $event->event_type->value)
            ->all();
        $this->assertSame([
            GuestOrderNotificationEvent::OrderCreated->value,
            GuestOrderNotificationEvent::PaymentConfirmed->value,
            GuestOrderNotificationEvent::FulfillmentCompleted->value,
        ], $types);
        Queue::assertPushed(SendGuestOrderNotification::class, 3);

        foreach (OrderNotificationEvent::query()->where('status', OrderNotificationEvent::STATUS_PENDING)->get() as $event) {
            $job = new SendGuestOrderNotification($event->id);
            $job->handle(app(GuestOrderNotificationService::class));
            $job->handle(app(GuestOrderNotificationService::class));
        }

        Mail::assertSentTimes(GuestOrderMilestoneMail::class, 3);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
    }

    public function test_expiry_and_cancellation_milestones_are_emitted_once(): void
    {
        Queue::fake();
        $this->manualPaymentModeIsActive();
        $product = $this->availableProduct(['stock' => 2]);
        $cancelled = $this->createOrder($product->id, 'cancel-session', 'cancel@example.test');
        $expired = $this->createOrder($product->id, 'expire-session', 'expire@example.test');

        app(OrderStateMachine::class)->applyPendingCancellation($cancelled);
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();
        app(OrderStateMachine::class)->applyPendingExpiry($expired->fresh());
        app(OrderStateMachine::class)->applyPendingExpiry($expired->fresh());

        $this->assertSame(2, OrderNotificationEvent::query()->where('event_type', GuestOrderNotificationEvent::OrderCreated)->count());
        $this->assertSame(1, OrderNotificationEvent::query()->where('event_type', GuestOrderNotificationEvent::OrderCancelled)->count());
        $this->assertSame(1, OrderNotificationEvent::query()->where('event_type', GuestOrderNotificationEvent::OrderExpired)->count());
        $this->assertSame(OrderStatus::Cancelled, $cancelled->fresh()->order_status);
        $this->assertSame(OrderStatus::Expired, $expired->fresh()->order_status);
    }

    public function test_failed_mail_delivery_redacts_transport_details_and_does_not_resend_on_retry(): void
    {
        Queue::fake();
        $this->manualPaymentModeIsActive();
        $product = $this->availableProduct(['stock' => 1]);
        $email = 'secret-recipient@example.test';
        $this->createOrder($product->id, 'failure-session', $email);
        $event = OrderNotificationEvent::query()->sole();
        Mail::shouldReceive('to')
            ->once()
            ->with($email)
            ->andThrow(new RuntimeException('SMTP rejected secret-recipient@example.test with provider secret'));

        try {
            (new SendGuestOrderNotification($event->id))->handle(app(GuestOrderNotificationService::class));
            $this->fail('Expected guest notification delivery to fail.');
        } catch (GuestOrderNotificationDeliveryException $exception) {
            $this->assertSame('Guest order notification delivery failed.', $exception->getMessage());
            $this->assertStringNotContainsString($email, $exception->getMessage());
        }

        $failed = $event->fresh();
        $this->assertSame(OrderNotificationEvent::STATUS_FAILED, $failed->status);
        $this->assertSame('mail_delivery_failed', $failed->last_error);
        $this->assertStringNotContainsString($email, json_encode($failed->toArray(), JSON_THROW_ON_ERROR));

        try {
            (new SendGuestOrderNotification($event->id))->handle(app(GuestOrderNotificationService::class));
            $this->fail('Expected a failed event retry to remain terminal.');
        } catch (GuestOrderNotificationDeliveryException $exception) {
            $this->assertSame('Guest order notification delivery failed.', $exception->getMessage());
        }
    }

    private function createOrder(int $productId, string $sessionId, string $email): Order
    {
        app(StorefrontShopCheckout::class)->start(new StorefrontShopCheckoutInput(
            productId: $productId,
            quantity: 1,
            buyerName: 'Fixture Buyer',
            email: $email,
        ), $sessionId);

        return Order::query()->where('checkout_key', hash_hmac('sha256', implode('|', [
            $sessionId,
            $productId,
            1,
            'fixture buyer',
            'qris',
        ]), (string) config('app.key')))->firstOrFail();
    }
}
