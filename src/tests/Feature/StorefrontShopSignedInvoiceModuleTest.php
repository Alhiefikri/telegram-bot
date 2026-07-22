<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Storefront\StorefrontShopPaymentStatusRefresh;
use App\Storefront\StorefrontShopSignedInvoice;
use App\Storefront\StorefrontShopSignedInvoiceState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;
use Tests\Support\StorefrontSignedInvoiceScenarios;
use Tests\TestCase;

class StorefrontShopSignedInvoiceModuleTest extends TestCase
{
    use RefreshDatabase;
    use StorefrontSignedInvoiceScenarios;

    public function test_projection_constructor_has_no_payment_provider_or_reconciliation_dependencies(): void
    {
        $dependencies = collect((new ReflectionClass(StorefrontShopSignedInvoice::class))
            ->getConstructor()?->getParameters() ?? [])
            ->map(fn ($parameter): string => (string) $parameter->getType())
            ->all();

        $this->assertNotContains('App\\Services\\Pakasir\\PakasirProviderAdapter', $dependencies);
        $this->assertNotContains('App\\Services\\Pakasir\\PakasirNativeQrisService', $dependencies);
        $this->assertNotContains('App\\Services\\Pakasir\\PakasirCallbackProcessor', $dependencies);
        $this->assertNotContains('App\\Storefront\\StorefrontFunnelEvents', $dependencies);
        $this->assertFalse((new ReflectionClass(StorefrontShopSignedInvoice::class))->hasMethod('refreshStatus'));
    }

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
                'delivery_note' => null,
                'secrets' => [
                    [
                        'text' => 'paid-account@example.test|Visible',
                        'parts' => null,
                        'label' => 'Data akun 1',
                    ],
                ],
                'copy_text' => "1.\npaid-account@example.test|Visible",
            ],
        ], $paidInvoice->accountGroups);
        $this->assertSame("Module Secret Product\n1.\npaid-account@example.test|Visible", $paidInvoice->accountCopyText);
        $this->assertSame('automatic_available', $paidInvoice->paidDeliveryType);
        $this->assertSame('processing', $paidInvoice->manualFulfillmentStatus);
    }

    public function test_read_result_blocks_qris_when_provider_total_is_missing(): void
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

        $this->assertNull($invoice->payableAmount);
        $this->assertFalse($invoice->payableAmountFromProvider);
        $this->assertFalse($invoice->isQrisPayable);
        $this->assertSame('provider_total_pending', $invoice->qrisReadinessState);
        $this->assertSame('Provider belum memberi nominal', $invoice->qrisReadinessTitle);
        $this->assertNull($invoice->qrisImageDataUri);
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

        $result = app(StorefrontShopPaymentStatusRefresh::class)->execute($order);

        $this->assertSame(200, $result->statusCode());
        $this->assertSame([
            'ok' => true,
            'payment_status' => PaymentStatus::Paid->value,
            'order_status' => OrderStatus::Completed->value,
            'is_paid' => true,
            'is_terminal' => false,
            'qris_ready' => false,
            'should_reload' => false,
            'redirect_url' => $result->redirectUrl,
            'message' => 'Pembayaran terverifikasi. Invoice akan dibuka ulang untuk menampilkan data akun.',
        ], $result->payload());
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
    }

    public function test_refresh_status_keeps_pending_provider_result_pending_without_lifecycle_mutation(): void
    {
        $this->pakasirConfigIsReady();
        [$order, $payment] = $this->pendingNativeQrisOrder(
            totalAmount: 61000,
            providerFee: 400,
            providerTotalPayment: 61400,
            paymentNumber: '00020101021226610016ID.CO.QRIS.MODULEPENDINGREFRESH99',
        );
        $this->providerTransactionDetailIsPending($payment);

        $result = app(StorefrontShopPaymentStatusRefresh::class)->execute($order);

        $this->assertTrue($result->payload()['ok']);
        $this->assertFalse($result->payload()['is_paid']);
        $this->assertFalse($result->payload()['is_terminal']);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
    }

    public function test_refresh_status_replay_reuses_canonical_paid_state_without_second_provider_call(): void
    {
        $this->pakasirConfigIsReady();
        Queue::fake();
        [$order, $payment] = $this->pendingNativeQrisOrder(
            totalAmount: 74000,
            providerFee: 500,
            providerTotalPayment: 74500,
            paymentNumber: '00020101021226610016ID.CO.QRIS.MODULEREPLAY99',
        );
        $this->providerTransactionDetailCompletesPayment($payment);
        $command = app(StorefrontShopPaymentStatusRefresh::class);

        $first = $command->execute($order);
        $second = $command->execute($order->fresh());

        $this->assertTrue($first->payload()['is_paid']);
        $this->assertTrue($second->payload()['is_paid']);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        Http::assertSentCount(1);
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

        $result = app(StorefrontShopPaymentStatusRefresh::class)->execute($order);

        $this->assertSame(200, $result->statusCode());
        $this->assertSame([
            'ok' => false,
            'title' => 'Status belum bisa dicek',
            'message' => 'Status pembayaran belum bisa dicek sekarang. Coba lagi beberapa saat lagi.',
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
            $result = app(StorefrontShopPaymentStatusRefresh::class)->execute($order);

            $this->assertSame(422, $result->statusCode());
            $this->assertSame([
                'ok' => false,
                'message' => 'Pembayaran QRIS tidak ditemukan.',
            ], $result->payload());
        }

        Http::assertNothingSent();
    }

    public function test_refresh_status_returns_terminal_message_without_provider_reconciliation(): void
    {
        $this->pakasirConfigIsReady();
        [$order, $payment] = $this->pendingNativeQrisOrder(
            totalAmount: 42000,
            providerFee: 300,
            providerTotalPayment: 42300,
            paymentNumber: '00020101021226610016ID.CO.QRIS.MODULEEXPIRED99',
        );
        $order->update(['order_status' => OrderStatus::Expired, 'payment_status' => PaymentStatus::Expired]);
        $payment->update(['status' => PaymentStatus::Expired]);
        Http::fake();

        $result = app(StorefrontShopPaymentStatusRefresh::class)->execute($order);

        $this->assertSame(200, $result->statusCode());
        $this->assertSame([
            'ok' => true,
            'payment_status' => PaymentStatus::Expired->value,
            'order_status' => OrderStatus::Expired->value,
            'is_paid' => false,
            'is_terminal' => true,
            'qris_ready' => false,
            'should_reload' => false,
            'redirect_url' => $result->redirectUrl,
            'message' => 'Invoice QRIS sudah kedaluwarsa. Jangan bayar QRIS ini; buat order baru dari katalog.',
        ], $result->payload());
        Http::assertNothingSent();
    }
}
