<?php

namespace App\Services\Telegram;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\PaymentReceiptReport;
use Illuminate\Support\Carbon;

final class TelegramStoreStatsReport
{
    public function __construct(
        private readonly PaymentReceiptReport $receiptReport,
    ) {}

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
     * @return array{report_end_at: Carbon, periods: array{today: array{current: array{new_orders: int, paid_orders: int, sold_products: int, sales_value: int, receipts: array<string, int>, earning: int}, previous: array{new_orders: int, paid_orders: int, sold_products: int, sales_value: int, receipts: array<string, int>, earning: int}}, week: array{current: array{new_orders: int, paid_orders: int, sold_products: int, sales_value: int, receipts: array<string, int>, earning: int}, previous: array{new_orders: int, paid_orders: int, sold_products: int, sales_value: int, receipts: array<string, int>, earning: int}}, month: array{current: array{new_orders: int, paid_orders: int, sold_products: int, sales_value: int, receipts: array<string, int>, earning: int}, previous: array{new_orders: int, paid_orders: int, sold_products: int, sales_value: int, receipts: array<string, int>, earning: int}}}}
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
            ],
        ];
    }

    /**
     * @return array{current: array{new_orders: int, paid_orders: int, sold_products: int, sales_value: int, receipts: array<string, int>, earning: int}, previous: array{new_orders: int, paid_orders: int, sold_products: int, sales_value: int, receipts: array<string, int>, earning: int}}
     */
    private function pair(Carbon $currentStart, Carbon $currentEnd, Carbon $previousStart, Carbon $previousEnd): array
    {
        return [
            'current' => $this->calculateStats($currentStart, $currentEnd, true),
            'previous' => $this->calculateStats($previousStart, $previousEnd, true),
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
            ? (int) Order::query()->whereIn('id', $paidOrderIds)->sum('total_amount')
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

    private function reportEnd(?Carbon $reportEndAt): Carbon
    {
        return ($reportEndAt ?? now('Asia/Jakarta'))->copy()->timezone('Asia/Jakarta');
    }
}
