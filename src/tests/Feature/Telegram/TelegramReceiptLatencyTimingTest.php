<?php

namespace Tests\Feature\Telegram;

use App\Enums\CredentialAccessMethod;
use App\Enums\CredentialFormat;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\SendTelegramReceipt;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\TelegramOutboundEffect;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\Credentials\StructuredCredentialPayload;
use App\Services\OrderCheckoutService;
use App\Services\OrderStateMachine;
use App\Services\Telegram\FakeTelegramBotClient;
use App\Services\Telegram\ReceiptDeliveryPipeline;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramReceiptLatencyTimingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.telegram.token', 'timing-token');
        config()->set('services.telegram.admin_telegram_chat_id', '100000001');
        config()->set('services.dogfood_timing.enabled', true);
        config()->set('services.dogfood_timing.fake_telegram_outbound', true);
        app()->instance(TelegramBotClient::class, new FakeTelegramBotClient);
    }

    public function test_paid_commit_handoff_enqueues_receipt_with_first_stage_and_timestamp(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        $order = $this->checkoutOrder();

        app(OrderStateMachine::class)->applyPaymentPaid($order->payments()->sole());

        Queue::assertPushed(SendTelegramReceipt::class, function (SendTelegramReceipt $job): bool {
            $this->assertSame(SendTelegramReceipt::ENQUEUE_FIRST, $job->enqueueStage);
            $this->assertSame(SendTelegramReceipt::REASON_PAID_COMMIT, $job->enqueueReason);
            $this->assertNotNull($job->jobEnqueuedAt);

            return true;
        });
    }

    public function test_missing_enqueue_timestamp_stays_unknown_instead_of_becoming_zero(): void
    {
        Queue::fake();
        [$order, $effect] = $this->paidOrderWithReceiptEffect();
        $logs = $this->captureDogfoodLogs();

        (new SendTelegramReceipt(
            (string) $order->getKey(),
            false,
            null,
            (string) $effect->getKey(),
        ))->handle(app(TelegramOutboundEffectService::class), app(ReceiptDeliveryPipeline::class));

        $this->assertDogfoodLog($logs, 'dogfood.telegram.receipt_job_picked_up', fn (array $context): bool => $context['job_enqueued_at'] === null
            && $context['queue_wait_ms'] === null
            && $context['queue_wait_unknown'] === true
            && $context['enqueue_stage'] === 'unknown');
    }

    public function test_recovery_and_explicit_resend_carry_distinct_enqueue_stages(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        [, $effect] = $this->paidOrderWithReceiptEffect();
        $dispatcher = app(TelegramOutboundEffectDispatcher::class);

        $dispatcher->dispatch($effect, now()->toISOString());
        Queue::assertPushed(SendTelegramReceipt::class, fn (SendTelegramReceipt $job): bool => $job->enqueueStage === SendTelegramReceipt::ENQUEUE_FIRST
            && $job->enqueueReason === SendTelegramReceipt::REASON_EFFECT_DISPATCH);

        $dispatcher->dispatch(
            $effect,
            now()->toISOString(),
            enqueueStage: SendTelegramReceipt::ENQUEUE_RECOVERY,
            enqueueReason: SendTelegramReceipt::REASON_RECOVERY_SWEEP,
        );

        Queue::assertPushed(SendTelegramReceipt::class, fn (SendTelegramReceipt $job): bool => $job->enqueueStage === SendTelegramReceipt::ENQUEUE_RECOVERY
            && $job->enqueueReason === SendTelegramReceipt::REASON_RECOVERY_SWEEP);

        $dispatcher->dispatchExplicitResend($effect);

        Queue::assertPushed(SendTelegramReceipt::class, fn (SendTelegramReceipt $job): bool => $job->enqueueStage === SendTelegramReceipt::ENQUEUE_RETRY
            && $job->enqueueReason === SendTelegramReceipt::REASON_EXPLICIT_RESEND);
    }

    public function test_recovery_command_dispatches_receipt_with_recovery_enqueue_stage(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        $this->paidOrderWithReceiptEffect();

        $this->artisan('telegram:recover-outbound')->assertSuccessful();

        Queue::assertPushed(SendTelegramReceipt::class, fn (SendTelegramReceipt $job): bool => $job->enqueueStage === SendTelegramReceipt::ENQUEUE_RECOVERY
            && $job->enqueueReason === SendTelegramReceipt::REASON_RECOVERY_SWEEP
            && $job->jobEnqueuedAt !== null);
    }

    public function test_receipt_pipeline_records_first_credential_ack_and_completion_without_sensitive_values(): void
    {
        [$order, $effect] = $this->paidOrderWithReceiptEffect();
        $logs = $this->captureDogfoodLogs();

        app(ReceiptDeliveryPipeline::class)->deliver($effect);

        $this->assertDogfoodLog($logs, 'dogfood.telegram.receipt_first_credential_acknowledged', fn (array $context): bool => isset($context['telegram_acknowledged_at'])
            && $context['order_correlation_id'] !== null
            && ! array_key_exists('email', $context)
            && ! array_key_exists('password', $context));
        $this->assertDogfoodLog($logs, 'dogfood.telegram.receipt_completed', fn (array $context): bool => isset($context['receipt_completed_at'])
            && $context['order_correlation_id'] !== null);

        $encoded = json_encode($logs->entries, JSON_THROW_ON_ERROR);
        foreach (['timing-account@example.test', 'timing-secret-password', (string) $order->customer->telegram_id] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
    }

    private function checkoutOrder(): Order
    {
        $customer = Customer::factory()->create(['telegram_id' => 8_850_001, 'locale' => 'en']);
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0, 'price_amount' => 10_000]);
        ProductStockItem::query()->create([
            'product_id' => $product->getKey(),
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => StructuredCredentialPayload::fromValues(
                'timing-account@example.test',
                'timing-secret-password',
                CredentialAccessMethod::None,
                null,
            )->toArray(),
        ]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $product, 1);

        return app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris());
    }

    /** @return array{0: Order, 1: TelegramOutboundEffect} */
    private function paidOrderWithReceiptEffect(): array
    {
        $customer = Customer::factory()->create(['telegram_id' => 8_850_002, 'locale' => 'en']);
        $product = Product::factory()->create(['name' => 'Timing Product']);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-TIMING-1',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 12_500,
            'currency' => 'IDR',
        ]);
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->getKey(),
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => 12_500,
            'subtotal' => 12_500,
        ]);
        Payment::factory()->for($order)->create([
            'method' => 'QR_MANUAL',
            'amount' => 12_500,
            'provider_total_payment' => 12_500,
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
        ProductStockItem::query()->create([
            'product_id' => $product->getKey(),
            'order_item_id' => $item->getKey(),
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => StructuredCredentialPayload::fromValues(
                'timing-account@example.test',
                'timing-secret-password',
                CredentialAccessMethod::None,
                null,
            )->toArray(),
        ]);

        $effect = app(TelegramOutboundEffectService::class)->recordReceipt($order->fresh());
        $this->assertInstanceOf(TelegramOutboundEffect::class, $effect);

        return [$order->fresh(), $effect];
    }

    private function captureDogfoodLogs(): object
    {
        $logs = (object) ['entries' => []];

        Log::shouldReceive('info')->zeroOrMoreTimes()->withArgs(function (string $event, array $context = []) use ($logs): bool {
            if (str_starts_with($event, 'dogfood.')) {
                $logs->entries[] = [$event, $context];
            }

            return true;
        });
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        return $logs;
    }

    private function assertDogfoodLog(object $logs, string $event, callable $predicate): void
    {
        foreach ($logs->entries as [$loggedEvent, $context]) {
            if ($loggedEvent === $event && $predicate($context)) {
                $this->assertTrue(true);

                return;
            }
        }

        $this->fail("Expected dogfood log [{$event}] was not emitted with matching context.");
    }
}
