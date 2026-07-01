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
            $stockModalProduct = collect($products)->firstWhere('id', (int) old('stock_product_id'));
            $editingProduct = collect($products)->firstWhere('id', (int) old('product_id'));
        @endphp

        <main
            class="admin-mini-shell flex min-h-screen flex-col px-3 pb-32 pt-3 sm:px-4 md:px-0 md:pb-10 md:pt-6"
            x-data="{
                activeTab: @js(($errors->productCreate->any() || $errors->productUpdate->any() || $errors->stockAdjustment->any() || $errors->categoryCreate->any() || $errors->stockItemUpdate->any()) ? 'produk' : ($activeTab ?? 'stats')),
                copied: false,
                storeLink: @js($storeLink['url']),
                copyState: 'Salin',
                productModalOpen: @js($errors->productCreate->any() || $errors->productUpdate->any()),
                productModalMode: @js($errors->productUpdate->any() ? 'edit' : 'create'),
                productFormAction: @js($errors->productUpdate->any() ? ($editingProduct['update_url'] ?? route('admin.products.store')) : route('admin.products.store')),
                productFormMethod: @js($errors->productUpdate->any() ? 'PATCH' : ''),
                productId: @js(old('product_id', $editingProduct['id'] ?? null)),
                productName: @js(old('name', $editingProduct['name'] ?? '')),
                productPrice: @js(old('price_amount', $editingProduct['price_amount'] ?? '')),
                productCategoryId: @js(old('category_id', $editingProduct['category_id'] ?? '')),
                productDescription: @js(old('description', $editingProduct['description'] ?? '')),
                productFeatures: @js(old('features', $editingProduct['features'] ?? '')),
                productSpecifications: @js(old('specifications', $editingProduct['specifications'] ?? '')),
                productIsActive: @js((bool) old('is_active', $editingProduct['is_active'] ?? true)),
                productFeaturesOpen: @js($errors->productCreate->has('features') || $errors->productUpdate->has('features') || trim((string) old('features', $editingProduct['features'] ?? '')) !== ''),
                productSpecificationsOpen: @js($errors->productCreate->has('specifications') || $errors->productUpdate->has('specifications') || trim((string) old('specifications', $editingProduct['specifications'] ?? '')) !== ''),
                stockModalOpen: @js($errors->stockAdjustment->any()),
                stockFormAction: @js($stockModalProduct['stock_url'] ?? ''),
                stockProductId: @js($stockModalProduct['id'] ?? null),
                stockProductName: @js($stockModalProduct['name'] ?? ''),
                stockProductStock: @js($stockModalProduct['stock'] ?? 0),
                stockItems: @js(old('stock_items', '')),
                manageStockModalOpen: false,
                manageStockProductName: '',
                manageStockProductStock: 0,
                manageStockItems: [],
                manageStockAvailableCount: 0,
                manageStockTruncated: false,
                manageStockAddPayload: null,
                stockItemCount() {
                    return this.stockItems
                        .split(/\r?\n/)
                        .map((row) => row.trim())
                        .filter(Boolean)
                        .length;
                },
                openProductForm() {
                    this.activeTab = 'produk';
                    this.productModalMode = 'create';
                    this.productFormAction = @js(route('admin.products.store'));
                    this.productFormMethod = '';
                    this.productId = null;
                    this.productName = '';
                    this.productPrice = '';
                    this.productCategoryId = '';
                    this.productDescription = '';
                    this.productFeatures = '';
                    this.productSpecifications = '';
                    this.productIsActive = true;
                    this.productFeaturesOpen = false;
                    this.productSpecificationsOpen = false;
                    this.productModalOpen = true;
                    this.$nextTick(() => this.$refs.productName?.focus());
                },
                openProductEditModal(product) {
                    this.activeTab = 'produk';
                    this.productModalMode = 'edit';
                    this.productFormAction = product.updateUrl;
                    this.productFormMethod = 'PATCH';
                    this.productId = product.id;
                    this.productName = product.name;
                    this.productPrice = product.priceAmount;
                    this.productCategoryId = product.categoryId ?? '';
                    this.productDescription = product.description;
                    this.productFeatures = product.features;
                    this.productSpecifications = product.specifications;
                    this.productIsActive = product.isActive;
                    this.productFeaturesOpen = String(product.features || '').trim().length > 0;
                    this.productSpecificationsOpen = String(product.specifications || '').trim().length > 0;
                    this.productModalOpen = true;
                    this.$nextTick(() => this.$refs.productName?.focus());
                },
                closeProductModal() {
                    this.productModalOpen = false;
                },
                openManageStockModal(product) {
                    this.activeTab = 'produk';
                    this.manageStockProductName = product.name;
                    this.manageStockProductStock = product.stock;
                    this.manageStockItems = (product.stockItems || []).map((item) => ({ ...item, editing: false }));
                    this.manageStockAvailableCount = product.availableStockItemCount || 0;
                    this.manageStockTruncated = Boolean(product.stockItemsTruncated);
                    this.manageStockAddPayload = {
                        id: product.id,
                        name: product.name,
                        stock: product.stock,
                        stockUrl: product.stockUrl,
                    };
                    this.manageStockModalOpen = true;
                },
                closeManageStockModal() {
                    this.manageStockModalOpen = false;
                    this.manageStockItems = [];
                    this.manageStockAddPayload = null;
                },
                openStockModalFromManage() {
                    if (this.manageStockAddPayload) {
                        const product = this.manageStockAddPayload;
                        this.closeManageStockModal();
                        this.openStockModal(product);
                    }
                },
                openStockModal(product) {
                    this.activeTab = 'produk';
                    this.stockFormAction = product.stockUrl;
                    this.stockProductId = product.id;
                    this.stockProductName = product.name;
                    this.stockProductStock = product.stock;
                    this.stockItems = '';
                    this.stockModalOpen = true;
                    this.$nextTick(() => this.$refs.stockItems?.focus());
                },
                closeStockModal() {
                    this.stockModalOpen = false;
                    this.stockItems = '';
                },
                markStoreLinkCopied() {
                    this.copied = true;
                    this.copyState = 'Tersalin';
                    setTimeout(() => {
                        this.copied = false;
                        this.copyState = 'Salin';
                    }, 1600);
                },
                async copyStoreLink() {
                    try {
                        if (! navigator.clipboard?.writeText) {
                            throw new Error('clipboard_unavailable');
                        }

                        await navigator.clipboard.writeText(this.storeLink);
                        this.markStoreLinkCopied();
                    } catch (error) {
                        window.prompt('Salin link toko', this.storeLink);
                    }
                },
                async shareStore() {
                    if (navigator.share) {
                        try {
                            await navigator.share({ title: 'LHIE STORE', url: this.storeLink });
                            return;
                        } catch (error) {
                            if (error?.name === 'AbortError') {
                                return;
                            }
                        }
                    }

                    await this.copyStoreLink();
                },
            }"
            x-on:admin-dashboard:close-modal.window="if ($event.detail?.modal === 'stock-modal') closeStockModal(); if ($event.detail?.modal === 'product-modal') closeProductModal(); if ($event.detail?.modal === 'manage-stock-modal') closeManageStockModal()"
        >
            {{-- ═══════════════════════════════════════════════════════
                 HEADER CARD — Purple/Magenta Gradient
                 ═══════════════════════════════════════════════════════ --}}
            <header class="header-gradient desktop-header relative overflow-hidden rounded-[1.5rem] p-4 text-neo-ink md:rounded-[2rem] md:p-6" style="border: 3px solid #050505; box-shadow: 5px 5px 0px #050505;">
                {{-- Diagonal stripe decoration --}}
                <div class="pointer-events-none absolute -right-6 top-0 h-36 w-36 opacity-20" style="background: radial-gradient(circle at center, rgba(255,255,255,0.85) 0, rgba(255,255,255,0.15) 42%, transparent 70%);"></div>

                {{-- Top chip row --}}
                <div class="relative z-10 flex flex-wrap items-center justify-between gap-2">
                    <span class="neo-badge" style="background: rgba(255,255,255,0.55); color: #050505;">
                        <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                        Telegram Mini App
                    </span>
                    <span class="neo-badge" style="background: rgba(255,255,255,0.55); color: #050505;">
                        {{ $storeStatus['label'] }}
                    </span>
                </div>

                {{-- Name --}}
                <h1 class="relative z-10 mt-4 max-w-[12ch] font-display text-[2rem] font-black leading-[0.95] tracking-tight text-neo-ink sm:text-[2.4rem]">
                    Halo, {{ $admin['name'] }}
                </h1>

                {{-- Role / tier info --}}
                <p class="relative z-10 mt-2 max-w-[22ch] text-xs font-bold leading-5 text-neo-ink/80">
                    {{ strtoupper($admin['role']) }} · {{ $storeStatus['hint'] }}
                </p>

                {{-- 2x2 stat cards grid --}}
                <section class="relative z-10 mt-4 grid grid-cols-2 gap-2 md:grid-cols-4 md:gap-3" aria-label="Ringkasan toko">
                    @foreach ($summary as $metric)
                        @php
                            $cardStyle = match($metric['tone']) {
                                'emerald' => 'stat-card-green',
                                'blue' => 'stat-card-cyan',
                                'amber' => 'stat-card-yellow',
                                default => 'stat-card-purple',
                            };
                        @endphp
                        <div class="{{ $cardStyle }} rounded-[1rem] p-3 md:p-4" style="border: 3px solid #050505; box-shadow: 3px 3px 0px #050505;">
                            <p class="font-display text-[10px] font-black uppercase tracking-[0.18em]" style="color: #050505;">{{ $metric['label'] }}</p>
                            <p class="mt-1 break-words font-display text-lg font-black leading-none" style="color: #050505;">{{ $metric['value'] }}</p>
                            @if ($metric['hint'])
                                <p class="mt-1 text-[10px] font-semibold opacity-70" style="color: #050505;">{{ $metric['hint'] }}</p>
                            @endif
                        </div>
                    @endforeach
                </section>

                {{-- Action buttons --}}
                <div class="relative z-10 mt-4 grid grid-cols-2 gap-2 md:mt-5 md:gap-3">
                    <a
                        href="{{ $actions['shop'] }}"
                        class="neo-btn neo-btn-white text-[11px] md:min-h-14 md:text-sm"
                        style="box-shadow: 3px 3px 0px rgba(0,0,0,0.35);"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M4.5 7.5h15l-.75 12.75H5.25L4.5 7.5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                            <path d="M8 7.5a4 4 0 0 1 8 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                        </svg>
                        Buka Toko
                    </a>

                    <button
                        type="button"
                        class="neo-btn neo-btn-yellow text-[11px] md:min-h-14 md:text-sm"
                        style="box-shadow: 3px 3px 0px rgba(0,0,0,0.35);"
                        x-on:click="shareStore()"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M8.5 12.5 15.5 16M15.5 8 8.5 11.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                            <circle cx="6" cy="12.5" r="2.5" stroke="currentColor" stroke-width="1.8"/>
                            <circle cx="18" cy="7" r="2.5" stroke="currentColor" stroke-width="1.8"/>
                            <circle cx="18" cy="17" r="2.5" stroke="currentColor" stroke-width="1.8"/>
                        </svg>
                        <span x-text="copied ? 'Tersalin' : 'Bagikan Toko'">Bagikan Toko</span>
                    </button>
                </div>
            </header>

            <nav class="desktop-tab-nav mt-4" aria-label="Navigasi desktop">
                <button
                    type="button"
                    x-on:click="activeTab = 'stats'"
                    class="flex items-center justify-center gap-2 rounded-[1rem] border-[3px] border-neo-ink bg-white px-3 py-3 text-neo-ink shadow-[4px_4px_0_0_#050505] transition-all duration-200 hover:-translate-y-0.5"
                    :class="activeTab === 'stats' ? 'bottom-nav-active' : ''"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                    </svg>
                    <span class="text-[11px] font-black uppercase tracking-wide">Ringkasan</span>
                </button>

                <button
                    type="button"
                    x-on:click="activeTab = 'produk'"
                    class="flex items-center justify-center gap-2 rounded-[1rem] border-[3px] border-neo-ink bg-white px-3 py-3 text-neo-ink shadow-[4px_4px_0_0_#050505] transition-all duration-200 hover:-translate-y-0.5"
                    :class="activeTab === 'produk' ? 'bottom-nav-active' : ''"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                    </svg>
                    <span class="text-[11px] font-black uppercase tracking-wide">Produk</span>
                </button>

                <button
                    type="button"
                    x-on:click="activeTab = 'penjualan'"
                    class="flex items-center justify-center gap-2 rounded-[1rem] border-[3px] border-neo-ink bg-white px-3 py-3 text-neo-ink shadow-[4px_4px_0_0_#050505] transition-all duration-200 hover:-translate-y-0.5"
                    :class="activeTab === 'penjualan' ? 'bottom-nav-active' : ''"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    <span class="text-[11px] font-black uppercase tracking-wide">Pesanan</span>
                </button>

                @if ($canManageProducts)
                <button
                    type="button"
                    x-on:click="openProductForm()"
                    class="flex items-center justify-center gap-2 rounded-[1rem] border-[3px] border-neo-ink bg-white px-3 py-3 text-neo-ink shadow-[4px_4px_0_0_#050505] transition-all duration-200 hover:-translate-y-0.5"
                    :class="productModalOpen && productModalMode === 'create' ? 'bottom-nav-active' : ''"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span class="text-[11px] font-black uppercase tracking-wide">Tambah</span>
                </button>
                @endif
            </nav>

            {{-- ═══════════════════════════════════════════════════════
                 STATUS MESSAGE
                 ═══════════════════════════════════════════════════════ --}}
            @if (session('status'))
                <div class="mt-4 animate-slide-up rounded-xl p-4 text-sm font-bold" style="background: #e8ffd4; border: 2px solid #050505; box-shadow: 3px 3px 0px #050505; color: #050505;">
                    {{ session('status') }}
                </div>
            @endif

            {{-- ═══════════════════════════════════════════════════════
                 TAB CONTENT AREA
                 ═══════════════════════════════════════════════════════ --}}

            {{-- ╔═════════════════════════════════════════════════════╗
                 ║  STATS TAB (Ringkasan)                              ║
                 ╚═════════════════════════════════════════════════════╝ --}}
            <div x-show="activeTab === 'stats'" class="mt-4 space-y-4">

                {{-- Link Toko section --}}
                <section class="store-link-section overflow-hidden rounded-[1.5rem] p-4 text-neo-ink" style="border: 3px solid #050505; box-shadow: 4px 4px 0px #050505;" aria-label="Link toko">
                    <p class="font-display text-xs font-black uppercase tracking-[0.18em] text-neo-ink/80">{{ $storeLink['label'] }}</p>
                    <div class="mt-2 overflow-hidden rounded-[1rem] bg-white px-3 py-2.5" style="border: 3px solid #050505;">
                        <p class="break-all text-sm font-bold leading-6" style="color: #050505;">{{ $storeLink['url'] }}</p>
                    </div>
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <button
                            type="button"
                            class="neo-btn neo-btn-white text-[11px]"
                            style="box-shadow: 2px 2px 0px rgba(0,0,0,0.35);"
                            x-on:click="shareStore()"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none"><path d="M8.5 12.5 15.5 16M15.5 8 8.5 11.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="6" cy="12.5" r="2.5" stroke="currentColor" stroke-width="2"/><circle cx="18" cy="7" r="2.5" stroke="currentColor" stroke-width="2"/><circle cx="18" cy="17" r="2.5" stroke="currentColor" stroke-width="2"/></svg>
                            Bagikan
                        </button>
                        <button
                            type="button"
                            class="neo-btn neo-btn-ink text-[11px]"
                            style="box-shadow: 2px 2px 0px rgba(0,0,0,0.35);"
                            x-on:click="copyStoreLink()"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none"><rect x="9" y="9" width="13" height="13" rx="2" stroke="currentColor" stroke-width="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1" stroke="currentColor" stroke-width="2"/></svg>
                            <span x-text="copyState">Salin</span>
                        </button>
                    </div>
                </section>

                {{-- STATISTIK heading --}}
                <div>
                    <h2 class="font-display text-lg font-black uppercase tracking-[0.18em]" style="color: #050505;">📊 Ringkasan</h2>
                    <p class="mt-1 text-xs font-semibold" style="color: #64748b;">Gambaran cepat operasional hari ini</p>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    {{-- Stats cards grid --}}
                    <section class="grid grid-cols-2 gap-3" aria-label="Kartu Stats">
                        @foreach ($statsCards as $metric)
                            @php
                                $statStyle = match($metric['tone']) {
                                    'emerald' => 'stat-card-green',
                                    'blue' => 'stat-card-cyan',
                                    'amber' => 'stat-card-yellow',
                                    default => 'stat-card-purple',
                                };
                            @endphp
                            <div class="{{ $statStyle }} rounded-xl p-3" style="border: 2px solid #050505; box-shadow: 2px 2px 0px #050505;">
                                <p class="font-display text-[10px] font-bold uppercase tracking-wider" style="color: #050505;">{{ $metric['label'] }}</p>
                                <p class="mt-1 break-words font-display text-xl font-bold" style="color: #050505;">{{ $metric['value'] }}</p>
                                @if ($metric['hint'])
                                    <p class="mt-0.5 text-[10px] font-semibold opacity-60" style="color: #050505;">{{ $metric['hint'] }}</p>
                                @endif
                            </div>
                        @endforeach
                    </section>

                    {{-- STATUS ORDER --}}
                    <section aria-label="Status order">
                        <h2 class="font-display text-sm font-black uppercase tracking-[0.18em]" style="color: #050505;">📦 Status Pesanan</h2>
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            @foreach ($actionableStatusCounts as $status)
                                @php
                                    $statusBg = match($status['tone']) {
                                        'emerald' => 'stat-card-green',
                                        'amber' => 'stat-card-yellow',
                                        'blue' => 'stat-card-cyan',
                                        'rose' => 'stat-card-pink',
                                        default => 'background: #fff;',
                                    };
                                    $isClass = !str_starts_with($statusBg, 'background');
                                @endphp
                                <div class="status-order-card {{ $isClass ? $statusBg : '' }}" @if(!$isClass) style="{{ $statusBg }}" @endif>
                                    <p class="truncate font-display text-[10px] font-bold uppercase tracking-wider" style="color: #050505;">{{ $status['label'] }}</p>
                                    <p class="mt-1 font-display text-2xl font-bold" style="color: #050505;">{{ $status['count'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    </section>
                </div>

                {{-- Pendapatan 7 hari --}}
                <section aria-label="Pendapatan 7 hari">
                    <h2 class="font-display text-sm font-bold uppercase tracking-wider" style="color: #050505;">💰 Pendapatan 7 hari</h2>
                    <p class="mt-1 text-[11px] font-semibold" style="color: #64748b;">Pembayaran lunas</p>
                    <div class="mt-3 space-y-3">
                        @foreach ($earnings as $earning)
                            <div class="flex items-center gap-3 text-sm">
                                <span class="w-12 shrink-0 font-display text-xs font-bold" style="color: #64748b;">{{ $earning['label'] }}</span>
                                <div class="earnings-bar-track flex-1">
                                    <div class="earnings-bar-fill" style="width: {{ $earning['bar_percent'] }}%"></div>
                                </div>
                                <span class="w-24 shrink-0 text-right font-display text-xs font-bold" style="color: #050505;">{{ $earning['amount_formatted'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </section>

                {{-- Payment control (merged from sidebar) --}}
                <section class="payment-section" aria-label="Mode pembayaran">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-display text-[10px] font-bold uppercase tracking-wider" style="color: #64748b;">Mode pembayaran</p>
                            <h2 class="mt-1 font-display text-base font-bold" style="color: #050505;">{{ $paymentControl['label'] }}</h2>
                        </div>
                        <span class="neo-badge" style="background: {{ $paymentControl['is_pakasir_enabled'] ? '#e8ffd4' : '#fce4f0' }}; color: #050505;">
                            <span class="h-1.5 w-1.5 rounded-full" style="background: {{ $paymentControl['is_pakasir_enabled'] ? '#7BD10A' : '#ef4444' }};"></span>
                            {{ $paymentControl['is_pakasir_enabled'] ? 'AKTIF' : 'NONAKTIF' }}
                        </span>
                    </div>

                    <p class="mt-2 text-xs font-semibold" style="color: #64748b;">
                        {{ $paymentControl['hint'] }}
                    </p>

                    @error('payment_mode', 'paymentMode')
                        <p class="mt-3 rounded-lg p-2 text-xs font-bold text-white" style="background: #ef4444; border: 2px solid #050505;">{{ $message }}</p>
                    @enderror

                    @if ($paymentControl['can_manage'])
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <form method="POST" action="{{ $paymentControl['update_url'] }}">
                                @csrf
                                <input type="hidden" name="payment_mode" value="manual">
                                <button
                                    type="submit"
                                    class="product-action-btn w-full text-xs {{ $paymentControl['mode'] === 'manual' ? 'neo-btn-yellow' : '' }}"
                                    style="{{ $paymentControl['mode'] !== 'manual' ? 'background: #fff;' : '' }}"
                                >
                                    Manual /payment
                                </button>
                            </form>

                            <form method="POST" action="{{ $paymentControl['update_url'] }}">
                                @csrf
                                <input type="hidden" name="payment_mode" value="pakasir">
                                <button
                                    type="submit"
                                    class="product-action-btn w-full text-xs {{ $paymentControl['mode'] === 'pakasir' ? 'neo-btn-purple' : '' }}"
                                    style="{{ $paymentControl['mode'] !== 'pakasir' ? 'background: #fff;' : '' }}"
                                >
                                    Pakasir
                                </button>
                            </form>
                        </div>
                    @else
                        <p class="mt-3 rounded-lg p-2 text-xs font-semibold" style="background: #fff; border: 2px solid #050505; color: #64748b;">
                            Hanya owner/admin yang bisa mengubah mode pembayaran.
                        </p>
                    @endif
                </section>
            </div>

            {{-- ╔═════════════════════════════════════════════════════╗
                 ║  PRODUK TAB (Katalog)                               ║
                 ╚═════════════════════════════════════════════════════╝ --}}
            <div x-cloak x-show="activeTab === 'produk'" class="mt-4 space-y-4">

                <div class="flex items-end justify-between gap-3">
                    <div>
                        <h2 class="font-display text-lg font-bold uppercase tracking-wider" style="color: #050505;">📦 Produk Saya</h2>
                        <p class="mt-1 text-xs font-semibold" style="color: #64748b;">Semua kelola produk dan stok tetap di dashboard ini</p>
                    </div>

                    @if ($canManageProducts)
                    <button
                        type="button"
                        class="neo-btn neo-btn-purple shrink-0 text-[11px]"
                        x-on:click="openProductForm()"
                    >
                        ➕ Tambah Produk
                    </button>
                    @endif
                </div>

                <div class="rounded-[1.25rem] border-[3px] border-neo-ink bg-[#ede5fa] px-4 py-3 text-sm font-bold text-neo-ink shadow-[4px_4px_0_0_#050505]">
                    <span class="font-black uppercase">Dipesan</span> berarti stok yang sedang ditahan untuk order aktif/pending. Kelola hanya menampilkan akun yang masih tersedia.
                </div>

                @if ($canManageProducts)
                <form method="POST" action="{{ route('admin.categories.store') }}" class="rounded-[1rem] border-[3px] border-neo-ink bg-[#fef9c3] p-4 shadow-[4px_4px_0_0_#050505]">
                    @csrf
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="font-display text-sm font-black uppercase text-neo-ink">Tambah kategori</p>
                            <p class="mt-1 text-[11px] font-bold text-neo-ink/60">Buat kategori cepat untuk produk baru.</p>
                        </div>
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_1.2fr_auto]">
                        <div>
                            <label for="category-name" class="font-display text-[11px] font-extrabold uppercase tracking-wider text-neo-ink">Nama</label>
                            <input id="category-name" name="category_name" value="{{ old('category_name') }}" class="neo-input mt-1.5" placeholder="AI Tools">
                            @error('category_name', 'categoryCreate')
                                <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="category-description" class="font-display text-[11px] font-extrabold uppercase tracking-wider text-neo-ink">Deskripsi opsional</label>
                            <input id="category-description" name="category_description" value="{{ old('category_description') }}" class="neo-input mt-1.5" placeholder="Kategori akun premium">
                            @error('category_description', 'categoryCreate')
                                <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                            @enderror
                        </div>
                        <button type="submit" class="neo-btn neo-btn-yellow self-end text-[11px]">
                            + Kategori
                        </button>
                    </div>
                </form>
                @endif

                @if (session('status'))
                    <div class="animate-slide-up rounded-xl p-4 text-sm font-bold" style="background: #e8ffd4; border: 2px solid #050505; box-shadow: 3px 3px 0px #050505; color: #050505;">
                        {{ session('status') }}
                    </div>
                @endif

                <div class="grid gap-4 md:grid-cols-2">
                    @forelse ($products as $product)
                        @php
                            $stockPayload = [
                                'id' => $product['id'],
                                'name' => $product['name'],
                                'stock' => $product['stock'],
                                'stockUrl' => $product['stock_url'],
                            ];
                            $productPayload = [
                                'id' => $product['id'],
                                'name' => $product['name'],
                                'priceAmount' => $product['price_amount'],
                                'categoryId' => $product['category_id'],
                                'description' => $product['description'],
                                'features' => $product['features'],
                                'specifications' => $product['specifications'],
                                'isActive' => $product['is_active'],
                                'updateUrl' => $product['update_url'],
                                'stockUrl' => $product['stock_url'],
                                'stock' => $product['stock'],
                                'availableStockItemCount' => $product['available_stock_item_count'],
                                'stockItemsTruncated' => $product['stock_items_truncated'],
                                'stockItems' => $product['stock_items'],
                            ];
                        @endphp

                        <article class="neo-card">
                            {{-- Product name + status badge --}}
                            <div class="flex min-w-0 items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate font-display text-base font-bold" style="color: #050505;">{{ $product['name'] }}</p>
                                    <span class="neo-badge mt-1" style="background: {{ $product['is_active'] ? '#e8ffd4' : '#f1f5f9' }}; color: {{ $product['is_active'] ? '#166534' : '#64748b' }};">
                                        {{ $product['status_label'] }}
                                    </span>
                                </div>
                                <span class="neo-badge" style="background: #ede5fa; color: #8758de;">
                                    {{ $product['category'] }}
                                </span>
                            </div>

                            {{-- Price --}}
                            <p class="mt-4 font-display text-2xl font-bold" style="color: #050505;">{{ $product['price'] }}</p>

                            {{-- Short description --}}
                            <p class="mt-2 break-words text-xs font-bold leading-5" style="color: #64748b;">{{ $product['description'] }}</p>

                            {{-- Stock info --}}
                            <div class="mt-3 grid grid-cols-3 gap-2">
                                <div class="rounded-lg p-2 text-center" style="background: #f1f5f9; border: 1px solid #e2e8f0;">
                                    <p class="text-[9px] font-bold uppercase" style="color: #64748b;">Stok</p>
                                    <p class="mt-0.5 font-display text-sm font-bold" style="color: #050505;">{{ $product['stock'] }}</p>
                                </div>
                                <div class="rounded-lg p-2 text-center" style="background: #d4f6fb; border: 1px solid #16b4ca;">
                                    <p class="text-[9px] font-bold uppercase" style="color: #0e7490;">Tersedia</p>
                                    <p class="mt-0.5 font-display text-sm font-bold" style="color: #050505;">{{ $product['available_stock'] }}</p>
                                </div>
                                <div class="rounded-lg p-2 text-center" style="background: #fef9c3; border: 1px solid #f2da3d;">
                                    <p class="text-[9px] font-bold uppercase" style="color: #92400e;">Dipesan</p>
                                    <p class="mt-0.5 font-display text-sm font-bold" style="color: #050505;">{{ $product['reserved_stock'] }}</p>
                                </div>
                            </div>

                            {{-- Visibility status --}}
                            <p class="mt-2 text-[11px] font-semibold" style="color: {{ $product['is_active'] ? '#7BD10A' : '#ef4444' }};">
                                {{ $product['is_active'] ? '● Tampil di toko' : '○ Tidak tampil' }}
                            </p>

                            @if ($canManageProducts)
                            {{-- Action buttons: in-page only --}}
                            <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3">
                                <button
                                    type="button"
                                    class="product-action-btn"
                                    style="background: #d4f6fb; color: #0e7490;"
                                    x-on:click="openProductEditModal(@js($productPayload))"
                                >
                                    ✏️ Ubah
                                </button>
                                <button
                                    type="button"
                                    class="product-action-btn"
                                    style="background: #7BD10A; color: #166534;"
                                    x-on:click="openStockModal(@js($stockPayload))"
                                >
                                    ➕ Stok
                                </button>
                                <button
                                    type="button"
                                    class="product-action-btn"
                                    style="background: #f2da3d; color: #92400e;"
                                    x-on:click="openManageStockModal(@js($productPayload))"
                                >
                                    📦 Kelola
                                </button>
                                <button
                                    type="button"
                                    class="product-action-btn"
                                    style="background: #8758de; color: #fff;"
                                    x-on:click="navigator.clipboard?.writeText(@js($product['share_url'])); copied = true; setTimeout(() => copied = false, 1600)"
                                >
                                    🔗 Share
                                </button>
                                <form method="POST" action="{{ $product['visibility_url'] }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="is_active" value="{{ $product['is_active'] ? 0 : 1 }}">
                                    <button
                                        type="submit"
                                        class="product-action-btn w-full"
                                        style="background: #fef9c3; color: #92400e;"
                                    >
                                        {{ $product['is_active'] ? '⏸ Pause' : '▶ Play' }}
                                    </button>
                                </form>
                                <form method="POST" action="{{ $product['delete_url'] }}" x-on:submit="if (! confirm('Arsipkan produk {{ addslashes($product['name']) }} dari toko?')) $event.preventDefault()">
                                    @csrf
                                    @method('DELETE')
                                    <button
                                        type="submit"
                                        class="product-action-btn w-full"
                                        style="background: #fecaca; color: #dc2626;"
                                    >
                                        🗑️ Hapus
                                    </button>
                                </form>
                            </div>
                            @else
                                <div class="mt-4 rounded-[0.75rem] border-[3px] border-neo-ink bg-[#f1f5f9] p-3 text-xs font-bold text-neo-ink/70">
                                    Role ini hanya bisa melihat produk. Aksi stok khusus owner/admin.
                                </div>
                            @endif
                        </article>
                    @empty
                        <div class="rounded-xl p-6 text-center" style="background: #fff; border: 2px dashed #050505;">
                            <p class="font-display text-sm font-bold" style="color: #64748b;">Belum ada produk.</p>
                        </div>
                    @endforelse
                </div>

                @if ($canManageProducts)
                {{-- ════════════════════════════════════════════
                     STOCK MODAL
                     ════════════════════════════════════════════ --}}
                <div
                    x-cloak
                    x-show="stockModalOpen"
                    x-transition.opacity
                    x-on:keydown.escape.window="closeStockModal()"
                    class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4"
                    style="background: rgba(5,5,5,0.6);"
                >
                    <div class="absolute inset-0" x-on:click="closeStockModal()" aria-hidden="true"></div>

                    <x-admin-dashboard.modal-shell
                        id="stock-modal"
                        title="Tambah stok"
                        description="1 baris = 1 item"
                        class="w-full max-w-md bg-[#7BD10A]"
                    >
                        <form method="POST" x-bind:action="stockFormAction" class="space-y-3">
                            @csrf
                            <input type="hidden" name="stock_product_id" x-bind:value="stockProductId">

                            <div class="flex flex-wrap items-center justify-between gap-2 text-xs font-extrabold uppercase text-neo-ink">
                                <span class="max-w-[13rem] truncate" x-text="stockProductName">Produk</span>
                                <span x-text="`${stockItemCount()} item`">0 item</span>
                            </div>

                            <div>
                                <textarea
                                    id="stock-items"
                                    name="stock_items"
                                    rows="6"
                                    placeholder="email@example.com|password&#10;KEY-XXXX-1234"
                                    class="neo-textarea"
                                    x-model="stockItems"
                                    x-ref="stockItems"
                                ></textarea>

                                @error('stock_items', 'stockAdjustment')
                                    <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="grid grid-cols-2 gap-2 pt-1">
                                <button type="button" class="neo-btn neo-btn-white" x-on:click="closeStockModal()">
                                    Batal
                                </button>
                                <button type="submit" class="neo-btn neo-btn-pink">
                                    ⚡ Simpan
                                </button>
                            </div>
                        </form>
                    </x-admin-dashboard.modal-shell>
                </div>

                <div
                    x-cloak
                    x-show="manageStockModalOpen"
                    x-transition.opacity
                    x-on:keydown.escape.window="closeManageStockModal()"
                    class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4"
                    style="background: rgba(5,5,5,0.6);"
                >
                    <div class="absolute inset-0" x-on:click="closeManageStockModal()" aria-hidden="true"></div>

                    <x-admin-dashboard.modal-shell
                        id="manage-stock-modal"
                        title="Kelola stok"
                        description="Akun tersedia, belum dipesan pembeli."
                        class="max-h-[92vh] w-full max-w-xl overflow-y-auto bg-[#fee648] p-3 sm:p-4"
                    >
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2 text-xs font-extrabold uppercase text-neo-ink">
                            <span class="max-w-[15rem] truncate" x-text="manageStockProductName">Produk</span>
                            <span x-text="`${manageStockItems.length} dari ${manageStockAvailableCount} akun`">0 akun</span>
                        </div>

                        <p x-show="manageStockTruncated" class="mb-2 rounded-[0.45rem] border-[3px] border-neo-ink bg-white p-2 text-[11px] font-bold text-neo-ink shadow-[2px_2px_0_0_#050505]">
                            25 akun pertama ditampilkan. Hapus/edit yang lama dulu untuk melihat sisanya.
                        </p>

                        <div class="space-y-2">
                            <template x-for="(item, index) in manageStockItems" :key="item.id">
                                <article class="rounded-[0.45rem] border-[3px] border-neo-ink bg-white p-2 shadow-[3px_3px_0_0_#050505]">
                                    <div class="grid grid-cols-[1fr_auto] gap-2">
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <span class="font-display text-[11px] font-black text-neo-ink" x-text="`#${index + 1}`">#1</span>
                                                <span class="rounded-[0.35rem] border-2 border-[#93c5fd] bg-[#dbeafe] px-1.5 py-0.5 text-[8px] font-black uppercase text-[#1d4ed8]">Tersedia</span>
                                            </div>
                                            <p class="mt-1.5 break-all font-mono text-[10px] font-bold leading-4 text-neo-ink" x-text="item.secret"></p>
                                        </div>
                                        <div class="flex flex-col gap-1.5">
                                            <button
                                                type="button"
                                                class="inline-flex h-9 w-9 items-center justify-center rounded-[0.35rem] border-[3px] border-neo-ink bg-[#16b4ca] text-sm font-black text-white shadow-[2px_2px_0_0_#050505]"
                                                aria-label="Ubah akun stok"
                                                x-bind:aria-expanded="item.editing"
                                                x-on:click="item.editing = ! item.editing"
                                            >✏️</button>
                                            <form method="POST" x-bind:action="item.delete_url" x-on:submit="if (! confirm('Hapus akun stok ini?')) $event.preventDefault()">
                                                @csrf
                                                <input type="hidden" name="_method" value="DELETE">
                                                <button
                                                    type="submit"
                                                    class="inline-flex h-9 w-9 items-center justify-center rounded-[0.35rem] border-[3px] border-neo-ink bg-[#db3f98] text-sm font-black text-white shadow-[2px_2px_0_0_#050505]"
                                                    aria-label="Hapus akun stok"
                                                >🗑️</button>
                                            </form>
                                        </div>
                                    </div>
                                    <form method="POST" x-bind:action="item.update_url" class="mt-2 grid gap-2 sm:grid-cols-[1fr_auto]" x-show="item.editing" x-transition>
                                        @csrf
                                        <input type="hidden" name="_method" value="PATCH">
                                        <textarea name="secret" rows="2" class="neo-textarea min-h-16 font-mono text-[11px]" x-model="item.secret"></textarea>
                                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-1">
                                            <button type="submit" class="product-action-btn min-h-9 self-start" style="background: #16b4ca; color: #fff;">Simpan</button>
                                            <button type="button" class="product-action-btn min-h-9 self-start" style="background: #fff; color: #050505;" x-on:click="item.editing = false">Batal</button>
                                        </div>
                                    </form>
                                </article>
                            </template>
                        </div>

                        <div x-show="manageStockItems.length === 0" class="rounded-[0.6rem] border-[3px] border-dashed border-neo-ink bg-white p-4 text-center text-sm font-bold text-neo-ink">
                            Belum ada akun tersedia. Tambahkan stok baru.
                        </div>

                        <button type="button" class="neo-btn neo-btn-green mt-4 w-full" x-on:click="openStockModalFromManage()">
                            ➕ Tambah stok baru
                        </button>
                    </x-admin-dashboard.modal-shell>
                </div>
                @endif
            </div>

            {{-- ╔═════════════════════════════════════════════════════╗
                 ║  PENJUALAN TAB (Pesanan)                            ║
                 ╚═════════════════════════════════════════════════════╝ --}}
            <div x-cloak x-show="activeTab === 'penjualan'" x-data="{ salesFilter: 'all' }" class="mt-4 space-y-4">

                <div>
                    <h2 class="font-display text-lg font-bold uppercase tracking-wider" style="color: #050505;">🛒 Penjualan Saya</h2>
                    <p class="mt-1 text-xs font-semibold" style="color: #64748b;">Order masuk dan diproses</p>
                </div>

                {{-- Filter pills --}}
                <div class="overflow-x-auto pb-1" aria-label="Filter penjualan">
                    <div class="flex min-w-max items-center gap-2">
                        @foreach ($salesFilters as $filter)
                            <button
                                type="button"
                                x-on:click="salesFilter = @js($filter['key'])"
                                x-bind:class="salesFilter === @js($filter['key']) ? 'filter-pill-active' : ''"
                                x-bind:aria-pressed="salesFilter === @js($filter['key'])"
                                class="filter-pill"
                                data-sales-filter="{{ $filter['key'] }}"
                            >
                                <span>{{ $filter['label'] }}</span>
                                <span class="filter-pill-count">{{ $filter['count'] }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- Order cards --}}
                <div class="space-y-3">
                    @forelse ($orders as $order)
                        <article
                            class="order-card"
                            data-order-status="{{ $order['order_status'] }}"
                            data-order-filter-groups="{{ implode(' ', $order['filter_groups']) }}"
                            x-show="salesFilter === 'all' || $el.dataset.orderFilterGroups.split(' ').includes(salesFilter)"
                        >
                            {{-- Invoice + status --}}
                            <div class="flex min-w-0 items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-[10px] font-bold uppercase tracking-wider" style="color: #64748b;">{{ $order['invoice_number'] }}</p>
                                    <p class="mt-1 truncate font-display text-base font-bold" style="color: #050505;">{{ $order['product_name'] }}</p>
                                </div>
                                @php
                                    $orderBadgeBg = match($order['order_status_tone']) {
                                        'emerald' => '#e8ffd4',
                                        'amber' => '#fef9c3',
                                        'blue' => '#d4f6fb',
                                        'rose' => '#fecaca',
                                        default => '#f1f5f9',
                                    };
                                    $orderBadgeColor = match($order['order_status_tone']) {
                                        'emerald' => '#166534',
                                        'amber' => '#92400e',
                                        'blue' => '#0e7490',
                                        'rose' => '#dc2626',
                                        default => '#64748b',
                                    };
                                @endphp
                                <span class="neo-badge" style="background: {{ $orderBadgeBg }}; color: {{ $orderBadgeColor }};">
                                    <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                                    {{ $order['order_status_label'] }}
                                </span>
                            </div>

                            {{-- Price + Earning --}}
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <div class="rounded-lg p-2.5" style="background: #f1f5f9; border: 1px solid #e2e8f0;">
                                    <p class="text-[9px] font-bold uppercase" style="color: #64748b;">Harga</p>
                                    <p class="mt-0.5 break-words font-display text-sm font-bold" style="color: #050505;">{{ $order['price'] }}</p>
                                </div>
                                <div class="rounded-lg p-2.5" style="background: #e8ffd4; border: 1px solid #7BD10A;">
                                    <p class="text-[9px] font-bold uppercase" style="color: #166534;">Earning</p>
                                    <p class="mt-0.5 break-words font-display text-sm font-bold" style="color: #050505;">{{ $order['earning'] }}</p>
                                </div>
                            </div>

                            {{-- Customer + Date --}}
                            <div class="mt-3 grid grid-cols-2 gap-3 border-t-2 pt-3" style="border-color: #e2e8f0;">
                                <div class="min-w-0">
                                    <p class="text-[9px] font-bold uppercase" style="color: #64748b;">Pembeli</p>
                                    <p class="mt-0.5 truncate text-sm font-bold" style="color: #050505;">{{ $order['customer'] }}</p>
                                </div>
                                <div class="min-w-0 text-right">
                                    <p class="text-[9px] font-bold uppercase" style="color: #64748b;">Tanggal</p>
                                    <p class="mt-0.5 text-sm font-bold" style="color: #050505;">{{ $order['created_at_label'] }}</p>
                                </div>
                            </div>

                            {{-- Payment badge + item count --}}
                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                @php
                                    $payBadgeBg = match($order['payment_status_tone']) {
                                        'emerald' => '#e8ffd4',
                                        'amber' => '#fef9c3',
                                        'rose' => '#fecaca',
                                        default => '#f1f5f9',
                                    };
                                    $payBadgeColor = match($order['payment_status_tone']) {
                                        'emerald' => '#166534',
                                        'amber' => '#92400e',
                                        'rose' => '#dc2626',
                                        default => '#64748b',
                                    };
                                @endphp
                                <span class="neo-badge" style="background: {{ $payBadgeBg }}; color: {{ $payBadgeColor }};">
                                    {{ $order['payment_status_label'] }}
                                </span>
                                <span class="text-xs font-bold" style="color: #64748b;">{{ $order['item_count'] }} item</span>
                            </div>
                        </article>
                    @empty
                        <div class="rounded-xl p-6 text-center" style="background: #fff; border: 2px dashed #050505;">
                            <p class="font-display text-sm font-bold" style="color: #64748b;">Belum ada order.</p>
                        </div>
                    @endforelse
                </div>
            </div>

            @if ($canManageProducts)
            {{-- ╔═════════════════════════════════════════════════════╗
                 ║  PRODUCT CREATE/EDIT MODAL                         ║
                 ╚═════════════════════════════════════════════════════╝ --}}
            <div
                x-cloak
                x-show="productModalOpen"
                x-transition.opacity
                x-on:keydown.escape.window="closeProductModal()"
                class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4"
                style="background: rgba(5,5,5,0.6);"
            >
                <div class="absolute inset-0" x-on:click="closeProductModal()" aria-hidden="true"></div>

                <x-admin-dashboard.modal-shell
                    id="product-modal"
                    title="Tambah produk"
                    title-expression="productModalMode === 'edit' ? 'Edit produk' : 'Tambah produk'"
                    description="Nama, harga, kategori, deskripsi."
                    class="max-h-[92vh] w-full max-w-xl overflow-y-auto"
                    x-bind:style="{ backgroundColor: productModalMode === 'edit' ? '#fee648' : '#7BD10A' }"
                >
                    @if ($errors->productCreate->any() || $errors->productUpdate->any())
                        <div class="mb-3 animate-slide-up rounded-[0.6rem] p-3 text-sm font-bold text-white" style="background: #ef4444; border: 3px solid #050505; box-shadow: 3px 3px 0px #050505;">
                            Periksa kembali field produk yang ditandai.
                        </div>
                    @endif

                    <form method="POST" x-bind:action="productFormAction" class="space-y-3">
                        @csrf
                        <input type="hidden" x-bind:name="productFormMethod ? '_method' : ''" x-bind:value="productFormMethod">
                        <input type="hidden" name="product_id" x-bind:value="productId">
                        <input type="hidden" name="is_active" x-bind:value="productIsActive ? 1 : 0">

                        <div>
                            <label for="product-name" class="font-display text-[11px] font-extrabold uppercase tracking-wider" style="color: #050505;">Nama produk</label>
                            <input
                                type="text"
                                id="product-name"
                                name="name"
                                placeholder="NETFLIX PREMIUM"
                                class="neo-input mt-1.5"
                                x-model="productName"
                                x-ref="productName"
                            >
                            @error('name', 'productCreate')
                                <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                            @enderror
                            @error('name', 'productUpdate')
                                <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label for="product-price" class="font-display text-[11px] font-extrabold uppercase tracking-wider" style="color: #050505;">Harga (IDR)</label>
                                <input
                                    type="number"
                                    id="product-price"
                                    name="price_amount"
                                    min="0"
                                    step="1"
                                    inputmode="numeric"
                                    placeholder="25000"
                                    class="neo-input mt-1.5"
                                    x-model="productPrice"
                                >
                                @error('price_amount', 'productCreate')
                                    <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                                @enderror
                                @error('price_amount', 'productUpdate')
                                    <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="product-category" class="font-display text-[11px] font-extrabold uppercase tracking-wider" style="color: #050505;">Kategori</label>
                                <select id="product-category" name="category_id" class="neo-select mt-1.5" x-model="productCategoryId">
                                    <option value="">— Pilih —</option>
                                    @foreach ($categoryOptions as $category)
                                        <option value="{{ $category['id'] }}">{{ $category['name'] }}</option>
                                    @endforeach
                                </select>
                                @error('category_id', 'productCreate')
                                    <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                                @enderror
                                @error('category_id', 'productUpdate')
                                    <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <div class="rounded-[0.6rem] border-[3px] border-neo-ink p-3">
                            <label for="product-description" class="font-display text-sm font-extrabold text-neo-ink">📝 Deskripsi singkat</label>
                            <textarea
                                id="product-description"
                                name="description"
                                rows="3"
                                placeholder="Deskripsi singkat produk (1–3 kalimat)..."
                                class="neo-textarea mt-2"
                                x-model="productDescription"
                            ></textarea>
                            @error('description', 'productCreate')
                                <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                            @enderror
                            @error('description', 'productUpdate')
                                <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid gap-3">
                            <section class="rounded-[0.6rem] border-[3px] border-neo-ink p-3">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <p class="font-display text-sm font-extrabold text-neo-ink">✨ Fitur utama</p>
                                        <p class="mt-1 text-[10px] font-extrabold uppercase text-neo-ink/60" x-show="! productFeaturesOpen">Tap “+ tambah” untuk menambah fitur.</p>
                                    </div>
                                    <button
                                        type="button"
                                        class="product-action-btn min-h-9 px-2 py-1 text-[10px]"
                                        style="background: #7BD10A; color: #050505;"
                                        x-on:click="productFeaturesOpen = ! productFeaturesOpen"
                                        x-text="productFeaturesOpen ? 'Tutup' : '+ Tambah'"
                                    >+ Tambah</button>
                                </div>

                                <div class="mt-3" x-show="productFeaturesOpen" x-transition>
                                    <textarea
                                        name="features"
                                        rows="3"
                                        placeholder="Satu fitur per baris"
                                        class="neo-textarea"
                                        x-model="productFeatures"
                                    ></textarea>
                                    @error('features', 'productCreate')
                                        <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                                    @enderror
                                    @error('features', 'productUpdate')
                                        <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                                    @enderror
                                </div>
                            </section>

                            <section class="rounded-[0.6rem] border-[3px] border-neo-ink p-3">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <p class="font-display text-sm font-extrabold text-neo-ink">🌐 Paket / spesifikasi</p>
                                        <p class="mt-1 text-[10px] font-extrabold uppercase text-neo-ink/60" x-show="! productSpecificationsOpen">Opsional. Cocok untuk produk multi-tier.</p>
                                    </div>
                                    <button
                                        type="button"
                                        class="product-action-btn min-h-9 px-2 py-1 text-[10px]"
                                        style="background: #16b4ca; color: #050505;"
                                        x-on:click="productSpecificationsOpen = ! productSpecificationsOpen"
                                        x-text="productSpecificationsOpen ? 'Tutup' : '+ Paket'"
                                    >+ Paket</button>
                                </div>

                                <div class="mt-3" x-show="productSpecificationsOpen" x-transition>
                                    <textarea
                                        name="specifications"
                                        rows="3"
                                        placeholder="Satu spesifikasi per baris"
                                        class="neo-textarea"
                                        x-model="productSpecifications"
                                    ></textarea>
                                    @error('specifications', 'productCreate')
                                        <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                                    @enderror
                                    @error('specifications', 'productUpdate')
                                        <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                                    @enderror
                                </div>
                            </section>
                        </div>

                        <div class="grid grid-cols-2 gap-2 pt-1">
                            <button type="button" class="neo-btn neo-btn-white" x-on:click="closeProductModal()">
                                Batal
                            </button>
                            <button type="submit" class="neo-btn neo-btn-pink">
                                <span x-text="productModalMode === 'edit' ? '⚡ Simpan' : '⚡ Submit produk'">⚡ Submit produk</span>
                            </button>
                        </div>
                    </form>
                </x-admin-dashboard.modal-shell>
            </div>
            @endif

            {{-- ╔═════════════════════════════════════════════════════╗
                 ║  BROADCAST TAB (hidden from bottom nav)             ║
                 ╚═════════════════════════════════════════════════════╝ --}}
            <div x-cloak x-show="activeTab === 'broadcast'" class="mt-4 space-y-4">

                <div>
                    <h2 class="font-display text-lg font-black uppercase tracking-[0.18em]" style="color: #050505;">📢 Siaran</h2>
                    <p class="mt-1 text-xs font-semibold" style="color: #64748b;">Kirim info ke pengguna aktif lewat bot</p>
                </div>

                @if ($errors->broadcast->any())
                    <div class="animate-slide-up rounded-xl p-4 text-sm font-bold text-white" style="background: #ef4444; border: 2px solid #050505; box-shadow: 3px 3px 0px #050505;">
                        Periksa kembali pesan broadcast.
                    </div>
                @endif

                <form method="POST" action="{{ route('admin.broadcast.store') }}" class="space-y-4">
                    @csrf

                    <div class="rounded-xl p-4" style="background: #fef9c3; border: 2px solid #050505; box-shadow: 3px 3px 0px #050505;">
                        <p class="text-xs font-bold" style="color: #050505;">
                            ⚠️ Jangan isi data akun, password, kode OTP, atau secret pembeli di broadcast. Data akun hanya dikirim otomatis ke pembeli setelah pembayaran lunas.
                        </p>
                    </div>

                    <div>
                        <label for="broadcast-message" class="font-display text-xs font-bold uppercase tracking-wider" style="color: #050505;">Pesan</label>
                        <textarea
                            id="broadcast-message"
                            name="message"
                            rows="7"
                            placeholder="Contoh: Stok Canva Pro sudah ready. Silakan cek /katalog."
                            class="neo-textarea mt-2"
                        >{{ old('message') }}</textarea>
                        @error('message', 'broadcast')
                            <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                        @enderror
                    </div>

                    <button type="submit" class="neo-btn neo-btn-purple w-full text-sm">
                        📤 Kirim Siaran
                    </button>
                </form>
            </div>

            {{-- ═══════════════════════════════════════════════════════
                 BOTTOM TAB NAVIGATION — Dark (#050505) with 4 tabs
                 ═══════════════════════════════════════════════════════ --}}
            <nav
                class="bottom-nav mobile-bottom-nav fixed inset-x-0 bottom-0 z-50 px-4 pb-safe pt-2"
                style="border-top: 2px solid #050505;"
                aria-label="Navigasi dashboard"
            >
                <div class="mx-auto flex max-w-md items-center justify-between">
                    {{-- Ringkasan --}}
                    <button
                        type="button"
                        x-on:click="activeTab = 'stats'"
                        class="flex flex-col items-center gap-1 rounded-2xl px-4 py-2 text-white/80 transition-all duration-200"
                        :class="activeTab === 'stats' ? 'bottom-nav-active' : ''"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                        </svg>
                        <span class="text-[10px] font-bold text-current">Ringkasan</span>
                    </button>

                    {{-- Produk --}}
                    <button
                        type="button"
                        x-on:click="activeTab = 'produk'"
                        class="flex flex-col items-center gap-1 rounded-2xl px-4 py-2 text-white/80 transition-all duration-200"
                        :class="activeTab === 'produk' ? 'bottom-nav-active' : ''"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                        </svg>
                        <span class="text-[10px] font-bold text-current">Produk</span>
                    </button>

                    {{-- Pesanan --}}
                    <button
                        type="button"
                        x-on:click="activeTab = 'penjualan'"
                        class="flex flex-col items-center gap-1 rounded-2xl px-4 py-2 text-white/80 transition-all duration-200"
                        :class="activeTab === 'penjualan' ? 'bottom-nav-active' : ''"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <span class="text-[10px] font-bold text-current">Pesanan</span>
                    </button>

                    @if ($canManageProducts)
                    {{-- Tambah --}}
                    <button
                        type="button"
                        x-on:click="openProductForm()"
                        class="flex flex-col items-center gap-1 rounded-2xl px-4 py-2 text-white/80 transition-all duration-200"
                        :class="productModalOpen && productModalMode === 'create' ? 'bottom-nav-active' : ''"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span class="text-[10px] font-bold text-current">Tambah</span>
                    </button>
                    @endif
                </div>
            </nav>
        </main>
    </body>
</html>
