<?php

namespace App\Services\Checkout;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Services\InvoiceNumberGenerator;

/**
 * Internal persistence boundary for a complete checkout payment plan.
 *
 * This class only writes the local Payment snapshot. Provider calls,
 * inventory, lifecycle transitions, and outbound effects stay outside it.
 */
final class CheckoutPaymentPlanWriter
{
    public function __construct(
        private readonly InvoiceNumberGenerator $numbers,
    ) {}

    public function persist(Order $order, CheckoutPaymentPlan $plan): Payment
    {
        $payment = Payment::query()->create([
            'order_id' => $order->getKey(),
            'provider' => $plan->provider,
            'merchant_ref' => $this->numbers->merchantReference(),
            'method' => $plan->method,
            'amount' => $plan->amount,
            'currency' => $plan->paymentCurrency,
            'status' => PaymentStatus::Pending,
            'expired_at' => $plan->paymentExpiresAt,
        ]);

        $plan->persistRailExtension($payment);

        return $payment;
    }
}
