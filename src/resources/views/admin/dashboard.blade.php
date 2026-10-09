<!DOCTYPE html>
<html lang="id" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <meta name="robots" content="noindex, nofollow, noarchive">
        <title>Admin LHIE STORE</title>
        @include('partials.theme-init')
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="admin-page admin-dashboard-page min-h-full font-sans antialiased">
        @php
            $storeLink = $statsTab['storeLink'];
            $summary = $statsTab['summary'];
            $storeStatus = $statsTab['storeStatus'];
            $actions = $statsTab['actions'];
            $statsCards = $statsTab['cards'] ?? [];
            $actionableStatusCounts = $statsTab['actionableStatusCounts'] ?? [];
            $earnings = $statsTab['earnings'] ?? [];
            $paymentControl = $statsTab['paymentControl'] ?? [];
            $fundingPolicy = $statsTab['fundingPolicy'] ?? [];
            $usdtFundingPolicy = $statsTab['usdtFundingPolicy'] ?? [];
            $balanceOperations = $statsTab['balanceOperations'] ?? [];
            $checkoutMaintenance = $statsTab['checkoutMaintenance'] ?? [];
            $canManageProducts = $productsTab['canManage'];
            $productPlaceholder = $productsTab['placeholder'] ?? [];
            $products = $productsTab['products'];
            $productGroups = $productsTab['productGroups'] ?? [];
            $familyOptions = $productsTab['familyOptions'] ?? [];
            $familyManagement = $productsTab['familyManagement'] ?? ['createUrl' => '', 'families' => []];
            $productsPagination = $productsTab['pagination'];
            $categoryOptions = $productsTab['categoryOptions'];
            $categoryManagement = $productsTab['categoryManagement'];
            $salesFilters = $salesTab['filters'];
            $orders = $salesTab['orders'];
            $salesPagination = $salesTab['pagination'];
            $storeChannel = $storeChannelTab;
            $historyTab = $historyTab ?? [];
            $withdrawTab = $withdrawTab ?? [];
            $funnelTab = $funnelTab ?? [];
            $balanceIssuesTab = $balanceIssuesTab ?? [];
            $canViewFunnel = (bool) ($funnelTab['can_view'] ?? false);
            $canViewWithdraw = (bool) ($withdrawTab['can_view'] ?? false);
            $canViewBalanceReports = (bool) ($statsTab['canViewBalanceReports'] ?? false);
            $canManageBalanceIssues = ($admin['role'] ?? '') === 'owner';
            $canManageBalanceAdjustments = $canManageBalanceIssues && (bool) ($balanceAdjustmentDashboardReady ?? false);
            $canManageSuppliers = in_array($admin['role'] ?? '', ['owner', 'admin'], true);
            $canViewBalanceIssues = ($navigation['activeTab'] ?? '') === 'balance-issues'
                && ($balanceIssuesTab['state'] ?? null) === 'ready';
            $structuredStockInputEnabled = (bool) ($dashboardState['structuredStockInputEnabled'] ?? false);
            $activeNavigationGroup = collect($navigation['groups'])
                ->first(fn (array $group) => collect($group['items'])->contains('active', true));
            $activeNavigationItem = collect($activeNavigationGroup['items'])->firstWhere('active', true);
        @endphp

        <x-admin-dashboard.shell
            data-return-target-highlight-class="admin-return-target-highlight"
            :x-data="'adminDashboard('.\Illuminate\Support\Js::from($dashboardState).')'"
            x-on:admin-dashboard:close-modal.window="handleCloseModal($event)"
        >
            <x-slot:sidebar>
                <div class="flex h-full min-h-0 flex-col border-r border-admin-line bg-admin-surface">
                    <a href="{{ route('admin.dashboard') }}" class="flex min-h-20 items-center px-6 text-base font-semibold text-admin-ink">LHIE STORE</a>
                    <x-admin-dashboard.navigation :groups="$navigation['groups']" enhance-tabs class="min-h-0 flex-1 overflow-y-auto px-3 pb-6" />
                    <div class="border-t border-admin-line px-6 py-4 text-xs text-admin-muted">
                        <p class="break-words font-medium text-admin-ink">{{ $admin['name'] }}</p>
                        <p>{{ ucfirst($admin['role']) }}</p>
                    </div>
                </div>
            </x-slot:sidebar>

            <x-slot:topbar>
                <x-admin-dashboard.topbar>
                    <x-slot:pageContext>
                        <p class="text-xs font-medium text-admin-muted">{{ $activeNavigationGroup['label'] }}</p>
                        <h1 class="text-lg font-semibold text-admin-ink sm:text-xl">{{ $activeNavigationItem['label'] }}</h1>
                    </x-slot:pageContext>
                    <x-slot:status>
                        <x-admin-dashboard.badge :tone="$storeStatus['tone']" dot>{{ $storeStatus['label'] }}</x-admin-dashboard.badge>
                        <x-admin-dashboard.badge :tone="$checkoutMaintenance['tone']" dot>{{ $checkoutMaintenance['status_badge']['label'] }}</x-admin-dashboard.badge>
                    </x-slot:status>
                    <x-slot:actions>
                        <x-admin-dashboard.button :href="$actions['shop']" variant="neutral" size="sm">Buka Toko</x-admin-dashboard.button>
                        <x-theme-toggle class="h-11 w-11 shrink-0 rounded-lg border border-admin-line bg-admin-surface text-admin-ink focus-visible:outline-2 focus-visible:outline-admin-focus" />
                    </x-slot:actions>
                    <x-slot:identity>
                        <p class="truncate font-medium text-admin-ink">{{ $admin['name'] }}</p>
                        <p>{{ ucfirst($admin['role']) }}</p>
                    </x-slot:identity>
                </x-admin-dashboard.topbar>
            </x-slot:topbar>

            <x-slot:status>
                @include('admin.dashboard.status-flash')
            </x-slot:status>

            <x-slot:content>
            @if ($navigation['activeTab'] === 'stats')
                <section class="grid grid-cols-2 gap-3 md:grid-cols-4" aria-label="Ringkasan toko">
                    @foreach ($summary as $metric)
                        <x-admin-dashboard.stat-card :label="$metric['label']" :value="$metric['value']" :hint="$metric['hint']" :tone="$metric['tone']" />
                    @endforeach
                </section>
            @endif
            @if ($navigation['activeTab'] === 'stats')
                @include('admin.dashboard.stats-tab')
            @elseif ($navigation['activeTab'] === 'produk')
                @include('admin.dashboard.products-tab')
                @include('admin.dashboard.product-modal')
                @include('admin.dashboard.product-family-modal')
                @include('admin.dashboard.category-management-modal')
            @elseif ($navigation['activeTab'] === 'penjualan')
                @include('admin.dashboard.sales-tab')
            @elseif ($navigation['activeTab'] === 'broadcast')
                @include('admin.dashboard.broadcast-tab')
            @elseif ($navigation['activeTab'] === 'telegram-channel')
                @include('admin.dashboard.store-channel-tab')
            @elseif ($navigation['activeTab'] === 'riwayat')
                @include('admin.dashboard.history-tab')
            @elseif ($navigation['activeTab'] === 'withdraw')
                @include('admin.dashboard.withdraw-tab')
            @elseif ($navigation['activeTab'] === 'funnel')
                @include('admin.dashboard.funnel-tab')
            @elseif ($navigation['activeTab'] === 'balance-issues')
                @include('admin.dashboard.balance-issues-tab')
            @elseif ($navigation['activeTab'] === 'suppliers')
                @include('admin.dashboard.suppliers-tab')
            @endif
            </x-slot:content>
            <x-slot:mobileDrawer>
                @include('admin.dashboard.mobile-navigation')
            </x-slot:mobileDrawer>
        </x-admin-dashboard.shell>
    </body>
</html>
