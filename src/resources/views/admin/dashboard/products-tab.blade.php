            {{-- ╔═════════════════════════════════════════════════════╗
                 ║  PRODUK TAB (Katalog)                               ║
                 ╚═════════════════════════════════════════════════════╝ --}}
            <div id="admin-section-products" data-admin-return-target tabindex="-1" x-cloak x-show="activeTab === 'produk'" class="mt-4 space-y-4">

                <div class="flex items-end justify-between gap-3">
                    <div>
                        <h2 class="font-display text-lg font-bold uppercase tracking-wider text-neo-ink">📦 Produk Saya</h2>
                        <p class="mt-1 text-xs font-semibold text-brand-muted">Semua kelola produk dan stok tetap di dashboard ini</p>
                    </div>

                    @if ($canManageProducts)
                    <x-admin-dashboard.button
                        type="button"
                        variant="purple"
                        size="sm"
                        class="shrink-0 text-[11px]"
                        x-on:click="openProductForm()"
                    >
                        ➕ Tambah Produk
                    </x-admin-dashboard.button>
                    @endif
                </div>

                <x-admin-dashboard.notice class="px-4 py-3 text-sm">
                    <span class="font-black uppercase">Dipesan</span> berarti stok yang sedang ditahan untuk order aktif/pending. Kelola hanya menampilkan akun yang masih tersedia.
                </x-admin-dashboard.notice>

                <div class="overflow-x-auto pb-1" aria-label="Filter produk">
                    <div class="flex min-w-max items-center gap-2">
                        @foreach ($productFilters as $filter)
                            <a
                                href="{{ $filter['url'] }}"
                                data-products-filter="{{ $filter['key'] }}"
                                @if ($filter['active']) aria-current="page" @endif
                                class="filter-pill @if ($filter['active']) filter-pill-active @endif"
                            >
                                <span>{{ $filter['label'] }}</span>
                                <span class="filter-pill-count">{{ $filter['count'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>

                @if ($canManageProducts)
                <x-admin-dashboard.card id="admin-category-create" data-admin-return-target tabindex="-1" as="form" method="POST" action="{{ route('admin.categories.store') }}" tone="yellow" padding="compact" shadow="sm" class="rounded-[1rem]">
                    @csrf
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="font-display text-sm font-black uppercase text-neo-ink">Tambah kategori</p>
                            <p class="mt-1 text-[11px] font-bold text-neo-ink/60">Buat kategori cepat untuk produk baru.</p>
                        </div>
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_1.2fr_auto]">
                        <div>
                            <x-admin-dashboard.field-label for="category-name">Nama</x-admin-dashboard.field-label>
                            <x-admin-dashboard.input id="category-name" name="category_name" value="{{ old('category_name') }}" class="mt-1.5" placeholder="AI Tools" />
                            @error('category_name', 'categoryCreate')
                                <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                            @enderror
                        </div>
                        <div>
                            <x-admin-dashboard.field-label for="category-description">Deskripsi opsional</x-admin-dashboard.field-label>
                            <x-admin-dashboard.input id="category-description" name="category_description" value="{{ old('category_description') }}" class="mt-1.5" placeholder="Kategori akun premium" />
                            @error('category_description', 'categoryCreate')
                                <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                            @enderror
                        </div>
                        <x-admin-dashboard.button type="submit" variant="warning" size="sm" class="self-end text-[11px]">
                            + Kategori
                        </x-admin-dashboard.button>
                    </div>
                </x-admin-dashboard.card>
                @endif

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
