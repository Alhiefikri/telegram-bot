<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Jobs\SendSakurupiahCheckoutInstructions;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\TelegramOutboundEffect;
use App\Services\Sakurupiah\SakurupiahAmount;
use App\Services\Sakurupiah\SakurupiahCreateInvoice;
use App\Services\Sakurupiah\SakurupiahFee;
use App\Services\Sakurupiah\SakurupiahInvoiceLifecycleService;
use App\Services\Sakurupiah\SakurupiahProviderAdapter;
use App\Services\Sakurupiah\SakurupiahStatus;
use App\Services\Sakurupiah\SakurupiahTransactionStatus;
use App\Services\Telegram\TelegramCheckoutIntents;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TelegramBotTestSupport;

class SakurupiahCheckoutTest extends TelegramBotTestSupport
{
    private const DIRECT_QRIS = '0002010102125303360540820450.005802ID63045B6D';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.sakurupiah', array_replace(config('services.sakurupiah', []), [
            'sandbox_enabled' => true,
            'sandbox_base_url' => 'https://sakurupiah.id/api-sanbox/',
            'sandbox_api_id' => 'api-test',
            'sandbox_api_key' => 'secret-key',
            'sandbox_allowed_hosts' => ['sakurupiah.id'],
            'qris_contract' => [
                'currency' => 'IDR',
                'currency_confirmed' => true,
                'fee_payer' => 'merchant',
                'merchant_fee' => 1,
                'fee_confirmed' => true,
                'expiry_hours' => 24,
                'expiry_confirmed' => true,
            ],
        ]));
    }

    public function test_direct_sakurupiah_effect_dispatches_and_delivers_only_validated_qris_instructions(): void
    {
        Queue::fake();
        $this->fakeTelegramHttp();
        [$order, $payment] = $this->payment([
            'invoice_number' => 'INV-SAK-TELEGRAM-DIRECT',
            'provider_presentation' => [
                'mode' => 'qris',
                'qris_payload' => self::DIRECT_QRIS,
                'checkout_url' => null,
            ],
        ]);

        $effect = app(TelegramOutboundEffectService::class)->recordCheckoutInstructions($order, $payment);

        $this->assertInstanceOf(TelegramOutboundEffect::class, $effect);
        $this->assertSame(TelegramOutboundEffectService::TYPE_SAKURUPIAH_CHECKOUT, $effect->effect_type);
        $this->assertSame(TelegramOutboundEffectService::TEMPLATE_SAKURUPIAH_CHECKOUT, $effect->template_version);
        $this->assertSame('sendPhoto', $effect->telegram_method);

        app(TelegramOutboundEffectDispatcher::class)->dispatch($effect);
        Queue::assertPushed(
            SendSakurupiahCheckoutInstructions::class,
            fn (SendSakurupiahCheckoutInstructions $job): bool => $job->orderId === $order->id
                && $job->effectId === $effect->id,
        );

        (new SendSakurupiahCheckoutInstructions($order->id, null, $effect->id))
            ->handle(app(TelegramCheckoutIntents::class));

        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
        $sendPhotos = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertCount(1, $sendPhotos);
        $body = $sendPhotos->first()[0]->body();
        $this->assertStringContainsString("\x89PNG\r\n\x1A\n", $body);
        $this->assertStringContainsString('filename="sakurupiah-INV-SAK-TELEGRAM-DIRECT.png"', $body);
        $this->assertStringContainsString('Rp20.450', $body);
        $this->assertStringContainsString('INV-SAK-TELEGRAM-DIRECT', $body);
        $this->assertStringContainsString('Check Payment', $body);
        $this->assertStringNotContainsString(self::DIRECT_QRIS, $body);
        $this->assertStringNotContainsString('provider-secret', $body);
        $this->assertStringContainsString('buyer:cancel-pending:'.$order->id, $body);
        $this->assertNotNull($order->fresh()->manual_checkout_customer_notified_at);
    }

    public function test_sakurupiah_delivery_loads_the_checkout_graph_without_relation_n_plus_one(): void
    {
        $this->fakeTelegramHttp();
        [$order, $payment] = $this->payment([
            'invoice_number' => 'INV-SAK-TELEGRAM-QUERY-BUDGET',
            'provider_presentation' => [
                'mode' => 'qris',
                'qris_payload' => self::DIRECT_QRIS,
                'checkout_url' => null,
            ],
        ]);
        $effect = app(TelegramOutboundEffectService::class)->recordCheckoutInstructions($order, $payment);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        (new SendSakurupiahCheckoutInstructions($order->id, null, $effect?->id))
            ->handle(app(TelegramCheckoutIntents::class));

        foreach (['customers', 'order_items'] as $table) {
            $relationQueries = collect($queries)
                ->filter(fn (string $sql): bool => str_contains($sql, 'from "'.$table.'"') && str_contains($sql, ' in ('))
                ->values();

            $this->assertCount(1, $relationQueries, implode("\n", $queries));
        }

        $paymentRelationQueries = collect($queries)
            ->filter(fn (string $sql): bool => str_contains($sql, 'from "payments"') && str_contains($sql, ' in ('))
            ->values();
        $this->assertCount(1, $paymentRelationQueries, implode("\n", $queries));
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect?->fresh()->state);
    }

    public function test_hosted_sakurupiah_effect_uses_the_allowlisted_checkout_url_and_same_total(): void
    {
        $this->fakeTelegramHttp();
        [$order, $payment] = $this->payment([
            'invoice_number' => 'INV-SAK-TELEGRAM-HOSTED',
            'provider_presentation' => [
                'mode' => 'hosted',
                'qris_payload' => null,
                'checkout_url' => 'https://sakurupiah.id/pay/invoice',
            ],
        ]);

        $effect = app(TelegramOutboundEffectService::class)->recordCheckoutInstructions($order, $payment);
        (new SendSakurupiahCheckoutInstructions($order->id, null, $effect?->id))
            ->handle(app(TelegramCheckoutIntents::class));

        $sendPhotos = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertCount(1, $sendPhotos);
        $body = $sendPhotos->first()[0]->body();
        $request = $sendPhotos->first()[0];
        $replyMarkup = json_decode((string) $this->telegramRequestField($request, 'reply_markup'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('https://sakurupiah.id/pay/invoice', $replyMarkup['inline_keyboard'][0][0]['url']);
        $this->assertStringContainsString('Rp20.450', $body);
        $this->assertStringContainsString('Pay the exact final amount shown', $body);
        $this->assertStringNotContainsString('provider-secret', $body);
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect?->fresh()->state);
    }

    public function test_non_payable_sakurupiah_state_sends_safe_retry_copy_without_provider_data(): void
    {
        $this->fakeTelegramHttp();
        [$order, $payment] = $this->payment([
            'invoice_number' => 'INV-SAK-TELEGRAM-RETRY',
            'provider_create_state' => 'creating',
            'provider_reference' => null,
            'provider_presentation' => null,
            'provider_payload' => ['provider-secret' => 'never-send', 'status' => 'pending'],
        ]);

        $effect = app(TelegramOutboundEffectService::class)->recordCheckoutInstructions($order, $payment);
        (new SendSakurupiahCheckoutInstructions($order->id, null, $effect?->id))
            ->handle(app(TelegramCheckoutIntents::class));

        $sendMessages = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'));
        $sendPhotos = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertCount(1, $sendMessages);
        $this->assertCount(0, $sendPhotos);
        $body = $sendMessages->first()[0]->body();
        $this->assertStringContainsString('INV-SAK-TELEGRAM-RETRY', $body);
        $this->assertStringContainsString('Check Payment', $body);
        $this->assertStringNotContainsString('never-send', $body);
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect?->fresh()->state);
    }

    public function test_store_bot_check_refreshes_presentation_pending_and_renders_recovered_instruction(): void
    {
        $this->fakeTelegramHttp();
        [$order, $payment] = $this->payment([
            'invoice_number' => 'INV-SAK-TELEGRAM-PRESENTATION-RETRY',
            'provider_create_state' => SakurupiahInvoiceLifecycleService::STATE_PROVIDER_QRIS_UNUSABLE,
            'provider_presentation' => [
                'mode' => 'qris',
                'qris_payload' => 'not-a-valid-qris',
                'checkout_url' => 'https://sakurupiah.id/pay/invoice?token=redacted',
            ],
        ]);
        $status = new SakurupiahStatus(
            trxId: (string) $payment->provider_reference,
            status: SakurupiahTransactionStatus::Pending,
            merchantRef: (string) $payment->merchant_ref,
            paymentCode: SakurupiahCreateInvoice::METHOD_QRIS,
            amount: SakurupiahAmount::from(20_000),
            occurredAt: now()->subMinute()->toImmutable(),
            expiresAt: $payment->expired_at->toImmutable(),
            sanitizedPayload: [],
            total: SakurupiahAmount::from(20_450),
            fee: SakurupiahFee::from(450),
            qr: self::DIRECT_QRIS,
            currency: 'IDR',
        );
        $this->mock(SakurupiahProviderAdapter::class)
            ->shouldReceive('transactionStatus')
            ->once()
            ->with($payment->provider_reference)
            ->andReturn($status);

        $this->postTelegramWebhook($this->messageUpdate(380, '/order '.$order->invoice_number, ['id' => 7412001], 7412001))->assertOk();
        $this->assertSame(SakurupiahInvoiceLifecycleService::STATE_CREATED, $payment->fresh()->provider_create_state);
        $sendPhotos = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertCount(1, $sendPhotos);
        $body = $sendPhotos->first()[0]->body();
        $this->assertStringContainsString('Rp20.450', $body);
        $this->assertStringContainsString($order->invoice_number, $body);
        $this->assertStringContainsString('Check Payment', $body);
        $recoveryEffect = TelegramOutboundEffect::query()
            ->where('effect_key', TelegramOutboundEffectService::sakurupiahPresentationRecoveryEffectKey((string) $payment->id))
            ->firstOrFail();
        $this->assertSame(TelegramOutboundEffectState::Sent, $recoveryEffect->state);
        $this->assertStringNotContainsString(self::DIRECT_QRIS, $body);
    }

    public function test_ambiguous_sakurupiah_delivery_becomes_unknown_without_a_second_send(): void
    {
        [$order, $payment] = $this->payment([
            'invoice_number' => 'INV-SAK-TELEGRAM-AMBIGUOUS',
            'provider_presentation' => [
                'mode' => 'qris',
                'qris_payload' => self::DIRECT_QRIS,
                'checkout_url' => null,
            ],
        ]);
        $effect = app(TelegramOutboundEffectService::class)->recordCheckoutInstructions($order, $payment);
        $sendCount = 0;
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => function () use (&$sendCount): never {
                $sendCount++;
                throw new ConnectionException('connection closed after Telegram accepted the request');
            },
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $job = new SendSakurupiahCheckoutInstructions($order->id, null, $effect?->id);
        $job->handle(app(TelegramCheckoutIntents::class));
        $job->handle(app(TelegramCheckoutIntents::class));

        $this->assertSame(TelegramOutboundEffectState::Unknown, $effect?->fresh()->state);
        $this->assertSame(1, $sendCount);
        $this->assertNotNull($effect?->fresh()->unknown_at);
        $this->assertNull($order->fresh()->manual_checkout_customer_notified_at);
    }

    /** @return array{0: Order, 1: Payment} */
    private function payment(array $attributes = []): array
    {
        $expiresAt = now()->addMinutes(20)->startOfSecond();
        $invoiceNumber = $attributes['invoice_number'] ?? 'INV-SAK-TELEGRAM';
        $presentation = $attributes['provider_presentation'] ?? null;
        unset($attributes['invoice_number'], $attributes['provider_presentation']);
        if (is_array($presentation)) {
            $presentation['effective_expires_at'] = $expiresAt->toIso8601String();
        }
        $customer = Customer::factory()->create([
            'telegram_id' => 7412001,
            'locale' => 'en',
        ]);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => $invoiceNumber,
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'total_amount' => 20_000,
            'currency' => 'IDR',
            'expires_at' => $expiresAt,
        ]);
        OrderItem::factory()->for($order)->create([
            'product_name' => 'Premium access',
            'quantity' => 1,
            'unit_price' => 20_000,
            'subtotal' => 20_000,
        ]);

        $payment = Payment::factory()->for($order)->create(array_merge([
            'provider' => 'sakurupiah',
            'method' => SakurupiahCreateInvoice::METHOD_QRIS,
            'provider_reference' => 'trx-'.$order->invoice_number,
            'provider_payment_method' => SakurupiahCreateInvoice::METHOD_QRIS,
            'provider_total_payment' => 20_450,
            'provider_fee' => 450,
            'provider_created_at' => now()->subMinute(),
            'provider_expires_at' => $expiresAt,
            'provider_presentation' => $presentation,
            'provider_payload' => [
                'data' => [[
                    'trx_id' => 'trx-'.$order->invoice_number,
                    'merchant_ref' => $order->invoice_number,
                    'payment_kode' => 'QRIS',
                    'payment_status' => 'pending',
                    'total' => '20450',
                    'fee' => '450',
                ]],
            ],
            'provider_create_state' => 'created',
            'amount' => 20_000,
            'currency' => 'IDR',
            'status' => PaymentStatus::Pending,
            'expired_at' => $expiresAt,
        ], $attributes));

        return [$order, $payment];
    }
}
