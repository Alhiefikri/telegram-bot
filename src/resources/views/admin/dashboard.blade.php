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
        <style>
            [x-cloak] { display: none !important; }

            body {
                background-color: #fff6cf;
                background-image:
                    radial-gradient(circle at top left, rgba(123, 209, 10, 0.26), transparent 26%),
                    radial-gradient(circle at top right, rgba(219, 63, 152, 0.18), transparent 22%),
                    radial-gradient(circle at bottom center, rgba(22, 180, 202, 0.16), transparent 24%),
                    repeating-linear-gradient(135deg, transparent, transparent 22px, rgba(5,5,5,0.03) 22px, rgba(5,5,5,0.03) 24px);
                min-height: 100vh;
                overflow-x: hidden;
            }

            .header-gradient {
                background: linear-gradient(135deg, #7BD10A 0%, #f2da3d 48%, #16b4ca 100%);
            }

            .desktop-header {
                background: linear-gradient(135deg, #ff4fa8 0%, #ff7cc3 100%);
            }

            .store-link-section {
                background: linear-gradient(135deg, #8758de 0%, #db3f98 100%);
            }

            .stat-card-green { background: #e8ffd4; border-color: #050505; }
            .stat-card-cyan { background: #d4f6fb; border-color: #050505; }
            .stat-card-yellow { background: #fef9c3; border-color: #050505; }
            .stat-card-purple { background: #ede5fa; border-color: #050505; }
            .stat-card-pink { background: #fce4f0; border-color: #050505; }

            .bottom-nav {
                background: rgba(5, 5, 5, 0.92);
                backdrop-filter: blur(14px);
            }

            .desktop-tab-nav {
                display: none;
            }

            .mobile-bottom-nav {
                display: block;
            }

            .admin-mini-shell {
                width: 100%;
                max-width: min(430px, calc(100vw - 24px));
                margin-inline: auto;
            }

            @media (min-width: 768px) {
                body {
                    background-color: #fff4bd;
                    background-image:
                        radial-gradient(circle at top left, rgba(255, 79, 168, 0.18), transparent 28%),
                        radial-gradient(circle at top right, rgba(123, 209, 10, 0.16), transparent 22%),
                        radial-gradient(circle at bottom center, rgba(22, 180, 202, 0.12), transparent 24%);
                }

                .desktop-tab-nav {
                    display: grid;
                    grid-template-columns: repeat(4, minmax(0, 1fr));
                    gap: 0.5rem;
                }

                .mobile-bottom-nav {
                    display: none;
                }

                .admin-mini-shell {
                    max-width: min(820px, calc(100vw - 48px));
                }
            }

            .bottom-nav-active {
                background: #f2da3d;
                border-radius: 999px;
                color: #050505;
                box-shadow: 4px 4px 0px #050505;
            }

            .neo-input {
                background: #ffffff;
                border: 3px solid #050505;
                border-radius: 10px;
                padding: 12px 14px;
                font-family: 'Plus Jakarta Sans', sans-serif;
                font-weight: 700;
                font-size: 14px;
                color: #050505;
                width: 100%;
                box-shadow: 3px 3px 0px #050505;
                transition: box-shadow 0.2s, border-color 0.2s, transform 0.15s;
            }
            .neo-input:focus {
                outline: none;
                border-color: #8758de;
                box-shadow: 0 0 0 4px rgba(135,88,222,0.18), 3px 3px 0px #050505;
            }
            .neo-input::placeholder {
                color: #9ca3af;
                font-weight: 600;
            }

            .neo-select {
                background: #ffffff;
                border: 3px solid #050505;
                border-radius: 10px;
                padding: 12px 14px;
                font-family: 'Plus Jakarta Sans', sans-serif;
                font-weight: 700;
                font-size: 14px;
                color: #050505;
                width: 100%;
                box-shadow: 3px 3px 0px #050505;
                appearance: none;
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%23050505' viewBox='0 0 16 16'%3E%3Cpath d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E");
                background-repeat: no-repeat;
                background-position: right 14px center;
                padding-right: 36px;
            }
            .neo-select:focus {
                outline: none;
                border-color: #8758de;
                box-shadow: 0 0 0 4px rgba(135,88,222,0.18), 3px 3px 0px #050505;
            }

            .neo-textarea {
                background: #ffffff;
                border: 3px solid #050505;
                border-radius: 10px;
                padding: 12px 14px;
                font-family: 'Plus Jakarta Sans', sans-serif;
                font-weight: 700;
                font-size: 14px;
                color: #050505;
                width: 100%;
                resize: vertical;
                box-shadow: 3px 3px 0px #050505;
                transition: box-shadow 0.2s, border-color 0.2s;
            }
            .neo-textarea:focus {
                outline: none;
                border-color: #8758de;
                box-shadow: 0 0 0 4px rgba(135,88,222,0.18), 3px 3px 0px #050505;
            }
            .neo-textarea::placeholder {
                color: #9ca3af;
                font-weight: 600;
            }

            .neo-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                min-height: 48px;
                padding: 10px 18px;
                border: 3px solid #050505;
                border-radius: 16px;
                font-family: 'Space Grotesk', sans-serif;
                font-weight: 800;
                font-size: 12px;
                text-transform: uppercase;
                letter-spacing: 0.03em;
                cursor: pointer;
                transition: transform 0.15s, box-shadow 0.15s;
                box-shadow: 4px 4px 0px #050505;
                text-decoration: none;
                text-align: center;
                line-height: 1.2;
            }
            .neo-btn:hover {
                transform: translate(-1px, -1px);
                box-shadow: 5px 5px 0px #050505;
            }
            .neo-btn:active {
                transform: translate(1px, 1px);
                box-shadow: 1px 1px 0px #050505;
            }

            .neo-btn-green { background: #7BD10A; color: #050505; }
            .neo-btn-cyan { background: #16b4ca; color: #fff; }
            .neo-btn-yellow { background: #f2da3d; color: #050505; }
            .neo-btn-purple { background: #8758de; color: #fff; }
            .neo-btn-pink { background: #db3f98; color: #fff; }
            .neo-btn-white { background: #fff; color: #050505; }
            .neo-btn-red { background: #ef4444; color: #fff; }
            .neo-btn-gray { background: #e2e8f0; color: #64748b; }
            .neo-btn-ink { background: #050505; color: #fff; }

            .neo-card {
                background: #fff;
                border: 3px solid #050505;
                border-radius: 18px;
                padding: 18px;
                box-shadow: 5px 5px 0px #050505;
            }

            .product-action-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-height: 44px;
                padding: 8px 12px;
                border: 3px solid #050505;
                border-radius: 14px;
                font-family: 'Space Grotesk', sans-serif;
                font-weight: 800;
                font-size: 11px;
                text-transform: uppercase;
                cursor: pointer;
                transition: transform 0.12s, box-shadow 0.12s;
                box-shadow: 3px 3px 0px #050505;
                text-decoration: none;
                text-align: center;
            }
            .product-action-btn:hover {
                transform: translate(-1px, -1px);
                box-shadow: 4px 4px 0px #050505;
            }
            .product-action-btn:active {
                transform: translate(1px, 1px);
                box-shadow: 0px 0px 0px #050505;
            }

            .filter-pill {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 9px 14px;
                border: 3px solid #050505;
                border-radius: 999px;
                font-family: 'Space Grotesk', sans-serif;
                font-weight: 800;
                font-size: 11px;
                text-transform: uppercase;
                cursor: pointer;
                transition: background-color 0.15s, color 0.15s, transform 0.15s;
                background: #fff;
                color: #050505;
                box-shadow: 3px 3px 0px #050505;
            }
            .filter-pill-active {
                background: #f2da3d;
                color: #050505;
                transform: translateY(-1px);
            }
            .filter-pill-count {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-width: 20px;
                height: 20px;
                padding: 0 6px;
                border-radius: 50px;
                font-size: 10px;
                font-weight: 700;
                background: rgba(0,0,0,0.1);
                color: inherit;
            }
            .filter-pill-active .filter-pill-count {
                background: rgba(255,255,255,0.2);
            }

            .order-card {
                background: #fff;
                border: 3px solid #050505;
                border-radius: 18px;
                padding: 16px;
                box-shadow: 4px 4px 0px #050505;
            }

            .earnings-bar-track {
                height: 12px;
                background: #f1f5f9;
                border: 3px solid #050505;
                border-radius: 6px;
                overflow: hidden;
            }
            .earnings-bar-fill {
                height: 100%;
                background: linear-gradient(90deg, #8758de, #db3f98);
                border-radius: 4px;
            }

            .status-order-card {
                border: 3px solid #050505;
                border-radius: 16px;
                padding: 12px;
                box-shadow: 3px 3px 0px #050505;
            }

            .neo-badge {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                padding: 3px 10px;
                border: 3px solid #050505;
                border-radius: 50px;
                font-family: 'Space Grotesk', sans-serif;
                font-weight: 800;
                font-size: 10px;
                text-transform: uppercase;
                letter-spacing: 0.04em;
                box-shadow: 2px 2px 0px #050505;
            }

            .payment-section {
                background: #ede5fa;
                border: 3px solid #050505;
                border-radius: 18px;
                padding: 16px;
                box-shadow: 4px 4px 0px #050505;
            }

            @keyframes slideUp {
                from { opacity: 0; transform: translateY(12px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .animate-slide-up {
                animation: slideUp 0.4s cubic-bezier(0.22, 1, 0.36, 1) both;
            }
        </style>
    </head>
    <body class="min-h-full font-sans antialiased" style="font-family: 'Plus Jakarta Sans', sans-serif;">
        @php
            $storeLink = $statsTab['storeLink'];
            $summary = $statsTab['summary'];
            $storeStatus = $statsTab['storeStatus'];
            $actions = $statsTab['actions'];
            $statsCards = $statsTab['cards'];
            $actionableStatusCounts = $statsTab['actionableStatusCounts'];
            $earnings = $statsTab['earnings'];
            $paymentControl = $statsTab['paymentControl'];
            $canManageProducts = $productsTab['canManage'];
            $products = $productsTab['products'];
            $categoryOptions = $productsTab['categoryOptions'];
            $salesFilters = $salesTab['filters'];
            $orders = $salesTab['orders'];
            $stockModalProduct = collect($products)->firstWhere('id', (int) old('stock_product_id'));
            $editingProduct = collect($products)->firstWhere('id', (int) old('product_id'));
            $hasProductErrors = $errors->productCreate->any() || $errors->productUpdate->any();
            $hasProductUpdateErrors = $errors->productUpdate->any();
            $hasProductTabErrors = $hasProductErrors
                || $errors->stockAdjustment->any()
                || $errors->categoryCreate->any()
                || $errors->stockItemUpdate->any();
            $productStoreUrl = $productsTab['productStoreUrl'];
            $dashboardState = [
                'activeTab' => $hasProductTabErrors ? 'produk' : $navigation['activeTab'],
                'storeLink' => $storeLink['url'],
                'productStoreUrl' => $productStoreUrl,
                'productModalOpen' => $hasProductErrors,
                'productForm' => [
                    'productModalMode' => $hasProductUpdateErrors ? 'edit' : 'create',
                    'productFormAction' => $hasProductUpdateErrors ? ($editingProduct['update_url'] ?? $productStoreUrl) : $productStoreUrl,
                    'productFormMethod' => $hasProductUpdateErrors ? 'PATCH' : '',
                    'productId' => old('product_id', $editingProduct['id'] ?? null),
                    'productName' => old('name', $editingProduct['name'] ?? ''),
                    'productPrice' => old('price_amount', $editingProduct['price_amount'] ?? ''),
                    'productCategoryId' => old('category_id', $editingProduct['category_id'] ?? ''),
                    'productDescription' => old('description', $editingProduct['description'] ?? ''),
                    'productFeatures' => old('features', $editingProduct['features'] ?? ''),
                    'productSpecifications' => old('specifications', $editingProduct['specifications'] ?? ''),
                    'productIsActive' => (bool) old('is_active', $editingProduct['is_active'] ?? true),
                    'productFeaturesOpen' => $errors->productCreate->has('features')
                        || $errors->productUpdate->has('features')
                        || trim((string) old('features', $editingProduct['features'] ?? '')) !== '',
                    'productSpecificationsOpen' => $errors->productCreate->has('specifications')
                        || $errors->productUpdate->has('specifications')
                        || trim((string) old('specifications', $editingProduct['specifications'] ?? '')) !== '',
                ],
                'stockModalOpen' => $errors->stockAdjustment->any(),
                'stockForm' => [
                    'stockFormAction' => $stockModalProduct['stock_url'] ?? '',
                    'stockProductId' => $stockModalProduct['id'] ?? null,
                    'stockProductName' => $stockModalProduct['name'] ?? '',
                    'stockProductStock' => $stockModalProduct['stock'] ?? 0,
                    'stockItems' => old('stock_items', ''),
                ],
            ];
        @endphp

        <main
            class="admin-mini-shell flex min-h-screen flex-col px-3 pb-32 pt-3 sm:px-4 md:px-0 md:pb-10 md:pt-6"
            x-data="adminDashboard(@js($dashboardState))"
            x-on:admin-dashboard:close-modal.window="handleCloseModal($event)"
        >
            @include('admin.dashboard.header')
            @include('admin.dashboard.desktop-navigation')
            @include('admin.dashboard.status-flash')

            {{-- ═══════════════════════════════════════════════════════
                 TAB CONTENT AREA
                 ═══════════════════════════════════════════════════════ --}}
            @include('admin.dashboard.stats-tab')
            @include('admin.dashboard.products-tab')
            @include('admin.dashboard.sales-tab')
            @include('admin.dashboard.product-modal')
            @include('admin.dashboard.broadcast-tab')
            @include('admin.dashboard.mobile-navigation')
        </main>
    </body>
</html>
