<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Support\Money;
use Filament\Widgets\Widget;

class RecentOrdersWidget extends Widget
{
    protected string $view = 'filament.widgets.recent-orders-widget';

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'lg' => 2,
    ];

    protected function getViewData(): array
    {
        $orders = Order::query()
            ->with(['customer:id,first_name,username', 'orderItems.product:id,name'])
            ->latest()
            ->limit(6)
            ->get()
            ->map(function (Order $order): array {
                return [
                    'invoice_number' => $order->invoice_number,
                    'customer_name' => $order->customer?->first_name ?: 'Pelanggan',
                    'customer_handle' => $order->customer?->username ? '@'.$order->customer->username : null,
                    'products' => $this->productSummary($order),
                    'order_status' => $this->labelForOrderStatus($order->order_status),
                    'order_status_tone' => $this->toneForOrderStatus($order->order_status),
                    'payment_status' => $this->labelForPaymentStatus($order->payment_status),
                    'payment_status_tone' => $this->toneForPaymentStatus($order->payment_status),
                    'total_amount' => Money::idr($order->total_amount),
                    'created_at' => $order->created_at?->format('d M Y, H:i'),
                    'url' => OrderResource::getUrl('edit', ['record' => $order]),
                ];
            });

        return [
            'orders' => $orders,
            'isEmpty' => $orders->isEmpty(),
        ];
    }

    private function productSummary(Order $order): string
    {
        $items = $order->orderItems
            ->map(function ($item): string {
                $name = $item->product?->name ?: $item->product_name ?: 'Produk';

                return $name.' × '.((int) $item->quantity);
            })
            ->filter();

        if ($items->isEmpty()) {
            return '—';
        }

        return $items->take(2)->join(', ').($items->count() > 2 ? ' +'.($items->count() - 2).' item' : '');
    }

    private function labelForOrderStatus(OrderStatus|string|null $status): string
    {
        $value = $status instanceof OrderStatus ? $status->value : $status;

        return match ($value) {
            OrderStatus::Draft->value => 'Draft',
            OrderStatus::PendingPayment->value => 'Menunggu pembayaran',
            OrderStatus::Processing->value => 'Diproses',
            OrderStatus::Completed->value => 'Selesai',
            OrderStatus::Cancelled->value => 'Dibatalkan',
            OrderStatus::Expired->value => 'Kedaluwarsa',
            default => 'Tidak diketahui',
        };
    }

    private function toneForOrderStatus(OrderStatus|string|null $status): string
    {
        $value = $status instanceof OrderStatus ? $status->value : $status;

        return match ($value) {
            OrderStatus::Completed->value => 'emerald',
            OrderStatus::Processing->value => 'blue',
            OrderStatus::PendingPayment->value => 'amber',
            OrderStatus::Cancelled->value => 'rose',
            OrderStatus::Expired->value => 'slate',
            default => 'slate',
        };
    }

    private function labelForPaymentStatus(PaymentStatus|string|null $status): string
    {
        $value = $status instanceof PaymentStatus ? $status->value : $status;

        return match ($value) {
            PaymentStatus::Pending->value => 'Menunggu',
            PaymentStatus::Paid->value => 'Lunas',
            PaymentStatus::Failed->value => 'Gagal',
            PaymentStatus::Expired->value => 'Kedaluwarsa',
            PaymentStatus::Refunded->value => 'Refund',
            default => 'Tidak diketahui',
        };
    }

    private function toneForPaymentStatus(PaymentStatus|string|null $status): string
    {
        $value = $status instanceof PaymentStatus ? $status->value : $status;

        return match ($value) {
            PaymentStatus::Paid->value => 'emerald',
            PaymentStatus::Pending->value => 'amber',
            PaymentStatus::Failed->value => 'rose',
            PaymentStatus::Refunded->value => 'cyan',
            PaymentStatus::Expired->value => 'slate',
            default => 'slate',
        };
    }
}
