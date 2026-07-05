<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StoreChannelSetting;
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
            'salesTab' => $this->salesData->forSales($statusCounts, (bool) $user->role?->canManagePaymentSettings()),
            'storeChannelTab' => $this->storeChannelTab($user),
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
            ['id' => 'telegram-channel', 'label' => 'Telegram Channel'],
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

    private function storeChannelTab(User $user): array
    {
        $setting = StoreChannelSetting::current();
        $configured = (bool) ($setting->channel_username || $setting->channel_link);
        $ready = $configured && $setting->telegram_chat_id && $setting->last_test_success === true;
        $status = match (true) {
            ! $configured => ['label' => 'Not configured', 'hint' => 'Isi username/link channel lalu jalankan Test Channel.', 'tone' => 'slate'],
            $ready => ['label' => 'Ready', 'hint' => 'Bot dapat mengakses dan mengirim pesan ke channel.', 'tone' => 'emerald'],
            $setting->last_test_success === null => ['label' => 'Needs test', 'hint' => 'Jalankan Test Channel untuk resolve chat ID dan cek izin post.', 'tone' => 'amber'],
            $setting->last_test_success === false && str_contains(strtolower((string) $setting->last_test_error), 'cannot post') => ['label' => 'Bot cannot post', 'hint' => $setting->last_test_error ?: 'Pastikan bot menjadi admin channel dan boleh mengirim pesan.', 'tone' => 'rose'],
            $setting->last_test_success === false && str_contains(strtolower((string) $setting->last_test_error), 'cannot access') => ['label' => 'Bot cannot access', 'hint' => $setting->last_test_error ?: 'Pastikan bot sudah ditambahkan ke channel.', 'tone' => 'rose'],
            $setting->last_test_success === false => ['label' => 'Test failed', 'hint' => $setting->last_test_error ?: 'Periksa akses bot ke channel.', 'tone' => 'rose'],
            default => ['label' => 'Needs test', 'hint' => 'Jalankan Test Channel untuk resolve chat ID dan cek izin post.', 'tone' => 'amber'],
        };
        $channelFeaturesEnabled = (bool) ($setting->community_gate_enabled || $setting->sales_proof_enabled);

        return [
            'setting' => $setting,
            'status' => $status,
            'details' => [
                'chat_id_label' => $setting->telegram_chat_id ?: 'Belum ada',
                'last_tested_label' => $setting->last_tested_at?->format('Y-m-d H:i') ?: 'Belum pernah',
                'message_id_label' => $setting->last_test_message_id ?: '-',
                'last_test_error' => $setting->last_test_error,
            ],
            'ready' => $ready,
            'show_setup_warning' => $channelFeaturesEnabled && ! $ready,
            'can_manage' => (bool) $user->role?->canManagePaymentSettings(),
            'update_url' => route('admin.store-channel.update'),
            'test_url' => route('admin.store-channel.test'),
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
        ];
    }
}
