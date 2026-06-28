<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Support\Money;
use Filament\Widgets\Widget;

class StoreOverviewWidget extends Widget
{
    protected string $view = 'filament.widgets.store-overview-widget';

    protected int|string|array $columnSpan = [
        'default' => 'full',
    ];

    protected function getViewData(): array
    {
        $today = now()->toDateString();

        return [
            'cards' => [
                [
                    'label' => 'Total Pesanan Hari Ini',
                    'value' => Order::query()->whereDate('created_at', $today)->count(),
                    'tone' => 'blue',
                ],
                [
                    'label' => 'Menunggu Pembayaran',
                    'value' => Order::query()->where('payment_status', PaymentStatus::Pending->value)->count(),
                    'tone' => 'amber',
                ],
                [
                    'label' => 'Diproses',
                    'value' => Order::query()->where('order_status', OrderStatus::Processing->value)->count(),
                    'tone' => 'cyan',
                ],
                [
                    'label' => 'Produk Aktif',
                    'value' => Product::query()->where('is_active', true)->count(),
                    'tone' => 'emerald',
                ],
                [
                    'label' => 'Pendapatan Hari Ini',
                    'value' => Money::idr(Payment::query()->where('status', PaymentStatus::Paid->value)->whereDate('paid_at', $today)->sum('amount')),
                    'tone' => 'slate',
                ],
            ],
        ];
    }
}
