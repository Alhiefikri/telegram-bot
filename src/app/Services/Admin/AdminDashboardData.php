<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreChannelSetting;
use App\Models\StorefrontSetting;
use App\Models\TelegramBroadcast;
use App\Models\User;
use App\Services\PaymentReceiptReport;
use App\Services\StoreBalance\StoreBalanceOwnerCompensationService;
use App\Services\Telegram\FakeSalesProofPostPublisher;
use App\Services\Telegram\TelegramBotApiRedactor;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

final class AdminDashboardData
{
    /** @var array<string, string> */
    private const NAVIGATION_ICONS = [
        'home' => 'home',
        'package' => 'package',
        'receipt' => 'receipt',
        'truck' => 'truck',
        'megaphone' => 'megaphone',
        'message' => 'message',
        'history' => 'history',
        'wallet' => 'wallet',
        'chart' => 'chart',
        'alert' => 'alert',
        'report' => 'report',
        'adjustment' => 'adjustment',
        'shield' => 'shield',
        'settings' => 'settings',
    ];

    public function __construct(
        private readonly AdminDashboardStatsData $statsData,
        private readonly AdminDashboardProductsData $productsData,
        private readonly AdminDashboardSalesData $salesData,
        private readonly FakeSalesProofPostPublisher $fakeSalesProof,
        private readonly PaymentReceiptReport $receiptReport,
        private readonly AdminDashboardReportingData $reportingData,
        private readonly StorefrontFunnelReport $funnelReport,
        private readonly StoreBalanceOwnerCompensationService $ownerCompensation,
        private readonly AdminSupplierDashboardData $supplierData,
        private readonly BalanceAdjustmentDashboardReadiness $balanceAdjustmentDashboardReadiness,
    ) {}

    /**
     * @return array{
     *     admin: array{name: string, role: string},
     *     metrics: array<string, int>,
     *     navigation: array{activeTab: string, tabs: list<array{id: string, label: string}>, groups: list<array{id: string, label: string, items: list<array<string, mixed>>}>},
     *     statsTab: array<string, mixed>,
     *     productsTab: array<string, mixed>,
     *     salesTab: array<string, mixed>,
     *     withdrawTab: array<string, mixed>,
     *     funnelTab: array<string, mixed>,
     *     balanceIssuesTab: array<string, mixed>
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
        $navigation = $this->navigation($requestedTab, $user);
        $canManageProducts = (bool) $user->role?->canMutateProducts();
        $funnelTab = $this->funnelReport->empty($user);
        $historyTab = $this->reportingData->emptyHistory();
        $withdrawTab = $this->reportingData->emptyWithdraw($user);
        $balanceIssuesTab = $this->ownerCompensation->emptyQueue($user);

        if ($navigation['activeTab'] === StorefrontFunnelReport::TAB) {
            $funnelTab = $this->funnelReport->forAdmin($user, $reportingFilters);
        }

        if ($navigation['activeTab'] === 'riwayat') {
            $historyTab = $this->reportingData->forHistory($reportingFilters['page'] ?? 1);
        }

        if ($navigation['activeTab'] === 'withdraw') {
            $withdrawTab = $this->reportingData->forWithdraw($user, $reportingFilters);
        }

        if ($navigation['activeTab'] === StoreBalanceOwnerCompensationService::TAB) {
            $balanceIssuesTab = $this->ownerCompensation->queue($user, $reportingFilters);
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
            'balanceIssuesTab' => $balanceIssuesTab,
            'suppliersTab' => $navigation['activeTab'] === 'suppliers' ? $this->supplierData->forTab() : [],
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
        $navigation = $this->navigation($requestedTab, $user);
        $activeTab = $navigation['activeTab'];
        $storeLink = $this->storeLink();
        if ($activeTab === 'stats') {
            $metrics = $this->metrics();
            $statusCounts = $this->statusCounts();
            $statsTab = $this->statsData->forStats($user, $metrics, $statusCounts, $storeLink);
        } else {
            $statusCounts = $activeTab === 'penjualan' ? $this->statusCounts() : [];
            $activeProductCount = Product::query()->where('is_active', true)->count();
            $ordersToProcess = $activeTab === 'penjualan'
                ? (int) ($statusCounts[OrderStatus::Processing->value]['count'] ?? 0)
                : Order::query()->where('order_status', OrderStatus::Processing)->count();
            $metrics = [];
            $statsTab = $this->statsData->forCompactHeader($activeProductCount, $ordersToProcess, $storeLink);
        }
        $canManageProducts = (bool) $user->role?->canMutateProducts();
        $data = [
            'admin' => $this->adminIdentity($user),
            'metrics' => $metrics,
            'navigation' => $navigation,
            'statsTab' => $statsTab,
            'productsTab' => [
                'canManage' => $canManageProducts,
                'productStoreUrl' => route('admin.products.store'),
                'placeholder' => [],
                'products' => [],
                'productGroups' => [],
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
            'balanceIssuesTab' => $this->ownerCompensation->emptyQueue($user),
            'suppliersTab' => [],
        ];

        match ($activeTab) {
            'stats' => null,
            'produk' => $data['productsTab'] = $this->productsData->forProducts($canManageProducts, $productsPage, includeStockItems: false),
            'penjualan' => $data['salesTab'] = $this->salesData->forSales($statusCounts, (bool) $user->role?->canManagePaymentSettings(), $salesFilter),
            'broadcast' => $data['broadcastTab'] = $this->broadcastTab(),
            'telegram-channel' => $data['storeChannelTab'] = $this->storeChannelTab($user),
            'riwayat' => $data['historyTab'] = $this->reportingData->forHistory($reportingFilters['page'] ?? 1),
            'withdraw' => $data['withdrawTab'] = $this->reportingData->forWithdraw($user, $reportingFilters),
            StorefrontFunnelReport::TAB => $data['funnelTab'] = $this->funnelReport->forAdmin($user, $reportingFilters),
            StoreBalanceOwnerCompensationService::TAB => $data['balanceIssuesTab'] = $this->ownerCompensation->queue($user, $reportingFilters),
            'suppliers' => $data['suppliersTab'] = $this->supplierData->forTab(),
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
            ->where('currency', 'IDR')
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

    /**
     * Returns the canonical permission-filtered navigation for a linked
     * operational page. Linked pages share dashboard navigation without
     * loading the active dashboard tab payload.
     *
     * @return array<string, mixed>
     */
    public function navigationFor(?string $requestedTab, User $user, ?string $activeNavigationId = null): array
    {
        return $this->navigation($requestedTab, $user, $activeNavigationId);
    }

