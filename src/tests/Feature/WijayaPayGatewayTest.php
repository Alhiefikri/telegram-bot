<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\CartService;
use App\Services\Checkout\PaymentProviderResolver;
use App\Services\OrderCheckoutService;
use App\Services\WijayaPay\WijayaPayCallback;
use App\Services\WijayaPay\WijayaPayChannel;
use App\Services\WijayaPay\WijayaPayCreateInvoice;
use App\Services\WijayaPay\WijayaPayInvoice;
use App\Services\WijayaPay\WijayaPayInvoiceLifecycleService;
use App\Services\WijayaPay\WijayaPayInvoiceOutcome;
use App\Services\WijayaPay\WijayaPayProviderAdapter;
use App\Services\WijayaPay\WijayaPayProviderException;
use App\Services\WijayaPay\WijayaPayProviderReadiness;
use App\Services\WijayaPay\WijayaPaySignature;
use App\Services\WijayaPay\WijayaPayStatus;
use App\Services\WijayaPay\WijayaPayStatusResponse;
use App\Support\PaymentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class WijayaPayGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const CODE_MERCHANT = 'WP692f1c7bafd86';

    private const API_KEY = 'wijayapay-secret-key';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.wijayapay', array_replace(config('services.wijayapay', []), [
            'base_url' => 'https://gateway.wijayapay.com',
            'code_merchant' => self::CODE_MERCHANT,
            'api_key' => self::API_KEY,
            'callback_url' => 'https://store.example.test/api/webhook/wijayapay',
            'return_url' => 'https://store.example.test/shop/orders',
            'allowed_hosts' => ['gateway.wijayapay.com', 'wijayapay.com'],
            'callback_allowed_hosts' => ['store.example.test'],
            'return_allowed_hosts' => ['store.example.test'],
            'callback_enforce_ip_allowlist' => false,
            'readiness_cache_seconds' => 60,
            'qris_contract' => [
                'currency' => 'IDR',
                'currency_confirmed' => true,
                'max_fee_bps' => 500,
                'expiry_confirmed' => true,
            ],
        ]));
        config()->set('queue.default', 'sync');
        Cache::flush();
    }

    public function test_wijayapay_is_resolved_to_a_typed_idr_qris_checkout_without_create_http(): void
    {
        Queue::fake();
        Http::fake();
        ManualPaymentSetting::create(['payment_mode' => PaymentProvider::WIJAYAPAY, 'is_active' => true]);
        $product = Product::factory()->create(['price_amount' => 12_000, 'stock' => 2, 'reserved_stock' => 0]);
        $customer = Customer::factory()->create(['telegram_id' => null]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->setItemQuantity($cart, $product, 1);
        $cart = $cart->fresh(['cartItems']);

        $provider = Mockery::mock(WijayaPayProviderAdapter::class);
        $provider->shouldReceive('listChannels')->once()->andReturn([
            new WijayaPayChannel(
                code: 'QRIS',
                name: 'QRIS',
                group: 'QRIS',
                status: 'active',
                feeAmount: 84,
                feePercentBps: 70,
                minimumAmount: 200,
                maximumAmount: 20_000_000,
                feePayer: 'customer',
            ),
        ]);
        $this->app->instance(WijayaPayProviderAdapter::class, $provider);

        $setting = ManualPaymentSetting::current();
        $selection = app(PaymentProviderResolver::class)->resolve('qris', $setting);
        $order = app(OrderCheckoutService::class)->checkout($cart, $selection->paymentIntent);
        $payment = $order->payments()->sole();

        $this->assertSame(PaymentProvider::WIJAYAPAY, $selection->provider);
        $this->assertSame('QRIS', $payment->method);
        $this->assertSame('IDR', $payment->currency);
        $this->assertSame(12_000, $payment->amount);
        $this->assertSame($order->expires_at->timestamp, $payment->expired_at->timestamp);
        $this->assertSame(1, Payment::query()->count());
        Http::assertNothingSent();
    }

    public function test_channel_observation_is_cached_and_single_flight(): void
    {
        $provider = Mockery::mock(WijayaPayProviderAdapter::class);
        $provider->shouldReceive('listChannels')->once()->andReturn([
            new WijayaPayChannel(
                code: 'QRIS',
                name: 'QRIS',
                group: 'QRIS',
                status: 'active',
                feeAmount: 84,
                feePercentBps: 70,
                minimumAmount: 200,
                maximumAmount: 20_000_000,
                feePayer: 'customer',
            ),
        ]);
        $this->app->instance(WijayaPayProviderAdapter::class, $provider);
        $readiness = app(WijayaPayProviderReadiness::class);

        $first = $readiness->observe();
        $second = $readiness->observe();

        $this->assertTrue($first->isReady());
        $this->assertTrue($second->isReady());
        $this->assertTrue($second->channelObserved());
        $this->assertSame('QRIS', $second->qrisChannel()?->code);
    }

    public function test_inactive_qris_channel_fails_readiness_closed(): void
    {
        $provider = Mockery::mock(WijayaPayProviderAdapter::class);
        $provider->shouldReceive('listChannels')->once()->andReturn([
            new WijayaPayChannel(
                code: 'QRIS',
                name: 'QRIS',
                group: 'QRIS',
                status: 'inactive',
                feeAmount: 0,
                feePercentBps: 0,
                minimumAmount: 200,
                maximumAmount: 20_000_000,
                feePayer: 'customer',
            ),
        ]);
        $this->app->instance(WijayaPayProviderAdapter::class, $provider);

        $report = app(WijayaPayProviderReadiness::class)->observe();

        $this->assertFalse($report->isReady());
        $this->assertSame('channel_inactive', $report->failureReason());
    }

    public function test_signature_matches_the_documented_md5_concatenation(): void
    {
        $expected = md5(self::CODE_MERCHANT.self::API_KEY.'INV-20251215-001');

        $this->assertSame($expected, WijayaPaySignature::create(
            self::CODE_MERCHANT,
            self::API_KEY,
            'INV-20251215-001',
        ));
        $this->assertTrue(WijayaPaySignature::verifyCallback(
            self::CODE_MERCHANT,
            self::API_KEY,
            'INV-20251215-001',
            strtoupper($expected),
        ));
        $this->assertFalse(WijayaPaySignature::verifyCallback(
            self::CODE_MERCHANT,
            self::API_KEY,
            'INV-20251215-001',
            md5('wrong'),
        ));
    }

    public function test_create_uses_json_signature_header_and_never_logs_credentials(): void
    {
        Http::fake([
            'gateway.wijayapay.com/api/transaction/create' => Http::response([
                'success' => true,
                'data' => [
                    'payment_name' => 'QRIS',
                    'payment_method' => 'QRIS',
                    'total_bayar' => 12_500,
                    'total_fee' => 500,
                    'total_diterima' => 12_000,
                    'ref_id' => 'INV-20251215-001',
                    'trx_reference' => 'WPTRX825527',
                    'expired' => now()->addMinutes(15)->format('Y-m-d H:i:s'),
                    'qr_string' => '0002010102125303360540820450.005802ID63045B6D',
                    'qr_image' => 'https://wijayapay.com/qris/test.png',
                    'status_pembayaran' => 'pending',
                ],
            ]),
        ]);

        $invoice = app(WijayaPayProviderAdapter::class)->createInvoice(new WijayaPayCreateInvoice(
            codeMerchant: self::CODE_MERCHANT,
            apiKey: self::API_KEY,
            paymentMethod: WijayaPayCreateInvoice::PAYMENT_METHOD_QRIS,
            refId: 'INV-20251215-001',
            amount: 12_000,
            callbackUrl: 'https://store.example.test/api/webhook/wijayapay',
            customerName: 'John Doe',
        ));

        $this->assertSame(WijayaPayStatus::Pending, $invoice->status);
        $this->assertSame('customer', $invoice->feePayer);
        $this->assertSame(12_500, $invoice->totalValue());
        $this->assertTrue($invoice->customerPaysFee());

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->url() === 'https://gateway.wijayapay.com/api/transaction/create'
                && $request->method() === 'POST'
                && $request->hasHeader('X-Signature', WijayaPaySignature::create(
                    self::CODE_MERCHANT,
                    self::API_KEY,
                    'INV-20251215-001',
                ))
                && ($body['code_merchant'] ?? null) === self::CODE_MERCHANT
                && ($body['payment_method'] ?? null) === 'QRIS'
                && ($body['nominal'] ?? null) === 12_000
                && ($body['ref_id'] ?? null) === 'INV-20251215-001';
        });
    }

    public function test_duplicate_ref_create_response_is_treated_as_existing_invoice(): void
    {
        Http::fake([
            'gateway.wijayapay.com/api/transaction/create' => Http::response([
                'data' => [
                    'payment_name' => 'QRIS',
                    'payment_method' => 'QRIS',
                    'total_bayar' => 12_000,
                    'total_fee' => 84,
                    'total_diterima' => 11_916,
                    'ref_id' => 'INV-20251215-002',
                    'trx_reference' => 'WPTRX825528',
                    'expired' => now()->addMinutes(15)->format('Y-m-d H:i:s'),
                    'qr_image' => 'https://wijayapay.com/qris/test.png',
                ],
                'status_pembayaran' => 'pending',
            ]),
        ]);

        $invoice = app(WijayaPayProviderAdapter::class)->createInvoice(new WijayaPayCreateInvoice(
            codeMerchant: self::CODE_MERCHANT,
            apiKey: self::API_KEY,
            paymentMethod: WijayaPayCreateInvoice::PAYMENT_METHOD_QRIS,
            refId: 'INV-20251215-002',
            amount: 12_000,
            callbackUrl: 'https://store.example.test/api/webhook/wijayapay',
        ));

        $this->assertSame('WPTRX825528', $invoice->trxReference);
        $this->assertSame('store', $invoice->feePayer);
    }

    public function test_lifecycle_persists_safe_snapshot_and_reuses_it_without_second_create(): void
    {
        Queue::fake();
        ManualPaymentSetting::create(['payment_mode' => PaymentProvider::WIJAYAPAY, 'is_active' => true]);
        $payment = $this->pendingPayment(prepared: false);
        $provider = Mockery::mock(WijayaPayProviderAdapter::class);
        $provider->shouldReceive('createInvoice')->once()->andReturn($this->invoice($payment));
        $provider->shouldReceive('transactionStatus')->once()->with((string) $payment->merchant_ref)->andReturnUsing(
            fn (): WijayaPayStatusResponse => $this->providerStatus($payment->fresh(), WijayaPayStatus::Pending),
        );
        $this->app->instance(WijayaPayProviderAdapter::class, $provider);
        $lifecycle = app(WijayaPayInvoiceLifecycleService::class);

        $first = $lifecycle->lifecycle($payment);
        $second = $lifecycle->lifecycle($payment);

        $this->assertSame(WijayaPayInvoiceOutcome::Payable, $first->outcome());
        $this->assertSame(WijayaPayInvoiceOutcome::Payable, $second->outcome());
        $this->assertSame('WPTRX-1', $payment->fresh()->provider_reference);
        $this->assertSame(WijayaPayInvoiceLifecycleService::STATE_CREATED, $payment->fresh()->provider_create_state);
        $this->assertSame('QRIS', $payment->fresh()->provider_payment_method);
        $this->assertSame(12_500, $payment->fresh()->provider_total_payment);
        $this->assertSame(500, $payment->fresh()->provider_fee);
        $this->assertSame(1, Payment::query()->count());
        $this->assertStringNotContainsString(self::API_KEY, json_encode($payment->fresh()->provider_payload));
    }

    public function test_paid_callback_requires_authoritative_paid_status_before_fulfillment(): void
    {
        Queue::fake();
        ManualPaymentSetting::create(['payment_mode' => PaymentProvider::WIJAYAPAY, 'is_active' => true]);
        $payment = $this->pendingPayment(managed: true);
        $provider = Mockery::mock(WijayaPayProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->once()->andReturn($this->providerStatus($payment, WijayaPayStatus::Pending));
        $this->app->instance(WijayaPayProviderAdapter::class, $provider);

        $response = $this->postCallback($this->callbackBody($payment, status: 'paid'));

        $response->assertOk()->assertExactJson(['status' => true]);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $payment->order->fresh()->order_status);
        $this->assertNull(PaymentEvent::query()->where('event_type', PaymentEventType::Callback->value)->sole()->processed_at);
    }

    public function test_verified_paid_callback_fulfills_exactly_once(): void
    {
        Queue::fake();
        ManualPaymentSetting::create(['payment_mode' => PaymentProvider::WIJAYAPAY, 'is_active' => true]);
        $payment = $this->pendingPayment(managed: true);
        $provider = Mockery::mock(WijayaPayProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->once()->andReturn($this->providerStatus($payment, WijayaPayStatus::Paid));
        $this->app->instance(WijayaPayProviderAdapter::class, $provider);

        $body = $this->callbackBody($payment, status: 'paid');
        $this->postCallback($body)->assertOk()->assertExactJson(['status' => true]);
        $this->postCallback($body)->assertOk()->assertExactJson(['status' => true]);

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Completed, $payment->order->fresh()->order_status);
        $this->assertSame(1, PaymentEvent::query()->where('event_type', PaymentEventType::Callback->value)->count());
        $this->assertNotNull(PaymentEvent::query()->where('event_type', PaymentEventType::Callback->value)->sole()->processed_at);
    }

    public function test_not_found_recovery_retries_the_same_reference_after_the_interval(): void
    {
        Queue::fake();
        ManualPaymentSetting::create(['payment_mode' => PaymentProvider::WIJAYAPAY, 'is_active' => true]);
        $payment = $this->pendingPayment(prepared: false);
        $payment->forceFill([
            'provider_create_state' => WijayaPayInvoiceLifecycleService::STATE_OUTCOME_UNKNOWN,
            'provider_create_attempted_at' => now()->subMinutes(2),
        ])->save();
        $provider = Mockery::mock(WijayaPayProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->once()->andThrow(new WijayaPayProviderException(
            'WijayaPay transactionStatus request failed.',
            operation: 'transactionStatus',
            providerMessage: 'Transaksi tidak ditemukan',
            reason: 'application_rejection',
        ));
        $provider->shouldReceive('createInvoice')->once()->andReturn($this->invoice($payment));
        $this->app->instance(WijayaPayProviderAdapter::class, $provider);

        $result = app(WijayaPayInvoiceLifecycleService::class)->lifecycle($payment);

        $this->assertSame(WijayaPayInvoiceOutcome::Payable, $result->outcome());
        $this->assertSame('WPTRX-1', $payment->fresh()->provider_reference);
        $this->assertSame(WijayaPayInvoiceLifecycleService::STATE_CREATED, $payment->fresh()->provider_create_state);
    }

    public function test_ambiguous_create_paid_status_with_customer_fee_is_not_misclassified(): void
    {
        Queue::fake();
        ManualPaymentSetting::create(['payment_mode' => PaymentProvider::WIJAYAPAY, 'is_active' => true]);
        $payment = $this->pendingPayment(prepared: false);
        $payment->forceFill(['provider_create_state' => WijayaPayInvoiceLifecycleService::STATE_OUTCOME_UNKNOWN])->save();
        $provider = Mockery::mock(WijayaPayProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->once()->andReturn(
            new WijayaPayStatusResponse(
                refId: (string) $payment->merchant_ref,
                trxReference: 'WPTRX-AMBIGUOUS',
                paymentMethod: 'QRIS',
                status: WijayaPayStatus::Paid,
                totalBayar: 12_500,
                totalDibayar: 12_500,
                totalFee: 500,
                amountReceived: 12_000,
                expiresAt: CarbonImmutable::now()->addMinutes(10),
                updatedAt: CarbonImmutable::now(),
                sanitizedPayload: ['status_pembayaran' => 'paid'],
            ),
        );
        $this->app->instance(WijayaPayProviderAdapter::class, $provider);

        $result = app(WijayaPayInvoiceLifecycleService::class)->refreshStatus($payment);

        $this->assertSame(WijayaPayInvoiceOutcome::Paid, $result->outcome());
        $this->assertSame(WijayaPayInvoiceLifecycleService::STATE_PROVIDER_PAID, $payment->fresh()->provider_create_state);
    }

    public function test_customer_fee_paid_status_is_accepted_before_the_create_snapshot_is_persisted(): void
    {
        Queue::fake();
        ManualPaymentSetting::create(['payment_mode' => PaymentProvider::WIJAYAPAY, 'is_active' => true]);
        $payment = $this->pendingPayment(managed: true, prepared: false);
        $provider = Mockery::mock(WijayaPayProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->once()->andReturn(
            new WijayaPayStatusResponse(
                refId: (string) $payment->merchant_ref,
                trxReference: 'WPTRX-CUSTOMER-FEE',
                paymentMethod: 'QRIS',
                status: WijayaPayStatus::Paid,
                totalBayar: 12_500,
                totalDibayar: 12_500,
                totalFee: 500,
                amountReceived: 12_000,
                expiresAt: CarbonImmutable::now()->addMinutes(10),
                updatedAt: CarbonImmutable::now(),
                sanitizedPayload: ['status_pembayaran' => 'paid'],
            ),
        );
        $this->app->instance(WijayaPayProviderAdapter::class, $provider);

        $body = json_encode([
            'status' => 'paid',
            'data' => [
                'payment_methode' => 'QRIS',
                'total_dibayar' => 12_500,
                'total_fee' => 500,
                'amount_received' => 12_000,
                'trx_reference' => 'WPTRX-CUSTOMER-FEE',
                'ref_id' => $payment->merchant_ref,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->postCallback($body)->assertOk()->assertExactJson(['status' => true]);

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(12_500, $payment->fresh()->provider_total_payment);
        $this->assertSame(500, $payment->fresh()->provider_fee);
    }

    public function test_invalid_callback_signature_is_rejected_without_mutation(): void
    {
        Queue::fake();
        $payment = $this->pendingPayment();

        $response = $this->call('POST', '/api/webhook/wijayapay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => md5('forged'),
        ], $this->callbackBody($payment, status: 'paid'));

        $response->assertStatus(401);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, PaymentEvent::query()->count());
    }

    public function test_status_outage_keeps_payment_pending_and_records_safe_evidence(): void
    {
        Queue::fake();
        ManualPaymentSetting::create(['payment_mode' => PaymentProvider::WIJAYAPAY, 'is_active' => true]);
        $payment = $this->pendingPayment();
        $provider = Mockery::mock(WijayaPayProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->andThrow(new WijayaPayProviderException(
            'WijayaPay transport failed',
            operation: 'transactionStatus',
            reason: 'transport',
        ));
        $this->app->instance(WijayaPayProviderAdapter::class, $provider);

        $this->postCallback($this->callbackBody($payment, status: 'paid'))->assertOk();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(WijayaPayInvoiceLifecycleService::STATE_CREATED, $payment->fresh()->provider_create_state);
        $this->assertTrue(PaymentEvent::query()
            ->where('provider', PaymentProvider::WIJAYAPAY)
            ->where('event_type', PaymentEventType::Reconciliation->value)
            ->where('provider_status', WijayaPayInvoiceLifecycleService::STATE_RECOVERY_UNCONFIRMED)
            ->exists());
    }

    public function test_callback_duplicate_keys_are_rejected(): void
    {
        $this->assertTrue(WijayaPayCallback::hasDuplicateKeys(
            '{"status":"paid","data":{"ref_id":"A","ref_id":"B"}}',
        ));
        $this->assertFalse(WijayaPayCallback::hasDuplicateKeys(
            '{"status":"paid","data":{"ref_id":"A","total_dibayar":1000}}',
        ));
    }

    private function pendingPayment(bool $managed = false, bool $prepared = true): Payment
    {
        $amount = 12_000;
        $customer = Customer::factory()->create([
            'phone_number' => '08123456789',
            'email' => 'buyer+'.bin2hex(random_bytes(4)).'@example.test',
        ]);
        $order = Order::factory()->for($customer, 'customer')->create([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'total_amount' => $amount,
            'currency' => 'IDR',
            'expires_at' => now()->addHours(2),
        ]);
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 1]);
        $orderItem = OrderItem::factory()->for($order, 'order')->for($product, 'product')->create([
            'quantity' => 1,
            'unit_price' => $amount,
            'subtotal' => $amount,
        ]);
        if ($managed) {
            ProductStockItem::create([
                'product_id' => $product->id,
                'order_item_id' => $orderItem->id,
                'secret' => 'wijayapay-fixture@example.test|password',
            ]);
        }
        InventoryMovement::create([
            'product_id' => $product->id,
            'order_id' => $order->id,
            'type' => InventoryMovementType::Reservation,
            'quantity' => 1,
            'notes' => 'Order reservation',
            'created_at' => now(),
        ]);

        return Payment::factory()->for($order, 'order')->create([
            'provider' => PaymentProvider::WIJAYAPAY,
            'merchant_ref' => 'INV-WP-'.$order->id,
            'provider_reference' => $prepared ? 'WPTRX-'.$order->id : null,
            'provider_payment_method' => $prepared ? 'QRIS' : null,
            'provider_total_payment' => $prepared ? 12_000 : null,
            'provider_fee' => $prepared ? 84 : null,
            'provider_create_state' => $prepared ? WijayaPayInvoiceLifecycleService::STATE_CREATED : null,
            'method' => WijayaPayCreateInvoice::PAYMENT_METHOD_QRIS,
            'amount' => $amount,
            'currency' => 'IDR',
            'status' => PaymentStatus::Pending,
            'expired_at' => now()->addHours(2),
            'provider_expires_at' => $prepared ? now()->addHours(2) : null,
            'provider_presentation' => $prepared ? [
                'mode' => 'qris_image',
                'qris_payload' => null,
                'qris_image_url' => 'https://wijayapay.com/qris/test.png',
                'customer_pays_fee' => false,
                'effective_expires_at' => now()->addHours(2)->toIso8601String(),
            ] : null,
        ]);
    }

    private function invoice(Payment $payment): WijayaPayInvoice
    {
        return new WijayaPayInvoice(
            refId: (string) $payment->merchant_ref,
            trxReference: 'WPTRX-1',
            paymentMethod: WijayaPayCreateInvoice::PAYMENT_METHOD_QRIS,
            status: WijayaPayStatus::Pending,
            totalBayar: 12_500,
            totalFee: 500,
            totalDiterima: 12_000,
            feePayer: 'customer',
            expiresAt: CarbonImmutable::now()->addMinutes(15),
            updatedAt: CarbonImmutable::now(),
            qrString: null,
            qrImageUrl: 'https://wijayapay.com/qris/test.png',
            sanitizedPayload: [
                'success' => true,
                'message' => 'ok',
                'api_key' => self::API_KEY,
                'code_merchant' => self::CODE_MERCHANT,
                'data' => [
                    'ref_id' => $payment->merchant_ref,
                    'trx_reference' => 'WPTRX-1',
                    'payment_method' => 'QRIS',
                    'total_bayar' => 12_500,
                    'total_fee' => 500,
                    'total_diterima' => 12_000,
                ],
            ],
        );
    }

    private function providerStatus(Payment $payment, WijayaPayStatus $status): WijayaPayStatusResponse
    {
        $paid = $status === WijayaPayStatus::Paid;
        $total = (int) ($payment->provider_total_payment ?? $payment->amount);
        $fee = (int) ($payment->provider_fee ?? 0);

        return new WijayaPayStatusResponse(
            refId: (string) $payment->merchant_ref,
            trxReference: (string) $payment->provider_reference,
            paymentMethod: 'QRIS',
            status: $status,
            totalBayar: $total,
            totalDibayar: $paid ? $total : null,
            totalFee: $fee,
            amountReceived: $paid ? max(0, $total - $fee) : null,
            expiresAt: CarbonImmutable::now()->addHours(1),
            updatedAt: CarbonImmutable::now(),
            sanitizedPayload: ['status_pembayaran' => $status->value, 'data' => ['ref_id' => $payment->merchant_ref]],
        );
    }

    private function callbackBody(Payment $payment, string $status): string
    {
        return json_encode([
            'status' => $status,
            'data' => [
                'updated_at' => now()->format('Y-m-d H:i:s'),
                'payment_methode' => 'QRIS',
                'total_dibayar' => 12_000,
                'total_fee' => 84,
                'amount_received' => 11_916,
                'trx_reference' => $payment->provider_reference,
                'ref_id' => $payment->merchant_ref,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function postCallback(string $body)
    {
        return $this->call('POST', '/api/webhook/wijayapay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => WijayaPaySignature::create(
                self::CODE_MERCHANT,
                self::API_KEY,
                $this->paymentRefFromBody($body),
            ),
        ], $body);
    }

    private function paymentRefFromBody(string $body): string
    {
        $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);

        return (string) $payload['data']['ref_id'];
    }
}
