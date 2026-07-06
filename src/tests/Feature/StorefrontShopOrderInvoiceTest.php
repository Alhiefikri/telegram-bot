<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\Support\StorefrontSignedInvoiceScenarios;
use Tests\TestCase;

class StorefrontShopOrderInvoiceTest extends TestCase
{
    use RefreshDatabase;
    use StorefrontSignedInvoiceScenarios;

    public function test_signed_pending_invoice_renders_native_pakasir_qris_payment_contract_without_revealing_allocated_secret(): void
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

        $response = $this->get(URL::signedRoute('storefront.shop.orders.show', $order));

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
            ->assertSee('Bayar via Website Pakasir')
            ->assertSee('https://app.pakasir.test/pay/lhie-test/120000', false)
            ->assertSee('data-shop-payment-status', false)
            ->assertSee('data-status-url=', false)
            ->assertSee('/shop/orders/'.$order->getKey().'/status', false)
            ->assertSee('data-redirect-url=', false)
            ->assertSee('/shop/orders/'.$order->getKey(), false)
            ->assertSee('data-poll-ms="6000"', false)
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

        $this->get(URL::signedRoute('storefront.shop.orders.show', $order))
            ->assertOk()
            ->assertSee('Total bayar QRIS')
            ->assertSee('Rp88.000')
            ->assertSee('Total final Pakasir belum tersedia. Jangan bayar sebelum QRIS berhasil dibuat ulang.');
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

        $redirectUrl = URL::signedRoute('storefront.shop.orders.show', $order);

        $this->postJson(URL::signedRoute('storefront.shop.orders.status', $order))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('payment_status', PaymentStatus::Paid->value)
            ->assertJsonPath('order_status', OrderStatus::Processing->value)
            ->assertJsonPath('is_paid', true)
            ->assertJsonPath('redirect_url', $redirectUrl);

        $payment->refresh();
        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(OrderStatus::Processing, $order->order_status);
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://app.pakasir.test/api/transactiondetail?project=lhie-test&amount=73000&order_id='.$payment->merchant_ref.'&api_key=secret-key');
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

        $this->get(URL::signedRoute('storefront.shop.orders.show', $paidOrder))
            ->assertOk()
            ->assertSee('Data akun')
            ->assertSee('Alpha Account')
            ->assertSee('alpha-one@example.test|AlphaSecret1', false)
            ->assertSee('alpha-two@example.test|AlphaSecret2', false)
            ->assertSee('Beta Account')
            ->assertSee('beta@example.test|BetaSecret', false)
            ->assertSee('Simpan data ini baik-baik dan jangan bagikan ke orang lain.')
            ->assertSee('Panduan login & keamanan');

        $this->get(URL::signedRoute('storefront.shop.orders.show', $pendingOrder))
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

        $this->get(URL::signedRoute('storefront.shop.orders.show', $paidOrder))
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
            $this->postJson(URL::signedRoute('storefront.shop.orders.status', $order))
                ->assertUnprocessable()
                ->assertJsonPath('ok', false)
                ->assertJsonPath('message', 'Payment Pakasir tidak ditemukan.');
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

        $this->postJson(URL::signedRoute('storefront.shop.orders.status', $order))
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'Status pembayaran belum bisa dicek otomatis sekarang.');

        $payment->refresh();
        $order->refresh();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        $this->assertSame(OrderStatus::PendingPayment, $order->order_status);
    }
}
