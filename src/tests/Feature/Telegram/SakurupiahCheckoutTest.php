<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Jobs\PollActiveSakurupiahPayment;
use App\Jobs\SendSakurupiahCheckoutInstructions;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\TelegramOutboundEffect;
use App\Services\ProviderVerification\ProviderVerificationProfiles;
use App\Services\Sakurupiah\SakurupiahAmount;
use App\Services\Sakurupiah\SakurupiahCreateInvoice;
use App\Services\Sakurupiah\SakurupiahFee;
use App\Services\Sakurupiah\SakurupiahInvoice;
use App\Services\Sakurupiah\SakurupiahInvoiceLifecycleService;
use App\Services\Sakurupiah\SakurupiahInvoiceOutcome;
use App\Services\Sakurupiah\SakurupiahProviderAdapter;
use App\Services\Sakurupiah\SakurupiahRequestBackpressure;
use App\Services\Sakurupiah\SakurupiahStatus;
use App\Services\Sakurupiah\SakurupiahTransactionStatus;
use App\Services\Telegram\TelegramCheckoutIntents;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TelegramBotTestSupport;

class SakurupiahCheckoutTest extends TelegramBotTestSupport
{
    private const DIRECT_QRIS = '0002010102125303360540820450.005802ID63045B6D';

    private const DIRECT_QRIS_20_000 = '0002010102125303360540820000.005802ID6304EEF1';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.sakurupiah', array_replace(config('services.sakurupiah', []), [
            'sandbox_enabled' => true,
            'sandbox_base_url' => 'https://sakurupiah.id/api-sanbox/',
            'sandbox_api_id' => 'api-test',
            'sandbox_api_key' => 'secret-key',
            'sandbox_allowed_hosts' => ['sakurupiah.id'],
            'sandbox_callback_url' => 'https://store.example.test/api/webhook/sakurupiah',
            'sandbox_return_url' => 'https://store.example.test/shop/orders',
            'sandbox_callback_allowed_hosts' => ['store.example.test'],
            'sandbox_return_allowed_hosts' => ['store.example.test'],
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

