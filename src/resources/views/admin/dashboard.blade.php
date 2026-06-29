<!DOCTYPE html>
<html lang="id" class="h-full bg-brand-bg">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Admin LHIE STORE</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-full bg-brand-bg font-lhie text-brand-navy antialiased">
        @php
            $stockModalProduct = collect($products)->firstWhere('id', (int) old('stock_product_id'));
        @endphp

        <main
            class="mx-auto flex min-h-screen w-full max-w-6xl flex-col px-4 py-4 sm:px-6 lg:px-8"
            x-data="{
                activeTab: @js($activeTab ?? 'stats'),
                copied: false,
                storeLink: @js($storeLink['url']),
                copyState: 'Copy',
                stockModalOpen: @js($errors->stockAdjustment->any()),
                stockFormAction: @js($stockModalProduct['stock_url'] ?? ''),
                stockProductId: @js($stockModalProduct['id'] ?? null),
                stockProductName: @js($stockModalProduct['name'] ?? ''),
                stockProductStock: @js($stockModalProduct['stock'] ?? 0),
                stockItems: @js(old('stock_items', '')),
                stockItemCount() {
                    return this.stockItems
                        .split(/\r?\n/)
                        .map((row) => row.trim())
                        .filter(Boolean)
                        .length;
                },
                openStockModal(product) {
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
                    this.copyState = 'Copied';
                    setTimeout(() => {
                        this.copied = false;
                        this.copyState = 'Copy';
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
        >
            <x-admin-dashboard.card as="header" padding="compact">
                <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-display text-lg font-bold tracking-normal text-brand-navy">LHIE STORE</p>
                            <x-admin-dashboard.status-badge :tone="$storeStatus['tone']">
                                {{ $storeStatus['label'] }}
                            </x-admin-dashboard.status-badge>
                        </div>
                        <h1 class="mt-2 text-2xl font-bold tracking-normal text-brand-navy sm:text-3xl">
                            {{ $admin['name'] }}
                        </h1>
                        <p class="mt-1 text-sm font-medium text-slate-500">
                            {{ strtoupper($admin['role']) }} &middot; {{ $storeStatus['hint'] }}
                        </p>
                    </div>

                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 md:w-auto">
                        <x-admin-dashboard.button :href="$actions['shop']">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M4.5 7.5h15l-.75 12.75H5.25L4.5 7.5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                                <path d="M8 7.5a4 4 0 0 1 8 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                            </svg>
                            Belanja Produk Lain
                        </x-admin-dashboard.button>

                        <x-admin-dashboard.button
                            type="button"
                            variant="neutral"
                            x-on:click="shareStore()"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M8.5 12.5 15.5 16M15.5 8 8.5 11.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                <circle cx="6" cy="12.5" r="2.5" stroke="currentColor" stroke-width="1.8"/>
                                <circle cx="18" cy="7" r="2.5" stroke="currentColor" stroke-width="1.8"/>
                                <circle cx="18" cy="17" r="2.5" stroke="currentColor" stroke-width="1.8"/>
                            </svg>
                            <span x-text="copied ? 'Link Disalin' : 'Bagikan Toko'">Bagikan Toko</span>
                        </x-admin-dashboard.button>
                    </div>
                </div>

                <section class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5" aria-label="Ringkasan toko">
                    @foreach ($summary as $metric)
                        <x-admin-dashboard.stat-card
                            :label="$metric['label']"
                            :value="$metric['value']"
                            :hint="$metric['hint']"
                            :tone="$metric['tone']"
                        />
                    @endforeach
                </section>
            </x-admin-dashboard.card>

            <x-admin-dashboard.card as="nav" padding="none" class="mt-4 overflow-x-auto" aria-label="Navigasi dashboard">
                <div class="flex min-w-max items-center gap-1 p-1">
                    @foreach ($tabs as $tab)
                        <x-admin-dashboard.tab :tab-id="$tab['id']">
                            {{ $tab['label'] }}
                        </x-admin-dashboard.tab>
                    @endforeach
                </div>
            </x-admin-dashboard.card>

            <section class="mt-4 grid flex-1 gap-4 lg:grid-cols-[1.25fr_0.75fr]">
                <x-admin-dashboard.card class="min-w-0">
                    <div x-show="activeTab === 'stats'">
                        <x-admin-dashboard.section-heading eyebrow="Stats" title="Ringkasan operasional hari ini" class="max-w-2xl">
                            Pantau saldo, GMV, produk aktif, dan order yang perlu diproses dari satu permukaan ringkas.
                        </x-admin-dashboard.section-heading>

                        <section class="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-4" aria-label="Link toko">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                <div class="min-w-0">
                                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ $storeLink['label'] }}</p>
                                    <p class="mt-1 break-all text-sm font-bold text-brand-navy">{{ $storeLink['url'] }}</p>
                                </div>

                                <div class="grid grid-cols-2 gap-2 sm:flex sm:items-center">
                                    <x-admin-dashboard.button type="button" variant="neutral" class="min-h-10 px-3 text-xs" x-on:click="shareStore()">
                                        Share
                                    </x-admin-dashboard.button>
                                    <x-admin-dashboard.button type="button" class="min-h-10 px-3 text-xs" x-on:click="copyStoreLink()">
                                        <span x-text="copyState">Copy</span>
                                    </x-admin-dashboard.button>
                                </div>
                            </div>
                        </section>

                        <section class="mt-4 grid grid-cols-2 gap-3 xl:grid-cols-4" aria-label="Kartu Stats">
                            @foreach ($statsCards as $metric)
                                <x-admin-dashboard.stat-card
                                    :label="$metric['label']"
                                    :value="$metric['value']"
                                    :hint="$metric['hint']"
                                    :tone="$metric['tone']"
                                />
                            @endforeach
                        </section>

                        <div class="mt-5 grid gap-5 xl:grid-cols-[1fr_0.85fr]">
                            <section aria-label="Pendapatan 7 hari">
                                <div class="flex items-center justify-between gap-3">
                                    <h2 class="text-sm font-bold text-brand-navy">Pendapatan 7 hari</h2>
                                    <span class="text-xs font-semibold text-slate-500">Pembayaran paid</span>
                                </div>
                                <div class="mt-3 space-y-3">
                                    @foreach ($earnings as $earning)
                                        <div class="grid grid-cols-[3.25rem_minmax(3rem,1fr)] items-center gap-2 text-sm sm:grid-cols-[3.75rem_1fr_7.5rem] sm:gap-3">
                                            <span class="font-semibold text-slate-500">{{ $earning['label'] }}</span>
                                            <div class="h-2 rounded-full bg-slate-100">
                                                <div class="h-2 rounded-full bg-brand-electric" style="width: {{ $earning['bar_percent'] }}%"></div>
                                            </div>
                                            <span class="col-span-2 text-right font-bold text-brand-navy sm:col-span-1">{{ $earning['amount_formatted'] }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </section>

                            <section aria-label="Status order">
                                <h2 class="text-sm font-bold text-brand-navy">Status order</h2>
                                <div class="mt-3 grid grid-cols-2 gap-2">
                                    @foreach ($actionableStatusCounts as $status)
                                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="truncate text-xs font-bold text-slate-500">{{ $status['label'] }}</span>
                                                <x-admin-dashboard.badge :tone="$status['tone']">{{ $status['count'] }}</x-admin-dashboard.badge>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </section>
                        </div>
                    </div>

                    <div x-cloak x-show="activeTab === 'produk'">
                        <x-admin-dashboard.section-heading eyebrow="Produk" title="Katalog siap dikelola">
                            Produk terbaru, stok tersedia, dan status tampil di toko.
                        </x-admin-dashboard.section-heading>

                        @if (session('status'))
                            <div class="mt-4 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">
                                {{ session('status') }}
                            </div>
                        @endif

                        <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            @forelse ($products as $product)
                                @php
                                    $stockPayload = [
                                        'id' => $product['id'],
                                        'name' => $product['name'],
                                        'stock' => $product['stock'],
                                        'stockUrl' => $product['stock_url'],
                                    ];
                                @endphp

                                <x-admin-dashboard.card as="article" padding="compact" class="w-full min-w-0">
                                    <div class="flex min-w-0 items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-bold text-brand-navy">{{ $product['name'] }}</p>
                                            <p class="mt-1 truncate text-xs font-semibold text-slate-500">{{ $product['category'] }}</p>
                                        </div>

                                        <x-admin-dashboard.badge :tone="$product['is_active'] ? 'emerald' : 'slate'">
                                            {{ $product['status_label'] }}
                                        </x-admin-dashboard.badge>
                                    </div>

                                    <div class="mt-4 grid grid-cols-3 gap-2">
                                        <div class="rounded-lg bg-slate-50 px-3 py-2">
                                            <p class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Stok</p>
                                            <p class="mt-1 text-sm font-bold text-brand-navy">{{ $product['stock'] }}</p>
                                        </div>
                                        <div class="rounded-lg bg-blue-50 px-3 py-2">
                                            <p class="text-[11px] font-bold uppercase tracking-wide text-blue-700">Tersedia</p>
                                            <p class="mt-1 text-sm font-bold text-brand-navy">{{ $product['available_stock'] }}</p>
                                        </div>
                                        <div class="rounded-lg bg-slate-50 px-3 py-2">
                                            <p class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Tahan</p>
                                            <p class="mt-1 text-sm font-bold text-brand-navy">{{ $product['reserved_stock'] }}</p>
                                        </div>
                                    </div>

                                    <div class="mt-4 flex items-center justify-between gap-3">
                                        <p class="text-sm font-bold text-brand-navy">{{ $product['price'] }}</p>
                                        <p class="text-xs font-semibold text-slate-500">{{ $product['is_active'] ? 'Tampil di toko' : 'Tidak tampil' }}</p>
                                    </div>

                                    <div class="mt-4 grid grid-cols-3 gap-2">
                                        <x-admin-dashboard.button :href="$product['edit_url']" variant="neutral" class="min-h-10 px-2 text-xs">
                                            Edit
                                        </x-admin-dashboard.button>

                                        <button
                                            type="button"
                                            class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-brand-electric/20 bg-brand-electricLight px-2 text-xs font-semibold text-brand-electric transition hover:border-brand-electric/30 hover:bg-blue-100 focus:outline-none focus:ring-2 focus:ring-brand-electric/30 focus:ring-offset-2"
                                            x-on:click="openStockModal(@js($stockPayload))"
                                        >
                                            Stok
                                        </button>

                                        <x-admin-dashboard.button :href="$product['manage_url']" variant="neutral" class="min-h-10 px-2 text-xs">
                                            Kelola
                                        </x-admin-dashboard.button>

                                        <button
                                            type="button"
                                            class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-2 text-xs font-semibold text-brand-navy transition hover:border-slate-300 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-300 focus:ring-offset-2"
                                            x-on:click="navigator.clipboard?.writeText(@js($product['share_url'])); copied = true; setTimeout(() => copied = false, 1600)"
                                        >
                                            Share
                                        </button>

                                        <x-admin-dashboard.button :href="$product['edit_url']" variant="warning" class="min-h-10 px-2 text-xs">
                                            Pause
                                        </x-admin-dashboard.button>

                                        <x-admin-dashboard.button :href="$product['edit_url']" variant="destructive" class="min-h-10 px-2 text-xs">
                                            Hapus
                                        </x-admin-dashboard.button>
                                    </div>
                                </x-admin-dashboard.card>
                            @empty
                                <p class="rounded-lg border border-dashed border-slate-200 bg-slate-50 p-4 text-sm font-medium text-slate-500 sm:col-span-2 xl:col-span-3">
                                    Belum ada produk.
                                </p>
                            @endforelse
                        </div>

                        <div
                            x-cloak
                            x-show="stockModalOpen"
                            x-transition.opacity
                            x-on:keydown.escape.window="closeStockModal()"
                            class="fixed inset-0 z-50 flex items-end bg-slate-950/40 p-3 sm:items-center sm:justify-center"
                        >
                            <div class="absolute inset-0" x-on:click="closeStockModal()" aria-hidden="true"></div>

                            <x-admin-dashboard.modal-shell
                                id="stock-modal"
                                title="Tambah Stok"
                                class="relative w-full max-w-lg"
                            >
                                <form method="POST" x-bind:action="stockFormAction" class="space-y-4">
                                    @csrf
                                    <input type="hidden" name="stock_product_id" x-bind:value="stockProductId">

                                    <div class="rounded-lg border border-blue-100 bg-blue-50 px-4 py-3">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="min-w-0 flex-1 truncate text-sm font-bold text-brand-navy" x-text="stockProductName">Produk</p>
                                            <x-admin-dashboard.badge tone="blue">
                                                <span x-text="`${stockItemCount()} item terdeteksi`">0 item terdeteksi</span>
                                            </x-admin-dashboard.badge>
                                        </div>
                                        <p class="mt-1 text-xs font-semibold text-blue-700">
                                            Stok saat ini <span x-text="stockProductStock">0</span>
                                        </p>
                                    </div>

                                    <div>
                                        <x-admin-dashboard.textarea
                                            name="stock_items"
                                            rows="6"
                                            placeholder="Tempel stok baru, satu item per baris"
                                            x-model="stockItems"
                                            x-ref="stockItems"
                                        />

                                        @error('stock_items', 'stockAdjustment')
                                            <p class="mt-2 text-sm font-semibold text-rose-700">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div class="grid grid-cols-2 gap-2">
                                        <x-admin-dashboard.button type="button" variant="neutral" x-on:click="closeStockModal()">
                                            Batal
                                        </x-admin-dashboard.button>
                                        <x-admin-dashboard.button type="submit">
                                            Simpan
                                        </x-admin-dashboard.button>
                                    </div>
                                </form>
                            </x-admin-dashboard.modal-shell>
                        </div>
                    </div>

                    <div x-cloak x-show="activeTab === 'penjualan'" x-data="{ salesFilter: 'all' }">
                        <x-admin-dashboard.section-heading eyebrow="Penjualan" title="Order masuk dan diproses">
                            Order terbaru dengan status ringkas, pembeli, tanggal, dan earning yang sudah paid.
                        </x-admin-dashboard.section-heading>

                        <div class="mt-5 overflow-x-auto pb-1" aria-label="Filter penjualan">
                            <div class="flex min-w-max items-center gap-2">
                                @foreach ($salesFilters as $filter)
                                    <button
                                        type="button"
                                        x-on:click="salesFilter = @js($filter['key'])"
                                        x-bind:class="salesFilter === @js($filter['key']) ? 'border-brand-navy bg-brand-navy text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:bg-slate-50'"
                                        x-bind:aria-pressed="salesFilter === @js($filter['key'])"
                                        class="inline-flex min-h-10 items-center justify-center gap-2 rounded-full border px-3 text-xs font-bold transition focus:outline-none focus:ring-2 focus:ring-brand-electric/25 focus:ring-offset-2"
                                        data-sales-filter="{{ $filter['key'] }}"
                                    >
                                        <span>{{ $filter['label'] }}</span>
                                        <span
                                            class="rounded-full px-2 py-0.5 text-[11px]"
                                            x-bind:class="salesFilter === @js($filter['key']) ? 'bg-white/15 text-white' : 'bg-slate-100 text-slate-500'"
                                        >
                                            {{ $filter['count'] }}
                                        </span>
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        <div class="mt-4 grid gap-3 lg:grid-cols-2">
                            @forelse ($orders as $order)
                                <article
                                    class="min-w-0 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/70"
                                    data-order-status="{{ $order['order_status'] }}"
                                    data-order-filter-groups="{{ implode(' ', $order['filter_groups']) }}"
                                    x-show="salesFilter === 'all' || $el.dataset.orderFilterGroups.split(' ').includes(salesFilter)"
                                >
                                    <div class="flex min-w-0 items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-bold text-brand-navy">{{ $order['invoice_number'] }}</p>
                                            <p class="mt-1 truncate text-sm font-semibold text-slate-700">{{ $order['product_name'] }}</p>
                                        </div>

                                        <x-admin-dashboard.badge :tone="$order['order_status_tone']" dot>
                                            {{ $order['order_status_label'] }}
                                        </x-admin-dashboard.badge>
                                    </div>

                                    <dl class="mt-4 grid grid-cols-2 gap-2 text-sm">
                                        <div class="rounded-lg bg-slate-50 px-3 py-2">
                                            <dt class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Harga</dt>
                                            <dd class="mt-1 break-words font-bold text-brand-navy">{{ $order['price'] }}</dd>
                                        </div>
                                        <div class="rounded-lg bg-emerald-50 px-3 py-2">
                                            <dt class="text-[11px] font-bold uppercase tracking-wide text-emerald-700">Earning</dt>
                                            <dd class="mt-1 break-words font-bold text-brand-navy">{{ $order['earning'] }}</dd>
                                        </div>
                                    </dl>

                                    <div class="mt-4 grid gap-3 border-t border-slate-100 pt-3 text-xs font-semibold text-slate-500 sm:grid-cols-2">
                                        <div class="min-w-0">
                                            <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Pembeli</p>
                                            <p class="mt-1 truncate text-brand-navy">{{ $order['customer'] }}</p>
                                        </div>
                                        <div class="min-w-0 sm:text-right">
                                            <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Tanggal</p>
                                            <p class="mt-1 text-brand-navy">{{ $order['created_at_label'] }}</p>
                                        </div>
                                    </div>

                                    <div class="mt-3 flex flex-wrap items-center gap-2">
                                        <x-admin-dashboard.badge :tone="$order['payment_status_tone']">{{ $order['payment_status_label'] }}</x-admin-dashboard.badge>
                                        <span class="text-xs font-semibold text-slate-500">{{ $order['item_count'] }} item</span>
                                    </div>
                                </article>
                            @empty
                                <p class="rounded-lg border border-dashed border-slate-200 bg-slate-50 p-4 text-sm font-medium text-slate-500 lg:col-span-2">
                                    Belum ada order.
                                </p>
                            @endforelse
                        </div>
                    </div>

                    <div x-cloak x-show="activeTab === 'tambah'">
                        <x-admin-dashboard.section-heading eyebrow="Tambah" title="Tambah produk baru">
                            Buat produk awal dengan harga, kategori, dan ringkasan yang langsung siap tampil setelah stok ditambahkan.
                        </x-admin-dashboard.section-heading>

                        @if ($errors->productCreate->any())
                            <div class="mt-4 rounded-lg border border-rose-100 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                                Periksa kembali field yang ditandai.
                            </div>
                        @endif

                        <form method="POST" action="{{ route('admin.products.store') }}" class="mt-5 space-y-5">
                            @csrf

                            <div class="grid min-w-0 gap-4 md:grid-cols-2">
                                <div class="min-w-0">
                                    <label for="product-name" class="text-sm font-bold text-brand-navy">Nama produk</label>
                                    <x-admin-dashboard.input
                                        id="product-name"
                                        name="name"
                                        value="{{ old('name') }}"
                                        class="mt-2"
                                        placeholder="Contoh: Canva Pro 1 Bulan"
                                    />

                                    @error('name', 'productCreate')
                                        <p class="mt-2 text-sm font-semibold text-rose-700">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="min-w-0">
                                    <label for="product-price" class="text-sm font-bold text-brand-navy">Harga</label>
                                    <x-admin-dashboard.input
                                        id="product-price"
                                        name="price_amount"
                                        type="number"
                                        min="0"
                                        step="1"
                                        inputmode="numeric"
                                        value="{{ old('price_amount') }}"
                                        class="mt-2"
                                        placeholder="75000"
                                    />

                                    @error('price_amount', 'productCreate')
                                        <p class="mt-2 text-sm font-semibold text-rose-700">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div class="min-w-0">
                                <label for="product-category" class="text-sm font-bold text-brand-navy">Kategori</label>
                                <x-admin-dashboard.select id="product-category" name="category_id" class="mt-2">
                                    <option value="">Tanpa kategori</option>
                                    @foreach ($categoryOptions as $category)
                                        <option value="{{ $category['id'] }}" @selected((string) old('category_id') === (string) $category['id'])>
                                            {{ $category['name'] }}
                                        </option>
                                    @endforeach
                                </x-admin-dashboard.select>

                                @error('category_id', 'productCreate')
                                    <p class="mt-2 text-sm font-semibold text-rose-700">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="min-w-0">
                                <label for="product-description" class="text-sm font-bold text-brand-navy">Deskripsi singkat</label>
                                <x-admin-dashboard.textarea
                                    id="product-description"
                                    name="description"
                                    rows="3"
                                    value="{{ old('description') }}"
                                    class="mt-2"
                                    placeholder="Ringkas manfaat utama produk."
                                />

                                @error('description', 'productCreate')
                                    <p class="mt-2 text-sm font-semibold text-rose-700">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="grid min-w-0 gap-3 md:grid-cols-2" x-data="{ featuresOpen: true, specsOpen: true }">
                                <section class="min-w-0 rounded-lg border border-slate-200 bg-slate-50 p-3">
                                    <button
                                        type="button"
                                        class="flex min-h-11 w-full items-center justify-between gap-3 text-left text-sm font-bold text-brand-navy"
                                        x-on:click="featuresOpen = ! featuresOpen"
                                    >
                                        <span>Fitur utama</span>
                                        <span class="text-slate-400" x-text="featuresOpen ? '-' : '+'">-</span>
                                    </button>

                                    <div class="mt-2" x-show="featuresOpen">
                                        <x-admin-dashboard.textarea
                                            name="features"
                                            rows="4"
                                            value="{{ old('features') }}"
                                            placeholder="Satu fitur per baris"
                                        />

                                        @error('features', 'productCreate')
                                            <p class="mt-2 text-sm font-semibold text-rose-700">{{ $message }}</p>
                                        @enderror
                                    </div>
                                </section>

                                <section class="min-w-0 rounded-lg border border-slate-200 bg-slate-50 p-3">
                                    <button
                                        type="button"
                                        class="flex min-h-11 w-full items-center justify-between gap-3 text-left text-sm font-bold text-brand-navy"
                                        x-on:click="specsOpen = ! specsOpen"
                                    >
                                        <span>Paket/spesifikasi</span>
                                        <span class="text-slate-400" x-text="specsOpen ? '-' : '+'">-</span>
                                    </button>

                                    <div class="mt-2" x-show="specsOpen">
                                        <x-admin-dashboard.textarea
                                            name="specifications"
                                            rows="4"
                                            value="{{ old('specifications') }}"
                                            placeholder="Satu spesifikasi per baris"
                                        />

                                        @error('specifications', 'productCreate')
                                            <p class="mt-2 text-sm font-semibold text-rose-700">{{ $message }}</p>
                                        @enderror
                                    </div>
                                </section>
                            </div>

                            <div class="sticky bottom-3 -mx-1 rounded-lg border border-slate-200 bg-white/95 p-2 shadow-lg shadow-slate-950/10 backdrop-blur sm:static sm:mx-0 sm:border-0 sm:bg-transparent sm:p-0 sm:shadow-none">
                                <x-admin-dashboard.button type="submit" class="w-full sm:w-auto">
                                    Submit Produk
                                </x-admin-dashboard.button>
                            </div>
                        </form>
                    </div>

                    <div x-cloak x-show="activeTab === 'withdraw'">
                        <x-admin-dashboard.section-heading eyebrow="Withdraw" title="Saldo dan penarikan">
                            Saldo di sini adalah nilai baca-saja dari pembayaran yang sudah paid.
                        </x-admin-dashboard.section-heading>

                        <div class="mt-5 rounded-lg border border-emerald-100 bg-emerald-50 p-4">
                            <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">{{ $balance['label'] }}</p>
                            <p class="mt-2 font-display text-3xl font-bold text-brand-navy">
                                {{ $balance['amount_formatted'] }}
                            </p>
                            <p class="mt-1 text-sm font-medium text-emerald-700">
                                Belum ada mutasi withdraw baru dari dashboard ini.
                            </p>
                        </div>
                    </div>

                    <div x-cloak x-show="activeTab === 'riwayat'">
                        <x-admin-dashboard.section-heading eyebrow="Riwayat" title="Aktivitas toko">
                            Log aktivitas admin dan perubahan penting bisa masuk di sini.
                        </x-admin-dashboard.section-heading>
                    </div>

                    <div x-cloak x-show="activeTab === 'affiliate'">
                        <x-admin-dashboard.section-heading eyebrow="Affiliate" title="Kanal affiliate">
                            Tempat awal untuk performa referral dan materi promosi reseller.
                        </x-admin-dashboard.section-heading>
                    </div>
                </x-admin-dashboard.card>

                <x-admin-dashboard.card as="section" class="min-w-0">
                    <x-admin-dashboard.section-heading title="Aksi berikutnya" />
                    <div class="mt-4 space-y-3">
                        <x-admin-dashboard.action-row :href="route('filament.admin.resources.products.index')">
                            Kelola produk
                        </x-admin-dashboard.action-row>
                        <x-admin-dashboard.action-row :href="route('filament.admin.resources.orders.index')">
                            Cek order
                        </x-admin-dashboard.action-row>
                    </div>
                </x-admin-dashboard.card>
            </section>
        </main>
    </body>
</html>
