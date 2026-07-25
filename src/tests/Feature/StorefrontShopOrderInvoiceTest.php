<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Storefront\StorefrontInvoiceUrl;
use App\Storefront\StorefrontShopPaymentRefreshCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\Support\StorefrontSignedInvoiceScenarios;
use Tests\TestCase;

class StorefrontShopOrderInvoiceTest extends TestCase
{
    use RefreshDatabase;
    use StorefrontSignedInvoiceScenarios;

    public function test_signed_pending_invoice_renders_native_qris_payment_contract_without_revealing_allocated_secret_or_provider_brand(): void
    {
        $this->pakasirConfigIsReady();
        $this->qrisRendererReturnsDeterministicPng();
        $paymentNumber = '00020101021226610016ID.CO.QRIS.ISSUE121NATIVE99';
        $secret = 'buyer-account@example.test|NeverBeforePaid';
        [$order, $payment] = $this->pendingNativeQrisOrder(
            totalAmount: 120000,
            providerFee: 1500,
            providerTotalPayment: 121500,
            paymentNumber: $paymentNumber,
            secret: $secret,
        );

        $response = $this->get(URL::signedRoute('storefront.shop.orders.show', $order, null, false));

        $expectedQrDataUri = 'data:image/png;base64,'.base64_encode('png-for:'.$paymentNumber);
        $response
            ->assertOk()
            ->assertSee($order->invoice_number)
            ->assertSee('Total produk')
            ->assertSee('Rp120.000')
            ->assertSee('Biaya QRIS')
            ->assertSee('Rp1.500')
            ->assertSee('Total bayar QRIS')
            ->assertSee('Rp121.500')
            ->assertSee($expectedQrDataUri, false)
            ->assertSee('Pembayaran QRIS')
            ->assertSeeInOrder([
                'Total bayar QRIS',
                'Rp121.500',
                'Batas bayar',
                $expectedQrDataUri,
                'Cek status pembayaran',
                'Ringkasan invoice',
            ], false)
            ->assertDontSee('Bayar via Website Pakasir')
            ->assertDontSee('https://app.pakasir.test/pay/lhie-test/120000', false)
            ->assertDontSee('Pakasir')
            ->assertSee('data-shop-payment-status', false)
            ->assertSee('data-status-url=', false)
            ->assertSee('/shop/orders/'.$order->getKey().'/status', false)
            ->assertSee('data-redirect-url=', false)
            ->assertSee('/shop/orders/'.$order->getKey(), false)
            ->assertSee('Batas bayar')
            ->assertSee('Cek status pembayaran')
            ->assertSee('Setelah membayar, tekan tombol ini untuk mengecek status QRIS.')
            ->assertDontSee('data-poll-ms', false)
            ->assertSee('Setelah pembayaran terverifikasi, data akun muncul di halaman ini. Simpan link invoice ini sampai akun diterima.')
            ->assertDontSee('/qr-lhie.jpg', false)
            ->assertDontSee($secret, false);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
    }

    public function test_pending_invoice_with_missing_provider_total_blocks_qris_and_warns_not_to_pay(): void
    {
        $this->pakasirConfigIsReady();
        $this->qrisRendererReturnsDeterministicPng();
        $paymentNumber = '00020101021226610016ID.CO.QRIS.ISSUE121NOFINAL99';
        [$order] = $this->pendingNativeQrisOrder(
            totalAmount: 88000,
            providerFee: null,
            providerTotalPayment: null,
            paymentNumber: $paymentNumber,
        );

        $this->get(URL::signedRoute('storefront.shop.orders.show', $order, null, false))
            ->assertOk()
            ->assertSee('Total produk')
            ->assertSee('Rp88.000')
            ->assertSee('Total bayar QRIS')
            ->assertSee('Menunggu provider')
            ->assertSee('Provider belum memberi nominal')
            ->assertSee('QRIS sudah dibuat, tetapi total bayar final dari provider belum tersedia. Jangan bayar total produk.')
            ->assertSee('Coba ambil total QRIS')
            ->assertDontSee('data:image/png;base64,'.base64_encode('png-for:'.$paymentNumber), false)
            ->assertDontSee('Pakasir');
    }

    public function test_new_web_order_recovery_key_is_copyable_once_after_primary_payment_action(): void
    {
        $this->pakasirConfigIsReady();
        $this->qrisRendererReturnsDeterministicPng();
        [$order] = $this->pendingNativeQrisOrder(
            totalAmount: 66000,
            providerFee: 500,
            providerTotalPayment: 66500,
            paymentNumber: '00020101021226610016ID.CO.QRIS.RECOVERYKEYORDER99',
        );
        $accessKey = 'LHIE-RECOVERY-KEY-ISSUE-139';
        $order->forceFill(['credential_access_key_hash' => Hash::make($accessKey)])->save();

        $response = $this->withSession([
            'storefront.order_access.issued_key.'.$order->getKey() => Crypt::encryptString($accessKey),
        ])->get(URL::signedRoute('storefront.shop.orders.show', $order, null, false));

        $response
            ->assertOk()
            ->assertSeeInOrder([
                'QRIS invoice',
                'Total bayar QRIS',
                'Cek status pembayaran',
                'Simpan Recovery Key pesanan website ini.',
                'Salin key',
                'Ringkasan invoice',
            ])
            ->assertSee($accessKey, false)
            ->assertSee('data-copy-text="'.$accessKey.'"', false)
            ->assertSee('data-copy-success="Recovery Key tersalin."', false);

        $this->assertSame(2, substr_count($response->getContent(), $accessKey));
        $this->assertSame(1, substr_count($response->getContent(), 'data-copy-text="'.$accessKey.'"'));
    }

