<!DOCTYPE html>
<html lang="id" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Admin LHIE STORE</title>
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
            $canManageProducts = $productsTab['canManage'];
            $products = $productsTab['products'];
            $productsPagination = $productsTab['pagination'];
            $categoryOptions = $productsTab['categoryOptions'];
            $salesFilters = $salesTab['filters'];
            $orders = $salesTab['orders'];
            $salesPagination = $salesTab['pagination'];
            $storeChannel = $storeChannelTab;
        @endphp

        <main
            class="admin-mini-shell flex min-h-screen flex-col px-3 pb-32 pt-3 sm:px-4 md:px-0 md:pb-10 md:pt-6"
            data-return-target-highlight-class="admin-return-target-highlight"
            x-data="adminDashboard(@js($dashboardState))"
            x-on:admin-dashboard:close-modal.window="handleCloseModal($event)"
        >
            @if ($navigation['activeTab'] === 'stats')
                @include('admin.dashboard.header')
            @else
                @include('admin.dashboard.compact-header')
            @endif
            @include('admin.dashboard.desktop-navigation')
            @include('admin.dashboard.status-flash')

            @if ($navigation['activeTab'] === 'stats')
                @include('admin.dashboard.stats-tab')
            @elseif ($navigation['activeTab'] === 'produk')
                @include('admin.dashboard.products-tab')
                @include('admin.dashboard.product-modal')
            @elseif ($navigation['activeTab'] === 'penjualan')
                @include('admin.dashboard.sales-tab')
            @elseif ($navigation['activeTab'] === 'broadcast')
                @include('admin.dashboard.broadcast-tab')
            @elseif ($navigation['activeTab'] === 'telegram-channel')
                @include('admin.dashboard.store-channel-tab')
            @endif
            @include('admin.dashboard.mobile-navigation')
        </main>
    </body>
</html>
