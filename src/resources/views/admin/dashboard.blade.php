<!DOCTYPE html>
<html lang="id" class="h-full bg-brand-bg">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Admin LHIE STORE</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-full bg-brand-bg font-lhie text-brand-navy antialiased">
        <main
            class="mx-auto flex min-h-screen w-full max-w-6xl flex-col px-4 py-4 sm:px-6 lg:px-8"
            x-data="{ activeTab: 'stats', copied: false }"
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
                            x-on:click="navigator.clipboard?.writeText('{{ $actions['share'] }}'); copied = true; setTimeout(() => copied = false, 1600)"
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
                <x-admin-dashboard.card>
                    <div x-show="activeTab === 'stats'">
                        <x-admin-dashboard.section-heading eyebrow="Stats" title="Ringkasan operasional hari ini" class="max-w-2xl">
                            Pantau saldo, GMV, produk aktif, dan order yang perlu diproses dari satu permukaan ringkas.
                        </x-admin-dashboard.section-heading>

                        <div class="mt-5 grid gap-5 xl:grid-cols-[1fr_0.85fr]">
                            <section aria-label="Pendapatan 7 hari">
                                <div class="flex items-center justify-between gap-3">
                                    <h2 class="text-sm font-bold text-brand-navy">Pendapatan 7 hari</h2>
                                    <span class="text-xs font-semibold text-slate-500">Pembayaran paid</span>
                                </div>
                                <div class="mt-3 space-y-3">
                                    @foreach ($earnings as $earning)
                                        <div class="grid grid-cols-[3.75rem_1fr_7.5rem] items-center gap-3 text-sm">
                                            <span class="font-semibold text-slate-500">{{ $earning['label'] }}</span>
                                            <div class="h-2 rounded-full bg-slate-100">
                                                <div class="h-2 rounded-full bg-brand-electric" style="width: {{ $earning['bar_percent'] }}%"></div>
                                            </div>
                                            <span class="text-right font-bold text-brand-navy">{{ $earning['amount_formatted'] }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </section>

                            <section aria-label="Status order">
                                <h2 class="text-sm font-bold text-brand-navy">Status order</h2>
                                <div class="mt-3 grid grid-cols-2 gap-2">
                                    @foreach ($statusCounts as $status)
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

                        <div class="mt-5 divide-y divide-slate-100">
                            @forelse ($products as $product)
                                <div class="flex items-center justify-between gap-4 py-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="truncate text-sm font-bold text-brand-navy">{{ $product['name'] }}</p>
                                            <x-admin-dashboard.badge :tone="$product['is_active'] ? 'emerald' : 'slate'">
                                                {{ $product['status_label'] }}
                                            </x-admin-dashboard.badge>
                                        </div>
                                        <p class="mt-1 text-xs font-medium text-slate-500">
                                            {{ $product['category'] }} &middot; Stok tersedia {{ $product['available_stock'] }}
                                        </p>
                                    </div>
                                    <p class="shrink-0 text-sm font-bold text-brand-navy">{{ $product['price'] }}</p>
                                </div>
                            @empty
                                <p class="rounded-lg border border-dashed border-slate-200 bg-slate-50 p-4 text-sm font-medium text-slate-500">
                                    Belum ada produk.
                                </p>
                            @endforelse
                        </div>
                    </div>

                    <div x-cloak x-show="activeTab === 'penjualan'">
                        <x-admin-dashboard.section-heading eyebrow="Penjualan" title="Order masuk dan diproses">
                            Order terbaru dengan status order, status pembayaran, dan total transaksi.
                        </x-admin-dashboard.section-heading>

                        <div class="mt-5 divide-y divide-slate-100">
                            @forelse ($orders as $order)
                                <div class="grid gap-3 py-3 md:grid-cols-[1fr_auto] md:items-center">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="font-bold text-brand-navy">{{ $order['invoice_number'] }}</p>
                                            <x-admin-dashboard.badge :tone="$order['order_status_tone']">{{ $order['order_status_label'] }}</x-admin-dashboard.badge>
                                            <x-admin-dashboard.badge tone="blue">{{ $order['payment_status_label'] }}</x-admin-dashboard.badge>
                                        </div>
                                        <p class="mt-1 truncate text-xs font-medium text-slate-500">
                                            {{ $order['customer'] }} &middot; {{ $order['item_count'] }} item
                                        </p>
                                    </div>
                                    <p class="text-sm font-bold text-brand-navy md:text-right">{{ $order['total'] }}</p>
                                </div>
                            @empty
                                <p class="rounded-lg border border-dashed border-slate-200 bg-slate-50 p-4 text-sm font-medium text-slate-500">
                                    Belum ada order.
                                </p>
                            @endforelse
                        </div>
                    </div>

                    <div x-cloak x-show="activeTab === 'tambah'">
                        <x-admin-dashboard.section-heading eyebrow="Tambah" title="Tambah produk atau penawaran">
                            Aksi tambah akan dihubungkan ke workflow katalog berikutnya.
                        </x-admin-dashboard.section-heading>
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

                <x-admin-dashboard.card as="section">
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
