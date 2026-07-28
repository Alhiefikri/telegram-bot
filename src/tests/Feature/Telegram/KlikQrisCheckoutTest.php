<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\SendKlikQrisCheckoutInstructions;
use App\Jobs\SendManualCheckoutNotifications;
use App\Jobs\SendPakasirCheckoutInstructions;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Checkout\CheckoutPostCreationBehavior;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\KlikQris\KlikQrisTransaction;
use App\Services\Telegram\TelegramCheckoutIntents;
use App\Services\Telegram\TelegramCheckoutPaymentIntentSelector;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\Support\TelegramBotTestSupport;

class KlikQrisCheckoutTest extends TelegramBotTestSupport
{
    private const PNG_BYTES = "\x89PNG\r\n\x1A\n".'telegram-qris-png';

    public function test_checkout_current_dispatches_only_klikqris_instruction_job_when_sandbox_is_ready(): void
    {
        Queue::fake();
        config()->set('services.klikqris', KlikQrisSandboxScenarios::readyConfig());
        ManualPaymentSetting::create([
            'payment_mode' => 'pakasir',
            'pakasir_checkout_flow' => 'api_qris',
            'is_active' => true,
        ]);

        $selection = app(TelegramCheckoutPaymentIntentSelector::class)->select(false);
        $order = Order::factory()->create();

        $this->assertSame('klikqris', $selection->requestedPaymentMode);
        $this->assertSame('klikqris', $selection->paymentMode);
        $this->assertSame('klikqris', $selection->paymentIntent->provider());
        $this->assertSame(CheckoutPostCreationBehavior::KlikQrisInstructions, $selection->paymentIntent->postCreationBehavior());
        SendKlikQrisCheckoutInstructions::dispatch($order->id)->onQueue('telegram-outbound');
        Queue::assertPushedOn('telegram-outbound', SendKlikQrisCheckoutInstructions::class);
        Queue::assertNotPushed(SendPakasirCheckoutInstructions::class);
        Queue::assertNotPushed(SendManualCheckoutNotifications::class);
    }

    public function test_sandbox_selector_is_fail_closed_when_not_ready_and_keeps_existing_pakasir_selection(): void
    {
        Queue::fake();
        config()->set('services.klikqris', array_replace(KlikQrisSandboxScenarios::readyConfig(), [
            'sandbox_enabled' => false,
        ]));
        ManualPaymentSetting::create([
            'payment_mode' => 'pakasir',
            'pakasir_checkout_flow' => 'hosted',
            'is_active' => true,
        ]);

        $selection = app(TelegramCheckoutPaymentIntentSelector::class)->select(false);

        $this->assertSame('pakasir', $selection->requestedPaymentMode);
        $this->assertSame('pakasir', $selection->paymentMode);
        $this->assertSame('pakasir', $selection->paymentIntent->provider());
        $this->assertSame(CheckoutPostCreationBehavior::PakasirInstructions, $selection->paymentIntent->postCreationBehavior());
        Queue::assertNotPushed(SendKlikQrisCheckoutInstructions::class);
    }

