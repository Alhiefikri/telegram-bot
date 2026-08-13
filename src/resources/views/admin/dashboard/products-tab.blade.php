            {{-- ╔═════════════════════════════════════════════════════╗
                 ║  PRODUK TAB (Katalog)                               ║
                 ╚═════════════════════════════════════════════════════╝ --}}
            <div id="admin-section-products" data-admin-return-target tabindex="-1" x-cloak x-show="activeTab === 'produk'" class="mt-4 space-y-4">

                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div class="min-w-0">
                        <h2 class="font-display text-lg font-bold uppercase tracking-wider text-neo-ink">📦 Produk Saya</h2>
                        <p class="mt-1 text-xs font-semibold text-brand-muted">Semua kelola produk dan stok tetap di dashboard ini</p>
                    </div>

                    @if ($canManageProducts)
                    <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:flex-wrap sm:justify-end">
                        <x-admin-dashboard.button
                            type="button"
                            variant="warning"
                            size="sm"
                            class="w-full text-[11px] sm:w-auto"
                            x-on:click="openCategoryManagement()"
                        >
                            ⚙️ Kelola Kategori
                        </x-admin-dashboard.button>
                        <x-admin-dashboard.button
                            type="button"
                            variant="purple"
                            size="sm"
                            class="w-full text-[11px] sm:w-auto"
                            x-on:click="openProductForm()"
                        >
                            ➕ Tambah Produk
                        </x-admin-dashboard.button>
                    </div>
                    @endif
                </div>

                <x-admin-dashboard.notice class="px-4 py-3 text-sm">
                    <span class="font-black uppercase">Dipesan</span> berarti stok yang sedang ditahan untuk order aktif/pending. Kelola hanya menampilkan akun yang masih tersedia.
                </x-admin-dashboard.notice>

                <div class="grid gap-4 md:grid-cols-2">
                    @forelse ($products as $product)

                        @include('admin.dashboard.product-card')
                    @empty
                        <x-admin-dashboard.empty-state title="Belum ada produk." />
                    @endforelse
                </div>

                @if ($productsPagination['lastPage'] > 1)
                    <nav aria-label="Halaman produk" class="flex flex-col items-center gap-2 rounded-[1rem] border-2 border-neo-ink bg-white/70 px-3 py-3 shadow-[3px_3px_0_#111827] sm:flex-row sm:justify-between">
                        <p class="text-xs font-black uppercase text-neo-ink/70">
                            Halaman {{ $productsPagination['currentPage'] }} dari {{ $productsPagination['lastPage'] }}
                        </p>
                        <div class="flex flex-wrap items-center justify-center gap-2">
                            @if ($productsPagination['previousUrl'])
                                <a href="{{ $productsPagination['previousUrl'] }}" class="rounded-lg border-2 border-neo-ink bg-white px-3 py-2 text-xs font-black uppercase text-neo-ink">← Sebelumnya</a>
                            @else
                                <span class="rounded-lg border-2 border-neo-ink/20 bg-white/40 px-3 py-2 text-xs font-black uppercase text-neo-ink/35">← Sebelumnya</span>
                            @endif

                            @foreach ($productsPagination['pages'] as $page)
                                <a
                                    href="{{ $page['url'] }}"
                                    @if ($page['current']) aria-current="page" @endif
                                    @class([
                                        'min-w-10 rounded-lg border-2 border-neo-ink px-3 py-2 text-center text-xs font-black',
                                        'bg-neo-yellow text-neo-ink' => $page['current'],
                                        'bg-white text-neo-ink' => ! $page['current'],
                                    ])
                                >{{ $page['number'] }}</a>
                            @endforeach

                            @if ($productsPagination['nextUrl'])
                                <a href="{{ $productsPagination['nextUrl'] }}" class="rounded-lg border-2 border-neo-ink bg-white px-3 py-2 text-xs font-black uppercase text-neo-ink">Berikutnya →</a>
                            @else
                                <span class="rounded-lg border-2 border-neo-ink/20 bg-white/40 px-3 py-2 text-xs font-black uppercase text-neo-ink/35">Berikutnya →</span>
                            @endif
                        </div>
                    </nav>
                @endif

                @include('admin.dashboard.stock-modals')
            </div>
