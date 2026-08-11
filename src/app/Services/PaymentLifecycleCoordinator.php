<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use Closure;
use Illuminate\Support\Facades\DB;

final class PaymentLifecycleCoordinator
{
    /**
     * @template T
     *
     * @param  Closure(Order, Payment): T  $callback
     * @return T
     */
    public function withLockedOrderPayment(Payment $payment, Closure $callback): mixed
    {
        return DB::transaction(function () use ($payment, $callback): mixed {
            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
            $lockedPayment = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            return $callback($order, $lockedPayment);
        }, 5);
    }

    public function isNativeQrisCreationAllowed(Payment $payment): bool
    {
        return $this->withLockedOrderPayment(
            $payment,
            fn (Order $order, Payment $lockedPayment): bool => $this->canCreateNativeQris($order, $lockedPayment),
        );
    }

    public function isNativeQrisExposureAllowed(Payment $payment): bool
    {
        return $this->withLockedOrderPayment(
            $payment,
            fn (Order $order, Payment $lockedPayment): bool => $this->canExposeNativeQris($order, $lockedPayment),
        );
    }

    public function canCreateNativeQris(Order $order, Payment $payment): bool
    {
        if (! OrderLifecycleProjection::from($order, $payment)->isPendingPayment) {
            return false;
        }

        return $this->hasFutureDeadline($order->expires_at)
            && $this->hasFutureDeadline($payment->expired_at)
            && $this->hasFutureDeadline($payment->provider_expires_at);
    }

    public function canExposeNativeQris(Order $order, Payment $payment): bool
    {
        $projection = OrderLifecycleProjection::from($order, $payment);

        if ($projection->isPaid) {
            return true;
        }

        return $this->canCreateNativeQris($order, $payment);
    }

    private function hasFutureDeadline(mixed $deadline): bool
    {
        return $deadline === null || $deadline->isFuture();
    }
}
