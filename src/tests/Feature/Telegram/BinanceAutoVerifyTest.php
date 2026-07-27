<?php

namespace Tests\Feature\Telegram;

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Services\Binance\BinanceUsdtAmount;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\Telegram\TelegramCheckoutIntents;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

class BinanceAutoVerifyTest extends TelegramBotTestSupport
{
    private const BASE_USDT_MICRO = 5_000_000;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.binance', [
            'pay_id' => '987654321',
            'api_key' => 'binance-key',
            'api_secret' => 'binance-secret',
            'base_url' => 'https://api.binance.test',
            'http_timeout_seconds' => 10,
            'http_connect_timeout_seconds' => 3,
            'recv_window_ms' => 10000,
            'history_max_attempts' => 1,
            'history_retry_delay_ms' => 0,
            'unique_amount_min_offset_micro' => 101,
            'unique_amount_max_offset_micro' => 9999,
            'verify_clock_skew_seconds' => 120,
            'verify_rate_limit_attempts' => 5,
            'verify_rate_limit_decay_seconds' => 300,
        ]);
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
    }

    public function test_binance_checkout_allocates_unique_amounts_when_auto_verify_is_configured(): void
    {
        $this->enableBinancePay();

        $first = $this->createBinanceOrder(telegramId: 12345);
        $second = $this->createBinanceOrder(telegramId: 54321);

        foreach ([$first, $second] as [, $payment]) {
            $this->assertGreaterThan(self::BASE_USDT_MICRO, $payment->amount);
            $this->assertLessThanOrEqual(self::BASE_USDT_MICRO + 9999, $payment->amount);
        }

        $this->assertNotSame($first[1]->amount, $second[1]->amount);
    }

    public function test_binance_checkout_keeps_exact_amount_without_api_credentials(): void
    {
        config()->set('services.binance.api_key', null);
        config()->set('services.binance.api_secret', null);
        $this->enableBinancePay();

        [, $payment] = $this->createBinanceOrder();

        $this->assertSame(self::BASE_USDT_MICRO, $payment->amount);
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
        $this->assertStringContainsString('do not round the amount', $body);
        $this->assertStringNotContainsString('screenshot', $body);
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

        $this->postTelegramWebhook($this->callbackUpdate(301, 'binance-verify', 'binance:verify:'.$order->id))->assertOk();

        $order->refresh();
        $payment->refresh();
        $this->assertSame(OrderStatus::Completed, $order->order_status);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame('445011487581044736', $payment->provider_reference);
        $this->assertNotNull($payment->paid_at);

        $event = PaymentEvent::query()->where('provider', 'binance')->sole();
        $this->assertSame($payment->id, $event->payment_id);
        $this->assertSame('445011487581044736', $event->provider_reference);
        $this->assertNotNull($event->processed_at);

        $confirmMessages = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Payment confirmed'));
        $this->assertCount(1, $confirmMessages);
    }

    public function test_ive_paid_tap_rejects_outgoing_wrong_amount_and_stale_transactions(): void
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

        $this->postTelegramWebhook($this->callbackUpdate(302, 'binance-verify', 'binance:verify:'.$order->id))->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::PendingPayment, $order->order_status);
        $this->assertSame(PaymentStatus::Pending, $order->payments()->sole()->status);
        $this->assertNull($order->payments()->sole()->provider_reference);

        $notFound = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Payment not detected yet'));
        $this->assertCount(1, $notFound);
        $this->assertStringContainsString('binance:orderid:'.$order->id, json_encode($notFound->first()[0]['reply_markup'] ?? []));
    }

    public function test_one_transaction_can_never_pay_two_orders(): void
    {
        $this->enableBinancePay();
        [$orderA, $paymentA] = $this->createBinanceOrder(telegramId: 12345);
        $transaction = $this->binanceTransaction('445099999999999999', $this->usdtString($paymentA->amount), now()->getTimestampMs());
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response($this->binanceHistory([$transaction])),
        ]);

        $this->postTelegramWebhook($this->callbackUpdate(303, 'binance-verify-a', 'binance:verify:'.$orderA->id))->assertOk();
        $this->assertSame(PaymentStatus::Paid, $paymentA->fresh()->status);

        // A second order that happens to share the same payable amount must not
        // be able to reuse the transaction that already paid order A.
        [$orderB, $paymentB] = $this->createBinanceOrder(telegramId: 54321);
        $paymentB->update(['amount' => $paymentA->amount]);

        $this->postTelegramWebhook($this->callbackUpdate(304, 'binance-verify-b', 'binance:verify:'.$orderB->id, chatId: 54321))->assertOk();

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

    public function test_verify_taps_are_rate_limited(): void
    {
        config()->set('services.binance.verify_rate_limit_attempts', 2);
        $this->enableBinancePay();
        [$order] = $this->createBinanceOrder();
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response($this->binanceHistory([])),
        ]);

        foreach ([311, 312, 313] as $updateId) {
            $this->postTelegramWebhook($this->callbackUpdate($updateId, 'binance-verify-'.$updateId, 'binance:verify:'.$order->id))->assertOk();
        }

        $binanceCalls = Http::recorded(fn ($request) => str_contains($request->url(), 'api.binance.test'));
        $this->assertCount(2, $binanceCalls);

        $throttled = Http::recorded(fn ($request) => str_ends_with($request->url(), '/answerCallbackQuery')
            && str_contains((string) ($request['text'] ?? ''), 'Too many attempts'));
        $this->assertCount(1, $throttled);
    }

    public function test_provider_outage_keeps_order_pending_and_tells_buyer_to_retry(): void
    {
        $this->enableBinancePay();
        [$order] = $this->createBinanceOrder();
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response(['msg' => 'down'], 500),
        ]);

        $this->postTelegramWebhook($this->callbackUpdate(321, 'binance-verify', 'binance:verify:'.$order->id))->assertOk();

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);

        $messages = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'temporarily unavailable'));
        $this->assertCount(1, $messages);
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
            && str_contains((string) ($request['text'] ?? ''), 'Send your Binance Order ID'));
        $this->assertCount(1, $prompt);

        $this->postTelegramWebhook($this->messageUpdate(332, '445064386362580992'))->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Completed, $order->order_status);
        $this->assertSame('445064386362580992', $order->payments()->sole()->provider_reference);
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

    public function test_order_id_fallback_rejects_non_numeric_input_and_unknown_order_ids(): void
    {
        $this->enableBinancePay();
        [$order] = $this->createBinanceOrder();
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response($this->binanceHistory([])),
        ]);

        $this->postTelegramWebhook($this->callbackUpdate(351, 'binance-orderid', 'binance:orderid:'.$order->id))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(352, 'not-a-number'))->assertOk();

        $formatHint = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'contains digits only'));
        $this->assertCount(1, $formatHint);

        $this->postTelegramWebhook($this->messageUpdate(353, '445000000000000000'))->assertOk();

        $unknown = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'could not find that Order ID'));
        $this->assertCount(1, $unknown);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
    }

    public function test_reconcile_command_confirms_pending_payment_without_buyer_action(): void
    {
        $this->enableBinancePay();
        [$order, $payment] = $this->createBinanceOrder();
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
        $this->assertSame('445088888888888888', $order->payments()->sole()->provider_reference);
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
        [$order] = $this->createBinanceOrder();
        $this->fakeTelegramHttp([
            'https://api.binance.test/*' => Http::response(['msg' => 'down'], 500),
        ]);

        $this->artisan('binance:reconcile')->assertExitCode(1);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
    }

    private function enableBinancePay(): void
    {
        ManualPaymentSetting::create([
            'binance_pay_enabled' => true,
            'is_active' => true,
            'admin_telegram_chat_id' => '999',
        ]);
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
    private function binanceTransaction(string $orderId, string $amount, int $transactionTimeMs, string $currency = 'USDT'): array
    {
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
