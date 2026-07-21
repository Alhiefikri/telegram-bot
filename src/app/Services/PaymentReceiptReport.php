<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PaymentReceiptReport
{
    /**
     * @return Collection<int, Payment>
     */
    public function canonicalPaidPayments(?Carbon $start = null, ?Carbon $end = null, bool $includeEnd = false): Collection
    {
        $query = Payment::query()
            ->where('status', PaymentStatus::Paid)
            ->whereNotNull('paid_at');

        if ($start !== null) {
            $query->where('paid_at', '>=', $start);
        }

        if ($end !== null) {
            $query->where('paid_at', $includeEnd ? '<=' : '<', $end);
        }

        return $query
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get(['id', 'order_id', 'amount', 'currency', 'provider_fee', 'paid_at'])
            ->groupBy('order_id')
            ->map(fn ($payments): Payment => $payments->sortBy([['paid_at', 'asc'], ['id', 'asc']])->first())
            ->values();
    }

    /**
     * @param  Collection<int, Payment>  $payments
     * @return array<string, int>
     */
    public function netReceiptsByCurrency(Collection $payments): array
    {
        return $payments
            ->groupBy(fn (Payment $payment): string => $this->currency($payment))
            ->map(fn ($payments): int => (int) $payments->sum(fn (Payment $payment): int => $this->netAmount($payment)))
            ->all();
    }

    /**
     * @param  Collection<int, Payment>  $payments
     */
    public function idrNetTotal(Collection $payments): int
    {
        return (int) $payments
            ->filter(fn (Payment $payment): bool => $this->currency($payment) === 'IDR')
            ->sum(fn (Payment $payment): int => $this->netAmount($payment));
    }

    public function netAmount(Payment $payment): int
    {
        return max(0, (int) $payment->amount - (int) ($payment->provider_fee ?? 0));
    }

    public function currency(Payment $payment): string
    {
        return strtoupper((string) $payment->currency ?: 'IDR');
    }
}
