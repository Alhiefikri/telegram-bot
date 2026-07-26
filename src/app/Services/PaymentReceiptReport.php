<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PaymentReceiptReport
{
    /**
     * @return Collection<int, Payment>
     */
    public function canonicalPaidPayments(?Carbon $start = null, ?Carbon $end = null, bool $includeEnd = false): Collection
    {
        return $this->canonicalPaidPaymentsQuery($start, $end, $includeEnd)
            ->orderBy('canonical_payments.paid_at')
            ->orderBy('canonical_payments.id')
            ->get([
                'canonical_payments.id',
                'canonical_payments.order_id',
                'canonical_payments.amount',
                'canonical_payments.currency',
                'canonical_payments.provider_fee',
                'canonical_payments.paid_at',
            ]);
    }

    /**
     * Net IDR receipts summed in the database. Mirrors idrNetTotal(netAmount())
     * over canonicalPaidPayments() but without hydrating every paid payment into
     * memory on each dashboard request.
     */
    public function idrNetTotalCanonical(?Carbon $start = null, ?Carbon $end = null, bool $includeEnd = false): int
    {
        $net = '(canonical_payments.amount - COALESCE(canonical_payments.provider_fee, 0))';

        return (int) $this->canonicalPaidPaymentsQuery($start, $end, $includeEnd)
            ->where(function ($query): void {
                $query
                    ->whereNull('canonical_payments.currency')
                    ->orWhere('canonical_payments.currency', '')
                    ->orWhereRaw('UPPER(canonical_payments.currency) = ?', ['IDR']);
            })
            ->sum(DB::raw("CASE WHEN {$net} > 0 THEN {$net} ELSE 0 END"));
    }

    /**
     * @return Builder<Payment>
     */
    private function canonicalPaidPaymentsQuery(?Carbon $start, ?Carbon $end, bool $includeEnd): Builder
    {
        $query = Payment::query()
            ->from('payments as canonical_payments')
            ->where('canonical_payments.status', PaymentStatus::Paid)
            ->whereNotNull('canonical_payments.paid_at')
            ->whereNotExists(function ($query): void {
                $query
                    ->select(DB::raw(1))
                    ->from('payments as earlier_payments')
                    ->whereColumn('earlier_payments.order_id', 'canonical_payments.order_id')
                    ->where('earlier_payments.status', PaymentStatus::Paid)
                    ->whereNotNull('earlier_payments.paid_at')
                    ->where(function ($query): void {
                        $query
                            ->whereColumn('earlier_payments.paid_at', '<', 'canonical_payments.paid_at')
                            ->orWhere(function ($query): void {
                                $query
                                    ->whereColumn('earlier_payments.paid_at', 'canonical_payments.paid_at')
                                    ->whereColumn('earlier_payments.id', '<', 'canonical_payments.id');
                            });
                    });
            });

        if ($start !== null) {
            $query->where('canonical_payments.paid_at', '>=', $start);
        }

        if ($end !== null) {
            $query->where('canonical_payments.paid_at', $includeEnd ? '<=' : '<', $end);
        }

        return $query;
    }

    /**
     * @param  Collection<int, Payment>  $payments
     * @return Collection<int, Payment>
     */
    public function canonicalFromPayments(Collection $payments): Collection
    {
        return $payments
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
