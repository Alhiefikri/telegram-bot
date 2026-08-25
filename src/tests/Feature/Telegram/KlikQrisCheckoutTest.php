<?php

namespace Tests\Feature\Telegram;

use App\Exceptions\InvalidCheckout;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\SendBinanceCheckoutInstructions;
use App\Jobs\SendKlikQrisCheckoutInstructions;
use App\Jobs\SendManualCheckoutNotifications;
use App\Jobs\SendPakasirCheckoutInstructions;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TelegramOutboundEffect;
use App\Services\Checkout\CheckoutPostCreationBehavior;
use App\Services\KlikQris\KlikQrisNativeQrisLifecycleResult;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\KlikQris\KlikQrisPayableNativeQris;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Services\Telegram\TelegramCheckoutIntents;
use App\Services\Telegram\TelegramCheckoutPaymentIntentSelector;
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

    public function test_checkout_current_dispatches_only_klikqris_instruction_job_when_klikqris_is_selected_and_sandbox_is_ready(): void
    {
        Queue::fake();
        KlikQrisSandboxScenarios::configureReadySandbox();
        ManualPaymentSetting::create([
            'payment_mode' => 'klikqris',
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

    public function test_new_selector_rejects_retired_pakasir_setting(): void
    {
        ManualPaymentSetting::create([
            'payment_mode' => 'pakasir',
            'is_active' => true,
        ]);

        $this->expectException(InvalidCheckout::class);

        app(TelegramCheckoutPaymentIntentSelector::class)->select(false);
    }

    public function test_klikqris_job_dedupes_confirmed_successful_send_but_keeps_at_least_once_retry_semantics(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        $this->fakeTelegramHttp([
            'https://klikqris.com/storage/sandbox/qris_job.png' => Http::response(self::PNG_BYTES, 200, ['Content-Type' => 'image/png']),
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'en']);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-KLIK-600',
            'order_status' => OrderStatus::PendingPayment,
            'manual_checkout_customer_notified_at' => null,
            'expires_at' => now()->addMinutes(14),
        ]);
        $payment = Payment::factory()->for($order)->create([
            'provider' => 'klikqris',
            'provider_payment_method' => 'qris',
            'merchant_ref' => 'INV-KLIK-600',
            'amount' => 1000,
            'provider_total_payment' => 1016,
            'provider_expires_at' => $order->expires_at->copy()->addMinute(),
            'status' => PaymentStatus::Pending,
            'provider_payload' => ['data' => [
                'order_id' => 'INV-KLIK-600',
                'amount' => 1000,
                'total_amount' => '1016.00',
                'status' => 'PENDING',
                'qris_url' => 'https://klikqris.com/storage/sandbox/qris_job.png',
                'expired_at' => $order->expires_at->copy()->addMinute()->toAtomString(),
            ]],
            'provider_create_state' => 'created',
        ]);
        $native = $this->mock(KlikQrisNativeQrisService::class);
        $native->shouldReceive('lifecycle')
            ->once()
            ->with(Mockery::on(fn (Payment $argument): bool => $argument->is($payment)))
            ->andReturn(KlikQrisNativeQrisLifecycleResult::payableResult(
                KlikQrisPayableNativeQris::fromPayment($payment->fresh()),
            ));

        (new SendKlikQrisCheckoutInstructions($order->id))->handle(app(TelegramCheckoutIntents::class));
        (new SendKlikQrisCheckoutInstructions($order->id))->handle(app(TelegramCheckoutIntents::class));

        $sendPhotos = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertCount(1, $sendPhotos);
        $request = $sendPhotos->first()[0];
        $this->assertStringContainsString(self::PNG_BYTES, $request->body());
        $this->assertStringContainsString('filename="klikqris-INV-KLIK-600.png"', $request->body());
        $caption = $request->body();
        $this->assertStringContainsString('IDR 1016.00', $caption);
        $this->assertStringNotContainsString('Rp 1.016', $caption);
        $this->assertStringContainsString('Do not round or change any digit', $caption);
        $this->assertStringContainsString('INV-KLIK-600', $caption);
        $this->assertStringContainsString('15 minutes', $caption);
        $this->assertStringContainsString('17:24', $caption);
        $this->assertStringNotContainsString('https://', $caption);
        $this->assertStringNotContainsString('Popup Snap', $caption);
        $this->assertStringNotContainsString('secret-signature-token', $caption);
        $this->assertStringNotContainsString('base64', strtolower($caption));
        $this->assertStringContainsString('order:INV-KLIK-600', $request->body());
        $this->assertStringContainsString('buyer:cancel-pending:'.$order->id, $request->body());
        $freshOrder = $order->fresh();
        $this->assertNotNull($freshOrder->manual_checkout_customer_notified_at);
        $this->assertSame('12345', $freshOrder->manual_checkout_customer_notification_chat_id);
        $this->assertSame(123456, $freshOrder->manual_checkout_customer_notification_message_id);
    }

    public function test_production_create_uses_qris_image_when_qris_url_returns_invalid_png(): void
    {
        $this->travelTo('2026-08-13 10:10:00 UTC');
        KlikQrisSandboxScenarios::configureReadyProduction();
        app()->detectEnvironment(fn (): string => 'production');
        $qrisUrl = 'https://api.klikqris.example/storage/qris_api/qris_INV-KLIK-PROD.png';
        $fallbackImage = KlikQrisSandboxScenarios::pngDataUri('production-fallback');

        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'en']);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-KLIK-PROD',
            'order_status' => OrderStatus::PendingPayment,
            'manual_checkout_customer_notified_at' => null,
            'expires_at' => now()->addMinutes(14),
        ]);
        $payment = Payment::factory()->for($order)->create([
            'provider' => 'klikqris',
            'provider_payment_method' => 'qris',
            'merchant_ref' => 'INV-KLIK-PROD',
            'amount' => 1000,
            'status' => PaymentStatus::Pending,
        ]);

        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 654321]]),
            'https://api.klikqris.example/qris/create' => function (Request $request) use ($fallbackImage, $qrisUrl) {
                return Http::response(['data' => [
                    'order_id' => $request['order_id'],
                    'amount' => 1000,
                    'total_amount' => 1016,
                    'status' => 'PENDING',
                    'qris_url' => $qrisUrl,
                    'qris_image' => $fallbackImage,
                    'signature' => 'production-signature',
                    'expired_at' => now()->addMinutes(15)->toISOString(),
                ]], 200);
            },
            $qrisUrl => Http::response('not-a-png-body', 200, ['Content-Type' => 'image/png']),
        ]);

        (new SendKlikQrisCheckoutInstructions($order->id))->handle(app(TelegramCheckoutIntents::class));

        $sendPhotos = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertCount(1, $sendPhotos);
        $this->assertStringContainsString("\x89PNG\r\n\x1A\nproduction-fallback", $sendPhotos->first()[0]->body());
        $this->assertSame('created', $payment->fresh()->provider_create_state);
        $this->assertStringNotContainsString($fallbackImage, json_encode($payment->fresh()->provider_payload, JSON_THROW_ON_ERROR));
    }

    public function test_existing_production_payable_recovers_qris_image_from_status_after_bad_qris_url(): void
    {
        $this->travelTo('2026-08-13 10:10:00 UTC');
        KlikQrisSandboxScenarios::configureReadyProduction();
        app()->detectEnvironment(fn (): string => 'production');
        $qrisUrl = 'https://api.klikqris.example/storage/qris_api/qris_INV-KLIK-PROD-RETRY.png';
        $fallbackImage = KlikQrisSandboxScenarios::pngDataUri('production-retry-fallback');
        $expiresAt = now()->addMinutes(15)->startOfSecond();

        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'en']);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-KLIK-PROD-RETRY',
            'order_status' => OrderStatus::PendingPayment,
            'manual_checkout_customer_notified_at' => null,
            'expires_at' => $expiresAt->copy()->subMinute(),
        ]);
        $payment = Payment::factory()->for($order)->create([
            'provider' => 'klikqris',
            'provider_payment_method' => 'qris',
            'merchant_ref' => 'INV-KLIK-PROD-RETRY',
            'amount' => 1000,
            'provider_total_payment' => 1016,
            'provider_fee' => 16,
            'provider_expires_at' => $expiresAt,
            'expired_at' => $expiresAt,
            'status' => PaymentStatus::Pending,
            'provider_create_state' => 'created',
            'provider_payload' => ['data' => [
                'order_id' => 'INV-KLIK-PROD-RETRY',
                'amount' => 1000,
                'total_amount' => '1016.00',
                'status' => 'PENDING',
                'qris_url' => $qrisUrl,
                'expired_at' => $expiresAt->toISOString(),
            ]],
        ]);

        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 654322]]),
            $qrisUrl => Http::response('not-a-png-body', 200, ['Content-Type' => 'image/png']),
            'https://api.klikqris.example/qris/status/INV-KLIK-PROD-RETRY' => Http::response(['data' => [
                'order_id' => 'INV-KLIK-PROD-RETRY',
                'amount' => 1000,
                'total_amount' => '1016.00',
                'status' => 'PENDING',
                'qris_url' => $qrisUrl,
                'qris_image' => $fallbackImage,
                'expired_at' => $expiresAt->toISOString(),
                'signature' => 'production-signature',
            ]], 200),
        ]);

        (new SendKlikQrisCheckoutInstructions($order->id))->handle(app(TelegramCheckoutIntents::class));

        $sendPhotos = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertCount(1, $sendPhotos);
        $this->assertStringContainsString("\x89PNG\r\n\x1A\nproduction-retry-fallback", $sendPhotos->first()[0]->body());
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/qris/status/INV-KLIK-PROD-RETRY'));
        $this->assertStringNotContainsString($fallbackImage, json_encode($payment->fresh()->provider_payload, JSON_THROW_ON_ERROR));
    }

    public function test_base64_only_create_is_never_sent_and_job_sends_retry_state_until_recovery(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        KlikQrisSandboxScenarios::configureReadySandbox();
        $base64 = KlikQrisSandboxScenarios::pngDataUri('telegram-base64-only');
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 654321]]),
            'https://klikqris.com/api/sandbox/qris/create' => function (Request $request) use ($base64) {
                return Http::response([
                    'data' => [
                        'order_id' => $request['order_id'],
                        'amount' => 1000,
                        'total_amount' => 1016,
                        'status' => 'PENDING',
                        'qris_url' => null,
                        'qris_image' => $base64,
                        'id_merchant' => 'MERCHANT_SECRET_TELEGRAM',
                        'signature' => 'TOKEN_SHOULD_NOT_LEAK',
                        'expired_at' => now()->addMinutes(15)->toISOString(),
                    ],
                ]);
            },
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'en']);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-KLIK-B64',
            'order_status' => OrderStatus::PendingPayment,
            'expires_at' => now()->addMinutes(14),
        ]);
        $payment = Payment::factory()->for($order)->create([
            'provider' => 'klikqris',
            'provider_payment_method' => 'qris',
            'merchant_ref' => 'INV-KLIK-B64',
            'amount' => 1000,
            'status' => PaymentStatus::Pending,
        ]);

        (new SendKlikQrisCheckoutInstructions($order->id))->handle(app(TelegramCheckoutIntents::class));

        $fresh = $payment->fresh();
        $payloadJson = json_encode($fresh->provider_payload, JSON_THROW_ON_ERROR);
        $this->assertSame('transient_qris_image', $fresh->provider_create_state);
        $this->assertFalse($fresh->isPayableNativeKlikQris());
        $this->assertStringNotContainsString($base64, $payloadJson);
        $this->assertStringNotContainsString('MERCHANT_SECRET_TELEGRAM', $payloadJson);

        $sendPhotos = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertCount(0, $sendPhotos);
        $sendMessages = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'));
        $this->assertCount(1, $sendMessages);
        $messageRequest = $sendMessages->first()[0];
        $this->assertStringContainsString('final payable amount', $messageRequest->body());
        $this->assertStringContainsString('Check Payment', $messageRequest->body());
        $this->assertStringNotContainsString($base64, $messageRequest->body());
        $this->assertStringNotContainsString('MERCHANT_SECRET_TELEGRAM', $messageRequest->body());
        $this->assertNotNull($order->fresh()->manual_checkout_customer_notified_at);
    }

    public function test_klikqris_job_releases_claim_after_definite_send_failure_and_keeps_provider_transaction(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $order = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::PendingPayment,
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
        $this->assertNull($order->fresh()->manual_checkout_customer_notified_at);

        TelegramOutboundEffect::query()->sole()->forceFill([
            'next_attempt_at' => now()->subSecond(),
        ])->save();

        $successfulIntents = $this->mock(TelegramCheckoutIntents::class);
        $successfulIntents->shouldReceive('sendKlikQrisPaymentInstructions')
            ->once()
            ->with('12345', Mockery::type(Order::class), Mockery::on(fn (Payment $argument): bool => $argument->is($payment)))
            ->andReturn(['ok' => true, 'result' => ['message_id' => 123456]]);
        $job->handle($successfulIntents);
        $this->assertNotNull($order->fresh()->manual_checkout_customer_notified_at);
        $this->assertSame(1, $order->payments()->where('provider', 'klikqris')->count());
    }

    public function test_recovery_redispatches_stale_klikqris_checkout_claim_as_klikqris(): void
    {
        config()->set('services.telegram.claim_lease_seconds', 60);
        Queue::fake();
        $order = Order::factory()->create([
            'order_status' => OrderStatus::PendingPayment,
            'manual_checkout_customer_notified_at' => null,
        ]);
        Payment::factory()->for($order)->create(['provider' => 'klikqris']);

        $this->artisan('telegram:recover-outbound')
            ->expectsOutputToContain('telegram:recover-outbound completed cleanly: dispatched=1')
            ->assertSuccessful();

        Queue::assertPushed(
            SendKlikQrisCheckoutInstructions::class,
            fn (SendKlikQrisCheckoutInstructions $job): bool => $job->orderId === $order->id,
        );
        Queue::assertNotPushed(SendManualCheckoutNotifications::class);
        Queue::assertNotPushed(SendBinanceCheckoutInstructions::class);
    }

    public function test_recovery_skips_expired_klikqris_checkout_claim(): void
    {
        config()->set('services.telegram.claim_lease_seconds', 60);
        Queue::fake();
        $order = Order::factory()->create([
            'order_status' => OrderStatus::PendingPayment,
            'expires_at' => now()->subSecond(),
            'manual_checkout_customer_notified_at' => null,
        ]);
        Payment::factory()->for($order)->create(['provider' => 'klikqris']);

        $this->artisan('telegram:recover-outbound')
            ->expectsOutputToContain('telegram:recover-outbound completed cleanly: dispatched=0')
            ->assertSuccessful();

        Queue::assertNotPushed(SendKlikQrisCheckoutInstructions::class);
    }

    public function test_delayed_klikqris_job_does_not_create_or_send_after_local_order_deadline(): void
    {
        $order = Order::factory()->create([
            'order_status' => OrderStatus::PendingPayment,
            'expires_at' => now()->subSecond(),
            'manual_checkout_customer_notified_at' => null,
        ]);
        Payment::factory()->for($order)->create(['provider' => 'klikqris']);
        $intents = $this->mock(TelegramCheckoutIntents::class);
        $intents->shouldNotReceive('sendKlikQrisPaymentInstructions');

        (new SendKlikQrisCheckoutInstructions($order->id))->handle($intents);

        $this->assertNull($order->fresh()->manual_checkout_customer_notified_at);
    }

    public function test_telegram_order_detail_refreshes_klikqris_success_before_rendering_without_calling_pakasir(): void
    {
        KlikQrisSandboxScenarios::configureReadySandbox();
        $this->mock(PakasirProviderAdapter::class)
            ->shouldReceive('transactionDetail')
            ->never();
        $this->fakeTelegramHttp([
            'https://klikqris.com/api/sandbox/qris/status/INV-KLIK-DETAIL' => Http::response(['data' => [
                'order_id' => 'INV-KLIK-DETAIL',
                'amount' => 1000,
                'total_amount' => 1016,
                'status' => 'SUCCESS',
                'paid_at' => '2026-07-28T10:10:00+00:00',
                'signature' => 'secret-signature-token',
            ]], 200),
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'en']);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-KLIK-DETAIL',
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'total_amount' => 1000,
        ]);
        Payment::factory()->for($order)->create([
            'provider' => 'klikqris',
            'provider_payment_method' => 'qris',
            'merchant_ref' => 'INV-KLIK-DETAIL',
            'amount' => 1000,
            'provider_total_payment' => 1016,
            'status' => PaymentStatus::Pending,
            'provider_payload' => ['data' => [
                'order_id' => 'INV-KLIK-DETAIL',
                'amount' => 1000,
                'total_amount' => 1016,
                'status' => 'PENDING',
                'qris_url' => 'https://klikqris.com/storage/sandbox/qris_detail.png',
                'expired_at' => '2026-07-28T10:24:00+00:00',
            ]],
            'provider_create_state' => 'created',
        ]);

        $this->postTelegramWebhook($this->callbackUpdate(9001, 'cb-detail', 'order:INV-KLIK-DETAIL'))
            ->assertOk();
        $this->processQueuedTelegramWebhookJob();

        $this->assertSame(PaymentStatus::Paid, $order->payments()->first()->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $sendMessages = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'));
        $this->assertNotEmpty($sendMessages);
        $detailBody = $sendMessages->last()[0]->body();
        $this->assertStringContainsString('Payment has been received', $detailBody);
        $this->assertStringNotContainsString('secret-signature-token', $detailBody);
    }

    public function test_telegram_klikqris_pending_outage_and_rate_limit_return_current_detail_safely(): void
    {
        KlikQrisSandboxScenarios::configureReadySandbox();
        config()->set('services.klikqris.status_refresh_rate_limit_per_minute', 1);
        $this->mock(PakasirProviderAdapter::class)
            ->shouldReceive('transactionDetail')
            ->never();
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 654321]]),
            'https://klikqris.com/api/sandbox/qris/status/INV-KLIK-PENDING' => Http::sequence()
                ->push(['data' => [
                    'order_id' => 'INV-KLIK-PENDING',
                    'amount' => 1000,
                    'total_amount' => 1016,
                    'status' => 'PENDING',
                    'signature' => 'secret-signature-token',
                ]], 200)
                ->pushStatus(500),
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'en']);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-KLIK-PENDING',
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'total_amount' => 1000,
        ]);
        Payment::factory()->for($order)->create([
            'provider' => 'klikqris',
            'provider_payment_method' => 'qris',
            'merchant_ref' => 'INV-KLIK-PENDING',
            'amount' => 1000,
            'provider_total_payment' => 1016,
            'status' => PaymentStatus::Pending,
            'provider_payload' => ['data' => [
                'order_id' => 'INV-KLIK-PENDING',
                'amount' => 1000,
                'total_amount' => 1016,
                'status' => 'PENDING',
                'qris_url' => 'https://klikqris.com/storage/sandbox/qris_pending.png',
                'expired_at' => '2026-07-28T10:24:00+00:00',
            ]],
            'provider_create_state' => 'created',
        ]);

        $this->postTelegramWebhook($this->callbackUpdate(9002, 'cb-pending', 'order:INV-KLIK-PENDING'))->assertOk();
        $this->processQueuedTelegramWebhookJob();
        $this->postTelegramWebhook($this->callbackUpdate(9003, 'cb-limited', 'order:INV-KLIK-PENDING'))->assertOk();
        $this->processQueuedTelegramWebhookJob();

        $this->assertSame(PaymentStatus::Pending, $order->payments()->first()->fresh()->status);
        $sendMessages = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'));
        $body = $sendMessages->last()[0]->body();
        $this->assertStringContainsString('temporarily limited', $body);
        $this->assertStringContainsString('awaiting payment', $body);
        $this->assertStringNotContainsString('secret-signature-token', $body);
        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/qris/status/INV-KLIK-PENDING');
        });
        $freshOrder = $order->fresh();
        $this->assertSame('12345', $freshOrder->buyer_order_detail_message_chat_id);
        $this->assertSame(654321, $freshOrder->buyer_order_detail_message_id);
    }

    public function test_expired_klikqris_order_notification_offers_fresh_qris_checkout_button(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 88]]),
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'en']);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-KLIK-EXPIRED',
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'total_amount' => 1000,
            'expires_at' => now()->subMinute(),
        ]);
        Payment::factory()->for($order)->create([
            'provider' => 'klikqris',
            'provider_payment_method' => 'qris',
            'merchant_ref' => 'INV-KLIK-EXPIRED',
            'amount' => 1000,
            'provider_total_payment' => 1016,
            'provider_expires_at' => now()->subMinute(),
            'status' => PaymentStatus::Pending,
            'provider_payload' => ['data' => [
                'order_id' => 'INV-KLIK-EXPIRED',
                'amount' => 1000,
                'total_amount' => 1016,
                'status' => 'PENDING',
                'qris_url' => 'https://klikqris.com/storage/sandbox/qris_expired.png',
                'expired_at' => now()->subMinute()->toAtomString(),
            ]],
            'provider_create_state' => 'created',
        ]);

        $this->artisan('orders:expire-pending')->assertSuccessful();

        $sendMessages = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'));
        $body = $sendMessages->last()[0]->body();
        $this->assertStringContainsString('Order expired', $body);
        $this->assertStringContainsString('Create New QRIS', $body);
        $this->assertStringContainsString('callback_data":"katalog', $body);
        $this->assertStringNotContainsString('qris_expired.png', $body);
        $this->assertStringNotContainsString('secret-signature-token', $body);
    }

    private function processQueuedTelegramWebhookJob(): void
    {
        $this->artisan('queue:work --once --queue=default --stop-when-empty')->assertSuccessful();
    }
}
