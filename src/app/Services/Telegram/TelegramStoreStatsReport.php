<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\PaymentReceiptReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

final class TelegramStoreStatsReport
{
    private const START_METRICS_CACHE_KEY = 'telegram:start:metrics:v2';

    public function __construct(
        private readonly PaymentReceiptReport $receiptReport,
    ) {}

    /**
     * @return array{successful_orders: int, bot_users: int}
     */
    public function startMetrics(): array
    {
        $cacheSeconds = (int) config('services.telegram.start_metrics_cache_seconds', 60);
        if ($cacheSeconds <= 0) {
            return $this->calculateStartMetrics();
        }

        return Cache::remember(self::START_METRICS_CACHE_KEY, $cacheSeconds, fn (): array => $this->calculateStartMetrics());
    }

    /**
     * Read one customer's successful paid transaction totals without loading
     * the customer's payment history into memory.
     *
     * @return array{IDR: int, USDT: int}
     */
    public function customerTransactionTotals(Customer $customer): array
    {
        return $this->receiptReport->customerTransactionTotals((int) $customer->getKey());
    }

    /**
     * @return array{new_orders: int, paid_orders: int, sold_products: int, sales_value: int, receipts: array<string, int>, earning: int}
     */
    public function today(?Carbon $reportEndAt = null): array
    {
        $end = $this->reportEnd($reportEndAt);

        return $this->calculateStats($end->copy()->startOfDay(), $end, true);
    }

    /**
     * @return array{new_orders: int, paid_orders: int, sold_products: int, sales_value: int, receipts: array<string, int>, earning: int}
     */
    public function yesterday(?Carbon $reportEndAt = null): array
    {
        $end = $this->reportEnd($reportEndAt)->startOfDay();

        return $this->calculateStats($end->copy()->subDay(), $end);
    }

    /**
     * @return array{report_end_at: Carbon, periods: array<string, array{current: array{new_orders: int, paid_orders: int, sold_products: int, sales_value: int, receipts: array<string, int>, earning: int}, previous: array{new_orders: int, paid_orders: int, sold_products: int, sales_value: int, receipts: array<string, int>, earning: int}}>}
     */
    public function growth(?Carbon $reportEndAt = null): array
    {
        $end = $this->reportEnd($reportEndAt);
        $dayStart = $end->copy()->startOfDay();
        $weekStart = $end->copy()->startOfWeek(Carbon::MONDAY);
        $monthStart = $end->copy()->startOfMonth();

        $previousMonthStart = $monthStart->copy()->subMonthNoOverflow();
        $elapsedSeconds = $monthStart->diffInSeconds($end);
        $previousMonthEnd = $previousMonthStart->copy()->addSeconds($elapsedSeconds);
        $previousMonthLastMoment = $previousMonthStart->copy()->endOfMonth();
        if ($previousMonthEnd->greaterThan($previousMonthLastMoment)) {
            $previousMonthEnd = $previousMonthLastMoment;
        }

        return [
            'report_end_at' => $end,
            'periods' => [
                'today' => $this->pair($dayStart, $end, $dayStart->copy()->subDay(), $end->copy()->subDay()),
                'week' => $this->pair($weekStart, $end, $weekStart->copy()->subWeek(), $end->copy()->subWeek()),
                'month' => $this->pair($monthStart, $end, $previousMonthStart, $previousMonthEnd),
                'last_month' => $this->pair(
                    $previousMonthStart,
                    $monthStart,
                    $previousMonthStart->copy()->subMonthNoOverflow(),
                    $previousMonthStart,
                    false,
                ),
            ],
        ];
    }

    /**
     * @return array{current: array{new_orders: int, paid_orders: int, sold_products: int, sales_value: int, receipts: array<string, int>, earning: int}, previous: array{new_orders: int, paid_orders: int, sold_products: int, sales_value: int, receipts: array<string, int>, earning: int}}
     */
    private function pair(Carbon $currentStart, Carbon $currentEnd, Carbon $previousStart, Carbon $previousEnd, bool $includeEnd = true): array
    {
        return [
            'current' => $this->calculateStats($currentStart, $currentEnd, $includeEnd),
            'previous' => $this->calculateStats($previousStart, $previousEnd, $includeEnd),
        ];
    }

    /**
     * @return array{new_orders: int, paid_orders: int, sold_products: int, sales_value: int, receipts: array<string, int>, earning: int}
     */
    private function calculateStats(Carbon $reportStartAt, Carbon $reportEndAt, bool $includeEnd = false): array
    {
        $endOperator = $includeEnd ? '<=' : '<';

        $newOrders = Order::query()
            ->where('created_at', '>=', $reportStartAt)
            ->where('created_at', $endOperator, $reportEndAt)
            ->count();

        $canonicalPaidPayments = $this->receiptReport->canonicalPaidPayments($reportStartAt, $reportEndAt, $includeEnd);
        $paidOrderIds = $canonicalPaidPayments->pluck('order_id')->all();
        $paidOrders = count($paidOrderIds);

        $soldProducts = 0;
        if ($paidOrders > 0) {
            $soldProducts = OrderItem::query()
                ->whereIn('order_id', $paidOrderIds)
                ->sum('quantity');
        }

        $salesValue = $paidOrders > 0
            ? (int) Order::query()->whereIn('id', $paidOrderIds)->where('currency', 'IDR')->sum('total_amount')
            : 0;

        $receipts = $this->receiptReport->netReceiptsByCurrency($canonicalPaidPayments);

        return [
            'new_orders' => $newOrders,
            'paid_orders' => $paidOrders,
            'sold_products' => (int) $soldProducts,
            'sales_value' => $salesValue,
            'receipts' => $receipts,
            'earning' => $receipts['IDR'] ?? 0,
        ];
    }

    /**
     * @return array{successful_orders: int, bot_users: int}
     */
    private function calculateStartMetrics(): array
    {
        return [
            'successful_orders' => $this->receiptReport->canonicalPaidOrderCount(),
            'bot_users' => (int) Customer::query()->whereNotNull('telegram_id')->count(),
        ];
    }

    private function reportEnd(?Carbon $reportEndAt): Carbon
    {
        return ($reportEndAt ?? now('Asia/Jakarta'))->copy()->timezone('Asia/Jakarta');
    }
}
