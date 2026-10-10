            {{-- ╔═════════════════════════════════════════════════════╗
                 ║  PRODUK TAB (Katalog)                               ║
                 ╚═════════════════════════════════════════════════════╝ --}}
            <div id="admin-section-products" data-admin-return-target tabindex="-1" class="mt-4 space-y-5">

                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div class="min-w-0">
                        <p class="text-xs font-medium text-admin-muted">Katalog</p>
                        <h2 class="mt-1 text-2xl font-semibold tracking-tight text-admin-ink">Produk Saya</h2>
                        <p class="mt-1 text-sm leading-6 text-admin-muted">Semua kelola produk dan stok tetap di dashboard ini</p>
                    </div>

                    @if ($canManageProducts)
                    <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:flex-wrap sm:justify-end">
                        <x-admin-dashboard.button
                            type="button"
                            variant="neutral"
                            size="sm"
                            class="w-full sm:w-auto"
                            x-on:click="openProductFamilyForm()"
                        >
                            Kelola Product Family
                        </x-admin-dashboard.button>
                        <x-admin-dashboard.button
                            type="button"
                            variant="neutral"
                            size="sm"
                            class="w-full sm:w-auto"
                            x-on:click="openCategoryManagement()"
                        >
                            Kelola Kategori
                        </x-admin-dashboard.button>
                        <x-admin-dashboard.button
                            type="button"
                            variant="primary"
                            size="sm"
                            class="w-full sm:w-auto"
                            x-on:click="openProductForm()"
                        >
                            Tambah Produk
                        </x-admin-dashboard.button>
                    </div>
                    @endif
                </div>

                <x-admin-dashboard.notice class="px-4 py-3 text-sm">
                    <span class="font-semibold">Dipesan</span> berarti stok yang sedang ditahan untuk order aktif/pending. Kelola hanya menampilkan akun yang masih tersedia.
                </x-admin-dashboard.notice>

                @include('admin.dashboard.storefront-banners')

                <x-admin-dashboard.card as="section" id="admin-product-placeholder" data-admin-return-target tabindex="-1" padding="compact" shadow="sm" aria-labelledby="admin-product-placeholder-title">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <h3 id="admin-product-placeholder-title" class="text-lg font-semibold text-admin-ink">Gambar default katalog</h3>
                            <p class="mt-1 max-w-2xl text-sm leading-6 text-admin-muted">Gambar ini tampil otomatis pada produk yang belum memiliki gambar khusus. Atur sekali di sini agar katalog tetap terlihat rapi.</p>
                            <x-admin-dashboard.badge :tone="$productPlaceholder['is_custom'] ? 'emerald' : 'amber'" class="mt-3">
                                {{ $productPlaceholder['is_custom'] ? 'Default custom aktif' : 'Default bawaan aktif' }}
                            </x-admin-dashboard.badge>
                        </div>
                        <div class="h-20 w-20 shrink-0 overflow-hidden rounded-lg border border-admin-line bg-admin-surface">
                            <img src="{{ $productPlaceholder['image_url'] }}" alt="Preview gambar default katalog" class="h-full w-full object-cover" loading="lazy" decoding="async">
                        </div>
                    </div>

                    @if ($canManageProducts)
                        <form method="POST" action="{{ $productPlaceholder['update_url'] }}" enctype="multipart/form-data" x-data="{ fileName: 'Belum ada file yang dipilih' }" class="mt-4 flex flex-col gap-3 rounded-lg border border-dashed border-admin-line bg-admin-surface-muted p-3 sm:flex-row sm:items-end">
                            @csrf
                            <div class="min-w-0 flex-1">
                                <x-admin-dashboard.field-label for="product-placeholder-image">Ganti gambar default</x-admin-dashboard.field-label>
                                <x-admin-dashboard.file-upload
                                    id="product-placeholder-image"
                                    name="image"
                                    label="Pilih gambar"
                                    required
                                    feedback-expression="fileName"
                                    empty-feedback="Belum ada file yang dipilih"
                                    x-on:change="fileName = $event.target.files[0]?.name ?? 'Belum ada file yang dipilih'"
                                />
                                <p class="mt-2 text-xs text-admin-muted">JPG, PNG, atau WebP. Maksimal 5 MB; otomatis dikompresi menjadi WebP maksimal 1600px.</p>
                            </div>
                            <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="w-full shrink-0 sm:w-auto">Simpan default</x-admin-dashboard.button>
                        </form>
                        @error('image', 'productPlaceholder')
                            <x-admin-dashboard.field-error class="mt-2">{{ $message }}</x-admin-dashboard.field-error>
                        @enderror
                    @else
                        <x-admin-dashboard.notice tone="neutral" class="mt-4 text-xs">Role ini hanya bisa melihat gambar default. Owner/Admin dapat menggantinya dari sini.</x-admin-dashboard.notice>
                    @endif
                </x-admin-dashboard.card>

                <section id="admin-product-families" class="space-y-4" aria-labelledby="admin-product-families-title">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div class="min-w-0">
                            <h3 id="admin-product-families-title" class="text-lg font-semibold text-admin-ink">Daftar Product Family</h3>
                            <p class="mt-1 text-sm leading-6 text-admin-muted">Semua Product ditampilkan sebagai satu family agar daftar tetap ringkas. Family bisa berisi satu atau beberapa Product.</p>
                        </div>
                        <x-admin-dashboard.badge tone="purple">{{ count($productGroups) }} family di halaman ini</x-admin-dashboard.badge>
                    </div>

                    @forelse ($productGroups as $group)
                        <x-admin-dashboard.card as="article" id="admin-product-family-group-{{ $group['id'] }}" padding="compact" shadow="sm">
                            <div class="contents" x-data="{ familyExpanded: false, familyEditPayload: @js($group['edit_payload']) }">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h4 class="break-words text-lg font-semibold text-admin-ink">{{ $group['name'] }}</h4>
                                        <x-admin-dashboard.badge>{{ $group['category'] }}</x-admin-dashboard.badge>
                                    </div>
                                    <p class="mt-1 text-xs text-admin-muted">{{ $group['member_count'] }} Product · {{ $group['available_member_count'] }} tersedia · {{ $group['sold_out_member_count'] }} habis</p>
                                    <p class="mt-1 text-sm font-semibold text-admin-ink">{{ $group['minimum_price'] ?? 'Harga belum diatur' }}{{ $group['minimum_usdt_price'] !== null ? ' · USDT '.$group['minimum_usdt_price'] : '' }}</p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                                    <x-admin-dashboard.button type="button" variant="neutral" size="sm" aria-controls="admin-product-family-members-{{ $group['id'] }}" x-bind:aria-expanded="familyExpanded.toString()" x-on:click="familyExpanded = ! familyExpanded">
                                        <span x-text="familyExpanded ? 'Tutup daftar product' : 'Lihat product'">Lihat product</span>
                                    </x-admin-dashboard.button>
                                    @if ($canManageProducts && ! ($group['is_single_product_group'] ?? false))
                                        <x-admin-dashboard.button type="button" variant="warning" size="sm" x-on:click="focusProductFamilyAssignment({{ $group['id'] }})">+ Tambah Product</x-admin-dashboard.button>
                                        <x-admin-dashboard.button type="button" variant="secondary" size="sm" x-on:click="openProductFamilyEditModal(familyEditPayload)">Edit family</x-admin-dashboard.button>
                                    @endif
                                </div>
                            </div>
                            <div id="admin-product-family-members-{{ $group['id'] }}" x-cloak x-show="familyExpanded" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                @forelse ($group['members'] as $product)
                                    @include('admin.dashboard.product-card')
                                @empty
                                    <x-admin-dashboard.notice tone="neutral" class="text-xs">Belum ada Product dalam family ini. Tambahkan Product melalui form grouping.</x-admin-dashboard.notice>
                                @endforelse
                            </div>
                            </div>
                        </x-admin-dashboard.card>
                    @empty
                        <x-admin-dashboard.empty-state title="Belum ada Product di halaman ini." />
                    @endforelse

                @if ($productsPagination['lastPage'] > 1)
                    <nav aria-label="Halaman produk" class="flex flex-col items-center gap-3 rounded-[var(--admin-radius)] border border-admin-line bg-admin-surface px-3 py-3 sm:flex-row sm:justify-between">
                        <p class="text-xs font-medium text-admin-muted">
                            Halaman {{ $productsPagination['currentPage'] }} dari {{ $productsPagination['lastPage'] }}
                        </p>
                        <div class="flex flex-wrap items-center justify-center gap-2">
                            @if ($productsPagination['previousUrl'])
                                <x-admin-dashboard.button :href="$productsPagination['previousUrl']" variant="neutral" size="sm">← Sebelumnya</x-admin-dashboard.button>
                            @else
                                <span class="inline-flex min-h-11 items-center rounded-lg border border-admin-line bg-admin-surface-muted px-3 text-sm font-medium text-admin-muted">← Sebelumnya</span>
                            @endif

                            @foreach ($productsPagination['pages'] as $page)
                                <a
                                    href="{{ $page['url'] }}"
                                    @if ($page['current']) aria-current="page" @endif
                                    @class([
                                        'inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg border border-admin-line px-3 text-sm font-medium',
                                        'bg-admin-accent-soft text-admin-accent-strong' => $page['current'],
                                        'bg-admin-surface text-admin-ink' => ! $page['current'],
                                    ])
                                >{{ $page['number'] }}</a>
                            @endforeach

                            @if ($productsPagination['nextUrl'])
                                <x-admin-dashboard.button :href="$productsPagination['nextUrl']" variant="neutral" size="sm">Berikutnya →</x-admin-dashboard.button>
                            @else
                                <span class="inline-flex min-h-11 items-center rounded-lg border border-admin-line bg-admin-surface-muted px-3 text-sm font-medium text-admin-muted">Berikutnya →</span>
                            @endif
                        </div>
                    </nav>
                @endif
                </section>

                @include('admin.dashboard.stock-modals')
            </div>
