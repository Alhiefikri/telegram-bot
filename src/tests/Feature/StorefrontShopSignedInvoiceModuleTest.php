<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\Telegram\TelegramQrCodeRenderer;
use App\Storefront\StorefrontShopSignedInvoice;
use App\Storefront\StorefrontShopSignedInvoiceState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StorefrontShopSignedInvoiceModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_result_selects_latest_payment_and_builds_pending_invoice_state(): void
    {
        $this->configurePakasirConfig();
        $this->fakeQrRenderer();
        [$order, $latestPayment] = $this->pendingNativePakasirOrder(
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
        [$notDeliverableOrder] = $this->paidOrderWithSecret(OrderStatus::PendingPayment, 'held-account@example.test|Hidden');
        [$deliverableOrder] = $this->paidOrderWithSecret(OrderStatus::Processing, 'paid-account@example.test|Visible');

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
        $this->configurePakasirConfig();
        $this->fakeQrRenderer();
        [$order] = $this->pendingNativePakasirOrder(
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
        $this->configurePakasirConfig();
        Queue::fake();
        [$order, $payment] = $this->pendingNativePakasirOrder(
            totalAmount: 73000,
            providerFee: 500,
            providerTotalPayment: 73500,
            paymentNumber: '00020101021226610016ID.CO.QRIS.MODULEPAID99',
        );
        Http::fake([
            'https://app.pakasir.test/api/transactiondetail*' => Http::response([
                'transaction' => [
                    'project' => 'lhie-test',
                    'order_id' => $payment->merchant_ref,
                    'amount' => 73000,
                    'payment_method' => 'qris',
                    'status' => 'completed',
                ],
            ], 200),
        ]);

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
        $this->configurePakasirConfig();
        [$order, $payment] = $this->pendingNativePakasirOrder(
            totalAmount: 51000,
            providerFee: 400,
            providerTotalPayment: 51400,
            paymentNumber: '00020101021226610016ID.CO.QRIS.MODULEFAIL99',
        );
        Http::fake([
            'https://app.pakasir.test/api/transactiondetail*' => Http::response(['message' => 'upstream unavailable'], 500),
        ]);

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

    private function pendingNativePakasirOrder(
        int $totalAmount,
        ?int $providerFee,
        ?int $providerTotalPayment,
        string $paymentNumber,
    ): array {
        $product = Product::factory()->create([
            'name' => 'Module Invoice Product',
            'price_amount' => $totalAmount,
            'stock' => 2,
            'reserved_stock' => 1,
        ]);
        $order = Order::factory()->create([
            'customer_id' => Customer::factory()->create(['first_name' => 'Module Buyer', 'username' => 'modulebuyer'])->id,
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'total_amount' => $totalAmount,
        ]);
        $orderItem = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => $totalAmount,
            'subtotal' => $totalAmount,
        ]);
        InventoryMovement::create([
            'product_id' => $product->id,
            'order_id' => $order->id,
            'type' => InventoryMovementType::Reservation,
            'quantity' => 1,
            'notes' => 'Order reservation',
            'created_at' => now(),
        ]);
        ProductStockItem::create([
            'product_id' => $product->id,
            'order_item_id' => $orderItem->id,
            'secret' => 'reserved-account@example.test|HiddenUntilPaid',
        ]);

        $payment = Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'pakasir',
            'merchant_ref' => 'INV-MODULE-'.$order->id,
            'provider_payment_method' => 'qris',
            'provider_payment_number' => $paymentNumber,
            'provider_payment_number_hash' => hash('sha256', $paymentNumber),
            'provider_fee' => $providerFee,
            'provider_total_payment' => $providerTotalPayment,
            'method' => 'api_qris',
            'amount' => $totalAmount,
            'currency' => 'IDR',
            'status' => PaymentStatus::Pending,
        ]);

        return [$order->fresh(['orderItems.stockItems', 'payments']), $payment->fresh()];
    }

    private function paidOrderWithSecret(OrderStatus $orderStatus, string $secret): array
    {
        $product = Product::factory()->create([
            'name' => 'Module Secret Product',
            'price_amount' => 99000,
            'stock' => 1,
            'reserved_stock' => 1,
        ]);
        $order = Order::factory()->create([
            'order_status' => $orderStatus,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 99000,
        ]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'pakasir',
            'merchant_ref' => 'INV-MODULE-PAID-'.$order->id,
            'provider_payment_method' => 'qris',
            'amount' => 99000,
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => 99000,
            'subtotal' => 99000,
        ]);
        ProductStockItem::create([
            'product_id' => $product->id,
            'order_item_id' => $item->id,
            'secret' => $secret,
        ]);

        return [$order->fresh(['orderItems.stockItems', 'payments'])];
    }

    private function configurePakasirConfig(): void
    {
        config()->set('services.pakasir.slug', 'lhie-test');
        config()->set('services.pakasir.api_key', 'secret-key');
        config()->set('services.pakasir.base_url', 'https://app.pakasir.test');
        config()->set('services.pakasir.redirect_url', 'https://lhie.test/shop/pakasir-return');
    }

    private function fakeQrRenderer(): void
    {
        $this->app->instance(TelegramQrCodeRenderer::class, new class extends TelegramQrCodeRenderer
        {
            public function png(string $content): string
            {
                return 'png-for:'.$content;
            }
        });
    }
}