    public function test_undispatched_create_recovers_the_same_order_without_the_rejection_delay(): void
    {
        config()->set('queue.default', 'database');
        Queue::fake();
        [$order, $payment] = $this->payment([
            'provider_reference' => null,
            'provider_payment_method' => null,
            'provider_total_payment' => null,
            'provider_fee' => null,
            'provider_created_at' => null,
            'provider_expires_at' => null,
            'provider_payload' => null,
            'provider_create_state' => null,
        ]);
        ManualPaymentSetting::create([
            'payment_mode' => 'sakurupiah', 'sakurupiah_channel' => 'QRISC',
            'is_active' => true, 'whatsapp_support_number' => '08123456789',
        ]);
        $this->fakeTelegramHttp([
            'https://sakurupiah.id/api-sanbox/create.php' => fn (Request $request) => Http::response([
                'status' => '200', 'data' => [[
                    'trx_id' => 'trx-after-local-deferral',
                    'merchant_ref' => $request['merchant_ref'], 'payment_kode' => 'QRISC',
                    'payment_status' => 'pending', 'amount' => '20000', 'total' => '20000',
                    'fee' => '0', 'merchant_fee' => 'Merchant', 'qr' => self::DIRECT_QRIS_20_000,
                    'expired' => now()->addDay()->format('Y-m-d H:i:s'),
                ]],
            ]),
        ]);
        $policy = new SakurupiahRequestBackpressure;
        $held = $policy->acquireRequest('transactionStatus', true, 'reconciliation_command');
        try {
            app(TelegramCheckoutIntents::class)->sendSakurupiahPaymentInstructions('7412001', $order, $payment);
            $this->assertSame(
                SakurupiahInvoiceOutcome::Creating,
                app(SakurupiahInvoiceLifecycleService::class)->lifecycle($payment)->outcome(),
            );
        } finally {
            $held->release();
        }
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'sakurupiah.id'));
        $this->assertNull($payment->fresh()->provider_create_attempted_at);
        $this->assertSame('create_failed', $payment->fresh()->provider_create_state);
        $this->assertNull($payment->fresh()->provider_reference);

        app(TelegramCheckoutIntents::class)->sendSakurupiahPaymentInstructions('7412001', $order, $payment);
        app(SakurupiahInvoiceLifecycleService::class)->classify($payment);
        $this->assertSame('trx-after-local-deferral', $payment->fresh()->provider_reference);
        Queue::assertPushed(PollActiveSakurupiahPayment::class, fn (PollActiveSakurupiahPayment $job) => $job->paymentId === (string) $payment->id);
        $this->assertSame('created', $payment->fresh()->provider_create_state);
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
        $this->assertCount(1, Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/create.php')));
        $photos = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertCount(1, $photos);
        $this->assertStringContainsString("\x89PNG\r\n\x1A\n", $photos->sole()[0]->body());
    }

    public function test_expired_background_orders_leave_simulated_provider_capacity_for_checkout(): void
    {
        [$order, $payment] = $this->payment([
            'provider_reference' => null, 'provider_payment_method' => null,
            'provider_total_payment' => null, 'provider_fee' => null,
            'provider_created_at' => null, 'provider_expires_at' => null,
            'provider_payload' => null, 'provider_create_state' => null,
        ]);
        ManualPaymentSetting::create([
            'payment_mode' => 'sakurupiah', 'sakurupiah_channel' => 'QRISC',
            'is_active' => true, 'whatsapp_support_number' => '08123456789',
        ]);
        foreach (range(1, 3) as $index) {
            $old = Order::factory()->create(['order_status' => OrderStatus::Expired, 'payment_status' => PaymentStatus::Expired, 'expires_at' => now()->subHour()]);
            Payment::factory()->for($old)->create([
                'provider' => 'sakurupiah', 'method' => 'QRIS', 'currency' => 'IDR',
                'status' => PaymentStatus::Expired, 'provider_reference' => 'old-'.$index,
                'provider_create_state' => 'created', 'expired_at' => now()->subHour(),
            ]);
        }
        $requests = 0;
        $this->fakeTelegramHttp([
            'https://sakurupiah.id/api-sanbox/status-transaction.php' => function () use (&$requests) {
                $requests++;

                return Http::response(['status' => '200', 'data' => [['payment_status' => 'pending']]]);
            },
            'https://sakurupiah.id/api-sanbox/create.php' => function (Request $request) use (&$requests) {
                if (++$requests > 1) {
                    return Http::response(['status' => '400', 'message' => 'Permintaan terlalu cepat silahkan coba lagi nanti.'], 400);
                }

                return Http::response(['status' => '200', 'data' => [[
                    'trx_id' => 'trx-capacity', 'merchant_ref' => $request['merchant_ref'],
                    'payment_kode' => 'QRISC', 'payment_status' => 'pending',
                    'amount' => '20000', 'total' => '20000', 'fee' => '0',
                    'merchant_fee' => 'Merchant', 'qr' => self::DIRECT_QRIS_20_000,
                    'expired' => now()->addDay()->format('Y-m-d H:i:s'),
                ]]]);
            },
        ]);
        $this->artisan('sakurupiah:reconcile')->assertExitCode(0);
        $this->assertSame(0, $requests);
        app(TelegramCheckoutIntents::class)->sendSakurupiahPaymentInstructions('7412001', $order, $payment);
        $this->assertSame(1, $requests);
        $this->assertSame('trx-capacity', $payment->fresh()->provider_reference);
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
        $this->assertCount(1, Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/sendPhoto')));
    }

    public function test_direct_sakurupiah_effect_dispatches_and_delivers_provider_qr_content(): void
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

    public function test_customer_borne_fee_delivers_qr_with_provider_total(): void
    {
        config()->set('services.sakurupiah.qris_contract.fee_payer', 'customer');
        config()->set('services.sakurupiah.qris_contract.merchant_fee', 2);
        $this->fakeTelegramHttp();
        [$order, $payment] = $this->payment([
            'invoice_number' => 'INV-SAK-TELEGRAM-CUSTOMER-FEE',
            'provider_total_payment' => 20_450,
            'provider_fee' => 450,
            'provider_presentation' => [
                'mode' => 'qris',
                'qris_payload' => self::DIRECT_QRIS,
                'checkout_url' => null,
            ],
        ]);

        $effect = app(TelegramOutboundEffectService::class)->recordCheckoutInstructions($order, $payment);
        (new SendSakurupiahCheckoutInstructions($order->id, null, $effect?->id))
            ->handle(app(TelegramCheckoutIntents::class));

        $sendPhotos = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertCount(1, $sendPhotos);
        $body = $sendPhotos->first()[0]->body();
        $this->assertStringContainsString('Rp20.450', $body);
        $this->assertStringContainsString('The QRIS fee is paid by the customer and is included in the total above.', $body);
        $this->assertStringNotContainsString('The QRIS fee is covered by the store', $body);
        $this->assertStringNotContainsString('could not be prepared', $body);
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect?->fresh()->state);
    }

    public function test_merchant_borne_fee_delivers_qr_without_increasing_buyer_total(): void
    {
        $this->fakeTelegramHttp();
        [$order, $payment] = $this->payment([
            'invoice_number' => 'INV-SAK-TELEGRAM-MERCHANT-FEE',
            'provider_total_payment' => 20_000,
            'provider_fee' => 450,
            'provider_presentation' => [
                'mode' => 'qris',
                'qris_payload' => self::DIRECT_QRIS_20_000,
                'checkout_url' => null,
            ],
            'provider_payload' => [
                'data' => [[
                    'trx_id' => 'trx-INV-SAK-TELEGRAM-MERCHANT-FEE',
                    'merchant_ref' => 'INV-SAK-TELEGRAM-MERCHANT-FEE',
                    'payment_kode' => 'QRISC',
                    'payment_status' => 'pending',
                    'total' => '20000',
                    'fee' => '450',
                    'merchant_fee' => 'Merchant',
                ]],
            ],
        ]);

        $effect = app(TelegramOutboundEffectService::class)->recordCheckoutInstructions($order, $payment);
        (new SendSakurupiahCheckoutInstructions($order->id, null, $effect?->id))
            ->handle(app(TelegramCheckoutIntents::class));

        $sendPhotos = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertCount(1, $sendPhotos);
        $body = $sendPhotos->first()[0]->body();
        $this->assertStringContainsString('Rp20.000', $body);
        $this->assertStringNotContainsString('Rp20.450', $body);
        $this->assertStringContainsString('The QRIS fee is covered by the store', $body);
        $this->assertStringNotContainsString('The QRIS fee is paid by the customer', $body);
        $this->assertStringNotContainsString('could not be prepared', $body);
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect?->fresh()->state);
    }

    public function test_checkout_without_customer_phone_uses_merchant_phone_and_delivers_provider_qr(): void
    {
        $this->fakeTelegramHttp();
        [$order, $payment] = $this->payment([
            'invoice_number' => 'INV-SAK-TELEGRAM-NO-PHONE',
            'provider_reference' => null,
            'provider_payment_method' => null,
            'provider_total_payment' => null,
            'provider_fee' => null,
            'provider_created_at' => null,
            'provider_expires_at' => null,
            'provider_presentation' => null,
            'provider_payload' => null,
            'provider_create_state' => null,
        ]);
        $order->customer()->update(['phone_number' => null]);
        $order->update([
            'support_contact_channel' => null,
            'support_contact_value' => null,
        ]);
        ManualPaymentSetting::create([
            'payment_mode' => 'sakurupiah',
            'sakurupiah_channel' => 'QRISC',
            'is_active' => true,
            'whatsapp_support_number' => '08123456789',
        ]);
        $invoice = new SakurupiahInvoice(
            trxId: 'trx-no-phone',
            merchantRef: (string) $payment->merchant_ref,
            paymentCode: SakurupiahCreateInvoice::METHOD_QRISC,
            status: SakurupiahTransactionStatus::Pending,
            total: 20_000,
            amount: 20_000,
            fee: SakurupiahFee::from(450),
            createdAt: now()->subMinute()->toImmutable(),
            expiresAt: now()->addMinutes(20)->toImmutable(),
            qr: self::DIRECT_QRIS_20_000,
            merchantFee: 1,
        );
        $this->mock(SakurupiahProviderAdapter::class)
            ->shouldReceive('createInvoice')
            ->once()
            ->withArgs(function (SakurupiahCreateInvoice $request): bool {
                $payload = $request->toProviderPayload('api-test', 'test-signature');

                return $request->phone === '628123456789'
                    && ($payload['phone'] ?? null) === '628123456789';
            })
            ->andReturn($invoice);

        $effect = app(TelegramOutboundEffectService::class)->recordCheckoutInstructions($order, $payment);
        (new SendSakurupiahCheckoutInstructions($order->id, null, $effect?->id))
            ->handle(app(TelegramCheckoutIntents::class));

        $sendPhotos = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $sendMessages = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'));
        $this->assertCount(1, $sendPhotos);
        $this->assertCount(0, $sendMessages);
        $this->assertStringContainsString('Rp20.000', $sendPhotos->first()[0]->body());
        $this->assertSame(SakurupiahInvoiceLifecycleService::STATE_CREATED, $payment->fresh()->provider_create_state);
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect?->fresh()->state);
    }

    public function test_hosted_sakurupiah_effect_sends_the_checkout_link_without_a_fake_payment_qr(): void
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
        $sendMessages = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'));
        $this->assertCount(0, $sendPhotos);
        $this->assertCount(1, $sendMessages);
        $request = $sendMessages->first()[0];
        $text = (string) $this->telegramRequestField($request, 'text');
        $replyMarkup = $this->telegramRequestField($request, 'reply_markup');
        $this->assertIsArray($replyMarkup);
        $this->assertSame('https://sakurupiah.id/pay/invoice', $replyMarkup['inline_keyboard'][0][0]['url']);
        $this->assertStringContainsString('Rp20.450', $text);
        $this->assertStringContainsString('Pay the exact final amount shown', $text);
        $this->assertStringNotContainsString('provider-secret', $text);
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
                'qris_payload' => 'stale-provider-qr-content',
                'checkout_url' => 'https://sakurupiah.id/pay/invoice?token=redacted',
            ],
        ]);
        $status = new SakurupiahStatus(
            trxId: (string) $payment->provider_reference,
            status: SakurupiahTransactionStatus::Pending,
            merchantRef: (string) $payment->merchant_ref,
            paymentCode: SakurupiahCreateInvoice::METHOD_QRISC,
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

    public function test_reconciliation_recovers_rejected_create_before_history_and_sends_one_valid_png(): void
    {
        config()->set('services.sakurupiah.reconcile_max_execution_seconds', 1);
        [$order, $payment] = $this->payment([
            'invoice_number' => 'INV-SAK-RECOVERY-PRESSURE',
            'provider_reference' => null,
            'provider_payment_method' => null,
            'provider_total_payment' => null,
            'provider_fee' => null,
            'provider_created_at' => null,
            'provider_expires_at' => null,
            'provider_payload' => null,
            'provider_create_state' => null,
        ]);
        ManualPaymentSetting::create([
            'payment_mode' => 'sakurupiah',
            'sakurupiah_channel' => 'QRISC',
            'is_active' => true,
            'whatsapp_support_number' => '08123456789',
        ]);
        for ($index = 0; $index < 12; $index++) {
            $terminalOrder = Order::factory()->create([
                'order_status' => OrderStatus::Expired,
                'payment_status' => PaymentStatus::Expired,
                'expires_at' => now()->subHour(),
            ]);
            Payment::factory()->for($terminalOrder)->create([
                'provider' => 'sakurupiah',
                'method' => 'QRIS',
                'currency' => 'IDR',
                'status' => PaymentStatus::Expired,
                'provider_reference' => 'trx-history-'.$index,
                'provider_create_state' => 'created',
                'created_at' => now()->subDay(),
                'expired_at' => now()->subHour(),
            ]);
        }
        $creates = 0;
        $references = [];
        $this->fakeTelegramHttp([
            'https://sakurupiah.id/api-sanbox/create.php' => function (Request $request) use (&$creates, &$references) {
                $references[] = $request['merchant_ref'];
                if (++$creates === 1) {
                    return Http::response(['status' => '400', 'message' => 'Permintaan terlalu cepat silahkan coba lagi nanti.']);
                }

                return Http::response(['status' => '200', 'data' => [[
                    'trx_id' => 'trx-recovered',
                    'merchant_ref' => $request['merchant_ref'],
                    'payment_kode' => 'QRISC',
                    'payment_status' => 'pending',
                    'amount' => '20000',
                    'total' => '20000',
                    'fee' => '0',
                    'merchant_fee' => 'Merchant',
                    'qr' => self::DIRECT_QRIS_20_000,
                    'expired' => now()->addDay()->format('Y-m-d H:i:s'),
                ]]]);
            },
            'https://sakurupiah.id/api-sanbox/status-transaction.php' => function (Request $request) {
                if ($request['trx_id'] !== 'trx-recovered') {
                    ProviderVerificationProfiles::pause(1_000);
                }

                return Http::response(['status' => '200', 'data' => [[
                    'trx_id' => $request['trx_id'],
                    'payment_status' => 'pending',
                ]]]);
            },
        ]);
        $initial = app(TelegramOutboundEffectService::class)->recordCheckoutInstructions($order, $payment);
        (new SendSakurupiahCheckoutInstructions($order->id, null, $initial->id))
            ->handle(app(TelegramCheckoutIntents::class));
        $this->assertSame('retry_notice_sent', $initial->fresh()->outcome_metadata['delivery_outcome'] ?? null);
        $this->assertSame('create_failed', $payment->fresh()->provider_create_state);
        $this->assertCount(0, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto')));
        $this->artisan('sakurupiah:reconcile --chunk=2')
            ->expectsOutputToContain('stop_reason=provider_throttled')
            ->assertExitCode(1);
        $this->assertSame(1, $creates);
        $this->travel(61)->seconds();
        $deadline = $order->expires_at->toIso8601String();

        $this->artisan('sakurupiah:reconcile --chunk=2')->assertExitCode(0);
        $this->artisan('sakurupiah:reconcile --chunk=2')->assertExitCode(0);

        $recovery = TelegramOutboundEffect::query()->where('effect_key',
            TelegramOutboundEffectService::sakurupiahPresentationRecoveryEffectKey((string) $payment->id))->sole();
        $this->assertSame(TelegramOutboundEffectState::Sent, $recovery->state);
        $this->assertSame('qris_sent', $recovery->outcome_metadata['delivery_outcome'] ?? null);
        $this->assertSame([(string) $payment->merchant_ref, (string) $payment->merchant_ref], $references);
        $photos = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertCount(1, $photos);
        $this->assertStringContainsString("\x89PNG\r\n\x1A\n", $photos->sole()[0]->body());
        $this->assertSame($deadline, $order->fresh()->expires_at->toIso8601String());
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertSame('trx-recovered', $payment->fresh()->provider_reference);
        $this->assertSame(20_000, (int) $payment->fresh()->amount);
        $this->assertSame('QRISC', $payment->fresh()->provider_payment_method);
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

    public function test_qris_image_presentation_delivers_the_provider_photo_and_checkout_link(): void
    {
        $imageUrl = 'https://sakurupiah.id/qris/trx-qr-image.png';
        $checkoutUrl = 'https://sakurupiah.id/pay/invoice?token=redacted';
        $this->fakeTelegramHttp([
            $imageUrl => Http::response("\x89PNG\r\n\x1A\n".'provider-qr-image', 200, ['Content-Type' => 'image/png']),
        ]);
        [$order, $payment] = $this->payment([
            'invoice_number' => 'INV-SAK-TELEGRAM-QR-IMAGE',
            'provider_presentation' => [
                'mode' => 'qris_image',
                'qris_image_url' => $imageUrl,
                'checkout_url' => $checkoutUrl,
            ],
        ]);

        $effect = app(TelegramOutboundEffectService::class)->recordCheckoutInstructions($order, $payment);
        (new SendSakurupiahCheckoutInstructions($order->id, null, $effect?->id))
            ->handle(app(TelegramCheckoutIntents::class));

        $sendPhotos = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertCount(1, $sendPhotos);
        $body = str_replace('\\/', '/', $sendPhotos->first()[0]->body());
        $this->assertStringContainsString("\x89PNG\r\n\x1A\n", $body);
        $this->assertStringContainsString('INV-SAK-TELEGRAM-QR-IMAGE', $body);
        $this->assertStringContainsString('Rp20.450', $body);
        $this->assertStringContainsString('Check Payment', $body);
        $this->assertStringContainsString('Pay via Website', $body);
        $this->assertStringContainsString($checkoutUrl, $body);
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect?->fresh()->state);
    }

    public function test_qris_image_download_failure_falls_back_to_the_checkout_link_message(): void
    {
        $checkoutUrl = 'https://sakurupiah.id/pay/invoice?token=redacted';
        $this->fakeTelegramHttp([
            'https://sakurupiah.id/qris/*' => Http::response('provider-image-unavailable', 503),
        ]);
        [$order, $payment] = $this->payment([
            'invoice_number' => 'INV-SAK-TELEGRAM-QR-IMAGE-FALLBACK',
            'provider_presentation' => [
                'mode' => 'qris_image',
                'qris_image_url' => 'https://sakurupiah.id/qris/trx-qr-image-fallback.png',
                'checkout_url' => $checkoutUrl,
            ],
        ]);

        $effect = app(TelegramOutboundEffectService::class)->recordCheckoutInstructions($order, $payment);
        (new SendSakurupiahCheckoutInstructions($order->id, null, $effect?->id))
            ->handle(app(TelegramCheckoutIntents::class));

        $this->assertCount(0, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto')));
        $sendMessages = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'));
        $this->assertCount(1, $sendMessages);
        $body = str_replace('\\/', '/', $sendMessages->first()[0]->body());
        $this->assertStringContainsString('INV-SAK-TELEGRAM-QR-IMAGE-FALLBACK', $body);
        $this->assertStringContainsString('Pay via Website', $body);
        $this->assertStringContainsString($checkoutUrl, $body);
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect?->fresh()->state);
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
            'method' => SakurupiahCreateInvoice::PAYMENT_METHOD_QRIS,
            'provider_reference' => 'trx-'.$order->invoice_number,
            'provider_payment_method' => SakurupiahCreateInvoice::METHOD_QRISC,
            'provider_total_payment' => 20_450,
            'provider_fee' => 450,
            'provider_created_at' => now()->subMinute(),
            'provider_expires_at' => $expiresAt,
            'provider_presentation' => $presentation,
            'provider_payload' => [
                'data' => [[
                    'trx_id' => 'trx-'.$order->invoice_number,
                    'merchant_ref' => $order->invoice_number,
                    'payment_kode' => 'QRISC',
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
