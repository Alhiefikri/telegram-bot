<?php

namespace Tests\Feature;

use App\Enums\CredentialAccessMethod;
use App\Enums\CredentialFormat;
use App\Enums\GuestOrderNotificationEvent;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Exceptions\OrderCredentialMailDeliveryException;
use App\Jobs\SendOrderCredentialMail;
use App\Mail\OrderCredentialMail;
use App\Models\Order;
use App\Models\OrderNotificationEvent;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\User;
use App\Services\GuestOrderNotificationService;
use App\Services\OrderCredentialMailService;
use App\Services\OrderStateMachine;
use App\Storefront\StorefrontShopCheckout;
use App\Storefront\StorefrontShopCheckoutInput;
use App\Storefront\StorefrontShopCheckoutResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\Support\StorefrontCheckoutScenarios;
use Tests\TestCase;

class OrderCredentialMailTest extends TestCase
{
    use RefreshDatabase;
    use StorefrontCheckoutScenarios;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config()->set('services.storefront.telegram_reservation_floor', 0);
        $this->smtpMailerIsConfigured();
    }

    public function test_paid_order_with_email_sends_one_credential_email_and_never_resends_on_replay(): void
    {
        Mail::fake();
        $this->manualPaymentModeIsActive();
        $product = $this->availableProductWithCredentials(['buyer-account@example.test|super-secret']);
        [$result, $order] = $this->checkoutWithEmail($product, 'credential-recipient@example.test');

        app(OrderStateMachine::class)->applyManualCompletion($order);

        $event = $this->credentialEvent($order);
        $this->assertNotNull($event);
        $this->assertSame('credential-recipient@example.test', $event->recipient_email);
        $this->assertSame(OrderNotificationEvent::STATUS_PENDING, $event->status);
        Queue::assertPushed(SendOrderCredentialMail::class, 1);

        $this->deliver($event->id);

        Mail::assertSentTimes(OrderCredentialMail::class, 1);
        $rendered = $this->renderedCredentialMail();
        $this->assertStringContainsString('buyer-account@example.test', $rendered);
        $this->assertStringContainsString('super-secret', $rendered);
        $this->assertStringContainsString((string) $order->invoice_number, $rendered);
        $this->assertStringContainsString(route('storefront.order-access.create'), $rendered);
        $this->assertStringNotContainsString((string) $result->recoveryKey, $rendered);

        $sent = $event->fresh();
        $this->assertSame(OrderNotificationEvent::STATUS_SENT, $sent->status);
        $this->assertNotNull($sent->sent_at);
        $this->assertNull($sent->next_attempt_at);

        // A duplicate queue delivery of the settled row must not send again.
        $this->deliver($event->id);

        Mail::assertSentTimes(OrderCredentialMail::class, 1);
    }

    public function test_repeated_lifecycle_handoffs_keep_exactly_one_handoff_row(): void
    {
        Mail::fake();
        $this->manualPaymentModeIsActive();
        $product = $this->availableProductWithCredentials(['repeat-account@example.test|secret']);
        [, $order] = $this->checkoutWithEmail($product, 'repeat-recipient@example.test');

        app(OrderStateMachine::class)->applyManualCompletion($order);
        app(OrderStateMachine::class)->applyManualCompletion($order->fresh());
        app(OrderCredentialMailService::class)->record($order->fresh());

        $this->assertSame(1, OrderNotificationEvent::query()
            ->where('event_type', GuestOrderNotificationEvent::CredentialsReady->value)
            ->count());
        Queue::assertPushed(SendOrderCredentialMail::class, 1);
    }

    public function test_order_without_email_never_records_or_sends_a_credential_email(): void
    {
        Mail::fake();
        $this->manualPaymentModeIsActive();
        $product = $this->availableProductWithCredentials(['no-email-account@example.test|secret']);
        [, $order] = $this->checkoutWithEmail($product, null);

        app(OrderStateMachine::class)->applyManualCompletion($order);

        $this->assertSame(0, OrderNotificationEvent::query()
            ->where('event_type', GuestOrderNotificationEvent::CredentialsReady->value)
            ->count());
        Queue::assertNotPushed(SendOrderCredentialMail::class);
    }

    public function test_unpaid_order_never_records_a_credential_handoff(): void
    {
        Mail::fake();
        $this->manualPaymentModeIsActive();
        $product = $this->availableProductWithCredentials(['unpaid-account@example.test|secret']);
        [, $order] = $this->checkoutWithEmail($product, 'unpaid-recipient@example.test');

        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        app(OrderCredentialMailService::class)->record($order->fresh());

        $this->assertSame(0, OrderNotificationEvent::query()
            ->where('event_type', GuestOrderNotificationEvent::CredentialsReady->value)
            ->count());
        Queue::assertNotPushed(SendOrderCredentialMail::class);
    }

    public function test_logging_transport_fails_closed_and_never_sends_credentials(): void
    {
        Mail::fake();
        config()->set('mail.default', 'log');
        $event = $this->paidOrderCredentialEvent('log-recipient@example.test');

        $this->deliver($event->id);

        Mail::assertNothingSent();
        $failed = $event->fresh();
        $this->assertSame(OrderNotificationEvent::STATUS_FAILED, $failed->status);
        $this->assertSame(OrderCredentialMailService::ERROR_MAILER_UNSAFE, $failed->last_error);
    }

    public function test_non_delivering_transport_defers_without_burning_an_attempt(): void
    {
        Mail::fake();
        config()->set('mail.default', 'array');
        $event = $this->paidOrderCredentialEvent('array-recipient@example.test');

        $this->deliver($event->id);

        Mail::assertNothingSent();
        $deferred = $event->fresh();
        $this->assertSame(OrderNotificationEvent::STATUS_PENDING, $deferred->status);
        $this->assertSame(OrderCredentialMailService::ERROR_MAILER_UNAVAILABLE, $deferred->last_error);
        $this->assertSame(0, $deferred->attempts);
        $this->assertTrue($deferred->next_attempt_at->isFuture());
    }

    public function test_transport_failure_stays_pending_with_backoff_and_the_row_is_the_retry_gate(): void
    {
        $event = $this->paidOrderCredentialEvent('failing-recipient@example.test');
        $transport = $this->scriptedTransport();

        try {
            $this->deliver($event->id);
            $this->fail('Expected credential email delivery to fail.');
        } catch (OrderCredentialMailDeliveryException $exception) {
            $this->assertSame('Credential email delivery failed.', $exception->getMessage());
            $this->assertStringNotContainsString('failing-recipient@example.test', $exception->getMessage());
        }

        $failed = $event->fresh();
        $this->assertSame(OrderNotificationEvent::STATUS_PENDING, $failed->status);
        $this->assertSame(OrderCredentialMailService::ERROR_MAIL_DELIVERY_FAILED, $failed->last_error);
        $this->assertSame(1, $failed->attempts);
        $this->assertSame(1, $transport->sends);
        $this->assertTrue($failed->next_attempt_at->isFuture());
        $this->assertStringNotContainsString('failing-recipient@example.test', json_encode($failed->toArray(), JSON_THROW_ON_ERROR));

        // Inside the lease a duplicate delivery is a no-op, so the provider is
        // never hit twice for one attempt.
        $this->deliver($event->id);
        $this->assertSame(1, $transport->sends);

        // Once the lease expires the retained row is retried exactly once.
        $transport->fail = false;
        $failed->forceFill(['next_attempt_at' => now()->subMinute()])->save();
        $this->deliver($event->id);

        $sent = $event->fresh();
        $this->assertSame(OrderNotificationEvent::STATUS_SENT, $sent->status);
        $this->assertSame(2, $sent->attempts);
        $this->assertSame(2, $transport->sends);
    }

    public function test_attempt_limit_marks_the_row_failed_without_throwing(): void
    {
        config()->set('services.storefront.credential_email.max_attempts', 1);
        $event = $this->paidOrderCredentialEvent('limit-recipient@example.test');
        $transport = $this->scriptedTransport();

        $this->deliver($event->id);

        $failed = $event->fresh();
        $this->assertSame(OrderNotificationEvent::STATUS_FAILED, $failed->status);
        $this->assertSame(OrderCredentialMailService::ERROR_MAIL_DELIVERY_FAILED, $failed->last_error);
        $this->assertNotNull($failed->failed_at);
        $this->assertNull($failed->next_attempt_at);
        $this->assertSame(1, $transport->sends);

        // A terminal row is never retried by a queue replay or the reconciler.
        $this->deliver($event->id);
        $this->assertSame(1, $transport->sends);
    }

    public function test_retired_credentials_defer_instead_of_sending_an_empty_email(): void
    {
        Mail::fake();
        $event = $this->paidOrderCredentialEvent('retired-recipient@example.test');
        ProductStockItem::query()->update(['retired_at' => now()]);

        $this->deliver($event->id);

        Mail::assertNothingSent();
        $deferred = $event->fresh();
        $this->assertSame(OrderNotificationEvent::STATUS_PENDING, $deferred->status);
        $this->assertSame(OrderCredentialMailService::ERROR_CREDENTIALS_UNAVAILABLE, $deferred->last_error);
    }

    public function test_structured_access_methods_are_rendered_and_html_is_escaped(): void
    {
        Mail::fake();
        $this->manualPaymentModeIsActive();
        $product = $this->structuredCredentialProduct();
        [, $order] = $this->checkoutWithEmail($product, 'structured-recipient@example.test');
        app(OrderStateMachine::class)->applyManualCompletion($order);

        $this->deliver($this->credentialEvent($order)->id);

        $rendered = $this->renderedCredentialMail();
        $this->assertStringContainsString('structured-account@example.test', $rendered);
        $this->assertStringContainsString('structured-password', $rendered);
        $this->assertStringContainsString('https://mail.example.test/otp?token=abc', $rendered);
        $this->assertStringContainsString('mail.example.test', $rendered);
        // Stored values are escaped, never emitted as markup.
        $this->assertStringNotContainsString('<b>structured-account@example.test</b>', $rendered);
    }

    public function test_delivery_keeps_the_checkout_recipient_snapshot_when_the_order_address_changes(): void
    {
        Mail::fake();
        $event = $this->paidOrderCredentialEvent('snapshot-recipient@example.test');

        Order::query()->whereKey($event->order_id)->sole()
            ->forceFill(['notification_email' => 'changed-recipient@example.test'])
            ->save();

        $this->deliver($event->id);

        Mail::assertSent(OrderCredentialMail::class, function (OrderCredentialMail $mail): bool {
            return $mail->hasTo('snapshot-recipient@example.test');
        });
        $this->assertSame('snapshot-recipient@example.test', $event->fresh()->recipient_email);
    }

    public function test_a_paid_order_never_retargets_its_notification_address(): void
    {
        $event = $this->paidOrderCredentialEvent('owner-recipient@example.test');
        $order = Order::query()->whereKey($event->order_id)->sole();

        app(GuestOrderNotificationService::class)->capture($order, 'attacker@example.test');

        $this->assertSame('owner-recipient@example.test', $order->fresh()->notification_email);
    }

    public function test_a_paid_order_created_without_an_email_can_never_gain_one(): void
    {
        Mail::fake();
        $this->manualPaymentModeIsActive();
        $product = $this->availableProductWithCredentials(['noemail-account@example.test|secret']);
        [, $order] = $this->checkoutWithEmail($product, null);
        app(OrderStateMachine::class)->applyManualCompletion($order);

        app(GuestOrderNotificationService::class)->capture($order->fresh(), 'attacker@example.test');

        $this->assertNull($order->fresh()->notification_email);
        $this->assertSame(0, OrderNotificationEvent::query()
            ->where('order_id', $order->getKey())
            ->where('event_type', GuestOrderNotificationEvent::CredentialsReady->value)
            ->count());
    }

    public function test_customer_resend_requires_reveal_access_and_only_rearms_the_stored_delivery(): void
    {
        Mail::fake();
        $this->manualPaymentModeIsActive();
        $keyedProduct = $this->availableProductWithCredentials(['keyed-account@example.test|secret']);
        [, $keyedOrder] = $this->checkoutWithEmail($keyedProduct, 'keyed-recipient@example.test', sessionId: 'resend-session');
        app(OrderStateMachine::class)->applyManualCompletion($keyedOrder);

        // Without the recovery key grant a keyed order keeps its invoice private.
        $this->post(route('storefront.shop.orders.credential-mail', $keyedOrder))->assertNotFound();

        $unkeyedProduct = $this->availableProductWithCredentials(['resend-account@example.test|secret']);
        [$result, $order] = $this->checkoutWithEmail($unkeyedProduct, 'resend-recipient@example.test');
        app(OrderStateMachine::class)->applyManualCompletion($order);
        $event = $this->credentialEvent($order);
        $this->deliver($event->id);
        $this->assertSame(OrderNotificationEvent::STATUS_SENT, $event->fresh()->status);

        // A later address change on the order must not retarget the resend.
        $order->forceFill(['notification_email' => 'attacker@example.test'])->save();

        // The order access form is the only way to obtain the reveal grant.
        $this->post(route('storefront.order-access.store'), [
            'invoice_number' => $order->invoice_number,
            'access_key' => (string) $result->recoveryKey,
        ])->assertRedirect();

        Queue::fake();

        $this->post(route('storefront.shop.orders.credential-mail', $order))
            ->assertRedirect()
            ->assertSessionHas('status');

        $rearmed = $event->fresh();
        $this->assertSame(OrderNotificationEvent::STATUS_PENDING, $rearmed->status);
        $this->assertSame(0, $rearmed->attempts);
        $this->assertSame('resend-recipient@example.test', $rearmed->recipient_email);
        Queue::assertPushed(SendOrderCredentialMail::class, 1);
    }

    public function test_recovery_command_materializes_missing_handoffs_for_paid_orders(): void
    {
        Mail::fake();
        $event = $this->paidOrderCredentialEvent('recover-recipient@example.test');
        OrderNotificationEvent::query()->whereKey($event->getKey())->delete();
        Queue::fake();

        $this->artisan('orders:recover-credential-mails', ['--limit' => 10])
            ->expectsOutputToContain('created 1 handoff row')
            ->assertSuccessful();

        $recreated = $this->credentialEvent(Order::query()->latest('id')->firstOrFail());
        $this->assertNotNull($recreated);
        $this->assertSame('recover-recipient@example.test', $recreated->recipient_email);
        Queue::assertPushed(SendOrderCredentialMail::class, 1);
    }

    public function test_terminal_events_are_never_sent_by_a_late_delivery_attempt(): void
    {
        Mail::fake();
        $event = $this->paidOrderCredentialEvent('late-recipient@example.test');
        Order::query()->whereKey($event->order_id)->update([
            'order_status' => OrderStatus::Cancelled,
            'payment_status' => PaymentStatus::Failed,
        ]);
        app(OrderStateMachine::class)->applyPaymentRefunded(
            Order::query()->whereKey($event->order_id)->sole()->payments()->sole(),
        );

        $this->deliver($event->id);

        Mail::assertNothingSent();
        $cancelled = $event->fresh();
        $this->assertSame(OrderNotificationEvent::STATUS_CANCELLED, $cancelled->status);
        $this->assertSame(OrderCredentialMailService::ERROR_ORDER_NOT_ELIGIBLE, $cancelled->last_error);
    }

    public function test_paid_invoice_page_shows_the_masked_delivery_state_and_resend_action(): void
    {
        Mail::fake();
        $this->manualPaymentModeIsActive();
        $product = $this->availableProductWithCredentials(['page-account@example.test|secret']);
        [$result, $order] = $this->checkoutWithEmail($product, 'page-recipient@example.test');
        app(OrderStateMachine::class)->applyManualCompletion($order);
        $this->deliver($this->credentialEvent($order)->id);

        // Without the reveal grant the paid page keeps the delivery details hidden.
        $this->get(URL::signedRoute('storefront.shop.orders.show', $order, null, false))
            ->assertOk()
            ->assertDontSee('page-recipient@example.test')
            ->assertDontSee('Kirim ulang ke email');

        $this->post(route('storefront.order-access.store'), [
            'invoice_number' => $order->invoice_number,
            'access_key' => (string) $result->recoveryKey,
        ])->assertRedirect();

        $this->get(URL::signedRoute('storefront.shop.orders.show', $order, null, false))
            ->assertOk()
            ->assertSee('Data akun sudah dikirim ke email')
            ->assertSee('p***@example.test')
            ->assertSee('Kirim ulang ke email')
            ->assertDontSee('page-recipient@example.test');
    }

    public function test_admin_resend_is_audited_and_stays_on_the_recorded_address(): void
    {
        Mail::fake();
        $event = $this->paidOrderCredentialEvent('admin-recipient@example.test');
        $order = Order::query()->whereKey($event->order_id)->sole();
        $this->deliver($event->id);
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        Queue::fake();
        $this->actingAs($admin)
            ->post(route('admin.orders.credential-email', $order))
            ->assertRedirect(route('admin.orders.show', $order));

        $rearmed = $event->fresh();
        $this->assertSame(OrderNotificationEvent::STATUS_PENDING, $rearmed->status);
        $this->assertSame('admin-recipient@example.test', $rearmed->recipient_email);
        $this->assertSame(0, $rearmed->attempts);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'order.credential_mail_resent',
            'subject_id' => (string) $order->getKey(),
        ]);
        Queue::assertPushed(SendOrderCredentialMail::class, 1);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('Email data akun')
            ->assertSee('a***@example.test')
            ->assertDontSee('admin-recipient@example.test');
    }

    private function smtpMailerIsConfigured(): void
    {
        config()->set([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.test',
            'mail.mailers.smtp.password' => 'smtp-secret',
        ]);
    }

    /**
     * Replaces the configured SMTP transport with a deterministic local one so
     * success and failure are scripted without a provider connection. The
     * send count is the assertion surface for duplicate-attempt protection.
     */
    private function scriptedTransport(): object
    {
        $transport = new class implements TransportInterface
        {
            public int $sends = 0;

            public bool $fail = true;

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $this->sends++;

                if ($this->fail) {
                    throw new RuntimeException('Scripted transport rejected the message.');
                }

                return null;
            }

            public function __toString(): string
            {
                return 'scripted';
            }
        };

        Mail::mailer('smtp')->setSymfonyTransport($transport);

        return $transport;
    }

    /**
     * @return array{0: StorefrontShopCheckoutResult, 1: Order}
     */
    private function checkoutWithEmail(Product $product, ?string $email, ?string $sessionId = null): array
    {
        $result = app(StorefrontShopCheckout::class)->start(new StorefrontShopCheckoutInput(
            productId: $product->id,
            quantity: 1,
            buyerName: 'Credential Buyer',
            termsAccepted: true,
            email: $email,
        ), $sessionId);

        return [$result, Order::query()->latest('id')->firstOrFail()];
    }

    private function paidOrderCredentialEvent(string $email): OrderNotificationEvent
    {
        $this->manualPaymentModeIsActive();
        $product = $this->availableProductWithCredentials(['paid-account@example.test|paid-secret']);
        [, $order] = $this->checkoutWithEmail($product, $email);
        app(OrderStateMachine::class)->applyManualCompletion($order);

        return $this->credentialEvent($order) ?? $this->fail('Expected a credential email handoff row.');
    }

    private function credentialEvent(Order $order): ?OrderNotificationEvent
    {
        return OrderNotificationEvent::query()
            ->where('order_id', $order->getKey())
            ->where('event_type', GuestOrderNotificationEvent::CredentialsReady->value)
            ->first();
    }

    private function deliver(int $eventId): void
    {
        (new SendOrderCredentialMail($eventId))->handle(app(OrderCredentialMailService::class));
    }

    private function renderedCredentialMail(): string
    {
        $rendered = null;
        Mail::assertSent(OrderCredentialMail::class, function (OrderCredentialMail $mail) use (&$rendered): bool {
            $rendered = $mail->render();

            return true;
        });

        return (string) $rendered;
    }

    private function structuredCredentialProduct(): Product
    {
        $product = $this->availableProduct(['stock' => 1, 'reserved_stock' => 0]);
        ProductStockItem::create([
            'product_id' => $product->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => [
                'version' => 1,
                'email' => 'structured-account@example.test',
                'password' => 'structured-password',
                'access_method' => CredentialAccessMethod::OtpMailLink->value,
                'access_value' => 'https://mail.example.test/otp?token=abc',
                'tutorial_override' => null,
            ],
        ]);

        return $product->fresh();
    }
}