    public function test_invoice_support_links_use_invoice_context_without_recovery_key_or_credentials(): void
    {
        config()->set('services.support.whatsapp_number', '6281234567890');
        config()->set('services.support.telegram_admin_username', 'lhiehuman');
        $this->pakasirConfigIsReady();
        $this->qrisRendererReturnsDeterministicPng();
        $secret = 'buyer-login@example.test|PasswordShouldNotLeak|MFA-SECRET';
        [$order] = $this->pendingNativeQrisOrder(
            totalAmount: 67000,
            providerFee: 500,
            providerTotalPayment: 67500,
            paymentNumber: '00020101021226610016ID.CO.QRIS.SUPPORTCONTEXT99',
            secret: $secret,
        );
        $accessKey = 'LHIE-SUPPORT-RECOVERY-KEY';
        $order->forceFill(['credential_access_key_hash' => Hash::make($accessKey)])->save();

        $html = $this->withSession([
            'storefront.order_access.issued_key.'.$order->getKey() => Crypt::encryptString($accessKey),
        ])->get(URL::signedRoute('storefront.shop.orders.show', $order, null, false))
            ->assertOk()
            ->assertSee('Butuh bantuan?')
            ->assertSee('WhatsApp Support')
            ->assertSee('Telegram Admin')
            ->assertSee('QRIS / pembayaran belum terdeteksi')
            ->assertSee('Pembayaran gagal, expired, atau dibatalkan')
            ->assertSee('Data akun belum muncul')
            ->assertSee('Kredensial tidak valid')
            ->assertSee('MFA / Authenticator bermasalah')
            ->assertSee('Login gagal')
            ->assertSee('data-support-channel="whatsapp"', false)
            ->assertSee('data-support-channel="telegram_admin"', false)
            ->getContent();

        preg_match_all('/href="([^"]+)"[^>]+data-support-channel=/i', $html, $matches);
        $this->assertNotEmpty($matches[1]);

        foreach ($matches[1] as $href) {
            $decodedHref = rawurldecode(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $this->assertStringContainsString('Invoice: '.$order->invoice_number, $decodedHref);
            $this->assertStringContainsString('Kategori masalah:', $decodedHref);
            $this->assertStringNotContainsString($accessKey, $decodedHref);
            $this->assertStringNotContainsString($secret, $decodedHref);
            $this->assertStringNotContainsString('PasswordShouldNotLeak', $decodedHref);
            $this->assertStringNotContainsString('MFA-SECRET', $decodedHref);
        }
    }

    public function test_signed_cancel_endpoint_cancels_pending_order_releases_resources_audits_and_is_idempotent(): void
    {
        $this->pakasirConfigIsReady();
        $secret = 'cancel-me@example.test|ReleaseMe';
        [$order, $payment] = $this->pendingNativeQrisOrder(
            totalAmount: 97000,
            providerFee: 700,
            providerTotalPayment: 97700,
            paymentNumber: '00020101021226610016ID.CO.QRIS.ISSUE131CANCEL99',
            secret: $secret,
        );
        $productId = $order->orderItems->first()->product_id;
        $stockItemId = $order->orderItems->first()->stockItems->first()->id;
        $this->providerTransactionDetailIsPending($payment);
        $cancelUrl = app(StorefrontInvoiceUrl::class)->cancel($order);

        $this->post($cancelUrl)
            ->assertRedirect(app(StorefrontInvoiceUrl::class)->show($order))
            ->assertSessionHas('status', 'Pesanan dibatalkan. Stok dan data akun sudah dilepas.');

        $order->refresh();
        $payment->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->order_status);
        $this->assertSame(PaymentStatus::Failed, $order->payment_status);
        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame(0, Product::findOrFail($productId)->reserved_stock);
        $this->assertNull(ProductStockItem::findOrFail($stockItemId)->order_item_id);
        $this->assertSame(1, InventoryMovement::where('order_id', $order->id)->where('type', InventoryMovementType::Release)->count());

        $audit = AuditLog::where('action', 'order.pending_cancelled_released')->where('subject_id', (string) $order->id)->firstOrFail();
        $this->assertSame('customer', $audit->new_values['actor']);
        $this->assertSame($order->customer_id, $audit->new_values['actor_id']);
        $this->assertSame('storefront_web', $audit->new_values['channel']);
        $this->assertSame('customer_requested_cancel', $audit->new_values['reason']);

        $this->post($cancelUrl)->assertRedirect(app(StorefrontInvoiceUrl::class)->show($order));
        $this->assertSame(0, Product::findOrFail($productId)->reserved_stock);
        $this->assertNull(ProductStockItem::findOrFail($stockItemId)->order_item_id);
        $this->assertSame(1, InventoryMovement::where('order_id', $order->id)->where('type', InventoryMovementType::Release)->count());
        $this->assertSame(1, AuditLog::where('action', 'order.pending_cancelled_released')->where('subject_id', (string) $order->id)->count());

        $this->get(app(StorefrontInvoiceUrl::class)->show($order))
            ->assertOk()
            ->assertSee('Order dibatalkan')
            ->assertSee('Stok dan data akun sudah dilepas')
            ->assertSee('Stok tidak lagi dicadangkan untuk invoice ini.')
            ->assertSee('Data akun tidak dikirim dari invoice ini')
            ->assertDontSee('data-shop-payment-status', false)
            ->assertDontSee('Batalkan order &amp; lepas stok', false)
            ->assertDontSee($secret, false);
    }

