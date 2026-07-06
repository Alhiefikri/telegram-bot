<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Storefront\StorefrontShopSignedInvoice;
use App\Storefront\StorefrontShopSignedInvoiceState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\StorefrontSignedInvoiceScenarios;
use Tests\TestCase;

class StorefrontShopSignedInvoiceModuleTest extends TestCase
{
    use RefreshDatabase;
    use StorefrontSignedInvoiceScenarios;

    public function test_read_result_selects_latest_payment_and_builds_pending_invoice_state(): void
    {
        $this->pakasirConfigIsReady();
        $this->qrisRendererReturnsDeterministicPng();
        [$order, $latestPayment] = $this->pendingNativeQrisOrder(
            totalAmount: 120000,
            providerFee: 1500,
            providerTotalPayment: 121500,
            paymentNumber: '00020101021226610016ID.CO.QRIS.MODULEPENDING99',
        );
        Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'qris',
            'amount' => 120000,
            'status' => PaymentStatus::Pending,
            'created_at' => now()->subMinute(),
        ]);

        $invoice = app(StorefrontShopSignedInvoice::class)->read($order);

        $this->assertTrue($invoice->payment->is($latestPayment));
        $this->assertSame(StorefrontShopSignedInvoiceState::Pending, $invoice->state);
        $this->assertTrue($invoice->isPendingPayment());
        $this->assertFalse($invoice->isPaid());
        $this->assertSame(121500, $invoice->payableAmount);
        $this->assertTrue($invoice->payableAmountFromProvider);
        $this->assertSame('data:image/png;base64,'.base64_encode('png-for:'.$latestPayment->provider_payment_number), $invoice->qrisImageDataUri);
        $this->assertSame('https://app.pakasir.test/pay/lhie-test/120000?order_id='.$latestPayment->merchant_ref.'&redirect=https%3A%2F%2Flhie.test%2Fshop%2Fpakasir-return', $invoice->hostedPaymentUrl);
        $this->assertSame([], $invoice->accountGroups);
    }

    public function test_read_result_uses_existing_paid_rule_for_account_delivery(): void
    {
        [$notDeliverableOrder] = $this->paidOrderWithAccountSecret(
            orderStatus: OrderStatus::PendingPayment,
            secret: 'held-account@example.test|Hidden',
            productName: 'Module Secret Product',
        );
        [$deliverableOrder] = $this->paidOrderWithAccountSecret(
            orderStatus: OrderStatus::Processing,
            secret: 'paid-account@example.test|Visible',
            productName: 'Module Secret Product',
        );

        $heldInvoice = app(StorefrontShopSignedInvoice::class)->read($notDeliverableOrder);
        $paidInvoice = app(StorefrontShopSignedInvoice::class)->read($deliverableOrder);

        $this->assertSame(StorefrontShopSignedInvoiceState::Terminal, $heldInvoice->state);
        $this->assertFalse($heldInvoice->isPaid());
        $this->assertSame([], $heldInvoice->accountGroups);

        $this->assertSame(StorefrontShopSignedInvoiceState::Paid, $paidInvoice->state);
        $this->assertTrue($paidInvoice->isPaid());
        $this->assertSame([
            [
                'product_name' => 'Module Secret Product',
                'secrets' => ['paid-account@example.test|Visible'],
            ],
        ], $paidInvoice->accountGroups);
    }

    public function test_read_result_preserves_provider_total_fallback_warning_state(): void
    {
        $this->pakasirConfigIsReady();
        $this->qrisRendererReturnsDeterministicPng();
        [$order] = $this->pendingNativeQrisOrder(
            totalAmount: 88000,
            providerFee: null,
            providerTotalPayment: null,
            paymentNumber: '00020101021226610016ID.CO.QRIS.MODULEFALLBACK99',
        );

        $invoice = app(StorefrontShopSignedInvoice::class)->read($order);

        $this->assertSame(88000, $invoice->payableAmount);
        $this->assertFalse($invoice->payableAmountFromProvider);
    }

    public function test_refresh_status_reconciles_pending_pakasir_payment_and_returns_redirect_payload(): void
    {
        $this->pakasirConfigIsReady();
        Queue::fake();
        [$order, $payment] = $this->pendingNativeQrisOrder(
            totalAmount: 73000,
            providerFee: 500,
            providerTotalPayment: 73500,
            paymentNumber: '00020101021226610016ID.CO.QRIS.MODULEPAID99',
        );
        $this->providerTransactionDetailCompletesPayment($payment);

        $result = app(StorefrontShopSignedInvoice::class)->refreshStatus($order);

        $this->assertSame(200, $result->statusCode());
        $this->assertSame([
            'ok' => true,
            'payment_status' => PaymentStatus::Paid->value,
            'order_status' => OrderStatus::Processing->value,
            'is_paid' => true,
            'redirect_url' => $result->redirectUrl,
        ], $result->payload());
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
    }

    public function test_refresh_status_returns_retryable_failure_when_provider_reconciliation_fails(): void
    {
        $this->pakasirConfigIsReady();
        [$order, $payment] = $this->pendingNativeQrisOrder(
            totalAmount: 51000,
            providerFee: 400,
            providerTotalPayment: 51400,
            paymentNumber: '00020101021226610016ID.CO.QRIS.MODULEFAIL99',
        );
        $this->providerTransactionDetailFails();

        $result = app(StorefrontShopSignedInvoice::class)->refreshStatus($order);

        $this->assertSame(200, $result->statusCode());
        $this->assertSame([
            'ok' => false,
            'message' => 'Status pembayaran belum bisa dicek otomatis sekarang.',
        ], $result->payload());
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
    }

    public function test_refresh_status_rejects_missing_or_non_pakasir_payment(): void
    {
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
            $result = app(StorefrontShopSignedInvoice::class)->refreshStatus($order);

            $this->assertSame(422, $result->statusCode());
            $this->assertSame([
                'ok' => false,
                'message' => 'Payment Pakasir tidak ditemukan.',
            ], $result->payload());
        }

        Http::assertNothingSent();
    }
}
