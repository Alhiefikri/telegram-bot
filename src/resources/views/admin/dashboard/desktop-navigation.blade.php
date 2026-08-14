            <nav class="desktop-tab-nav mt-4" aria-label="Navigasi desktop">
                <x-admin-dashboard.button
                    type="button"
                    variant="neutral"
                    size="sm"
                    x-on:click="goToTab('stats')"
                    class="py-3"
                    x-bind:class="activeTab === 'stats' ? 'bottom-nav-active' : ''"
                    x-bind:aria-current="activeTab === 'stats' ? 'page' : null"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                    </svg>
                    <span class="text-[11px] font-black uppercase tracking-wide">Ringkasan</span>
                </x-admin-dashboard.button>

                <x-admin-dashboard.button
                    type="button"
                    variant="neutral"
                    size="sm"
                    x-on:click="goToTab('produk')"
                    class="py-3"
                    x-bind:class="activeTab === 'produk' ? 'bottom-nav-active' : ''"
                    x-bind:aria-current="activeTab === 'produk' ? 'page' : null"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                    </svg>
                    <span class="text-[11px] font-black uppercase tracking-wide">Produk</span>
                </x-admin-dashboard.button>

                <x-admin-dashboard.button
                    type="button"
                    variant="neutral"
                    size="sm"
                    x-on:click="goToTab('penjualan')"
                    class="py-3"
                    x-bind:class="activeTab === 'penjualan' ? 'bottom-nav-active' : ''"
                    x-bind:aria-current="activeTab === 'penjualan' ? 'page' : null"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    <span class="text-[11px] font-black uppercase tracking-wide">Pesanan</span>
                </x-admin-dashboard.button>

                <x-admin-dashboard.button
                    type="button"
                    variant="neutral"
                    size="sm"
                    x-on:click="goToTab('broadcast')"
                    class="py-3"
                    x-bind:class="activeTab === 'broadcast' ? 'bottom-nav-active' : ''"
                    x-bind:aria-current="activeTab === 'broadcast' ? 'page' : null"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5 6 9H3v6h3l5 4V5Zm4.5 4.5a4 4 0 0 1 0 5m2.5-8a8 8 0 0 1 0 10" />
                    </svg>
                    <span class="text-[11px] font-black uppercase tracking-wide">Siaran</span>
                </x-admin-dashboard.button>

                <x-admin-dashboard.button
                    type="button"
                    variant="neutral"
                    size="sm"
                    x-on:click="goToTab('telegram-channel')"
                    class="py-3"
                    x-bind:class="activeTab === 'telegram-channel' ? 'bottom-nav-active' : ''"
                    x-bind:aria-current="activeTab === 'telegram-channel' ? 'page' : null"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 4v-4z" />
                    </svg>
                    <span class="text-[11px] font-black uppercase tracking-wide">Channel</span>
                </x-admin-dashboard.button>
                <x-admin-dashboard.button
                    type="button"
                    variant="neutral"
                    size="sm"
                    x-on:click="goToTab('riwayat')"
                    class="py-3"
                    x-bind:class="activeTab === 'riwayat' ? 'bottom-nav-active' : ''"
                    x-bind:aria-current="activeTab === 'riwayat' ? 'page' : null"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0Z" />
                    </svg>
                    <span class="text-[11px] font-black uppercase tracking-wide">Riwayat</span>
                </x-admin-dashboard.button>

                @if ($canViewWithdraw)
                <x-admin-dashboard.button
                    type="button"
                    variant="neutral"
                    size="sm"
                    x-on:click="goToTab('withdraw')"
                    class="py-3"
                    x-bind:class="activeTab === 'withdraw' ? 'bottom-nav-active' : ''"
                    x-bind:aria-current="activeTab === 'withdraw' ? 'page' : null"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h2m-6 4h18a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2Z" />
                    </svg>
                    <span class="text-[11px] font-black uppercase tracking-wide">Withdraw</span>
                </x-admin-dashboard.button>
                @endif

                @if ($canManageProducts)
                <x-admin-dashboard.button
                    type="button"
                    variant="neutral"
                    size="sm"
                    x-on:click="goToTab('produk', true)"
                    class="py-3"
                    x-bind:class="productModalOpen && productModalMode === 'create' ? 'bottom-nav-active' : ''"
                    x-bind:aria-current="productModalOpen && productModalMode === 'create' ? 'page' : null"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span class="text-[11px] font-black uppercase tracking-wide">Tambah</span>
                </x-admin-dashboard.button>
                @endif
            </nav>
