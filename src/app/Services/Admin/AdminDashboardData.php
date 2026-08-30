<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreChannelSetting;
use App\Models\TelegramBroadcast;
use App\Models\User;
use App\Services\PaymentReceiptReport;
use App\Services\Telegram\FakeSalesProofPostPublisher;
use App\Services\Telegram\TelegramBotApiRedactor;

final class AdminDashboardData
{
    public function __construct(
        private readonly AdminDashboardStatsData $statsData,
        private readonly AdminDashboardProductsData $productsData,
        private readonly AdminDashboardSalesData $salesData,
        private readonly FakeSalesProofPostPublisher $fakeSalesProof,
        private readonly PaymentReceiptReport $receiptReport,
        private readonly AdminDashboardReportingData $reportingData,
        private readonly StorefrontFunnelReport $funnelReport,
    ) {}

    /**
     * @return array{
     *     admin: array{name: string, role: string},
     *     metrics: array<string, int>,
     *     navigation: array{activeTab: string, tabs: list<array{id: string, label: string}>},
     *     statsTab: array<string, mixed>,
     *     productsTab: array<string, mixed>,
     *     salesTab: array<string, mixed>,
     *     historyTab: array<string, mixed>,
     *     withdrawTab: array<string, mixed>,
     *     funnelTab: array<string, mixed>
     * }
     */
    public function forAdmin(
        User $user,
        ?string $requestedTab = null,
        ?string $salesFilter = null,
        int $productsPage = 1,
        array $reportingFilters = [],
    ): array {
        $metrics = $this->metrics();
        $statusCounts = $this->statusCounts();
        $storeLink = $this->storeLink();
        $navigation = $this->navigation($requestedTab);
        $canManageProducts = (bool) $user->role?->canMutateProducts();
        $funnelTab = $this->funnelReport->empty($user);
        $historyTab = $this->reportingData->emptyHistory();
        $withdrawTab = $this->reportingData->emptyWithdraw($user);

        if ($navigation['activeTab'] === StorefrontFunnelReport::TAB) {
            $funnelTab = $this->funnelReport->forAdmin($user, $reportingFilters);
        }

        if ($navigation['activeTab'] === 'riwayat') {
            $historyTab = $this->reportingData->forHistory($reportingFilters['page'] ?? 1);
        }

        if ($navigation['activeTab'] === 'withdraw') {
            $withdrawTab = $this->reportingData->forWithdraw($user, $reportingFilters);
        }

        return [
            'admin' => $this->adminIdentity($user),
            'metrics' => $metrics,
            'navigation' => $navigation,
            'statsTab' => $this->statsData->forStats($user, $metrics, $statusCounts, $storeLink),
            'productsTab' => $this->productsData->forProducts($canManageProducts, $productsPage),
            'salesTab' => $this->salesData->forSales($statusCounts, (bool) $user->role?->canManagePaymentSettings(), $salesFilter),
            'broadcastTab' => $this->broadcastTab(),
            'storeChannelTab' => $this->storeChannelTab($user),
            'historyTab' => $historyTab,
            'withdrawTab' => $withdrawTab,
            'funnelTab' => $funnelTab,
        ];
    }

