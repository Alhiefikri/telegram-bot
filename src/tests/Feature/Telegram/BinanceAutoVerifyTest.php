<?php

namespace Tests\Feature\Telegram;

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\VerifyBinanceOrderId;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Services\Binance\BinanceAutoVerification;
use App\Services\Binance\BinanceOrderIdActivation;
use App\Services\Binance\BinanceUsdtAmount;
use App\Services\Binance\BinanceVerificationOutcome;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\OrderLifecycle;
use App\Services\Telegram\TelegramBinanceVerifyIntents;
use App\Services\Telegram\TelegramCheckoutIntents;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TelegramBotTestSupport;

class BinanceAutoVerifyTest extends TelegramBotTestSupport
{
    private const BASE_USDT_MICRO = 5_000_000;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.binance', [
            'pay_id' => '987654321',
            'pay_uid' => null,
            'verification_mode' => 'order_id',
            'api_key' => 'binance-key',
            'api_secret' => 'binance-secret',
            'base_url' => 'https://api.binance.test',
            'http_timeout_seconds' => 10,
            'http_connect_timeout_seconds' => 3,
            'recv_window_ms' => 10000,
            'history_max_attempts' => 1,
            'history_retry_delay_ms' => 0,
            'history_cache_seconds' => 20,
            'history_lock_seconds' => 5,
            'verify_clock_skew_seconds' => 0,
            'verify_rate_limit_attempts' => 5,
            'verify_rate_limit_decay_seconds' => 300,
        ]);
        Cache::forget('binance:pay-history:recent');
    }

    public function test_usdt_amount_parsing_is_exact_and_rejects_unrepresentable_values(): void
    {
        $this->assertSame(800_000, BinanceUsdtAmount::toMicro('0.8'));
        $this->assertSame(2_500_000, BinanceUsdtAmount::toMicro('2.5'));
        $this->assertSame(502_814, BinanceUsdtAmount::toMicro('0.502814'));
        $this->assertSame(-800_000, BinanceUsdtAmount::toMicro('-0.8'));
        $this->assertSame(5_000_000, BinanceUsdtAmount::toMicro('5'));
        $this->assertSame(1_200_000, BinanceUsdtAmount::toMicro('1.2000000'));
        $this->assertNull(BinanceUsdtAmount::toMicro('1.0000001'));
        $this->assertNull(BinanceUsdtAmount::toMicro('abc'));
        $this->assertNull(BinanceUsdtAmount::toMicro(''));
        $this->assertNull(BinanceUsdtAmount::toMicro('1.2.3'));
        $this->assertNull(BinanceUsdtAmount::toMicro(str_repeat('9', 100)));
    }

    public function test_binance_checkout_uses_the_exact_amount_when_auto_verify_is_configured(): void
    {
        $this->enableBinancePay();

        $first = $this->createBinanceOrder(telegramId: 12345);
        $second = $this->createBinanceOrder(telegramId: 54321);

        foreach ([$first, $second] as [, $payment]) {
            $this->assertSame(self::BASE_USDT_MICRO, $payment->amount);
            $this->assertTrue($payment->binance_auto_verification_enabled);
        }

        $this->assertSame($first[1]->amount, $second[1]->amount);
    }

    public function test_binance_checkout_preserves_exact_three_decimal_product_price(): void
    {
        $this->enableBinancePay();

        [$order, $payment] = $this->createBinanceOrder(telegramId: 12345);

        $this->assertSame($order->total_amount, 50_000);
        $this->assertSame(self::BASE_USDT_MICRO, $payment->amount);
    }

    public function test_binance_checkout_keeps_exact_amount_without_api_credentials(): void
    {
        config()->set('services.binance.api_key', null);
        config()->set('services.binance.api_secret', null);
        $this->enableBinancePay();

        [, $payment] = $this->createBinanceOrder();

        $this->assertSame(self::BASE_USDT_MICRO, $payment->amount);
    }

    public function test_database_allows_duplicate_pending_binance_amounts(): void
    {
        $this->enableBinancePay();
        [, $payment] = $this->createBinanceOrder();
        $otherOrder = Order::factory()->create();

        $duplicate = Payment::factory()->create([
            'order_id' => $otherOrder->id,
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'binance_auto_verification_enabled' => true,
            'amount' => $payment->amount,
            'currency' => 'USDT',
            'status' => PaymentStatus::Pending,
        ]);

        $this->assertSame($payment->amount, $duplicate->amount);
    }

    public function test_binance_migration_refuses_rollback_when_duplicate_pending_amounts_exist(): void
    {
        $this->enableBinancePay();
        $this->createBinanceOrder(telegramId: 12345);
        $this->createBinanceOrder(telegramId: 54321);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('duplicate pending Binance amounts');

        $migration = require database_path('migrations/2026_08_01_000001_add_binance_order_id_verification_fields.php');
        $migration->down();
    }

    public function test_instruction_message_offers_ive_paid_button_when_auto_verify_is_enabled(): void
    {
        $this->fakeTelegramHttp();
        $this->enableBinancePay();
        [$order, $payment, $customer] = $this->createBinanceOrder();

        app(TelegramCheckoutIntents::class)->sendBinancePaymentInstructions('12345', $order, $payment, $customer);

        $photoSends = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertCount(1, $photoSends);
        $body = $photoSends->first()[0]->body();
        $this->assertStringContainsString('binance:verify:'.$order->id, $body);
        $this->assertStringContainsString("I've Paid", $body);
        $this->assertStringContainsString('buyer:cancel-pending:'.$order->id, $body);
        $this->assertStringContainsString('caption_entities', $body);
        $this->assertStringContainsString('"type":"code"', $body);
        $this->assertStringContainsString('⚠️ IMPORTANT: Send exactly', $body);
        $this->assertStringContainsString('including every decimal digit', $body);
        $this->assertStringContainsString('Do not round or change the amount.', $body);
        $this->assertStringContainsString('Order Details', $body);
        $this->assertStringContainsString('send it in this chat', $body);
        $this->assertStringNotContainsString('screenshot', $body);
    }

    public function test_old_payment_snapshot_keeps_manual_screenshot_flow_after_credentials_are_added(): void
    {
        config()->set('services.binance.api_key', null);
        config()->set('services.binance.api_secret', null);
        $this->enableBinancePay();
        [$order, $payment, $customer] = $this->createBinanceOrder();
        config()->set('services.binance.api_key', 'binance-key');
        config()->set('services.binance.api_secret', 'binance-secret');
        $this->fakeTelegramHttp();

        app(TelegramCheckoutIntents::class)->sendBinancePaymentInstructions((string) $customer->telegram_id, $order, $payment, $customer);

        $body = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendPhoto'))->first()[0]->body();
        $this->assertStringContainsString('/payment', $body);
        $this->assertStringNotContainsString('binance:verify:', $body);
    }

    public function test_ive_paid_tap_only_prompts_for_the_required_order_id(): void
    {
        $this->fakeTelegramHttp();
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();

        $this->postTelegramWebhook($this->callbackUpdate(305, 'binance-verify-prompt', 'binance:verify:'.$order->id))->assertOk();

        $binanceCalls = Http::recorded(fn ($request) => str_contains($request->url(), 'api.binance.test'));
        $this->assertCount(0, $binanceCalls);
        $this->assertNull($payment->fresh()->binance_order_id);

        $prompt = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Please send the Binance Pay Order ID'));
        $this->assertCount(1, $prompt);
    }

    public function test_order_id_submission_is_persisted_and_queues_retry_when_not_found(): void
    {
        Queue::fake([VerifyBinanceOrderId::class]);
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response($this->binanceHistory([])),
        ]);

        $this->postTelegramWebhook($this->callbackUpdate(306, 'binance-order-id-prompt', 'binance:verify:'.$order->id))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(307, '445011487581044736'))->assertOk();

        $stored = $payment->fresh();
        $this->assertSame('445011487581044736', $stored->binance_order_id);
        $this->assertSame(1, $stored->binance_order_id_attempts);
        $this->assertNotNull($stored->binance_order_id_submitted_at);
        $this->assertTrue($stored->binance_verification_grace_until->equalTo($payment->expired_at->copy()->addMinutes(3)));
        Queue::assertPushed(VerifyBinanceOrderId::class, fn (VerifyBinanceOrderId $job): bool => $job->paymentId === $payment->id
            && $job->attemptNumber === 1);
    }

    public function test_order_id_submission_persists_then_dispatches_without_provider_http_in_the_request(): void
    {
        Queue::fake([VerifyBinanceOrderId::class]);
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $this->fakeTelegramHttp();

        $this->submitOrderId($order, '445011487581044736', 364, 365);

        $stored = $payment->fresh();
        $this->assertSame('checking', $stored->binance_verification_status);
        $this->assertSame(1, $stored->binance_order_id_submission_version);
        $this->assertCount(0, Http::recorded(fn ($request) => str_contains($request->url(), 'api.binance.test')));
        Queue::assertPushed(VerifyBinanceOrderId::class, fn (VerifyBinanceOrderId $job): bool => $job->queue === 'binance-verification'
            && $job->submissionVersion === 1);
    }

    public function test_transaction_id_compatibility_alias_can_confirm_only_after_all_payment_checks_pass(): void
    {
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response($this->binanceHistory([[
                'orderId' => '',
                'transactionId' => '445011487581044736',
                'orderType' => 'C2C',
                'amount' => $this->usdtString($payment->amount),
                'currency' => 'USDT',
                'transactionTime' => now()->getTimestampMs(),
                'receiverInfo' => ['accountId' => '987654321'],
            ]])),
        ]);

        $this->submitOrderId($order, '445011487581044736', 360, 361);

        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame('445011487581044736', $payment->fresh()->provider_reference);
    }

    public function test_transaction_after_original_payment_deadline_never_confirms_during_technical_grace(): void
    {
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $deadline = now()->subMinute();
        $order->update(['expires_at' => $deadline]);
        $payment->update([
            'expired_at' => $deadline,
            'binance_order_id' => '445011487581044736',
            'binance_order_id_attempts' => 1,
            'binance_order_id_submitted_at' => $deadline->copy()->subSecond(),
            'binance_verification_grace_until' => now()->addMinute(),
        ]);
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response($this->binanceHistory([
                $this->binanceTransaction('445011487581044736', $this->usdtString($payment->amount), now()->getTimestampMs()),
            ])),
        ]);

        $result = app(BinanceAutoVerification::class)->verifyPaymentByProviderOrderId($payment, '445011487581044736');

        $this->assertNotSame(BinanceVerificationOutcome::Confirmed, $result->outcome);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertNull($payment->fresh()->provider_reference);
    }

    public function test_uid_receiver_is_checked_against_binance_pay_uid_not_pay_id(): void
    {
        config()->set('services.binance.pay_id', null);
        config()->set('services.binance.pay_uid', '123456789');
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response($this->binanceHistory([[
                'orderId' => '445011487581044736',
                'transactionId' => 'P_UID_RECEIVER',
                'orderType' => 'C2C',
                'amount' => $this->usdtString($payment->amount),
                'currency' => 'USDT',
                'transactionTime' => now()->getTimestampMs(),
                'receiverInfo' => ['binanceId' => '123456789'],
            ]])),
        ]);

        $this->submitOrderId($order, '445011487581044736', 362, 363);

        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
    }

    public function test_matching_order_id_submission_confirms_the_payment(): void
    {
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response($this->binanceHistory([
                $this->binanceTransaction('445011487581044736', $this->usdtString($payment->amount), now()->getTimestampMs()),
            ])),
        ]);

        $this->postTelegramWebhook($this->callbackUpdate(308, 'binance-order-id-match-prompt', 'binance:verify:'.$order->id))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(309, '445011487581044736'))->assertOk();

        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame('445011487581044736', $payment->fresh()->binance_order_id);
    }

    public function test_only_three_valid_order_ids_are_accepted(): void
    {
        Queue::fake([VerifyBinanceOrderId::class]);
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response($this->binanceHistory([])),
        ]);

        $this->postTelegramWebhook($this->callbackUpdate(310, 'binance-order-id-limit-prompt', 'binance:verify:'.$order->id))->assertOk();
        foreach (['445011487581044736', '445011487581044737', '445011487581044738', '445011487581044739'] as $updateId => $orderId) {
            $this->postTelegramWebhook($this->messageUpdate(311 + $updateId, $orderId))->assertOk();
        }

        $this->assertSame(3, $payment->fresh()->binance_order_id_attempts);
        $this->assertSame('445011487581044738', $payment->fresh()->binance_order_id);
        $limitMessage = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'maximum of 3'));
        $this->assertCount(1, $limitMessage);
    }

    public function test_delayed_retry_confirms_when_the_transaction_appears_after_initial_not_found(): void
    {
        Queue::fake([VerifyBinanceOrderId::class]);
        config()->set('services.binance.history_cache_seconds', 0);
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::sequence()
                ->push($this->binanceHistory([]))
                ->push($this->binanceHistory([
                    $this->binanceTransaction('445011487581044736', $this->usdtString($payment->amount), now()->getTimestampMs()),
                ])),
        ]);

        $this->postTelegramWebhook($this->callbackUpdate(315, 'binance-retry-prompt', 'binance:verify:'.$order->id))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(316, '445011487581044736'))->assertOk();

        $queued = null;
        Queue::assertPushed(VerifyBinanceOrderId::class, function (VerifyBinanceOrderId $job) use (&$queued): bool {
            $queued = $job;

            return true;
        });
        $this->assertNotNull($queued);
        $queued->handle(app(TelegramBinanceVerifyIntents::class));

        if ($order->fresh()->order_status !== OrderStatus::Completed) {
            $retry = null;
            Queue::assertPushed(VerifyBinanceOrderId::class, function (VerifyBinanceOrderId $job) use (&$retry): bool {
                if ($job->retryNumber > 1) {
                    $retry = $job;
                }

                return true;
            });
            $this->assertNotNull($retry);
            $retry->handle(app(TelegramBinanceVerifyIntents::class));
        }

        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    public function test_retry_for_a_replaced_order_id_is_a_noop(): void
    {
        Queue::fake([VerifyBinanceOrderId::class]);
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response($this->binanceHistory([])),
        ]);

        $this->postTelegramWebhook($this->callbackUpdate(317, 'binance-replace-prompt', 'binance:verify:'.$order->id))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(318, '445011487581044736'))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(319, 'binance-replace-order-id', 'binance:orderid:'.$order->id))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(320, '445011487581044737'))->assertOk();

        (new VerifyBinanceOrderId($payment->id, '445011487581044736', 1))->handle(app(TelegramBinanceVerifyIntents::class));

        $this->assertSame(2, $payment->fresh()->binance_order_id_attempts);
        $this->assertSame('445011487581044737', $payment->fresh()->binance_order_id);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
    }

    public function test_stale_job_cannot_record_mismatch_after_the_same_order_id_is_resubmitted(): void
    {
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $payment->update([
            'binance_order_id' => '445011487581044736',
            'binance_order_id_attempts' => 2,
            'binance_order_id_submission_version' => 2,
            'binance_order_id_submitted_at' => now(),
            'binance_verification_grace_until' => now()->addMinutes(3),
        ]);
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response($this->binanceHistory([
                $this->binanceTransaction('445011487581044736', '0.1', now()->getTimestampMs()),
            ])),
        ]);

        $result = app(BinanceAutoVerification::class)->verifyPaymentByProviderOrderId($payment, '445011487581044736', 1);

        $this->assertSame(BinanceVerificationOutcome::StaleSubmission, $result->outcome);
        $fresh = $payment->fresh();
        $this->assertNull($fresh->provider_payload);
        $this->assertTrue($fresh->binance_verification_grace_until->isFuture());
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
    }

    public function test_ive_paid_tap_confirms_matching_payment_and_completes_order(): void
    {
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response($this->binanceHistory([
                $this->binanceTransaction('445011487581044736', $this->usdtString($payment->amount), now()->getTimestampMs()),
            ])),
        ]);

        $this->submitOrderId($order, '445011487581044736', 301, 302);

        $order->refresh();
        $payment->refresh();
        $this->assertSame(OrderStatus::Completed, $order->order_status);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame('P_487581044736', $payment->provider_reference);
        $this->assertNotNull($payment->paid_at);

        $event = PaymentEvent::query()->where('provider', 'binance')->sole();
        $this->assertSame($payment->id, $event->payment_id);
        $this->assertSame('P_48••••4736', $event->provider_reference);
        $this->assertNotNull($event->processed_at);

        $confirmMessages = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Payment confirmed'));
        $this->assertCount(1, $confirmMessages);
    }

    public function test_order_id_verification_requires_a_provider_order_id_in_the_transaction(): void
    {
        Queue::fake([VerifyBinanceOrderId::class]);
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response($this->binanceHistory([[
                'transactionId' => 'P_TRANSACTION_ONLY',
                'orderType' => 'C2C',
                'amount' => $this->usdtString($payment->amount),
                'currency' => 'USDT',
                'transactionTime' => now()->getTimestampMs(),
                'payerInfo' => ['name' => 'Buyer'],
                'receiverInfo' => ['accountId' => '987654321'],
            ]])),
        ]);

        $this->submitOrderId($order, '445011487581044736', 302, 303);

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertNull($payment->fresh()->provider_reference);
    }

    public function test_ive_paid_tap_rejects_outgoing_wrong_amount_and_escalates_stale_or_wrong_currency_matches(): void
    {
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $exact = $this->usdtString($payment->amount);
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response($this->binanceHistory([
                // Outgoing transfer of the exact amount (we sent it, not the buyer).
                $this->binanceTransaction('445000000000000001', '-'.$exact, now()->getTimestampMs()),
                // Incoming transfer with the wrong amount.
                $this->binanceTransaction('445000000000000002', '0.1', now()->getTimestampMs()),
                // Incoming exact amount, but from before the order existed.
                $this->binanceTransaction('445000000000000003', $exact, now()->subMinutes(10)->getTimestampMs()),
                // Incoming exact amount in the wrong currency.
                $this->binanceTransaction('445000000000000004', $exact, now()->getTimestampMs(), 'BUSD'),
            ])),
        ]);

        $this->submitOrderId($order, '445000000000000002', 304, 305);

        $order->refresh();
        $this->assertSame(OrderStatus::PendingPayment, $order->order_status);
        $this->assertSame(PaymentStatus::Pending, $order->payments()->sole()->status);
        $this->assertNull($order->payments()->sole()->provider_reference);

        $manualCheck = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'manual check'));
        $this->assertCount(1, $manualCheck);

        $adminNotice = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '999'
            && str_contains((string) ($request['text'] ?? ''), $order->invoice_number));
        $this->assertCount(1, $adminNotice);
    }

    public function test_positive_amount_sent_to_another_receiver_never_completes_the_order(): void
    {
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response($this->binanceHistory([
                $this->binanceTransaction(
                    '445000000000000009',
                    $this->usdtString($payment->amount),
                    now()->getTimestampMs(),
                    receiverPayId: '111222333',
                    receiverBinanceId: '987654321',
                ),
            ])),
        ]);

        $this->submitOrderId($order, '445000000000000009', 306, 307);

        $order->refresh();
        $this->assertSame(OrderStatus::PendingPayment, $order->order_status);
        $this->assertSame(PaymentStatus::Pending, $order->payments()->sole()->status);
        $this->assertNull($order->payments()->sole()->provider_reference);
    }

    public function test_exact_amount_with_wrong_currency_escalates_instead_of_becoming_not_found(): void
    {
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response($this->binanceHistory([
                $this->binanceTransaction(
                    '445000000000000010',
                    $this->usdtString($payment->amount),
                    now()->getTimestampMs(),
                    currency: 'BUSD',
                ),
            ])),
        ]);

        $this->submitOrderId($order, '445000000000000010', 308, 309);

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $adminNotice = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '999'
            && str_contains((string) ($request['text'] ?? ''), $order->invoice_number));
        $this->assertCount(1, $adminNotice);
    }

    public function test_exact_payment_from_before_checkout_never_completes_the_new_order(): void
    {
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response($this->binanceHistory([
                $this->binanceTransaction(
                    '445000000000000011',
                    $this->usdtString($payment->amount),
                    now()->subSeconds(30)->getTimestampMs(),
                ),
            ])),
        ]);

        $this->submitOrderId($order, '445000000000000011', 310, 311);

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertNull($payment->fresh()->provider_reference);
    }

    public function test_one_transaction_can_never_pay_two_orders(): void
    {
        $this->enableBinancePay();
        [$orderA, $paymentA] = $this->createBinanceOrder(telegramId: 12345);
        $transaction = $this->binanceTransaction('445099999999999999', $this->usdtString($paymentA->amount), now()->getTimestampMs());
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response($this->binanceHistory([$transaction])),
        ]);

        $this->submitOrderId($orderA, '445099999999999999', 312, 313);
        $this->assertSame(PaymentStatus::Paid, $paymentA->fresh()->status);

        // A second order that happens to share the same payable amount must not
        // be able to reuse the transaction that already paid order A.
        [$orderB, $paymentB] = $this->createBinanceOrder(telegramId: 54321);
        $paymentB->update(['amount' => $paymentA->amount]);

        $this->postTelegramWebhook($this->callbackUpdate(314, 'binance-verify-b', 'binance:verify:'.$orderB->id, chatId: 54321))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(315, '445099999999999999', chatId: 54321))->assertOk();

        $orderB->refresh();
        $this->assertSame(OrderStatus::PendingPayment, $orderB->order_status);
        $this->assertSame(PaymentStatus::Pending, $paymentB->fresh()->status);
        $this->assertNull($paymentB->fresh()->provider_reference);

        $buyerNotice = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '54321'
            && str_contains((string) ($request['text'] ?? ''), 'manual check'));
        $this->assertCount(1, $buyerNotice);

        $adminNotice = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '999'
            && str_contains((string) ($request['text'] ?? ''), 'Verifikasi Binance butuh perhatian')
            && str_contains((string) ($request['text'] ?? ''), $orderB->invoice_number));
        $this->assertCount(1, $adminNotice);
    }

    public function test_order_id_submissions_are_rate_limited(): void
    {
        Queue::fake([VerifyBinanceOrderId::class]);
        config()->set('services.binance.verify_rate_limit_attempts', 2);
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response($this->binanceHistory([])),
        ]);

        $this->postTelegramWebhook($this->callbackUpdate(316, 'binance-rate-limit-prompt', 'binance:verify:'.$order->id))->assertOk();
        foreach (['445000000000000031', '445000000000000032', '445000000000000033'] as $offset => $candidate) {
            $this->postTelegramWebhook($this->messageUpdate(317 + $offset, $candidate))->assertOk();
        }

        $binanceCalls = Http::recorded(fn ($request) => str_contains($request->url(), 'api.binance.test'));
        $this->assertCount(0, $binanceCalls);

        $throttled = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Too many attempts'));
        $this->assertCount(1, $throttled);
        $this->assertSame(2, $payment->fresh()->binance_order_id_attempts);
    }

    public function test_recent_history_is_shared_across_orders_to_protect_the_high_weight_endpoint(): void
    {
        $this->enableBinancePay();
        [$firstOrder, $firstPayment] = $this->createBinanceOrder(telegramId: 12345);
        [$secondOrder, $secondPayment] = $this->createBinanceOrder(telegramId: 54321);
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response($this->binanceHistory([
                $this->binanceTransaction('445000000000000021', $this->usdtString($firstPayment->amount), now()->getTimestampMs()),
                $this->binanceTransaction('445000000000000022', $this->usdtString($secondPayment->amount), now()->getTimestampMs()),
            ])),
        ]);

        $this->submitOrderId($firstOrder, '445000000000000021', 318, 319);
        $this->postTelegramWebhook($this->callbackUpdate(320, 'binance-verify-second', 'binance:verify:'.$secondOrder->id, chatId: 54321))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(321, '445000000000000022', chatId: 54321))->assertOk();

        $binanceCalls = Http::recorded(fn ($request) => str_starts_with($request->url(), 'https://api.binance.test/'));
        $this->assertCount(1, $binanceCalls);
        $this->assertSame(OrderStatus::Completed, $firstOrder->fresh()->order_status);
        $this->assertSame(OrderStatus::Completed, $secondOrder->fresh()->order_status);
    }

    public function test_provider_outage_keeps_order_pending_and_tells_buyer_to_retry(): void
    {
        Queue::fake([VerifyBinanceOrderId::class]);
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response(['msg' => 'down'], 500),
        ]);

        $this->submitOrderId($order, '445000000000000041', 322, 323);

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);

        $messages = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'received'));
        $this->assertCount(1, $messages);
        $this->assertSame('445000000000000041', $payment->fresh()->binance_order_id);
    }

    public function test_order_id_fallback_confirms_payment_from_receipt_order_id(): void
    {
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response($this->binanceHistory([
                $this->binanceTransaction('445064386362580992', $this->usdtString($payment->amount), now()->getTimestampMs()),
            ])),
        ]);

        $this->postTelegramWebhook($this->callbackUpdate(331, 'binance-orderid', 'binance:orderid:'.$order->id))->assertOk();

        $prompt = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Please send the Binance Pay Order ID'));
        $this->assertCount(1, $prompt);

        $this->postTelegramWebhook($this->messageUpdate(332, '445064386362580992'))->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Completed, $order->order_status);
        $this->assertSame('P_386362580992', $order->payments()->sole()->provider_reference);
    }

    public function test_order_id_fallback_with_wrong_amount_escalates_instead_of_delivering(): void
    {
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response($this->binanceHistory([
                $this->binanceTransaction('445064386362580992', '0.1', now()->getTimestampMs()),
            ])),
        ]);

        $this->postTelegramWebhook($this->callbackUpdate(341, 'binance-orderid', 'binance:orderid:'.$order->id))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(342, '445064386362580992'))->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::PendingPayment, $order->order_status);
        $this->assertNull($order->payments()->sole()->provider_reference);

        $adminNotice = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '999'
            && str_contains((string) ($request['text'] ?? ''), $order->invoice_number));
        $this->assertCount(1, $adminNotice);
    }

    public function test_order_id_fallback_records_sanitized_mismatch_evidence_for_manual_review(): void
    {
        $this->enableBinancePay();
        [$order] = $this->createBinanceOrder();
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response($this->binanceHistory([
                $this->binanceTransaction('445064386362580992', '0.1', now()->getTimestampMs()),
            ])),
        ]);

        $this->postTelegramWebhook($this->callbackUpdate(343, 'binance-orderid', 'binance:orderid:'.$order->id))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(344, '445064386362580992'))->assertOk();

        $payload = $order->payments()->sole()->fresh()->provider_payload;
        $this->assertSame('0.1', $payload['amount']);
        $this->assertSame('USDT', $payload['currency']);
        $this->assertSame('P_38••••0992', $payload['transactionId']);
        $this->assertSame(PaymentStatus::Pending, $order->payments()->sole()->fresh()->status);
        $this->assertTrue($order->payments()->sole()->fresh()->binance_verification_grace_until->isPast());
    }

    public function test_order_id_fallback_rejects_invalid_input_and_unknown_order_ids(): void
    {
        $this->enableBinancePay();
        [$order] = $this->createBinanceOrder();
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response($this->binanceHistory([])),
        ]);

        $this->postTelegramWebhook($this->callbackUpdate(351, 'binance-orderid', 'binance:orderid:'.$order->id))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(352, 'not a number'))->assertOk();

        $formatHint = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), '10–128 ASCII characters'));
        $this->assertCount(1, $formatHint);

        $this->postTelegramWebhook($this->messageUpdate(353, '445000000000000000'))->assertOk();

        $unknown = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Order ID received'));
        $this->assertCount(1, $unknown);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
    }

    public function test_reconcile_command_confirms_pending_payment_without_buyer_action(): void
    {
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $payment->update([
            'binance_order_id' => '445088888888888888',
            'binance_order_id_attempts' => 1,
            'binance_order_id_submitted_at' => now(),
            'binance_verification_grace_until' => now()->addMinutes(3),
        ]);
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response($this->binanceHistory([
                $this->binanceTransaction('445088888888888888', $this->usdtString($payment->amount), now()->getTimestampMs()),
            ])),
        ]);

        $this->artisan('binance:reconcile')
            ->expectsOutputToContain('confirmed=1')
            ->assertExitCode(0);

        $order->refresh();
        $this->assertSame(OrderStatus::Completed, $order->order_status);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame('P_888888888888', $order->payments()->sole()->provider_reference);
    }

    public function test_reconcile_command_does_not_confirm_an_exact_amount_without_a_submitted_order_id(): void
    {
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response($this->binanceHistory([
                $this->binanceTransaction('445088888888888887', $this->usdtString($payment->amount), now()->getTimestampMs()),
            ])),
        ]);

        $this->artisan('binance:reconcile')
            ->expectsOutputToContain('no pending Binance payments')
            ->assertExitCode(0);

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertNull($payment->fresh()->provider_reference);
    }

    public function test_reconcile_command_notifies_admin_about_matching_amount_mismatch(): void
    {
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $payment->update([
            'binance_order_id' => '445088888888888889',
            'binance_order_id_attempts' => 1,
            'binance_order_id_submitted_at' => now(),
            'binance_verification_grace_until' => now()->addMinutes(3),
        ]);
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response($this->binanceHistory([
                $this->binanceTransaction(
                    '445088888888888889',
                    $this->usdtString($payment->amount),
                    now()->getTimestampMs(),
                    currency: 'BUSD',
                ),
            ])),
        ]);

        $this->artisan('binance:reconcile')
            ->expectsOutputToContain('flagged=1')
            ->assertExitCode(0);

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $adminNotice = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '999'
            && str_contains((string) ($request['text'] ?? ''), $order->invoice_number));
        $this->assertCount(1, $adminNotice);
    }

    public function test_reconcile_command_is_a_safe_noop_without_credentials_or_pending_payments(): void
    {
        config()->set('services.binance.api_key', null);
        $this->artisan('binance:reconcile')
            ->expectsOutputToContain('skipped')
            ->assertExitCode(0);

        config()->set('services.binance.api_key', 'binance-key');
        $this->artisan('binance:reconcile')
            ->expectsOutputToContain('no pending Binance payments')
            ->assertExitCode(0);
    }

    public function test_reconcile_command_fails_gracefully_when_binance_is_down(): void
    {
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $payment->update([
            'binance_order_id' => '445099999999999998',
            'binance_order_id_attempts' => 1,
            'binance_order_id_submitted_at' => now(),
            'binance_verification_grace_until' => now()->addMinutes(3),
        ]);
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response(['msg' => 'down'], 500),
        ]);

        $this->artisan('binance:reconcile')->assertExitCode(1);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
    }

    public function test_pending_binance_order_is_not_expired_while_verification_grace_is_active(): void
    {
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $past = now()->subMinute();
        $order->update(['expires_at' => $past]);
        $payment->update([
            'expired_at' => $past,
            'binance_order_id' => '445099999999999991',
            'binance_order_id_attempts' => 1,
            'binance_order_id_submitted_at' => $past,
            'binance_verification_grace_until' => now()->addMinutes(2),
        ]);

        $this->artisan('orders:expire-pending')->assertSuccessful();

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_pending_binance_order_expires_after_verification_grace(): void
    {
        $this->fakeTelegramHttp();
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $past = now()->subMinute();
        $order->update(['expires_at' => $past]);
        $payment->update([
            'expired_at' => $past,
            'binance_order_id' => '445099999999999992',
            'binance_order_id_attempts' => 1,
            'binance_order_id_submitted_at' => $past,
            'binance_verification_grace_until' => now()->subSecond(),
        ]);

        $this->artisan('orders:expire-pending')->assertSuccessful();

        $this->assertSame(OrderStatus::Expired, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Expired, $payment->fresh()->status);
        $fallback = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), '/payment '.$order->invoice_number));
        $this->assertCount(1, $fallback);
    }

    public function test_expiry_rechecks_grace_after_the_order_was_loaded(): void
    {
        $this->fakeTelegramHttp();
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $order->load('payments');
        $past = now()->subMinute();
        $order->update(['expires_at' => $past]);
        $payment->update([
            'expired_at' => $past,
            'binance_order_id' => '445099999999999993',
            'binance_order_id_attempts' => 1,
            'binance_order_id_submitted_at' => $past,
            'binance_verification_grace_until' => now()->addMinutes(2),
        ]);

        $result = app(OrderLifecycle::class)->applyPendingExpiry($order);

        $this->assertFalse($result->newlyApplied);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_provider_confirmation_cannot_claim_a_transaction_after_order_id_replacement(): void
    {
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $payment->update([
            'binance_order_id' => '445099999999999994',
            'binance_order_id_attempts' => 1,
            'binance_order_id_submitted_at' => now(),
            'binance_verification_grace_until' => now()->addMinutes(3),
        ]);
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => function () use ($payment) {
                Payment::query()->whereKey($payment->id)->update([
                    'binance_order_id' => '445099999999999995',
                    'binance_order_id_attempts' => 2,
                    'binance_order_id_submitted_at' => now(),
                ]);

                return Http::response($this->binanceHistory([
                    $this->binanceTransaction('445099999999999994', $this->usdtString($payment->amount), now()->getTimestampMs()),
                ]));
            },
        ]);

        $result = app(BinanceAutoVerification::class)->verifyPaymentByProviderOrderId($payment, '445099999999999994');

        $this->assertSame(BinanceVerificationOutcome::StaleSubmission, $result->outcome);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertNull($payment->fresh()->provider_reference);
        $this->assertTrue($payment->fresh()->binance_verification_grace_until->isFuture());
    }

    public function test_provider_mismatch_cannot_escalate_after_order_id_replacement(): void
    {
        config()->set('services.binance.history_cache_seconds', 0);
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $payment->update([
            'binance_order_id' => '445099999999999998',
            'binance_order_id_attempts' => 1,
            'binance_order_id_submitted_at' => now(),
            'binance_verification_grace_until' => now()->addMinutes(3),
        ]);
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => function () use ($payment) {
                Payment::query()->whereKey($payment->id)->update([
                    'binance_order_id' => '445099999999999999',
                    'binance_order_id_attempts' => 2,
                    'binance_order_id_submitted_at' => now(),
                ]);

                return Http::response($this->binanceHistory([
                    $this->binanceTransaction('445099999999999998', '0.1', now()->getTimestampMs()),
                ]));
            },
        ]);

        $result = app(BinanceAutoVerification::class)->verifyPaymentByProviderOrderId($payment, '445099999999999998');

        $this->assertSame(BinanceVerificationOutcome::StaleSubmission, $result->outcome);
        $this->assertNull($payment->fresh()->provider_payload);
        $this->assertTrue($payment->fresh()->binance_verification_grace_until->isFuture());
        $adminNotice = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '999'
            && str_contains((string) ($request['text'] ?? ''), $order->invoice_number));
        $this->assertCount(0, $adminNotice);
    }

    public function test_payment_created_without_auto_verification_does_not_become_verifiable_when_credentials_are_added(): void
    {
        config()->set('services.binance.api_key', null);
        config()->set('services.binance.api_secret', null);
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        config()->set('services.binance.api_key', 'binance-key');
        config()->set('services.binance.api_secret', 'binance-secret');
        $payment->update([
            'binance_order_id' => '445099999999999996',
            'binance_order_id_attempts' => 1,
            'binance_order_id_submitted_at' => now(),
            'binance_verification_grace_until' => now()->addMinutes(3),
        ]);
        $this->fakeTelegramHttp();

        $result = app(BinanceAutoVerification::class)->verifyPaymentByProviderOrderId($payment, '445099999999999996');

        $this->assertSame(BinanceVerificationOutcome::NotConfigured, $result->outcome);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertNull($payment->fresh()->provider_reference);
        $this->assertCount(0, Http::recorded(fn ($request) => str_contains($request->url(), 'api.binance.test')));
    }

    public function test_expired_binance_notice_is_retried_after_telegram_failure(): void
    {
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
        $past = now()->subMinute();
        $order->update(['expires_at' => $past]);
        $payment->update([
            'expired_at' => $past,
            'binance_order_id' => '445099999999999997',
            'binance_order_id_attempts' => 1,
            'binance_order_id_submitted_at' => $past,
            'binance_verification_grace_until' => now()->subSecond(),
        ]);
        $buyerFailures = 0;
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendMessage' => function ($request) use (&$buyerFailures) {
                if ((string) ($request['chat_id'] ?? '') === '12345' && $buyerFailures++ === 0) {
                    return Http::response(['ok' => false, 'description' => 'temporary Telegram failure'], 500);
                }

                return Http::response(['ok' => true]);
            },
        ]);

        $this->artisan('orders:expire-pending')->assertFailed();
        $this->assertNull($order->fresh()->telegram_expired_order_notified_at);
        $this->artisan('orders:expire-pending')->assertSuccessful();

        $this->assertNotNull($order->fresh()->telegram_expired_order_notified_at);
    }

    private function submitOrderId(Order $order, string $orderId, int $callbackUpdateId, int $messageUpdateId, int $chatId = 12345): void
    {
        $this->postTelegramWebhook($this->callbackUpdate($callbackUpdateId, 'binance-order-id-prompt-'.$callbackUpdateId, 'binance:verify:'.$order->id, chatId: $chatId))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate($messageUpdateId, $orderId, chatId: $chatId))->assertOk();
    }

    private function enableBinancePay(): void
    {
        ManualPaymentSetting::create([
            'binance_pay_enabled' => true,
            'is_active' => true,
            'admin_telegram_chat_id' => '999',
            'binance_verification_mode' => 'order_id',
            'binance_order_id_compatibility_ready_at' => now(),
        ]);

        // Test the post-canary schema, where the legacy amount uniqueness
        // index has been narrowed to amount_unique payments only.
        app(BinanceOrderIdActivation::class)->activate();
    }

    /** @return array{0: Order, 1: Payment, 2: Customer} */
    private function createBinanceOrder(int $telegramId = 12345): array
    {
        $customer = Customer::factory()->create(['telegram_id' => $telegramId, 'locale' => 'en']);
        $product = Product::factory()->create([
            'stock' => 5,
            'reserved_stock' => 0,
            'price_amount' => 50000,
            'usdt_price_amount' => self::BASE_USDT_MICRO,
        ]);
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'status' => CartStatus::Active, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1]);

        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::binancePay());

        return [$order, $order->payments()->sole(), $customer];
    }

    /** @return array<string, mixed> */
    private function binanceTransaction(
        string $orderId,
        string $amount,
        int $transactionTimeMs,
        string $currency = 'USDT',
        string $receiverPayId = '987654321',
        ?string $receiverBinanceId = null,
    ): array {
        return [
            'uid' => 1251268604,
            'orderId' => $orderId,
            'transactionId' => 'P_'.substr($orderId, -12),
            'orderType' => 'C2C',
            'amount' => $amount,
            'currency' => $currency,
            'transactionTime' => $transactionTimeMs,
            'note' => '',
            'payerInfo' => ['name' => 'Buyer', 'binanceId' => 1215081702],
            'receiverInfo' => array_filter([
                'name' => 'Store',
                'accountId' => $receiverPayId,
                'binanceId' => $receiverBinanceId,
            ], fn ($value) => $value !== null),
        ];
    }

    /** @param list<array<string, mixed>> $transactions */
    private function binanceHistory(array $transactions): array
    {
        return ['code' => '000000', 'message' => 'success', 'data' => $transactions, 'success' => true];
    }

    private function usdtString(int $amountMicro): string
    {
        return rtrim(rtrim(number_format($amountMicro / 1_000_000, 6, '.', ''), '0'), '.');
    }
}
