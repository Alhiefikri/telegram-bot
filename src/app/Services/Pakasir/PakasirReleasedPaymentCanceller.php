<?php

namespace App\Services\Pakasir;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;

class PakasirReleasedPaymentCanceller
{
    /**
     * @var array<int, PaymentStatus>
     */
    private const CANCELLABLE_STATUSES = [
        PaymentStatus::Expired,
        PaymentStatus::Failed,
    ];

    public function __construct(
        private readonly PakasirProviderAdapter $pakasir,
        private readonly PakasirPaymentEventLogger $events,
    ) {}

    public function cancelReleasedOrder(Order $order): int
    {
        $order->loadMissing('payments');

        $cancelled = 0;
        foreach ($order->payments as $payment) {
            if ($this->cancelReleasedPayment($payment)) {
                $cancelled++;
            }
        }

        return $cancelled;
    }

    public function cancelReleasedPayment(Payment $payment): bool
    {
        $payment->refresh();
        if (! $this->shouldCancel($payment)) {
            return false;
        }

        $response = $this->pakasir->cancelTransaction($payment);
        $this->events->logProviderCancel($payment, $response);

        return true;
    }

    private function shouldCancel(Payment $payment): bool
    {
        return $payment->provider === 'pakasir'
            && filled($payment->merchant_ref)
            && in_array($payment->status, self::CANCELLABLE_STATUSES, true);
    }
}
