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

                @include('admin.dashboard.stock-modals')
            </div>
