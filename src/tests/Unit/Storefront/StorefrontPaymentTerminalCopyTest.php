<?php

namespace Tests\Unit\Storefront;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderLifecycleProjection;
use App\Storefront\StorefrontPaymentTerminalCopy;
use Tests\TestCase;

class StorefrontPaymentTerminalCopyTest extends TestCase
{
    public function test_cancelled_binance_copy_mentions_usdt_and_never_qris(): void
    {
        $copy = new StorefrontPaymentTerminalCopy;
        $projection = new OrderLifecycleProjection(
            OrderStatus::Cancelled,
            PaymentStatus::Pending,
            false,
            false,
            true,
            'cancelled',
        );
        $payment = new Payment(['method' => 'BINANCE_PAY_ID']);

        $message = $copy->message($projection, $payment);

        $this->assertStringContainsString('USDT', $message);
        $this->assertStringNotContainsString('QRIS', $message);
    }

    public function test_cancelled_qris_copy_and_effective_status_are_explicit(): void
    {
        $copy = new StorefrontPaymentTerminalCopy;
        $projection = new OrderLifecycleProjection(
            OrderStatus::Cancelled,
            PaymentStatus::Pending,
            false,
            false,
            true,
            'cancelled',
        );
        $payment = new Payment(['method' => 'QRIS']);
        $order = new Order(['order_status' => OrderStatus::Cancelled, 'payment_status' => PaymentStatus::Pending]);

        $this->assertStringContainsString('QRIS', $copy->message($projection, $payment));
        $this->assertSame('Dibatalkan', $copy->effectivePaymentStatus($order, $payment));
        $this->assertSame('Sedang diproses', $copy->orderStatusLabel(OrderStatus::Processing));
        $this->assertSame('Menunggu pembayaran', $copy->paymentStatusLabel(PaymentStatus::Pending));
    }

    public function test_invoice_copy_uses_the_configured_storefront_locale(): void
    {
        config()->set('storefront.locale', 'en');
        $copy = new StorefrontPaymentTerminalCopy;

        $this->assertSame('Cancelled', $copy->effectivePaymentStatus(
            new Order(['order_status' => OrderStatus::Cancelled, 'payment_status' => PaymentStatus::Pending]),
            new Payment(['method' => 'QRIS']),
        ));
        $this->assertSame('Checking payment', $copy->statusUiCopy('qris')['loading_title']);
        $this->assertSame('Preparing QRIS', $copy->qrisReadiness('creating')['title']);
    }
}
