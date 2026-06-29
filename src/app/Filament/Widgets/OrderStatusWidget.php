<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Models\Order;
use Filament\Widgets\Widget;

class OrderStatusWidget extends Widget
{
    protected string $view = 'filament.widgets.order-status-widget';

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'lg' => 4,
    ];

    protected function getViewData(): array
    {
        $totalOrders = Order::query()->count();
        $total = max($totalOrders, 1);

        $statuses = collect(OrderStatus::cases())->map(function (OrderStatus $status) use ($total): array {
            $count = Order::query()->where('order_status', $status->value)->count();

            return [
                'value' => $status->value,
                'label' => $this->labelForStatus($status),
                'tone' => $this->toneForStatus($status),
                'count' => $count,
                'percentage' => (int) round(($count / $total) * 100),
            ];
        })->values();

        return [
            'statuses' => $statuses,
            'total' => $totalOrders,
        ];
    }

    private function labelForStatus(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Draft => 'Draft',
            OrderStatus::PendingPayment => 'Menunggu pembayaran',
            OrderStatus::Processing => 'Diproses',
            OrderStatus::Completed => 'Selesai',
            OrderStatus::Cancelled => 'Dibatalkan',
            OrderStatus::Expired => 'Kedaluwarsa',
        };
    }

    private function toneForStatus(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Completed => 'emerald',
            OrderStatus::Processing => 'blue',
            OrderStatus::PendingPayment => 'amber',
            OrderStatus::Cancelled => 'rose',
            OrderStatus::Expired, OrderStatus::Draft => 'slate',
        };
    }
}
