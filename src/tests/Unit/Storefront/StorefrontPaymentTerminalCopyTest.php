<?php

namespace Tests\Unit\Storefront;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderLifecycleProjection;
use App\Storefront\StorefrontPaymentTerminalCopy;
use PHPUnit\Framework\TestCase;

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
        $this->assertSame('cancelled', $copy->effectivePaymentStatus($order, $payment));
    }
}
