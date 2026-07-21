<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;

interface OrderLifecycle
{
    public function applyPaymentPaid(Payment $payment): OrderLifecycleResult;

    public function applyManualCompletion(Order $order): OrderLifecycleResult;

    public function applyManualRejection(Order $order): OrderLifecycleResult;

    public function applyPaymentRefunded(Payment $payment): OrderLifecycleResult;

    public function applyPaymentExpired(Payment $payment): OrderLifecycleResult;

    public function applyPendingExpiry(Order $order): OrderLifecycleResult;

    public function applyPendingCancellation(Order $order, bool $requireNoPaymentProof = false): OrderLifecycleResult;

    public function project(Order $order, ?Payment $payment = null): OrderLifecycleProjection;
}