    public function test_signed_cancel_endpoint_rejects_provider_paid_order_before_webhook(): void
    {
        $this->pakasirConfigIsReady();
        [$order, $payment] = $this->pendingNativeQrisOrder(
            totalAmount: 99000,
            providerFee: 700,
            providerTotalPayment: 99700,
            paymentNumber: '00020101021226610016ID.CO.QRIS.ISSUE131PAIDRACE99',
        );
        $productId = $order->orderItems->first()->product_id;
        $this->providerTransactionDetailCompletesPayment($payment);

        $this->post(app(StorefrontInvoiceUrl::class)->cancel($order))
            ->assertRedirect(app(StorefrontInvoiceUrl::class)->show($order))
            ->assertSessionHasErrors(['order' => 'Pembayaran QRIS sudah diterima, jadi pesanan tidak bisa dibatalkan.']);

        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(0, Product::findOrFail($productId)->reserved_stock);
        $this->assertSame(0, InventoryMovement::where('order_id', $order->id)->where('type', InventoryMovementType::Release)->count());
        $this->assertSame(0, AuditLog::where('action', 'order.pending_cancelled_released')->where('subject_id', (string) $order->id)->count());
    }

    public function test_signed_cancel_endpoint_rejects_pending_order_when_payment_proof_is_being_processed(): void
    {
        $this->pakasirConfigIsReady();
        [$order, $payment] = $this->pendingNativeQrisOrder(
            totalAmount: 98000,
            providerFee: 700,
            providerTotalPayment: 98700,
            paymentNumber: '00020101021226610016ID.CO.QRIS.ISSUE131PROOF99',
            secret: 'proof@example.test|KeepReserved',
        );
        $productId = $order->orderItems->first()->product_id;
        $stockItemId = $order->orderItems->first()->stockItems->first()->id;
        $payment->update(['payment_proof_submitted_at' => now()]);

        $this->post(app(StorefrontInvoiceUrl::class)->cancel($order))
            ->assertRedirect(app(StorefrontInvoiceUrl::class)->show($order))
            ->assertSessionHasErrors(['order']);

        $order->refresh();
        $payment->refresh();
        $this->assertSame(OrderStatus::PendingPayment, $order->order_status);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(1, Product::findOrFail($productId)->reserved_stock);
        $this->assertNotNull(ProductStockItem::findOrFail($stockItemId)->order_item_id);
        $this->assertSame(0, AuditLog::where('action', 'order.pending_cancelled_released')->where('subject_id', (string) $order->id)->count());

        $this->followingRedirects()
            ->post(app(StorefrontInvoiceUrl::class)->cancel($order))
            ->assertOk()
            ->assertSee('Pesanan sudah diproses atau bukti pembayaran sedang diverifikasi, jadi tidak bisa dibatalkan.');
    }

    public function test_signed_status_endpoint_reconciles_completed_pakasir_transaction_and_returns_paid_redirect_payload(): void
    {
        $this->pakasirConfigIsReady();
        Queue::fake();
        [$order, $payment] = $this->pendingNativeQrisOrder(
            totalAmount: 73000,
            providerFee: 500,
            providerTotalPayment: 73500,
            paymentNumber: '00020101021226610016ID.CO.QRIS.ISSUE121PAID99',
        );
        $this->providerTransactionDetailCompletesPayment($payment);

        $redirectUrl = app(StorefrontInvoiceUrl::class)->show($order);

        $this->postJson(URL::signedRoute('storefront.shop.orders.status', $order, null, false))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('payment_status', PaymentStatus::Paid->value)
            ->assertJsonPath('order_status', OrderStatus::Completed->value)
            ->assertJsonPath('is_paid', true)
            ->assertJsonPath('is_terminal', false)
            ->assertJsonPath('message', 'Pembayaran terverifikasi. Invoice akan dibuka ulang untuk menampilkan data akun.')
            ->assertJsonPath('redirect_url', $redirectUrl);

        $payment->refresh();
        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(OrderStatus::Completed, $order->order_status);
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://app.pakasir.test/api/transactiondetail?project=lhie-test&amount=73000&order_id='.$payment->merchant_ref.'&api_key=secret-key');
    }

