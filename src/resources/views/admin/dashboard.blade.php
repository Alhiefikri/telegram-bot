<!DOCTYPE html>
<html lang="id" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
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
            $storeStatus = $statsTab['storeStatus'];
            $actions = $statsTab['actions'];
            $overview = $statsTab['overview'] ?? [];
            $settings = $settingsTab ?? [];
            $settingsSection = $settings['section'] ?? 'pembayaran';
            $paymentControl = $settings['paymentControl'] ?? [];
            $fundingPolicy = $settings['fundingPolicy'] ?? [];
            $usdtFundingPolicy = $settings['usdtFundingPolicy'] ?? [];
            $balanceOperations = $settings['balanceOperations'] ?? [];
            $checkoutMaintenance = $settings['checkoutMaintenance'] ?? $statsTab['checkoutMaintenance'] ?? [];
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
            $salesInvoice = $salesTab['invoice'] ?? '';
            $salesInvoiceTooShort = (bool) ($salesTab['invoice_too_short'] ?? false);
            $salesActiveFilter = $salesTab['active_filter'] ?? 'all';
            $storeChannel = $storeChannelTab;
            $funnelTab = $funnelTab ?? [];
            $balanceIssuesTab = $balanceIssuesTab ?? [];
            $canViewFunnel = (bool) ($funnelTab['can_view'] ?? false);
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
                    <x-admin-dashboard.navigation :groups="$navigation['groups']" class="min-h-0 flex-1 overflow-y-auto px-3 pb-6" />
                    <div class="border-t border-admin-line px-6 py-4 text-xs text-admin-muted">
                        <p class="break-words font-medium text-admin-ink">{{ $admin['name'] }}</p>
                        <p>{{ ucfirst($admin['role']) }}</p>
                    </div>
                </div>
            </x-slot:sidebar>

            <x-slot:topbar>
                <x-admin-dashboard.topbar>
                    <x-slot:menu>
                        <button x-cloak x-ref="sidebarTrigger" type="button" aria-label="Buka navigasi admin" aria-haspopup="dialog" aria-controls="admin-dashboard-drawer" aria-expanded="false" x-bind:aria-expanded="sidebarOpen" x-on:click="openSidebar($event.currentTarget)" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-admin-line bg-admin-surface text-admin-ink focus-visible:outline-2 focus-visible:outline-admin-focus lg:hidden">
                            <svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
                        </button>
                    </x-slot:menu>
                    <x-slot:pageContext>
                        <p class="truncate text-xs font-medium text-admin-muted">{{ $activeNavigationGroup['label'] }}</p>
                        <h1 class="truncate text-lg font-semibold text-admin-ink sm:text-xl">{{ $activeNavigationItem['label'] }}</h1>
                    </x-slot:pageContext>
                    <x-slot:status>
                        <x-admin-dashboard.badge :tone="$storeStatus['tone']" dot>{{ $storeStatus['label'] }}</x-admin-dashboard.badge>
                        <x-admin-dashboard.badge :tone="$checkoutMaintenance['tone']" dot>{{ $checkoutMaintenance['status_badge']['label'] }}</x-admin-dashboard.badge>
                    </x-slot:status>
                    <x-slot:actions>
                        <x-admin-dashboard.button :href="$actions['shop']" variant="neutral" size="sm" class="min-w-0 truncate">Buka Toko</x-admin-dashboard.button>
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
            @elseif ($navigation['activeTab'] === 'settings')
                @include('admin.dashboard.settings-tab')
            @elseif ($navigation['activeTab'] === 'funnel')
                @include('admin.dashboard.funnel-tab')
            @elseif ($navigation['activeTab'] === 'balance-issues')
                @include('admin.dashboard.balance-issues-tab')
            @elseif ($navigation['activeTab'] === 'suppliers')
                @include('admin.dashboard.suppliers-tab')
            @endif
            </x-slot:content>
            <x-slot:mobileDrawer>
                <details x-show="!navigationReady" class="border-b border-admin-line bg-admin-surface px-4 py-3 lg:hidden">
                    <summary class="min-h-11 cursor-pointer py-3 text-sm font-medium text-admin-ink">Navigasi dashboard</summary>
                    <x-admin-dashboard.navigation :groups="$navigation['groups']" id-prefix="admin-fallback-navigation" label="Navigasi dashboard" />
                    @if ($canManageProducts)
                        <a href="{{ route('admin.dashboard', [...request()->query(), 'tab' => 'produk', 'product_form' => 'create']) }}" x-on:click="handleSidebarNavigation($event)" class="flex min-h-11 items-center px-3 text-sm font-medium text-admin-accent-strong">Tambah Produk</a>
                    @endif
                </details>

                <x-admin-dashboard.mobile-drawer label="Navigasi admin">
                    <x-slot:close>
                        <button type="button" x-on:click="closeSidebar()" aria-label="Tutup navigasi" class="flex h-11 w-11 items-center justify-center rounded-lg text-xl text-admin-ink focus-visible:outline-2 focus-visible:outline-admin-focus">×</button>
                    </x-slot:close>
                    <x-admin-dashboard.navigation :groups="$navigation['groups']" id-prefix="admin-drawer-navigation" label="Navigasi dashboard" />
                    @if ($canManageProducts)
                        <a href="{{ route('admin.dashboard', [...request()->query(), 'tab' => 'produk', 'product_form' => 'create']) }}" x-on:click.prevent="goToTab('produk', true)" class="mt-6 flex min-h-11 items-center rounded-lg bg-admin-accent px-3 text-sm font-medium text-admin-accent-ink focus-visible:outline-2 focus-visible:outline-admin-focus">Tambah Produk</a>
                    @endif
                </x-admin-dashboard.mobile-drawer>
            </x-slot:mobileDrawer>
        </x-admin-dashboard.shell>
    </body>
</html>