    /**
     * @return array<string, mixed>
     */
    private function navigation(?string $requestedTab, User $user, ?string $activeNavigationId = null): array
    {
        $canReconcilePayments = (bool) $user->role?->canReconcilePayments();
        $canViewStorefrontAnalytics = (bool) $user->role?->canViewStorefrontAnalytics();
        $canManageStoreOperations = (bool) $user->role?->canManageStoreOperations();
        $canCompensateStoreBalance = (bool) $user->role?->canCompensateStoreBalance();
        $canManagePaymentSettings = (bool) $user->role?->canManagePaymentSettings();


        $tabItems = [
            'stats' => $this->navigationItem(
                'stats',
                'Ringkasan',
                'home',
                route('admin.dashboard', ['tab' => 'stats']),
            ),
            'produk' => $this->navigationItem(
                'produk',
                'Produk',
                'package',
                route('admin.dashboard', ['tab' => 'produk']),
                action: [
                    'id' => 'create-product',
                    'label' => 'Tambah Produk',
                    'href' => route('admin.dashboard', ['tab' => 'produk', 'product_form' => 'create']),
                ],
            ),
            'penjualan' => $this->navigationItem(
                'penjualan',
                'Pesanan',
                'receipt',
                route('admin.dashboard', ['tab' => 'penjualan']),
            ),
            'suppliers' => $this->navigationItem(
                'suppliers',
                'Supplier',
                'truck',
                route('admin.dashboard', ['tab' => 'suppliers']),
                visible: $canManageStoreOperations,
            ),
            'broadcast' => $this->navigationItem(
                'broadcast',
                'Siaran',
                'megaphone',
                route('admin.dashboard', ['tab' => 'broadcast']),
            ),
            'telegram-channel' => $this->navigationItem(
                'telegram-channel',
                'Store Channel',
                'message',
                route('admin.dashboard', ['tab' => 'telegram-channel']),
            ),
            'riwayat' => $this->navigationItem(
                'riwayat',
                'Riwayat KlikQRIS',
                'history',
                route('admin.dashboard', ['tab' => 'riwayat']),
            ),
            'withdraw' => $this->navigationItem(
                'withdraw',
                'Withdraw',
                'wallet',
                route('admin.dashboard', ['tab' => 'withdraw']),
                visible: $canReconcilePayments,
            ),
            StorefrontFunnelReport::TAB => $this->navigationItem(
                StorefrontFunnelReport::TAB,
                'Funnel',
                'chart',
                route('admin.dashboard', ['tab' => StorefrontFunnelReport::TAB]),
                visible: $canViewStorefrontAnalytics,
            ),
            StoreBalanceOwnerCompensationService::TAB => $this->navigationItem(
                StoreBalanceOwnerCompensationService::TAB,
                'Kompensasi Top-up',
                'alert',
                route('admin.dashboard', ['tab' => StoreBalanceOwnerCompensationService::TAB]),
                visible: $canCompensateStoreBalance,
            ),
        ];

        $groups = [
            [
                'id' => 'primary',
                'label' => 'Utama',
                'items' => [$tabItems['stats']],
            ],
            [
                'id' => 'operations',
                'label' => 'Operasional',
                'items' => $this->visibleNavigationItems([$tabItems['penjualan'], $tabItems['produk'], $tabItems['suppliers']]),
            ],
            [
                'id' => 'finance',
                'label' => 'Keuangan',
                'items' => $this->visibleNavigationItems([
                    $this->navigationItem('balance-reports', 'Laporan saldo', 'report', route('admin.store-balance.reports.index'), visible: $canReconcilePayments),
                    $this->navigationItem('balance-adjustments', 'Kelola saldo', 'adjustment', route('admin.store-balance.adjustments.index'), visible: $canCompensateStoreBalance && $this->balanceAdjustmentDashboardReadiness->isReady()),
                    $this->navigationItem('usdt-review', 'Review deposit USDT', 'shield', route('admin.store-balance.usdt-review.index'), visible: $canCompensateStoreBalance),
                    $tabItems['balance-issues'],
                    $tabItems['riwayat'],
                    $tabItems['withdraw'],
                ]),
            ],
            [
                'id' => 'communications',
                'label' => 'Komunikasi',
                'items' => [$tabItems['broadcast'], $tabItems['telegram-channel']],
            ],
            [
                'id' => 'insights-system',
                'label' => 'Insight & sistem',
                'items' => $this->visibleNavigationItems([
                    $tabItems['funnel'],
                    $this->navigationItem(
                        'payment-settings',
                        'Pengaturan pembayaran',
                        'settings',
                        route('admin.dashboard', ['tab' => 'stats']).'#admin-payment-settings',
                        visible: $canManagePaymentSettings,
                        active: false,
                    ),
                ]),
            ],
        ];

        $visibleTabs = array_values(array_filter($tabItems, static fn (array $item): bool => $item['visible']));
        $tabs = array_map(
            static fn (array $item): array => ['id' => $item['id'], 'label' => $item['label']],
            $visibleTabs,
        );
        $tabIds = array_column($tabs, 'id');
        $requestedTab = (string) ($requestedTab ?: 'stats');
        $activeTab = in_array($requestedTab, $tabIds, true) ? $requestedTab : 'stats';
        $groups = array_map(
            static function (array $group) use ($activeTab, $activeNavigationId): array {
                $group['items'] = array_map(
                    static function (array $item) use ($activeTab, $activeNavigationId): array {
                        if ($item['tab'] !== null) {
                            $item['href'] = route('admin.dashboard', [
                                ...Arr::except(request()->query(), ['product_form']),
                                'tab' => $item['tab'],
                            ]);
                        }

                        $item['active'] = $activeNavigationId !== null
                            ? $item['id'] === $activeNavigationId
                            : ($item['tab'] !== null && $item['tab'] === $activeTab);

                        return $item;
                    },
                    $group['items'],
                );

                return $group;
            },
            $groups,
        );

        return [
            'activeTab' => $activeTab,
            'tabs' => $tabs,
            'groups' => $groups,
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function navigationItem(
        string $id,
        string $label,
        string $icon,
        string $href,
        bool $visible = true,
        ?array $action = null,
        ?bool $active = null,
    ): array {
        return [
            'id' => $id,
            'tab' => in_array($id, ['stats', 'produk', 'penjualan', 'suppliers', 'broadcast', 'telegram-channel', 'riwayat', 'withdraw', 'funnel', 'balance-issues'], true) ? $id : null,
            'label' => $label,
            'shortLabel' => $label,
            'icon' => self::NAVIGATION_ICONS[$icon] ?? self::NAVIGATION_ICONS['home'],
            'active' => $active ?? false,
            'visible' => $visible,
            'href' => $href,
            ...($action === null ? [] : ['action' => $action]),
        ];
    }

    /** @param list<array<string, mixed>> $items */
    private function visibleNavigationItems(array $items): array
    {
        return array_values(array_filter($items, static fn (array $item): bool => $item['visible']));
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
        $channelFeaturesEnabled = (bool) $setting->sales_proof_enabled;

        $canManage = (bool) $user->role?->canManagePaymentSettings();

        return [
            'setting' => $setting,
            'welcome_banner' => $this->welcomeBannerTab($user),
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

    private function welcomeBannerTab(User $user): array
    {
        $setting = StorefrontSetting::current();
        $path = trim((string) $setting->telegram_welcome_banner_path);
        $isCustom = $path !== ''
            && str_starts_with($path, 'telegram/welcome-banners/')
            && ! str_contains($path, '..')
            && Storage::disk('public')->exists($path);

        return [
            'can_manage' => (bool) $user->role?->canManagePaymentSettings(),
            'is_custom' => $isCustom,
            'image_url' => $isCustom ? '/storage/'.ltrim($path, '/') : null,
            'version' => (int) ($setting->telegram_welcome_banner_version ?? 0),
            'file_id_cached' => $isCustom && filled($setting->telegram_welcome_banner_file_id),
            'update_url' => route('admin.telegram-welcome-banner.update'),
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
