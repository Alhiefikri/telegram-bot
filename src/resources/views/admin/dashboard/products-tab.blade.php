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
                            variant="secondary"
                            size="sm"
                            class="w-full text-[11px] sm:w-auto"
                            x-on:click="openProductFamilyForm()"
                        >
                            🧩 Kelola Product Family
                        </x-admin-dashboard.button>
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

                @include('admin.dashboard.storefront-banners')

                <section id="admin-product-placeholder" class="rounded-[1.125rem] border-[3px] border-neo-ink bg-white/80 p-4 shadow-[4px_4px_0_#111827] sm:p-5" aria-labelledby="admin-product-placeholder-title">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <h3 id="admin-product-placeholder-title" class="font-display text-base font-black uppercase tracking-wide text-neo-ink">🖼️ Gambar default katalog</h3>
                            <p class="mt-1 max-w-2xl text-xs font-semibold leading-5 text-brand-muted">Gambar ini tampil otomatis pada produk yang belum memiliki gambar khusus. Atur sekali di sini agar katalog tetap terlihat rapi.</p>
                            <span @class([
                                'mt-3 inline-flex rounded-full border-2 border-neo-ink px-2.5 py-1 text-[10px] font-black uppercase tracking-wide shadow-[2px_2px_0_#111827]',
                                'bg-neo-green text-neo-ink' => $productPlaceholder['is_custom'],
                                'bg-neo-yellow text-neo-ink' => ! $productPlaceholder['is_custom'],
                            ])>
                                {{ $productPlaceholder['is_custom'] ? 'Default custom aktif' : 'Default bawaan aktif' }}
                            </span>
                        </div>
                        <div class="h-20 w-20 shrink-0 overflow-hidden rounded-xl border-[3px] border-neo-ink bg-white shadow-[3px_3px_0_#050505]">
                            <img src="{{ $productPlaceholder['image_url'] }}" alt="Preview gambar default katalog" class="h-full w-full object-cover" loading="lazy" decoding="async">
                        </div>
                    </div>

                    @if ($canManageProducts)
                        <form method="POST" action="{{ $productPlaceholder['update_url'] }}" enctype="multipart/form-data" x-data="{ fileName: 'Belum ada file yang dipilih' }" class="mt-4 flex flex-col gap-3 rounded-xl border-2 border-dashed border-neo-ink/40 bg-neo-admin-paper/60 p-3 sm:flex-row sm:items-end">
                            @csrf
                            <div class="min-w-0 flex-1">
                                <label for="product-placeholder-image" class="text-[11px] font-black uppercase tracking-wide text-neo-ink">Ganti gambar default</label>
                                <div class="mt-2 flex min-w-0 items-center gap-3 rounded-lg border-2 border-neo-ink bg-white p-2 shadow-[2px_2px_0_#050505]">
                                    <label for="product-placeholder-image" class="shrink-0 cursor-pointer rounded-md bg-neo-ink px-3 py-2 text-xs font-black text-white transition hover:bg-[#8758de]">Pilih gambar</label>
                                    <span class="min-w-0 flex-1 truncate text-xs font-black text-neo-ink" x-text="fileName">Belum ada file yang dipilih</span>
                                </div>
                                <input type="file" id="product-placeholder-image" name="image" accept="image/jpeg,image/png,image/webp" required class="sr-only" x-on:change="fileName = $event.target.files[0]?.name ?? 'Belum ada file yang dipilih'">
                                <p class="mt-2 text-[10px] font-bold text-neo-ink/60">JPG, PNG, atau WebP. Maksimal 5 MB; otomatis dikompresi menjadi WebP maksimal 1600px.</p>
                            </div>
                            <x-admin-dashboard.button type="submit" variant="purple" size="sm" class="w-full shrink-0 sm:w-auto">Simpan default</x-admin-dashboard.button>
                        </form>
                        @error('image', 'productPlaceholder')
                            <x-admin-dashboard.field-error class="mt-2">{{ $message }}</x-admin-dashboard.field-error>
                        @enderror
                    @else
                        <x-admin-dashboard.notice tone="neutral" class="mt-4 text-xs">Role ini hanya bisa melihat gambar default. Owner/Admin dapat menggantinya dari sini.</x-admin-dashboard.notice>
                    @endif
                </section>

                <section id="admin-product-families" class="space-y-4" aria-labelledby="admin-product-families-title">
                    <div class="flex items-end justify-between gap-3">
                        <div>
                            <h3 id="admin-product-families-title" class="font-display text-base font-black uppercase text-neo-ink">Daftar Product Family</h3>
                            <p class="mt-1 text-xs font-bold text-brand-muted">Semua Product ditampilkan sebagai satu family agar daftar tetap ringkas. Family bisa berisi satu atau beberapa Product.</p>
                        </div>
                        <span class="rounded-full border-2 border-neo-ink bg-neo-purple px-2 py-1 text-[10px] font-black uppercase">{{ count($productGroups) }} family di halaman ini</span>
                    </div>

                    @forelse ($productGroups as $group)
                        <article id="admin-product-family-group-{{ $group['id'] }}" x-data="{ familyExpanded: false, familyEditPayload: @js($group['edit_payload']) }" class="rounded-[1.125rem] border-[3px] border-neo-ink bg-[#efe8ff] p-3 shadow-[4px_4px_0_#111827] sm:p-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h4 class="break-words font-display text-lg font-black text-neo-ink">{{ $group['name'] }}</h4>
                                        <span class="rounded-full border-2 border-neo-ink bg-white px-2 py-0.5 text-[10px] font-black uppercase">{{ $group['category'] }}</span>
                                    </div>
                                    <p class="mt-1 text-xs font-bold text-neo-ink/65">{{ $group['member_count'] }} Product · {{ $group['available_member_count'] }} tersedia · {{ $group['sold_out_member_count'] }} habis</p>
                                    <p class="mt-1 text-xs font-black text-neo-ink">{{ $group['minimum_price'] ?? 'Harga belum diatur' }}{{ $group['minimum_usdt_price'] !== null ? ' · USDT '.$group['minimum_usdt_price'] : '' }}</p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                                    <button type="button" class="min-h-11 rounded-lg border-2 border-neo-ink bg-white px-3 py-2 text-xs font-black uppercase text-neo-ink shadow-[2px_2px_0_#111827]" aria-controls="admin-product-family-members-{{ $group['id'] }}" x-bind:aria-expanded="familyExpanded.toString()" x-on:click="familyExpanded = ! familyExpanded">
                                        <span x-text="familyExpanded ? 'Tutup daftar product' : 'Lihat product'">Lihat product</span>
                                    </button>
                                    @if ($canManageProducts && ! ($group['is_single_product_group'] ?? false))
                                        <button type="button" class="min-h-11 rounded-lg border-2 border-neo-ink bg-neo-yellow px-3 py-2 text-xs font-black uppercase text-neo-ink shadow-[2px_2px_0_#111827]" x-on:click="focusProductFamilyAssignment({{ $group['id'] }})">+ Tambah Product</button>
                                        <button type="button" class="min-h-11 rounded-lg border-2 border-neo-ink bg-white px-3 py-2 text-xs font-black uppercase text-neo-ink shadow-[2px_2px_0_#111827]" x-on:click="openProductFamilyEditModal(familyEditPayload)">✏️ Edit family</button>
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
                        </article>
                    @empty
                        <x-admin-dashboard.empty-state title="Belum ada Product di halaman ini." />
                    @endforelse

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
                </section>

                @include('admin.dashboard.stock-modals')
            </div>