    public function test_klikqris_job_dedupes_confirmed_successful_send_but_keeps_at_least_once_retry_semantics(): void
    {
        $this->fakeTelegramHttp([
            'https://klikqris.com/storage/sandbox/qris_job.png' => Http::response(self::PNG_BYTES, 200, ['Content-Type' => 'image/png']),
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'en']);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-KLIK-600',
            'order_status' => OrderStatus::PendingPayment,
            'manual_checkout_customer_notification_started_at' => null,
            'manual_checkout_customer_notified_at' => null,
            'expires_at' => CarbonImmutable::parse('2026-07-28 10:24:00', 'UTC'),
        ]);
        $payment = Payment::factory()->for($order)->create([
            'provider' => 'klikqris',
            'provider_payment_method' => 'qris',
            'merchant_ref' => 'INV-KLIK-600',
            'amount' => 1000,
            'provider_total_payment' => 1016,
            'provider_expires_at' => CarbonImmutable::parse('2026-07-28 10:24:00', 'UTC'),
            'status' => PaymentStatus::Pending,
            'provider_payload' => ['data' => [
                'order_id' => 'INV-KLIK-600',
                'amount' => 1000,
                'total_amount' => 1016,
                'status' => 'PENDING',
                'qris_url' => 'https://klikqris.com/storage/sandbox/qris_job.png',
                'expired_at' => '2026-07-28T10:24:00+00:00',
            ]],
            'provider_transaction_token' => 'secret-signature-token',
            'provider_create_state' => 'created',
        ]);
        $native = $this->mock(KlikQrisNativeQrisService::class);
        $native->shouldReceive('ensureCreated')
            ->once()
            ->with(Mockery::on(fn (Payment $argument): bool => $argument->is($payment)))
            ->andReturn(KlikQrisTransaction::fromCreatePayload(['data' => [
                'order_id' => 'INV-KLIK-600',
                'amount' => 1000,
                'total_amount' => 1016,
                'status' => 'PENDING',
                'qris_url' => 'https://klikqris.com/storage/sandbox/qris_job.png',
                'qris_image' => 'data:image/png;base64,'.base64_encode(self::PNG_BYTES),
                'expired_at' => '2026-07-28T10:24:00+00:00',
                'signature' => 'secret-signature-token',
            ]]));

        (new SendKlikQrisCheckoutInstructions($order->id))->handle(app(TelegramCheckoutIntents::class));
        (new SendKlikQrisCheckoutInstructions($order->id))->handle(app(TelegramCheckoutIntents::class));

        $sendPhotos = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertCount(1, $sendPhotos);
        $request = $sendPhotos->first()[0];
        $this->assertStringContainsString(self::PNG_BYTES, $request->body());
        $this->assertStringContainsString('filename="klikqris-INV-KLIK-600.png"', $request->body());
        $caption = $request->body();
        $this->assertStringContainsString('IDR 1,016.00', $caption);
        $this->assertStringNotContainsString('Rp 1.016', $caption);
        $this->assertStringContainsString('Do not round or change any digit', $caption);
        $this->assertStringContainsString('INV-KLIK-600', $caption);
        $this->assertStringContainsString('15 minutes', $caption);
        $this->assertStringContainsString('10:24', $caption);
        $this->assertStringNotContainsString('https://', $caption);
        $this->assertStringNotContainsString('Popup Snap', $caption);
        $this->assertStringNotContainsString('secret-signature-token', $caption);
        $this->assertStringNotContainsString('base64', strtolower($caption));
        $this->assertStringContainsString('order:INV-KLIK-600', $request->body());
        $this->assertStringContainsString('buyer:cancel-pending:'.$order->id, $request->body());
        $this->assertNotNull($order->fresh()->manual_checkout_customer_notified_at);
    }

    public function test_klikqris_job_releases_claim_after_definite_send_failure_and_keeps_provider_transaction(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $order = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::PendingPayment,
            'manual_checkout_customer_notification_started_at' => null,
            'manual_checkout_customer_notified_at' => null,
        ]);
        $payment = Payment::factory()->for($order)->create(['provider' => 'klikqris']);
        $failingIntents = $this->mock(TelegramCheckoutIntents::class);
        $failingIntents->shouldReceive('sendKlikQrisPaymentInstructions')
            ->once()
            ->andThrow(new RuntimeException('Telegram sendPhoto temporarily failed.'));

        $job = new SendKlikQrisCheckoutInstructions($order->id);
        try {
            $job->handle($failingIntents);
            $this->fail('The first outbound attempt should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Telegram sendPhoto temporarily failed.', $exception->getMessage());
        }
        $this->assertNull($order->fresh()->manual_checkout_customer_notification_started_at);
        $this->assertNull($order->fresh()->manual_checkout_customer_notified_at);

        $successfulIntents = $this->mock(TelegramCheckoutIntents::class);
        $successfulIntents->shouldReceive('sendKlikQrisPaymentInstructions')
            ->once()
            ->with('12345', Mockery::type(Order::class), Mockery::on(fn (Payment $argument): bool => $argument->is($payment)));
        $job->handle($successfulIntents);
        $this->assertNotNull($order->fresh()->manual_checkout_customer_notified_at);
        $this->assertSame(1, $order->payments()->where('provider', 'klikqris')->count());
    }
}
