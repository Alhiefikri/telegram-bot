<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Support\Money;

final class AdminDashboardSalesData
{
    /**
     * @param  array<string, mixed>  $statusCounts
     * @return array<string, mixed>
     */
    public function forSales(array $statusCounts): array
    {
        return [
            'orders' => $this->latestOrders(),
            'filters' => $this->salesFilters($statusCounts),
            'statusCounts' => $statusCounts,
        ];
    }

    private function latestOrders(): array
    {
        return Order::query()
            ->with([
                'customer:id,first_name,username',
                'orderItems:id,order_id,product_name,quantity,subtotal',
                'payments' => fn ($query) => $query
                    ->where('status', PaymentStatus::Paid)
                    ->select(['id', 'order_id', 'amount', 'status']),
            ])
            ->select(['id', 'customer_id', 'invoice_number', 'order_status', 'payment_status', 'total_amount', 'currency', 'created_at'])
            ->withCount('orderItems')
            ->latest()
            ->limit(5)
            ->get()
            ->map(function (Order $order): array {
                $paidAmount = (int) $order->payments->sum('amount');
                $orderStatusTone = $this->orderStatusTone($order->order_status);
                $paymentStatusTone = $this->paymentStatusTone($order->payment_status);

                return [
                    'id' => $order->id,
                    'invoice_number' => $order->invoice_number,
                    'customer' => $this->customerLabel($order),
                    'product_name' => $this->orderProductName($order),
                    'filter_groups' => $this->orderFilterGroups($order->order_status),
                    'order_status' => $order->order_status->value,
                    'order_status_label' => $this->orderStatusLabel($order->order_status),
                    'order_status_tone' => $orderStatusTone,
                    'order_status_badge' => $this->toneBadgeStyle($orderStatusTone),
                    'payment_status' => $order->payment_status->value,
                    'payment_status_label' => $this->paymentStatusLabel($order->payment_status),
                    'payment_status_tone' => $paymentStatusTone,
                    'payment_status_badge' => $this->toneBadgeStyle($paymentStatusTone),
                    'price' => Money::idr($order->total_amount),
                    'earning' => Money::idr($paidAmount),
                    'earning_amount' => $paidAmount,
                    'item_count' => $order->order_items_count,
                    'created_at' => $order->created_at?->toDateTimeString(),
                    'created_at_label' => $order->created_at?->format('d M Y') ?? '-',
                ];
            })
            ->all();
    }

    private function salesFilters(array $statusCounts): array
    {
        $countFor = fn (OrderStatus ...$statuses): int => collect($statuses)
            ->sum(fn (OrderStatus $status): int => $statusCounts[$status->value]['count'] ?? 0);

        return [
            [
                'key' => 'all',
                'label' => 'Semua',
                'count' => $countFor(...OrderStatus::cases()),
            ],
            [
                'key' => 'completed',
                'label' => 'Selesai',
                'count' => $countFor(OrderStatus::Completed),
            ],
            [
                'key' => 'issue',
                'label' => 'Masalah',
                'count' => $countFor(OrderStatus::Cancelled, OrderStatus::Expired),
            ],
            [
                'key' => 'waiting',
                'label' => 'Menunggu',
                'count' => $countFor(OrderStatus::Draft, OrderStatus::PendingPayment),
            ],
            [
                'key' => 'processing',
                'label' => 'Diproses',
                'count' => $countFor(OrderStatus::Processing),
            ],
        ];
    }

    private function orderProductName(Order $order): string
    {
        $firstItem = $order->orderItems->first();

        if (! $firstItem) {
            return 'Produk';
        }

        $remainingItems = max(0, $order->order_items_count - 1);

        return $remainingItems > 0
            ? "{$firstItem->product_name} + {$remainingItems} produk"
            : $firstItem->product_name;
    }

    private function orderFilterGroups(OrderStatus $status): array
    {
        return match ($status) {
            OrderStatus::Completed => ['all', 'completed'],
            OrderStatus::Cancelled, OrderStatus::Expired => ['all', 'issue'],
            OrderStatus::Draft, OrderStatus::PendingPayment => ['all', 'waiting'],
            OrderStatus::Processing => ['all', 'processing'],
        };
    }

    private function customerLabel(Order $order): string
    {
        if (! $order->customer) {
            return 'Pelanggan';
        }

        if ($order->customer->first_name && $order->customer->username) {
            return "{$order->customer->first_name} (@{$order->customer->username})";
        }

        return $order->customer->first_name ?: ($order->customer->username ? "@{$order->customer->username}" : 'Pelanggan');
    }

    private function orderStatusLabel(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Draft => 'Draft',
            OrderStatus::PendingPayment => 'Menunggu bayar',
            OrderStatus::Processing => 'Diproses',
            OrderStatus::Completed => 'Selesai',
            OrderStatus::Cancelled => 'Dibatalkan',
            OrderStatus::Expired => 'Expired',
        };
    }

    private function paymentStatusLabel(PaymentStatus $status): string
    {
        return match ($status) {
            PaymentStatus::Pending => 'Pending',
            PaymentStatus::Paid => 'Paid',
            PaymentStatus::Failed => 'Failed',
            PaymentStatus::Expired => 'Expired',
            PaymentStatus::Refunded => 'Refunded',
        };
    }

    private function paymentStatusTone(PaymentStatus $status): string
    {
        return match ($status) {
            PaymentStatus::Paid => 'emerald',
            PaymentStatus::Failed, PaymentStatus::Expired => 'rose',
            PaymentStatus::Pending => 'amber',
            PaymentStatus::Refunded => 'slate',
        };
    }

    private function orderStatusTone(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Processing => 'amber',
            OrderStatus::Completed => 'emerald',
            OrderStatus::Cancelled, OrderStatus::Expired => 'rose',
            default => 'slate',
        };
    }

    private function toneBadgeStyle(string $tone): array
    {
        return match ($tone) {
            'emerald' => ['background' => '#e8ffd4', 'color' => '#166534'],
            'amber' => ['background' => '#fef9c3', 'color' => '#92400e'],
            'blue' => ['background' => '#d4f6fb', 'color' => '#0e7490'],
            'rose' => ['background' => '#fecaca', 'color' => '#dc2626'],
            default => ['background' => '#f1f5f9', 'color' => '#64748b'],
        };
    }
}
