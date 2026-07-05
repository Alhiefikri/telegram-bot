<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\SalesProofPost;
use App\Models\StoreChannelSetting;
use App\Support\Money;

final class AdminDashboardSalesData
{
    private ?StoreChannelSetting $storeChannelSetting = null;

    /**
     * @param  array<string, mixed>  $statusCounts
     * @return array<string, mixed>
     */
    public function forSales(array $statusCounts, bool $canRetrySalesProof = false): array
    {
        return [
            'orders' => $this->latestOrders($canRetrySalesProof),
            'filters' => $this->salesFilters($statusCounts),
            'statusCounts' => $statusCounts,
        ];
    }

    private function latestOrders(bool $canRetrySalesProof): array
    {
        return Order::query()
            ->with([
                'customer:id,first_name,username',
                'orderItems:id,order_id,product_name,quantity,subtotal',
                'payments' => fn ($query) => $query
                    ->where('status', PaymentStatus::Paid)
                    ->select(['id', 'order_id', 'amount', 'status']),
                'salesProofPost:id,order_id,status,telegram_message_id,last_error,attempts',
            ])
            ->select(['id', 'customer_id', 'invoice_number', 'order_status', 'payment_status', 'total_amount', 'currency', 'created_at'])
            ->withCount('orderItems')
            ->latest()
            ->limit(5)
            ->get()
            ->map(function (Order $order) use ($canRetrySalesProof): array {
                $paidAmount = (int) $order->payments->sum('amount');
                $orderStatusTone = $this->orderStatusTone($order->order_status);
                $paymentStatusTone = $this->paymentStatusTone($order->payment_status);

                return [
                    'id' => $order->id,
                    'invoice_number' => $order->invoice_number,
                    'detail_url' => route('admin.orders.show', $order),
                    'customer' => $this->customerLabel($order),
                    'product_name' => $this->orderProductName($order),
                    'filter_groups' => $this->orderFilterGroups($order->order_status),
                    'order_status' => $order->order_status->value,
                    'order_status_label' => $this->orderStatusLabel($order->order_status),
                    'order_status_tone' => $orderStatusTone,
                    'payment_status' => $order->payment_status->value,
                    'payment_status_label' => $this->paymentStatusLabel($order->payment_status),
                    'payment_status_tone' => $paymentStatusTone,
                    'payment_attention' => $this->paymentAttention($order),
                    'price' => Money::idr($order->total_amount),
                    'earning' => Money::idr($paidAmount),
                    'earning_amount' => $paidAmount,
                    'item_count' => $order->order_items_count,
                    'sales_proof' => $this->salesProofState($order, $canRetrySalesProof),
                    'created_at' => $order->created_at?->toDateTimeString(),
                    'created_at_label' => $order->created_at?->format('d M Y') ?? '-',
                ];
            })
            ->all();
    }

    public function paymentAttention(Order $order): ?array
    {
        if (! $this->isLatePaidReleasedOrder($order)) {
            return null;
        }

        return [
            'label' => 'Late paid',
            'message' => 'Perlu refund/support manual.',
            'tone' => 'warning',
        ];
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

    public function salesProofState(Order $order, bool $canRetrySalesProof = false): array
    {
        $post = $order->salesProofPost;
        if (! $post) {
            return [
                'status' => 'none',
                'label' => 'Belum ada post',
                'tone' => 'slate',
                'message_id' => null,
                'last_error' => null,
                'attempts' => 0,
                'can_retry' => false,
                'retry_url' => null,
            ];
        }

        if (in_array($post->status, [SalesProofPost::STATUS_PENDING, SalesProofPost::STATUS_SENDING], true)) {
            $setting = $this->storeChannelSetting();
            if (! $setting->sales_proof_enabled) {
                return $this->salesProofUnavailableState($order, $post, 'disabled', 'Disabled', 'Sales Proof Post is disabled.', false);
            }

            if (! ($setting->telegram_chat_id ?: $setting->channel_username)) {
                return $this->salesProofUnavailableState($order, $post, SalesProofPost::STATUS_FAILED, 'Failed', 'Store Channel chat is not configured.', $canRetrySalesProof);
            }
        }

        $tone = match ($post->status) {
            SalesProofPost::STATUS_SENT => 'emerald',
            SalesProofPost::STATUS_FAILED => 'rose',
            SalesProofPost::STATUS_PENDING, SalesProofPost::STATUS_SENDING => 'amber',
            default => 'slate',
        };

        return [
            'status' => $post->status,
            'label' => match ($post->status) {
                SalesProofPost::STATUS_PENDING => 'Pending',
                SalesProofPost::STATUS_SENDING => 'Sending',
                SalesProofPost::STATUS_SENT => 'Sent',
                SalesProofPost::STATUS_FAILED => 'Failed',
                default => ucfirst((string) $post->status),
            },
            'tone' => $tone,
            'message_id' => $post->telegram_message_id,
            'last_error' => $post->last_error,
            'attempts' => $post->attempts,
            'can_retry' => $canRetrySalesProof && $post->status === SalesProofPost::STATUS_FAILED,
            'retry_url' => route('admin.orders.sales-proof.retry', $order),
        ];
    }

    private function salesProofUnavailableState(Order $order, SalesProofPost $post, string $status, string $label, string $lastError, bool $canRetry): array
    {
        return [
            'status' => $status,
            'label' => $label,
            'tone' => $status === SalesProofPost::STATUS_FAILED ? 'rose' : 'slate',
            'message_id' => $post->telegram_message_id,
            'last_error' => $lastError,
            'attempts' => $post->attempts,
            'can_retry' => $canRetry,
            'retry_url' => route('admin.orders.sales-proof.retry', $order),
        ];
    }

    private function storeChannelSetting(): StoreChannelSetting
    {
        return $this->storeChannelSetting ??= StoreChannelSetting::current();
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

    private function isLatePaidReleasedOrder(Order $order): bool
    {
        return $order->payment_status === PaymentStatus::Paid
            && in_array($order->order_status, [OrderStatus::Cancelled, OrderStatus::Expired], true);
    }
}