    public function test_signed_status_endpoint_returns_in_progress_when_same_payment_refresh_is_already_active(): void
    {
        $this->pakasirConfigIsReady();
        config()->set('services.pakasir.status_refresh_in_progress_retry_after_seconds', 4);
        [$order, $payment] = $this->pendingNativeQrisOrder(
            totalAmount: 73000,
            providerFee: 500,
            providerTotalPayment: 73500,
            paymentNumber: '00020101021226610016ID.CO.QRIS.ISSUE173LOCKED99',
        );
        Http::fake([
            'https://app.pakasir.test/api/transactiondetail*' => Http::response(['unexpected' => true], 500),
        ]);

        $coordinator = app(StorefrontShopPaymentRefreshCoordinator::class);
        $lock = Cache::lock($coordinator->lockKey($payment), 15);
        $this->assertTrue($lock->get());

        try {
            $this->postJson(URL::signedRoute('storefront.shop.orders.status', $order, null, false))
                ->assertStatus(202)
                ->assertJsonPath('ok', false)
                ->assertJsonPath('status', 'in_progress')
                ->assertJsonPath('retry_after', 4);
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
    }

    public function test_signed_status_endpoint_keeps_different_payment_refreshes_independent(): void
    {
        $this->pakasirConfigIsReady();
        [$lockedOrder, $lockedPayment] = $this->pendingNativeQrisOrder(
            totalAmount: 73000,
            providerFee: 500,
            providerTotalPayment: 73500,
            paymentNumber: '00020101021226610016ID.CO.QRIS.ISSUE173LOCKA99',
        );
        [$otherOrder, $otherPayment] = $this->pendingNativeQrisOrder(
            totalAmount: 88000,
            providerFee: 600,
            providerTotalPayment: 88600,
            paymentNumber: '00020101021226610016ID.CO.QRIS.ISSUE173OTHER99',
        );
        unset($lockedOrder);
        $this->providerTransactionDetailIsPending($otherPayment);

        $coordinator = app(StorefrontShopPaymentRefreshCoordinator::class);
        $lock = Cache::lock($coordinator->lockKey($lockedPayment), 15);
        $this->assertTrue($lock->get());

        try {
            $this->postJson(URL::signedRoute('storefront.shop.orders.status', $otherOrder, null, false))
                ->assertOk()
                ->assertJsonPath('ok', true)
                ->assertJsonPath('status', 'refreshed')
                ->assertJsonPath('payment_status', PaymentStatus::Pending->value)
                ->assertJsonPath('order_status', OrderStatus::PendingPayment->value);
        } finally {
            $lock->release();
        }

        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), 'order_id='.$otherPayment->merchant_ref));
        Http::assertSentCount(1);
    }

    public function test_signed_status_endpoint_releases_single_flight_after_provider_failure_so_later_request_can_retry(): void
    {
        $this->pakasirConfigIsReady();
        config()->set('services.pakasir.transaction_detail_max_attempts', 1);
        config()->set('services.pakasir.status_refresh_in_progress_retry_after_seconds', 6);
        [$order, $payment] = $this->pendingNativeQrisOrder(
            totalAmount: 73000,
            providerFee: 500,
            providerTotalPayment: 73500,
            paymentNumber: '00020101021226610016ID.CO.QRIS.ISSUE173RETRY99',
        );

        Http::fake([
            'https://app.pakasir.test/api/transactiondetail*' => Http::sequence()
                ->push(['message' => 'upstream unavailable'], 500)
                ->push([
                    'transaction' => [
                        'project' => 'lhie-test',
                        'order_id' => $payment->merchant_ref,
                        'amount' => $payment->amount,
                        'payment_method' => 'qris',
                        'status' => 'completed',
                    ],
                ], 200),
        ]);

        $this->postJson(URL::signedRoute('storefront.shop.orders.status', $order, null, false))
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('status', 'provider_failure')
            ->assertJsonPath('retry_after', 6);

        $this->postJson(URL::signedRoute('storefront.shop.orders.status', $order, null, false))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('payment_status', PaymentStatus::Paid->value)
            ->assertJsonPath('order_status', OrderStatus::Completed->value)
            ->assertJsonPath('is_paid', true);

        Http::assertSentCount(2);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
    }

    public function test_signed_status_endpoint_skips_provider_lookup_for_paid_and_terminal_states(): void
    {
        $this->pakasirConfigIsReady();
        config()->set('services.pakasir.status_refresh_rate_limit_per_minute', 20);

        $cases = [
            'paid' => [OrderStatus::Completed, PaymentStatus::Paid, 'paid'],
            'cancelled' => [OrderStatus::Cancelled, PaymentStatus::Pending, 'terminal'],
            'expired_order' => [OrderStatus::Expired, PaymentStatus::Pending, 'terminal'],
            'expired_payment' => [OrderStatus::PendingPayment, PaymentStatus::Expired, 'terminal'],
            'failed_payment' => [OrderStatus::PendingPayment, PaymentStatus::Failed, 'terminal'],
        ];

        foreach ($cases as $label => [$orderStatus, $paymentStatus, $expectedStatus]) {
            Http::fake([
                'https://app.pakasir.test/api/transactiondetail*' => Http::response(['unexpected' => $label], 500),
            ]);
            [$order, $payment] = $this->pendingNativeQrisOrder(
                totalAmount: 73000,
                providerFee: 500,
                providerTotalPayment: 73500,
                paymentNumber: '00020101021226610016ID.CO.QRIS.ISSUE173'.strtoupper($label).'99',
            );
            $order->update([
                'order_status' => $orderStatus,
                'payment_status' => $paymentStatus,
            ]);
            $payment->update([
                'status' => $paymentStatus,
                'paid_at' => $paymentStatus === PaymentStatus::Paid ? now() : null,
            ]);

            $this->postJson(URL::signedRoute('storefront.shop.orders.status', $order, null, false))
                ->assertOk()
                ->assertJsonPath('ok', true)
                ->assertJsonPath('status', $expectedStatus)
                ->assertJsonPath('payment_status', $paymentStatus->value)
                ->assertJsonPath('order_status', $orderStatus->value);

            Http::assertNothingSent();
        }
    }

    public function test_signed_status_endpoint_accepts_legacy_absolute_signed_links_after_relative_signature_migration(): void
    {
        $this->pakasirConfigIsReady();
        Queue::fake();
        [$order, $payment] = $this->pendingNativeQrisOrder(
            totalAmount: 73000,
            providerFee: 500,
            providerTotalPayment: 73500,
            paymentNumber: '00020101021226610016ID.CO.QRIS.ISSUE121LEGACYSTATUS99',
        );
        $this->providerTransactionDetailCompletesPayment($payment);

        $legacyUrl = URL::signedRoute('storefront.shop.orders.status', $order);
        $path = parse_url($legacyUrl, PHP_URL_PATH);
        $query = parse_url($legacyUrl, PHP_URL_QUERY);
        $this->assertIsString($path);
        $this->assertIsString($query);
        $this->assertFalse(URL::hasValidSignature(Request::create('https://proxy-observed.example.test'.$path.'?'.$query, 'POST'), false));

        $this->postJson('https://proxy-observed.example.test'.$path.'?'.$query)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('payment_status', PaymentStatus::Paid->value)
            ->assertJsonPath('order_status', OrderStatus::Completed->value)
            ->assertJsonPath('is_paid', true);

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
    }

    public function test_paid_invoice_reveals_grouped_account_secrets_but_pending_invoice_keeps_them_hidden(): void
    {
        $this->pakasirConfigIsReady();
        [$paidOrder] = $this->paidOrderWithAccountSecretGroups([
            [
                'product_name' => 'Alpha Account',
                'unit_price' => 45000,
                'secrets' => [
                    'alpha-one@example.test|AlphaSecret1',
                    'alpha-two@example.test|AlphaSecret2',
                ],
            ],
            [
                'product_name' => 'Beta Account',
                'unit_price' => 9000,
                'secrets' => ['beta@example.test|BetaSecret'],
            ],
        ]);

        [$pendingOrder] = $this->pendingNativeQrisOrder(
            totalAmount: 45000,
            providerFee: 300,
            providerTotalPayment: 45300,
            paymentNumber: '00020101021226610016ID.CO.QRIS.ISSUE121PENDING99',
            secret: 'pending-account@example.test|DoNotLeak',
        );

        $this->get(URL::signedRoute('storefront.shop.orders.show', $paidOrder, null, false))
            ->assertOk()
            ->assertSeeInOrder([
                'Data akun',
                'Alpha Account',
                'alpha-one@example.test|AlphaSecret1',
                'Panduan login &amp; keamanan',
                'Ringkasan invoice',
            ], false)
            ->assertSee('Data akun')
            ->assertSee('Alpha Account')
            ->assertSee('alpha-one@example.test|AlphaSecret1', false)
            ->assertSee('alpha-two@example.test|AlphaSecret2', false)
            ->assertSee('Beta Account')
            ->assertSee('beta@example.test|BetaSecret', false)
            ->assertSee('Simpan data ini di password manager pribadi. Jangan kirim Recovery Key, password, MFA Secret, atau kode MFA ke support.')
            ->assertSee('Panduan login & keamanan')
            ->assertDontSee('Pembayaran QRIS');

        $credentialEvent = AuditLog::where('action', 'storefront.credentials_opened')->where('subject_id', (string) $paidOrder->id)->firstOrFail();
        $this->assertSame($paidOrder->invoice_number, $credentialEvent->new_values['invoice_number']);
        $this->assertSame('automatic_available', $credentialEvent->new_values['delivery_type']);
        $this->assertStringNotContainsString('AlphaSecret1', json_encode($credentialEvent->new_values, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('MFA', json_encode($credentialEvent->new_values, JSON_THROW_ON_ERROR));

        $this->get(URL::signedRoute('storefront.shop.orders.show', $pendingOrder, null, false))
            ->assertOk()
            ->assertSee('Setelah pembayaran terverifikasi, data akun muncul di halaman ini. Simpan link invoice ini sampai akun diterima.')
            ->assertDontSee('pending-account@example.test|DoNotLeak', false);
    }

    public function test_paid_invoice_makes_multiple_credentials_copyable_with_accessibile_feedback_and_product_instructions(): void
    {
        $this->pakasirConfigIsReady();
        [$paidOrder] = $this->paidOrderWithAccountSecretGroups([
            [
                'product_name' => 'Alpha Account',
                'unit_price' => 45000,
                'delivery_note' => "Login dari browser pribadi.\nGanti password setelah login pertama.",
                'secrets' => [
                    'alpha-one@example.test|AlphaSecret1|MFA-ONE',
                    'alpha-two@example.test|AlphaSecret2|MFA-TWO',
                ],
            ],
        ]);

        $response = $this->get(URL::signedRoute('storefront.shop.orders.show', $paidOrder, null, false));

        $response
            ->assertOk()
            ->assertSeeInOrder([
                'Data akun siap digunakan',
                'Salin semua data',
                'Alpha Account',
                'Data akun 1',
                'email : alpha-one@example.test',
                'password : AlphaSecret1',
                'MFA : MFA-ONE',
                'Data akun 2',
                'Instruksi produk',
                'Login dari browser pribadi.',
                'Panduan login &amp; keamanan',
            ], false)
            ->assertSee('data-copy-success="Semua data akun tersalin."', false)
            ->assertSee('data-copy-success="Email tersalin."', false)
            ->assertSee('data-copy-success="Password tersalin."', false)
            ->assertSee('data-copy-success="MFA Secret tersalin."', false)
            ->assertSee('aria-live="polite"', false)
            ->assertSee('Jangan kirim Recovery Key, password, MFA Secret, atau kode MFA ke support.')
            ->assertSee('invoice='.$paidOrder->invoice_number, false)
            ->assertSee('/panduan-login-akun?', false);
    }

    public function test_paid_invoice_without_allocated_stock_items_renders_admin_follow_up_copy(): void
    {
        $this->pakasirConfigIsReady();
        $paidOrder = Order::factory()->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 64000,
        ]);
        Payment::factory()->create([
            'order_id' => $paidOrder->id,
            'provider' => 'pakasir',
            'merchant_ref' => 'INV-ISSUE121-NO-STOCK-ITEMS',
            'provider_payment_method' => 'qris',
            'amount' => 64000,
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
        OrderItem::factory()->create([
            'order_id' => $paidOrder->id,
            'product_name' => 'Consulting Credit',
            'quantity' => 1,
            'unit_price' => 64000,
            'subtotal' => 64000,
        ]);

        $this->get(URL::signedRoute('storefront.shop.orders.show', $paidOrder, null, false))
            ->assertOk()
            ->assertSee('Pembayaran diterima, menunggu diproses')
            ->assertSee('Produk ini tidak punya kredensial otomatis di invoice.')
            ->assertSee('Panduan login & keamanan');
    }

    public function test_manual_fulfillment_distinguishes_processing_and_completed_states_without_promising_time(): void
    {
        $this->pakasirConfigIsReady();
        $processingOrder = Order::factory()->create([
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 64000,
        ]);
        Payment::factory()->create([
            'order_id' => $processingOrder->id,
            'provider' => 'pakasir',
            'merchant_ref' => 'INV-ISSUE145-MANUAL-PROCESSING',
            'provider_payment_method' => 'qris',
            'amount' => 64000,
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
        OrderItem::factory()->create([
            'order_id' => $processingOrder->id,
            'product_name' => 'Manual Setup',
            'quantity' => 1,
            'unit_price' => 64000,
            'subtotal' => 64000,
        ]);
        $completedOrder = $processingOrder->replicate(['invoice_number'])->forceFill([
            'invoice_number' => 'INV-ISSUE145-MANUAL-COMPLETED',
            'order_status' => OrderStatus::Completed,
        ]);
        $completedOrder->save();
        Payment::factory()->create([
            'order_id' => $completedOrder->id,
            'provider' => 'manual',
            'merchant_ref' => 'INV-ISSUE145-MANUAL-COMPLETED',
            'provider_payment_method' => 'qris',
            'amount' => 64000,
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
        OrderItem::factory()->create([
            'order_id' => $completedOrder->id,
            'product_name' => 'Manual Setup',
            'quantity' => 1,
            'unit_price' => 64000,
            'subtotal' => 64000,
        ]);

        $this->get(URL::signedRoute('storefront.shop.orders.show', $processingOrder, null, false))
            ->assertOk()
            ->assertSee('Pembayaran diterima, menunggu diproses')
            ->assertSee('Pembayaran diterima')
            ->assertSee('Menunggu diproses')
            ->assertSee('Status akan berubah setelah admin menandai fulfillment selesai.')
            ->assertDontSee('menit')
            ->assertDontSee('jam');

        $this->get(URL::signedRoute('storefront.shop.orders.show', $completedOrder, null, false))
            ->assertOk()
            ->assertSee('Fulfillment selesai')
            ->assertSee('Produk manual ditandai selesai oleh admin.')
            ->assertSee('Selesai');
    }

    public function test_paid_invoice_distinguishes_expected_automatic_credentials_that_are_not_available_yet(): void
    {
        $this->pakasirConfigIsReady();
        $product = Product::factory()->create([
            'name' => 'Credential Expected',
            'stock' => 1,
            'reserved_stock' => 1,
        ]);
        ProductStockItem::create([
            'product_id' => $product->id,
            'secret' => 'available-but-not-allocated@example.test|Hidden',
        ]);
        $paidOrder = Order::factory()->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 64000,
        ]);
        Payment::factory()->create([
            'order_id' => $paidOrder->id,
            'provider' => 'pakasir',
            'merchant_ref' => 'INV-ISSUE145-CREDENTIALS-MISSING',
            'provider_payment_method' => 'qris',
            'amount' => 64000,
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
        OrderItem::factory()->create([
            'order_id' => $paidOrder->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => 64000,
            'subtotal' => 64000,
        ]);

        $this->get(URL::signedRoute('storefront.shop.orders.show', $paidOrder, null, false))
            ->assertOk()
            ->assertSee('Data akun belum tersedia')
            ->assertSee('Pembayaran sudah diterima, tetapi kredensial otomatis belum tersedia di invoice ini.')
            ->assertSee('Data akun belum muncul')
            ->assertDontSee('available-but-not-allocated@example.test|Hidden', false)
            ->assertDontSee('Fulfillment manual');
    }

    public function test_login_guide_preserves_invoice_context_from_invoice_link(): void
    {
        $this->pakasirConfigIsReady();
        [$paidOrder] = $this->paidOrderWithAccountSecret(
            OrderStatus::Processing,
            'guide-context@example.test|GuideSecret|MFA-GUIDE',
        );
        $invoiceUrl = URL::signedRoute('storefront.shop.orders.show', $paidOrder, null, false);

        $invoiceResponse = $this->get($invoiceUrl)->assertOk();
        $this->assertStringContainsString('invoice='.$paidOrder->invoice_number, $invoiceResponse->getContent());
        $this->assertStringContainsString('return=%2Fshop%2Forders%2F'.$paidOrder->getKey(), $invoiceResponse->getContent());

        $this->get(route('storefront.login-guide', [
            'invoice' => $paidOrder->invoice_number,
            'return' => $invoiceUrl,
        ]))
            ->assertOk()
            ->assertSee('Kembali ke invoice')
            ->assertSee('Konteks invoice')
            ->assertSee($paidOrder->invoice_number)
            ->assertSee($invoiceUrl);
    }

    public function test_signed_status_endpoint_rejects_orders_without_pakasir_payment(): void
    {
        $this->pakasirConfigIsReady();
        $noPaymentOrder = Order::factory()->create();
        $manualPaymentOrder = Order::factory()->create();
        Payment::factory()->create([
            'order_id' => $manualPaymentOrder->id,
            'provider' => 'manual',
            'method' => 'qris',
            'amount' => 42000,
            'status' => PaymentStatus::Pending,
        ]);

        foreach ([$noPaymentOrder, $manualPaymentOrder] as $order) {
            $this->postJson(URL::signedRoute('storefront.shop.orders.status', $order, null, false))
                ->assertUnprocessable()
                ->assertJsonPath('ok', false)
                ->assertJsonPath('message', 'Pembayaran QRIS tidak ditemukan.');
        }

        Http::assertNothingSent();
    }

    public function test_signed_status_endpoint_returns_retryable_failure_when_provider_reconciliation_fails(): void
    {
        $this->pakasirConfigIsReady();
        [$order, $payment] = $this->pendingNativeQrisOrder(
            totalAmount: 51000,
            providerFee: 400,
            providerTotalPayment: 51400,
            paymentNumber: '00020101021226610016ID.CO.QRIS.ISSUE121FAIL99',
        );
        $this->providerTransactionDetailFails();

        $this->postJson(URL::signedRoute('storefront.shop.orders.status', $order, null, false))
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'Status pembayaran belum bisa dicek sekarang. Coba lagi beberapa saat lagi.');

        $payment->refresh();
        $order->refresh();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        $this->assertSame(OrderStatus::PendingPayment, $order->order_status);

        $event = AuditLog::where('action', 'storefront.checkout_submitted')->where('subject_id', (string) $order->id)->latest('id')->firstOrFail();
        $this->assertSame('provider_failure', $event->new_values['status_endpoint_result']);
        $this->assertNotSame('', $event->new_values['failure_kind']);
    }

    public function test_signed_status_endpoint_recovers_incomplete_qris_without_creating_duplicate_payment(): void
    {
        $this->pakasirConfigIsReady();
        $paymentNumber = '00020101021226610016ID.CO.QRIS.RECOVERFINAL99';
        [$order, $payment] = $this->pendingNativeQrisOrder(
            totalAmount: 52000,
            providerFee: null,
            providerTotalPayment: null,
            paymentNumber: $paymentNumber,
        );
        $payment->update([
            'provider_create_state' => 'provider_total_pending',
            'provider_create_last_error' => 'missing_provider_total_payment',
        ]);
        $this->providerTransactionDetailReturnsQrisTotal($payment, $paymentNumber, 450);

        $this->postJson(URL::signedRoute('storefront.shop.orders.status', $order, null, false))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('payment_status', PaymentStatus::Pending->value)
            ->assertJsonPath('order_status', OrderStatus::PendingPayment->value)
            ->assertJsonPath('is_paid', false)
            ->assertJsonPath('is_terminal', false)
            ->assertJsonPath('qris_ready', true)
            ->assertJsonPath('should_reload', true)
            ->assertJsonPath('message', 'QRIS dan total bayar final sudah siap. Invoice akan dimuat ulang.');

        $payment->refresh();
        $this->assertSame(450, $payment->provider_fee);
        $this->assertSame(52450, $payment->provider_total_payment);
        $this->assertSame('created', $payment->provider_create_state);
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
        $this->assertSame(1, Order::count());
        $this->assertSame(1, AuditLog::where('action', 'storefront.qris_created')->where('subject_id', (string) $payment->id)->count());
    }

    public function test_signed_status_endpoint_returns_terminal_payment_messages_without_provider_call(): void
    {
        $this->pakasirConfigIsReady();
        [$expiredOrder, $expiredPayment] = $this->pendingNativeQrisOrder(42000, 300, 42300, '00020101021226610016ID.CO.QRIS.EXPIRED99');
        [$failedOrder, $failedPayment] = $this->pendingNativeQrisOrder(43000, 300, 43300, '00020101021226610016ID.CO.QRIS.FAILED99');
        [$cancelledOrder, $cancelledPayment] = $this->pendingNativeQrisOrder(44000, 300, 44300, '00020101021226610016ID.CO.QRIS.CANCELLED99');

        $expiredOrder->update(['order_status' => OrderStatus::Expired, 'payment_status' => PaymentStatus::Expired]);
        $expiredPayment->update(['status' => PaymentStatus::Expired]);
        $failedPayment->update(['status' => PaymentStatus::Failed]);
        $cancelledOrder->update(['order_status' => OrderStatus::Cancelled, 'payment_status' => PaymentStatus::Failed]);
        Http::fake();

        foreach ([
            [$expiredOrder, PaymentStatus::Expired->value, OrderStatus::Expired->value, 'Invoice QRIS sudah kedaluwarsa. Jangan bayar QRIS ini; buat order baru dari katalog.'],
            [$failedOrder, PaymentStatus::Failed->value, OrderStatus::PendingPayment->value, 'Pembayaran gagal. Jangan bayar QRIS ini; buat order baru atau hubungi admin.'],
            [$cancelledOrder, PaymentStatus::Pending->value, OrderStatus::Cancelled->value, 'Order dibatalkan. Jangan bayar QRIS ini; buat order baru jika masih ingin membeli.'],
        ] as [$order, $paymentStatus, $orderStatus, $message]) {
            $this->postJson(URL::signedRoute('storefront.shop.orders.status', $order, null, false))
                ->assertOk()
                ->assertJsonPath('ok', true)
                ->assertJsonPath('payment_status', $paymentStatus)
                ->assertJsonPath('order_status', $orderStatus)
                ->assertJsonPath('is_paid', false)
                ->assertJsonPath('is_terminal', true)
                ->assertJsonPath('message', $message);
        }

        Http::assertNothingSent();
    }

    public function test_signed_status_endpoint_rate_limit_returns_wait_time_json(): void
    {
        config()->set('services.pakasir.status_refresh_rate_limit_per_minute', 1);
        $this->pakasirConfigIsReady();
        [$order, $payment] = $this->pendingNativeQrisOrder(45000, 300, 45300, '00020101021226610016ID.CO.QRIS.RATELIMIT99');
        $order->update(['order_status' => OrderStatus::Expired, 'payment_status' => PaymentStatus::Expired]);
        $payment->update(['status' => PaymentStatus::Expired]);
        $url = URL::signedRoute('storefront.shop.orders.status', $order, null, false);

        $this->postJson($url)->assertOk();
        $response = $this->postJson($url)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('ok', false);
        $this->assertContains($response->json('retry_after'), [59, 60]);
        $this->assertMatchesRegularExpression(
            '/^Terlalu sering cek status\. Coba lagi dalam (59|60) detik\.$/',
            (string) $response->json('message'),
        );
    }

    public function test_terminal_invoice_replaces_pending_status_card_with_human_instruction(): void
    {
        $this->pakasirConfigIsReady();
        [$order, $payment] = $this->pendingNativeQrisOrder(46000, 300, 46300, '00020101021226610016ID.CO.QRIS.RENDEREXPIRED99');
        $order->update(['order_status' => OrderStatus::Expired, 'payment_status' => PaymentStatus::Expired]);
        $payment->update(['status' => PaymentStatus::Expired]);
        Http::fake();

        $this->postJson(URL::signedRoute('storefront.shop.orders.status', $order, null, false))->assertOk();

        $this->get(URL::signedRoute('storefront.shop.orders.show', $order, null, false))
            ->assertOk()
            ->assertSee('Status akhir')
            ->assertSee('Invoice ini tidak bisa dibayar lagi')
            ->assertSee('Invoice QRIS sudah kedaluwarsa. Jangan bayar QRIS ini; buat order baru dari katalog.')
            ->assertSee('Buat order baru')
            ->assertSee('Butuh bantuan?')
            ->assertSeeInOrder([
                'Status akhir',
                'Invoice ini tidak bisa dibayar lagi',
                'Buat order baru',
                'Ringkasan invoice',
            ])
            ->assertDontSee('data-shop-payment-status', false)
            ->assertDontSee('Setelah membayar, tekan tombol ini untuk mengecek status QRIS.')
            ->assertDontSee('Pembayaran QRIS')
            ->assertDontSee('Total bayar QRIS');

        $event = AuditLog::where('action', 'storefront.checkout_submitted')->where('subject_id', (string) $order->id)->latest('id')->firstOrFail();
        $this->assertSame('terminal', $event->new_values['status_endpoint_result']);
        $this->assertSame('expired', $event->new_values['recovery_cause']);
    }

    public function test_support_selection_beacon_records_safe_operational_context(): void
    {
        $this->pakasirConfigIsReady();
        [$order] = $this->pendingNativeQrisOrder(48000, 300, 48300, '00020101021226610016ID.CO.QRIS.SUPPORTBEACON99');

        $this->postJson(route('storefront.events.store'), [
            'action' => 'storefront.support_selected',
            'order_id' => $order->id,
            'support_channel' => 'whatsapp',
            'support_category' => 'payment_pending',
        ])->assertForbidden();

        $this->postJson(route('storefront.events.store'), [
            'action' => 'storefront.support_selected',
            'order_id' => $order->id,
            'support_channel' => 'whatsapp',
            'support_category' => 'payment_pending',
            'signed_url' => URL::signedRoute('storefront.shop.orders.show', $order, null, false),
            'message' => 'Recovery Key: SHOULD-NOT-LOG password=secret MFA=SECRET',
        ])->assertOk()->assertJsonPath('ok', true);

        $event = AuditLog::where('action', 'storefront.support_selected')->where('subject_id', (string) $order->id)->firstOrFail();
        $this->assertSame($order->invoice_number, $event->new_values['invoice_number']);
        $this->assertSame('whatsapp', $event->new_values['support_channel']);
        $this->assertSame('payment_pending', $event->new_values['support_category']);
        $payload = json_encode($event->new_values, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('SHOULD-NOT-LOG', $payload);
        $this->assertStringNotContainsString('secret', $payload);
        $this->assertStringNotContainsString('MFA', $payload);
    }

    public function test_cancelled_invoice_replaces_payment_instructions_with_new_order_action(): void
    {
        $this->pakasirConfigIsReady();
        [$order, $payment] = $this->pendingNativeQrisOrder(47000, 300, 47300, '00020101021226610016ID.CO.QRIS.RENDERCANCELLED99');
        $order->update(['order_status' => OrderStatus::Cancelled, 'payment_status' => PaymentStatus::Failed]);
        $payment->update(['status' => PaymentStatus::Failed]);

        $this->get(URL::signedRoute('storefront.shop.orders.show', $order, null, false))
            ->assertOk()
            ->assertSee('Order dibatalkan')
            ->assertSee('Invoice ini tidak bisa dibayar lagi')
            ->assertSee('Order dibatalkan. Jangan bayar QRIS ini; buat order baru jika masih ingin membeli.')
            ->assertSee('Buat order baru')
            ->assertDontSee('Pembayaran QRIS')
            ->assertDontSee('Cek status pembayaran')
            ->assertDontSee('Batalkan order &amp; lepas stok', false);
    }
}
