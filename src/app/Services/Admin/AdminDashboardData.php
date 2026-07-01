<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;

final class AdminDashboardData
{
    public function __construct(
        private readonly AdminDashboardStatsData $statsData,
        private readonly AdminDashboardProductsData $productsData,
        private readonly AdminDashboardSalesData $salesData,
    ) {}

    /**
     * Compatibility façade for controllers/tests that consume the full dashboard payload.
     *
     * @return array{
     *     admin: array{name: string, role: string},
     *     metrics: array<string, int>,
     *     navigation: array{activeTab: string, tabs: list<array{id: string, label: string}>},
     *     statsTab: array<string, mixed>,
     *     productsTab: array<string, mixed>,
     *     salesTab: array<string, mixed>
     * }
     */
    public function forAdmin(User $user, ?string $requestedTab = null): array
    {
        $metrics = $this->metrics();
        $statusCounts = $this->statusCounts();
        $storeLink = $this->storeLink();
        $canManageProducts = (bool) $user->role?->canMutateProducts();

        return [
            'admin' => $this->adminIdentity($user),
            'metrics' => $metrics,
            'navigation' => $this->navigation($requestedTab),
            'statsTab' => $this->statsData->forStats($user, $metrics, $statusCounts, $storeLink),
            'productsTab' => $this->productsData->forProducts($canManageProducts),
            'salesTab' => $this->salesData->forSales($statusCounts),
        ];
    }

    private function adminIdentity(User $user): array
    {
        return [
            'name' => $user->name ?: 'Admin LHIE',
            'role' => $user->role?->value ?? 'operator',
        ];
    }

    private function metrics(): array
    {
        $productCount = Product::query()->count();
        $activeProductCount = Product::query()->where('is_active', true)->count();
        $orderCount = Order::query()->count();
        $ordersToProcess = Order::query()->where('order_status', OrderStatus::Processing)->count();
        $gmvAmount = (int) Order::query()
            ->whereIn('order_status', [OrderStatus::Processing, OrderStatus::Completed])
            ->sum('total_amount');
        $readOnlyBalanceAmount = (int) Payment::query()
            ->where('status', PaymentStatus::Paid)
            ->sum('amount');

        return [
            'read_only_balance_amount' => $readOnlyBalanceAmount,
            'gmv_amount' => $gmvAmount,
            'product_count' => $productCount,
            'active_product_count' => $activeProductCount,
            'order_count' => $orderCount,
            'orders_to_process' => $ordersToProcess,
        ];
    }

    private function navigation(?string $requestedTab): array
    {
        $tabs = [
            ['id' => 'stats', 'label' => 'Stats'],
            ['id' => 'produk', 'label' => 'Produk'],
            ['id' => 'penjualan', 'label' => 'Penjualan'],
            ['id' => 'broadcast', 'label' => 'Broadcast'],
            ['id' => 'withdraw', 'label' => 'Withdraw'],
            ['id' => 'riwayat', 'label' => 'Riwayat'],
            ['id' => 'affiliate', 'label' => 'Affiliate'],
        ];
        $tabIds = array_column($tabs, 'id');
        $requestedTab = (string) ($requestedTab ?: 'stats');

        return [
            'activeTab' => in_array($requestedTab, $tabIds, true) ? $requestedTab : 'stats',
            'tabs' => $tabs,
        ];
    }

    private function storeLink(): array
    {
        $storeUrl = route('storefront.home');

        return [
            'label' => 'Link toko',
            'url' => $storeUrl,
            'display' => parse_url($storeUrl, PHP_URL_HOST) ?: $storeUrl,
        ];
    }

    private function statusCounts(): array
    {
        $counts = Order::query()
            ->selectRaw('order_status, count(*) as aggregate')
            ->groupBy('order_status')
            ->pluck('aggregate', 'order_status');

        return collect(OrderStatus::cases())
            ->mapWithKeys(fn (OrderStatus $status): array => [
                $status->value => $this->statusCount($status, (int) ($counts[$status->value] ?? 0)),
            ])
            ->all();
    }

    private function statusCount(OrderStatus $status, int $count): array
    {
        $tone = match ($status) {
            OrderStatus::Processing => 'amber',
            OrderStatus::Completed => 'emerald',
            OrderStatus::Cancelled, OrderStatus::Expired => 'rose',
            default => 'slate',
        };

        return [
            'label' => match ($status) {
                OrderStatus::Draft => 'Draft',
                OrderStatus::PendingPayment => 'Menunggu bayar',
                OrderStatus::Processing => 'Diproses',
                OrderStatus::Completed => 'Selesai',
                OrderStatus::Cancelled => 'Dibatalkan',
                OrderStatus::Expired => 'Expired',
            },
            'count' => $count,
            'tone' => $tone,
            'card_class' => match ($tone) {
                'emerald' => 'stat-card-green',
                'blue' => 'stat-card-cyan',
                'amber' => 'stat-card-yellow',
                'rose' => 'stat-card-pink',
                default => 'stat-card-purple',
            },
            'inline_style' => $tone === 'slate' ? 'background: #fff;' : null,
        ];
    }
}
