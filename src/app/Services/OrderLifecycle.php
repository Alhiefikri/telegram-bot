<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;

interface OrderLifecycle
{
    public function applyPaymentPaid(Payment $payment): OrderLifecycleResult;

    /** Apply an authenticated Stars receipt claimed in the current inbox transaction. */
    public function applyClaimedStarsPayment(Payment $payment, int $inboxId): OrderLifecycleResult;

    public function applyManualCompletion(Order $order): OrderLifecycleResult;

    public function applyManualRejection(Order $order): OrderLifecycleResult;

    public function applyPaymentRefunded(Payment $payment): OrderLifecycleResult;

    /** Apply a confirmed original Stars refund in the current refund transaction. */
    public function applyConfirmedStarsRefund(Payment $payment): void;

    public function applyPaymentExpired(Payment $payment): OrderLifecycleResult;

    public function applyPendingExpiry(Order $order): OrderLifecycleResult;

    public function applyPendingCancellation(Order $order, bool $requireNoPaymentProof = false): OrderLifecycleResult;

    public function project(Order $order, ?Payment $payment = null): OrderLifecycleProjection;
}
