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
