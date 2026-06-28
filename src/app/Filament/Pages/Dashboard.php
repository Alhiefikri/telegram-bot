<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\LowStockProductsWidget;
use App\Filament\Widgets\OrderStatusWidget;
use App\Filament\Widgets\RecentOrdersWidget;
use App\Filament\Widgets\StoreOverviewWidget;
use App\Filament\Widgets\StoreSupportWidget;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\AccountWidget;
use Illuminate\Contracts\Support\Htmlable;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = 1;

    public function getTitle(): string|Htmlable
    {
        return 'LHIE STORE Admin';
    }

    public function getHeading(): string|Htmlable|null
    {
        $name = Filament::auth()->user()?->name ?: 'Admin';

        return "Selamat datang, {$name} 👋";
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Pantau pesanan, produk, pembayaran, dan pengiriman digital hari ini.';
    }

    public function getColumns(): int|array
    {
        return [
            'default' => 1,
            'lg' => 3,
        ];
    }

    public function getWidgets(): array
    {
        return [
            StoreOverviewWidget::class,
            RecentOrdersWidget::class,
            OrderStatusWidget::class,
            LowStockProductsWidget::class,
            StoreSupportWidget::class,
            AccountWidget::class,
        ];
    }
}
