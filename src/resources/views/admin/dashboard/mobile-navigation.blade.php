            {{-- ═══════════════════════════════════════════════════════
                 BOTTOM TAB NAVIGATION — Dark (#050505) with 4 tabs
                 ═══════════════════════════════════════════════════════ --}}
            <nav
                class="bottom-nav mobile-bottom-nav admin-safe-nav fixed inset-x-0 bottom-0 z-40 border-t-2 border-neo-ink px-3 pt-2"
                aria-label="Navigasi dashboard"
            >
                <div class="mx-auto grid w-full max-w-md grid-cols-4 gap-1">
                    <button
                        type="button"
                        x-on:click="goToTab('stats')"
                        class="flex min-w-0 w-full flex-col items-center gap-1 rounded-2xl px-1.5 py-2 text-white/80 transition-all duration-200"
                        :class="activeTab === 'stats' ? 'bottom-nav-active' : ''"
                        x-bind:aria-current="activeTab === 'stats' ? 'page' : null"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0v-4a1 1 0 011-1h2a1 1 0 011 1v4" />
                        </svg>
                        <span class="truncate text-[10px] font-bold text-current">Ringkasan</span>
                    </button>

                    <button
                        type="button"
                        x-on:click="goToTab('penjualan')"
                        class="flex min-w-0 w-full flex-col items-center gap-1 rounded-2xl px-1.5 py-2 text-white/80 transition-all duration-200"
                        :class="activeTab === 'penjualan' ? 'bottom-nav-active' : ''"
                        x-bind:aria-current="activeTab === 'penjualan' ? 'page' : null"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <span class="truncate text-[10px] font-bold text-current">Pesanan</span>
                    </button>

                    <button
                        type="button"
                        x-on:click="goToTab('produk')"
                        class="flex min-w-0 w-full flex-col items-center gap-1 rounded-2xl px-1.5 py-2 text-white/80 transition-all duration-200"
                        :class="activeTab === 'produk' ? 'bottom-nav-active' : ''"
                        x-bind:aria-current="activeTab === 'produk' ? 'page' : null"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                        </svg>
                        <span class="truncate text-[10px] font-bold text-current">Produk</span>
                    </button>

                    <button
                        type="button"
                        x-on:click="openMoreMenu()"
                        class="flex min-w-0 w-full flex-col items-center gap-1 rounded-2xl px-1.5 py-2 text-white/80 transition-all duration-200"
                        :class="moreMenuOpen || ['broadcast', 'telegram-channel', 'riwayat', 'withdraw', 'funnel', 'balance-issues', 'suppliers'].includes(activeTab) ? 'bottom-nav-active' : ''"
                        x-bind:aria-expanded="moreMenuOpen"
                        aria-haspopup="dialog"
                        aria-controls="admin-more-menu"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h.01M12 12h.01M19 12h.01" />
                        </svg>
                        <span class="truncate text-[10px] font-bold text-current">Lainnya</span>
                    </button>
                </div>
            </nav>

            <div
                x-cloak
                x-show="moreMenuOpen"
                x-transition.opacity
                class="fixed inset-0 z-[60] flex items-end bg-neo-ink/60 p-3 admin-safe-bottom md:hidden"
                x-on:keydown.escape.window="if (moreMenuOpen) closeMoreMenu()"
            >
                <div class="absolute inset-0" x-on:click="closeMoreMenu()" aria-hidden="true"></div>

                <div
                    id="admin-more-menu"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="admin-more-menu-title"
                    tabindex="-1"
                    x-on:keydown.tab="trapModalFocus($event)"
                    x-on:focusin.window="if (moreMenuOpen) keepModalFocus($event, 'admin-more-menu')"
                    class="relative w-full max-w-md rounded-[1.25rem] border-[4px] border-neo-ink bg-neo-paper p-4 text-neo-ink shadow-neo-xl"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-display text-[10px] font-black uppercase tracking-[0.18em] text-brand-muted">Utilitas dashboard</p>
                            <h2 id="admin-more-menu-title" class="mt-1 font-display text-xl font-black uppercase tracking-tight">Menu lainnya</h2>
                        </div>
                        <button
                            type="button"
                            class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-[0.45rem] border-[3px] border-neo-ink bg-white text-lg font-black shadow-neo-sm"
                            aria-label="Tutup menu"
                            x-on:click="closeMoreMenu()"
                        >×</button>
                    </div>

                    <div class="mt-4 grid gap-2">
                        <button
                            type="button"
                            x-ref="moreMenuFirst"
                            class="admin-dashboard-action min-h-11 rounded-neo-2xl border-[3px] border-neo-ink bg-white px-3 py-2 text-left text-xs font-black uppercase tracking-wide shadow-neo-sm"
                            x-on:click="goToTabFromMore('broadcast')"
                        >
                            <span class="mr-2" aria-hidden="true">📢</span> Siaran
                        </button>
                        @if ($canManageSuppliers)
                            <button type="button" class="admin-dashboard-action min-h-11 rounded-neo-2xl border-[3px] border-neo-ink bg-white px-3 py-2 text-left text-xs font-black uppercase tracking-wide shadow-neo-sm" x-on:click="goToTabFromMore('suppliers')">Supplier</button>
                        @endif
                        <button
                            type="button"
                            class="admin-dashboard-action min-h-11 rounded-neo-2xl border-[3px] border-neo-ink bg-white px-3 py-2 text-left text-xs font-black uppercase tracking-wide shadow-neo-sm"
                            x-on:click="goToTabFromMore('telegram-channel')"
                        >
                            <span class="mr-2" aria-hidden="true">💬</span> Store Channel
                        </button>
                        <button
                            type="button"
                            class="admin-dashboard-action min-h-11 rounded-neo-2xl border-[3px] border-neo-ink bg-white px-3 py-2 text-left text-xs font-black uppercase tracking-wide shadow-neo-sm"
                            x-on:click="goToTabFromMore('riwayat')"
                        >
                            Riwayat KlikQRIS
                        </button>
                        @if ($canManageBalanceIssues)
                            <button
                                type="button"
                                class="admin-dashboard-action min-h-11 rounded-neo-2xl border-[3px] border-neo-ink bg-neo-yellow px-3 py-2 text-left text-xs font-black uppercase tracking-wide shadow-neo-sm"
                                x-on:click="goToTabFromMore('balance-issues')"
                            >
                                Kompensasi top-up
                            </button>
                        @endif
                        @if ($canViewFunnel)
                            <button
                                type="button"
                                class="admin-dashboard-action min-h-11 rounded-neo-2xl border-[3px] border-neo-ink bg-white px-3 py-2 text-left text-xs font-black uppercase tracking-wide shadow-neo-sm"
                                x-on:click="goToTabFromMore('funnel')"
                            >
                                Funnel storefront
                            </button>
                        @endif
                        @if ($canViewWithdraw)
                            <button
                                type="button"
                                class="admin-dashboard-action min-h-11 rounded-neo-2xl border-[3px] border-neo-ink bg-white px-3 py-2 text-left text-xs font-black uppercase tracking-wide shadow-neo-sm"
                                x-on:click="goToTabFromMore('withdraw')"
                            >
                                Withdraw
                            </button>
                        @endif
                        @if ($canManageProducts)
                            <button
                                type="button"
                                class="admin-dashboard-action min-h-11 rounded-neo-2xl border-[3px] border-neo-ink bg-neo-yellow px-3 py-2 text-left text-xs font-black uppercase tracking-wide shadow-neo-sm"
                                x-on:click="goToTabFromMore('produk', true)"
                            >
                                <span class="mr-2" aria-hidden="true">➕</span> Tambah Produk
                            </button>
                        @endif
                    </div>
                </div>
            </div>
