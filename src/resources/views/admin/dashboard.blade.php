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
            <header class="rounded-lg border border-slate-200 bg-white px-4 py-4 shadow-sm shadow-slate-200/70 sm:px-5">
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
                        <x-admin-dashboard.action-link :href="$actions['shop']">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M4.5 7.5h15l-.75 12.75H5.25L4.5 7.5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                                <path d="M8 7.5a4 4 0 0 1 8 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                            </svg>
                            Belanja Produk Lain
                        </x-admin-dashboard.action-link>

                        <button
                            type="button"
                            class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-4 text-sm font-semibold text-brand-navy transition hover:border-slate-300 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-300 focus:ring-offset-2"
                            x-on:click="navigator.clipboard?.writeText('{{ $actions['share'] }}'); copied = true; setTimeout(() => copied = false, 1600)"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M8.5 12.5 15.5 16M15.5 8 8.5 11.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                <circle cx="6" cy="12.5" r="2.5" stroke="currentColor" stroke-width="1.8"/>
                                <circle cx="18" cy="7" r="2.5" stroke="currentColor" stroke-width="1.8"/>
                                <circle cx="18" cy="17" r="2.5" stroke="currentColor" stroke-width="1.8"/>
                            </svg>
                            <span x-text="copied ? 'Link Disalin' : 'Bagikan Toko'">Bagikan Toko</span>
                        </button>
                    </div>
                </div>

                <section class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Ringkasan toko">
                    @foreach ($summary as $metric)
                        <x-admin-dashboard.stat-card
                            :label="$metric['label']"
                            :value="$metric['value']"
                            :hint="$metric['hint']"
                            :tone="$metric['tone']"
                        />
                    @endforeach
                </section>
            </header>

            <nav class="mt-4 overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm shadow-slate-200/70" aria-label="Navigasi dashboard">
                <div class="flex min-w-max items-center gap-1 p-1">
                    @foreach ($tabs as $tab)
                        <button
                            type="button"
                            class="rounded-md px-3 py-2 text-sm font-bold transition"
                            x-bind:class="activeTab === '{{ $tab['id'] }}' ? 'bg-brand-navy text-white' : 'text-slate-600 hover:bg-slate-100 hover:text-brand-navy'"
                            x-on:click="activeTab = '{{ $tab['id'] }}'"
                        >
                            {{ $tab['label'] }}
                        </button>
                    @endforeach
                </div>
            </nav>

            <section class="mt-4 grid flex-1 gap-4 lg:grid-cols-[1.25fr_0.75fr]">
                <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm shadow-slate-200/70">
                    <div x-show="activeTab === 'stats'">
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Stats</p>
                        <h2 class="mt-2 font-display text-xl font-bold text-brand-navy">Ringkasan operasional hari ini</h2>
                        <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                            Pantau saldo, GMV, produk aktif, dan order yang perlu diproses dari satu permukaan ringkas.
                        </p>
                    </div>

                    <div x-cloak x-show="activeTab === 'produk'">
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Produk</p>
                        <h2 class="mt-2 font-display text-xl font-bold text-brand-navy">Katalog siap dikelola</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-600">Gunakan tab ini untuk daftar produk dan stok pada slice berikutnya.</p>
                    </div>

                    <div x-cloak x-show="activeTab === 'penjualan'">
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Penjualan</p>
                        <h2 class="mt-2 font-display text-xl font-bold text-brand-navy">Order masuk dan diproses</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-600">Ruang untuk status order, pembayaran, dan aksi operasional cepat.</p>
                    </div>

                    <div x-cloak x-show="activeTab === 'tambah'">
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Tambah</p>
                        <h2 class="mt-2 font-display text-xl font-bold text-brand-navy">Tambah produk atau penawaran</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-600">Aksi tambah akan dihubungkan ke workflow katalog berikutnya.</p>
                    </div>

                    <div x-cloak x-show="activeTab === 'withdraw'">
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Withdraw</p>
                        <h2 class="mt-2 font-display text-xl font-bold text-brand-navy">Saldo dan penarikan</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-600">Area ini disiapkan untuk ringkasan saldo dan riwayat penarikan.</p>
                    </div>

                    <div x-cloak x-show="activeTab === 'riwayat'">
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Riwayat</p>
                        <h2 class="mt-2 font-display text-xl font-bold text-brand-navy">Aktivitas toko</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-600">Log aktivitas admin dan perubahan penting bisa masuk di sini.</p>
                    </div>

                    <div x-cloak x-show="activeTab === 'affiliate'">
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Affiliate</p>
                        <h2 class="mt-2 font-display text-xl font-bold text-brand-navy">Kanal affiliate</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-600">Tempat awal untuk performa referral dan materi promosi reseller.</p>
                    </div>
                </div>

                <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm shadow-slate-200/70">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Aksi berikutnya</p>
                    <div class="mt-4 space-y-3">
                        <a class="flex items-center justify-between rounded-lg border border-slate-200 p-3 text-sm font-semibold text-brand-navy hover:bg-slate-50" href="{{ route('filament.admin.resources.products.index') }}">
                            Kelola produk
                            <span aria-hidden="true">-&gt;</span>
                        </a>
                        <a class="flex items-center justify-between rounded-lg border border-slate-200 p-3 text-sm font-semibold text-brand-navy hover:bg-slate-50" href="{{ route('filament.admin.resources.orders.index') }}">
                            Cek order
                            <span aria-hidden="true">-&gt;</span>
                        </a>
                    </div>
                </section>
            </section>
        </main>
    </body>
</html>
