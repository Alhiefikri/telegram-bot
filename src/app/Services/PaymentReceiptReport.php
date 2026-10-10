<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\Pakasir\PakasirQrisFeePolicy;
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
                'canonical_payments.provider',
                'canonical_payments.method',
                'canonical_payments.provider_payment_method',
                'canonical_payments.provider_fee',
                'canonical_payments.provider_total_payment',
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
        $net = $this->netAmountSql('canonical_payments');

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
     * One canonical receipt per successful Order; monetary units stay separated
     * by currency. DATE uses the application's timestamp storage convention.
     *
     * @return list<array{date: string, currency: string, amount: int, orders: int}>
     */
    public function dailySalesReceipts(Carbon $start, Carbon $end): array
    {
        $net = $this->netAmountSql('canonical_payments');
        $currency = "UPPER(COALESCE(NULLIF(canonical_payments.currency, ''), 'IDR'))";

        return $this->canonicalPaidPaymentsQuery($start, $end, true)
            ->whereExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('orders')
                    ->whereColumn('orders.id', 'canonical_payments.order_id')
                    ->where('orders.payment_status', PaymentStatus::Paid->value)
                    ->whereIn('orders.order_status', [OrderStatus::Processing->value, OrderStatus::Completed->value]);
            })
            ->selectRaw("DATE(canonical_payments.paid_at) AS sale_date, {$currency} AS sale_currency, SUM(CASE WHEN {$net} > 0 THEN {$net} ELSE 0 END) AS sale_amount, COUNT(*) AS sale_orders")
            ->groupByRaw("DATE(canonical_payments.paid_at), {$currency}")
            ->get()
            ->map(static fn ($row): array => [
                'date' => (string) $row->sale_date,
                'currency' => (string) $row->sale_currency,
                'amount' => (int) $row->sale_amount,
                'orders' => (int) $row->sale_orders,
            ])
            ->all();
    }

    /**
     * Counts orders whose one canonical paid Payment currently stands and
     * whose Order is an eligible successful Order. Duplicate paid Payments,
     * quantity lines, terminal Orders, and refunded Payments add nothing.
     */
    public function canonicalPaidOrderCount(?Carbon $start = null, ?Carbon $end = null, bool $includeEnd = false): int
    {
        return (int) $this->canonicalPaidPaymentsQuery($start, $end, $includeEnd)
            ->whereExists(function ($query): void {
                $query
                    ->select(DB::raw(1))
                    ->from('orders')
                    ->whereColumn('orders.id', 'canonical_payments.order_id')
                    ->whereIn('orders.order_status', [OrderStatus::Processing, OrderStatus::Completed]);
            })
            ->count();
    }

    /**
     * Sum canonical paid Payment amounts for one Customer's successful Orders.
     *
     * The aggregate intentionally uses the Payment amount, not the net receipt
     * projection: this is the customer's transaction value, while provider fees
     * belong to store earnings. IDR and USDT are returned in their persisted
     * integer units (rupiah and micro-USDT).
     *
     * @return array{IDR: int, USDT: int}
     */
    public function customerTransactionTotals(int $customerId, ?Carbon $createdFrom = null, ?Carbon $createdUntil = null): array
    {
        $currency = "UPPER(COALESCE(NULLIF(TRIM(canonical_payments.currency), ''), 'IDR'))";
        $amount = 'CASE WHEN canonical_payments.amount > 0 THEN canonical_payments.amount ELSE 0 END';
        $totals = $this->canonicalPaidPaymentsQuery(null, null, false)
            ->join('orders as transaction_orders', 'transaction_orders.id', '=', 'canonical_payments.order_id')
            ->where('transaction_orders.customer_id', $customerId)
            ->when($createdFrom !== null, fn ($query) => $query->where('transaction_orders.created_at', '>=', $createdFrom))
            ->when($createdUntil !== null, fn ($query) => $query->where('transaction_orders.created_at', '<', $createdUntil))
            ->whereIn('transaction_orders.order_status', [
                OrderStatus::Processing->value,
                OrderStatus::Completed->value,
            ])
            ->selectRaw("COALESCE(SUM(CASE WHEN {$currency} = 'IDR' THEN {$amount} ELSE 0 END), 0) AS idr_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$currency} = 'USDT' THEN {$amount} ELSE 0 END), 0) AS usdt_total")
            ->first();

        return [
            'IDR' => (int) ($totals?->idr_total ?? 0),
            'USDT' => (int) ($totals?->usdt_total ?? 0),
        ];
    }

    public function canonicalPaidOrderItemQuantity(?Carbon $start = null, ?Carbon $end = null, bool $includeEnd = false): int
    {
        $canonicalOrderIds = $this->canonicalPaidPaymentsQuery($start, $end, $includeEnd)
            ->select('canonical_payments.order_id');

        return (int) OrderItem::query()
            ->whereIn('order_id', $canonicalOrderIds)
            ->whereHas('order', fn ($query) => $query->whereIn('order_status', [OrderStatus::Processing, OrderStatus::Completed]))
            ->sum('quantity');
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
        $amount = (int) $payment->amount;
        $fee = (int) ($payment->provider_fee ?? 0);

        if ($payment->provider_total_payment !== null) {
            return max(0, (int) $payment->provider_total_payment - $fee);
        }

        if ($this->usesCustomerPaidPakasirQris($payment)) {
            return max(0, $amount);
        }

        return max(0, $amount - $fee);
    }

    public function currency(Payment $payment): string
    {
        return strtoupper((string) $payment->currency ?: 'IDR');
    }

    private function usesCustomerPaidPakasirQris(Payment $payment): bool
    {
        return $this->isPakasirQris($payment)
            && PakasirQrisFeePolicy::fromConfig()->feePayer() === 'customer';
    }

    private function isPakasirQris(Payment $payment): bool
    {
        $provider = strtolower(trim((string) $payment->provider));
        $method = strtolower(trim((string) $payment->method));
        $providerPaymentMethod = strtolower(trim((string) $payment->provider_payment_method));

        return $provider === 'pakasir'
            && ($method === 'api_qris' || $providerPaymentMethod === 'qris');
    }

    private function netAmountSql(string $table): string
    {
        $amount = $table.'.amount';
        $fee = 'COALESCE('.$table.'.provider_fee, 0)';
        $providerTotal = $table.'.provider_total_payment';
        $isPakasirQris = "(LOWER(COALESCE({$table}.provider, '')) = 'pakasir' AND (LOWER(COALESCE({$table}.method, '')) = 'api_qris' OR LOWER(COALESCE({$table}.provider_payment_method, '')) = 'qris'))";
        $fallback = PakasirQrisFeePolicy::fromConfig()->feePayer() === 'customer'
            ? "CASE WHEN {$isPakasirQris} THEN {$amount} ELSE {$amount} - {$fee} END"
            : "{$amount} - {$fee}";

        return "CASE WHEN {$providerTotal} IS NOT NULL THEN {$providerTotal} - {$fee} ELSE {$fallback} END";
    }
}
