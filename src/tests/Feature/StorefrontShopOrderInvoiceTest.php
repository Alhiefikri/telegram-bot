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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
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

    public function test_pending_invoice_with_missing_provider_total_uses_amount_as_fallback_and_warns_not_to_pay(): void
    {
        $this->pakasirConfigIsReady();
        $this->qrisRendererReturnsDeterministicPng();
        [$order] = $this->pendingNativeQrisOrder(
            totalAmount: 88000,
            providerFee: null,
            providerTotalPayment: null,
            paymentNumber: '00020101021226610016ID.CO.QRIS.ISSUE121NOFINAL99',
        );

        $this->get(URL::signedRoute('storefront.shop.orders.show', $order, null, false))
            ->assertOk()
            ->assertSee('Total bayar QRIS')
            ->assertSee('Rp88.000')
            ->assertSee('Total final QRIS belum tersedia. Jangan bayar sebelum QRIS berhasil dibuat ulang.')
            ->assertDontSee('Pakasir');
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
            ->assertSee('Data akun')
            ->assertSee('Alpha Account')
            ->assertSee('alpha-one@example.test|AlphaSecret1', false)
            ->assertSee('alpha-two@example.test|AlphaSecret2', false)
            ->assertSee('Beta Account')
            ->assertSee('beta@example.test|BetaSecret', false)
            ->assertSee('Simpan data ini di password manager pribadi. Screenshot pribadi hanya cadangan aman. Jangan bagikan ke orang lain atau menyimpannya di perangkat umum.')
            ->assertSee('Panduan login & keamanan');

        $this->get(URL::signedRoute('storefront.shop.orders.show', $pendingOrder, null, false))
            ->assertOk()
            ->assertSee('Setelah pembayaran terverifikasi, data akun muncul di halaman ini. Simpan link invoice ini sampai akun diterima.')
            ->assertDontSee('pending-account@example.test|DoNotLeak', false);
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
            ->assertSee('Pembayaran diterima. Produk ini tidak punya data akun otomatis; admin akan follow up memakai invoice ini.')
            ->assertSee('Panduan login & keamanan');
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
        $this->postJson($url)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('ok', false)
            ->assertJsonPath('retry_after', 60)
            ->assertJsonPath('message', 'Terlalu sering cek status. Coba lagi dalam 60 detik.');
    }

    public function test_terminal_invoice_replaces_pending_status_card_with_human_instruction(): void
    {
        $this->pakasirConfigIsReady();
        [$order, $payment] = $this->pendingNativeQrisOrder(46000, 300, 46300, '00020101021226610016ID.CO.QRIS.RENDEREXPIRED99');
        $order->update(['order_status' => OrderStatus::Expired, 'payment_status' => PaymentStatus::Expired]);
        $payment->update(['status' => PaymentStatus::Expired]);

        $this->get(URL::signedRoute('storefront.shop.orders.show', $order, null, false))
            ->assertOk()
            ->assertSee('Status pembayaran final')
            ->assertSee('Invoice QRIS sudah kedaluwarsa. Jangan bayar QRIS ini; buat order baru dari katalog.')
            ->assertDontSee('data-shop-payment-status', false)
            ->assertDontSee('Setelah membayar, tekan tombol ini untuk mengecek status QRIS.');
    }
}