    /**
     * Builds the request payload for one dashboard tab while retaining the
     * lightweight header and navigation shared by every dashboard response.
     *
     * @return array<string, mixed>
     */
    public function forActiveTab(
        User $user,
        ?string $requestedTab = null,
        ?string $salesFilter = null,
        int $productsPage = 1,
        array $reportingFilters = [],
    ): array {
        $metrics = $this->metrics();
        $statusCounts = $this->statusCounts();
        $storeLink = $this->storeLink();
        $navigation = $this->navigation($requestedTab);
        $canManageProducts = (bool) $user->role?->canMutateProducts();
        $data = [
            'admin' => $this->adminIdentity($user),
            'metrics' => $metrics,
            'navigation' => $navigation,
            'statsTab' => $this->statsData->forHeader($metrics, $storeLink),
            'productsTab' => [
                'canManage' => $canManageProducts,
                'productStoreUrl' => route('admin.products.store'),
                'placeholder' => [],
                'products' => [],
                'productGroups' => [],
                'standaloneProducts' => [],
                'pagination' => [],
                'categoryOptions' => [],
                'familyOptions' => [],
                'familyManagement' => ['createUrl' => route('admin.product-families.store'), 'families' => []],
                'categoryManagement' => ['createUrl' => route('admin.categories.store'), 'categories' => []],
            ],
            'salesTab' => [
                'orders' => [],
                'filters' => [],
                'statusCounts' => $statusCounts,
                'active_filter' => 'all',
                'pagination' => [],
            ],
            'broadcastTab' => ['recent' => []],
            'storeChannelTab' => [],
            'historyTab' => $this->reportingData->emptyHistory(),
            'withdrawTab' => $this->reportingData->emptyWithdraw($user),
            'funnelTab' => $this->funnelReport->empty($user),
        ];

        match ($navigation['activeTab']) {
            'stats' => $data['statsTab'] = $this->statsData->forStats($user, $metrics, $statusCounts, $storeLink),
            'produk' => $data['productsTab'] = $this->productsData->forProducts($canManageProducts, $productsPage),
            'penjualan' => $data['salesTab'] = $this->salesData->forSales($statusCounts, (bool) $user->role?->canManagePaymentSettings(), $salesFilter),
            'broadcast' => $data['broadcastTab'] = $this->broadcastTab(),
            'telegram-channel' => $data['storeChannelTab'] = $this->storeChannelTab($user),
            'riwayat' => $data['historyTab'] = $this->reportingData->forHistory($reportingFilters['page'] ?? 1),
            'withdraw' => $data['withdrawTab'] = $this->reportingData->forWithdraw($user, $reportingFilters),
            StorefrontFunnelReport::TAB => $data['funnelTab'] = $this->funnelReport->forAdmin($user, $reportingFilters),
            default => null,
        };

        return $data;
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
        $completedOrderCount = Order::query()->where('order_status', OrderStatus::Completed)->count();
        $gmvAmount = (int) Order::query()
            ->whereIn('order_status', [OrderStatus::Processing, OrderStatus::Completed])
            ->sum('total_amount');
        $readOnlyBalanceAmount = $this->receiptReport->idrNetTotalCanonical();

        return [
            'read_only_balance_amount' => $readOnlyBalanceAmount,
            'gmv_amount' => $gmvAmount,
            'product_count' => $productCount,
            'active_product_count' => $activeProductCount,
            'order_count' => $orderCount,
            'orders_to_process' => $ordersToProcess,
            'completed_order_count' => $completedOrderCount,
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
            ['id' => StorefrontFunnelReport::TAB, 'label' => 'Funnel'],
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
        $lastTestError = $setting->last_test_error === null ? null : TelegramBotApiRedactor::redactText((string) $setting->last_test_error);
        $configured = (bool) ($setting->channel_username || $setting->channel_link);
        $ready = $configured && $setting->telegram_chat_id && $setting->last_test_success === true;
        $status = match (true) {
            ! $configured => ['label' => 'Not configured', 'hint' => 'Isi username/link channel lalu jalankan Test Channel.', 'tone' => 'slate'],
            $ready => ['label' => 'Ready', 'hint' => 'Bot dapat mengakses dan mengirim pesan ke channel.', 'tone' => 'emerald'],
            $setting->last_test_success === null => ['label' => 'Needs test', 'hint' => 'Jalankan Test Channel untuk resolve chat ID dan cek izin post.', 'tone' => 'amber'],
            $setting->last_test_success === false && str_contains(strtolower((string) $lastTestError), 'cannot post') => ['label' => 'Bot cannot post', 'hint' => $lastTestError ?: 'Pastikan bot menjadi admin channel dan boleh mengirim pesan.', 'tone' => 'rose'],
            $setting->last_test_success === false && str_contains(strtolower((string) $lastTestError), 'cannot access') => ['label' => 'Bot cannot access', 'hint' => $lastTestError ?: 'Pastikan bot sudah ditambahkan ke channel.', 'tone' => 'rose'],
            $setting->last_test_success === false => ['label' => 'Test failed', 'hint' => $lastTestError ?: 'Periksa akses bot ke channel.', 'tone' => 'rose'],
            default => ['label' => 'Needs test', 'hint' => 'Jalankan Test Channel untuk resolve chat ID dan cek izin post.', 'tone' => 'amber'],
        };
        $channelFeaturesEnabled = (bool) ($setting->community_gate_enabled || $setting->sales_proof_enabled);

        $canManage = (bool) $user->role?->canManagePaymentSettings();

        return [
            'setting' => $setting,
            'status' => $status,
            'details' => [
                'chat_id_label' => $setting->telegram_chat_id ?: 'Belum ada',
                'last_tested_label' => $setting->last_tested_at?->format('Y-m-d H:i') ?: 'Belum pernah',
                'message_id_label' => $setting->last_test_message_id ?: '-',
                'last_test_error' => $lastTestError,
            ],
            'ready' => $ready,
            'show_setup_warning' => $channelFeaturesEnabled && ! $ready,
            'can_manage' => $canManage,
            'update_url' => route('admin.store-channel.update'),
            'test_url' => route('admin.store-channel.test'),
            'fake_sales_proof' => $this->fakeSalesProof->dashboardState($setting, $ready, $canManage),
        ];
    }

    private function broadcastTab(): array
    {
        return [
            'recent' => TelegramBroadcast::query()
                ->latest('id')
                ->limit(10)
                ->get(['id', 'status', 'recipient_count', 'sent_count', 'failed_count', 'pending_count', 'created_at', 'completed_at']),
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
