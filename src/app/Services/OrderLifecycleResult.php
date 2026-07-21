<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;

class OrderLifecycleResult
{
    public function __construct(
        public readonly ?Order $order,
        public readonly ?Payment $payment,
        public readonly bool $newlyApplied,
        public readonly bool $receiptEligible = false,
        public readonly bool $salesProofEligible = false,
    ) {}

    public function orderStatus(): ?OrderStatus
    {
        return $this->order?->order_status;
    }

    public function paymentStatus(): ?PaymentStatus
    {
        return $this->payment?->status ?? $this->order?->payment_status;
    }

    public function projection(): ?OrderLifecycleProjection
    {
        return $this->order === null
            ? null
            : OrderLifecycleProjection::from($this->order, $this->payment);
    }

    public function shouldDispatchReceipt(): bool
    {
        return $this->newlyApplied && $this->receiptEligible;
    }

    public function shouldDispatchSalesProof(): bool
    {
        return $this->newlyApplied && $this->salesProofEligible;
    }
}
