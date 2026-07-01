            {{-- ╔═════════════════════════════════════════════════════╗
                 ║  PRODUK TAB (Katalog)                               ║
                 ╚═════════════════════════════════════════════════════╝ --}}
            <div x-cloak x-show="activeTab === 'produk'" class="mt-4 space-y-4">

                <div class="flex items-end justify-between gap-3">
                    <div>
                        <h2 class="font-display text-lg font-bold uppercase tracking-wider" style="color: #050505;">📦 Produk Saya</h2>
                        <p class="mt-1 text-xs font-semibold" style="color: #64748b;">Semua kelola produk dan stok tetap di dashboard ini</p>
                    </div>

                    @if ($canManageProducts)
                    <button
                        type="button"
                        class="neo-btn neo-btn-purple shrink-0 text-[11px]"
                        x-on:click="openProductForm()"
                    >
                        ➕ Tambah Produk
                    </button>
                    @endif
                </div>

                <div class="rounded-[1.25rem] border-[3px] border-neo-ink bg-[#ede5fa] px-4 py-3 text-sm font-bold text-neo-ink shadow-[4px_4px_0_0_#050505]">
                    <span class="font-black uppercase">Dipesan</span> berarti stok yang sedang ditahan untuk order aktif/pending. Kelola hanya menampilkan akun yang masih tersedia.
                </div>

                @if ($canManageProducts)
                <form method="POST" action="{{ route('admin.categories.store') }}" class="rounded-[1rem] border-[3px] border-neo-ink bg-[#fef9c3] p-4 shadow-[4px_4px_0_0_#050505]">
                    @csrf
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="font-display text-sm font-black uppercase text-neo-ink">Tambah kategori</p>
                            <p class="mt-1 text-[11px] font-bold text-neo-ink/60">Buat kategori cepat untuk produk baru.</p>
                        </div>
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_1.2fr_auto]">
                        <div>
                            <label for="category-name" class="font-display text-[11px] font-extrabold uppercase tracking-wider text-neo-ink">Nama</label>
                            <input id="category-name" name="category_name" value="{{ old('category_name') }}" class="neo-input mt-1.5" placeholder="AI Tools">
                            @error('category_name', 'categoryCreate')
                                <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="category-description" class="font-display text-[11px] font-extrabold uppercase tracking-wider text-neo-ink">Deskripsi opsional</label>
                            <input id="category-description" name="category_description" value="{{ old('category_description') }}" class="neo-input mt-1.5" placeholder="Kategori akun premium">
                            @error('category_description', 'categoryCreate')
                                <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                            @enderror
                        </div>
                        <button type="submit" class="neo-btn neo-btn-yellow self-end text-[11px]">
                            + Kategori
                        </button>
                    </div>
                </form>
                @endif

                @if (session('status'))
                    <div class="animate-slide-up rounded-xl p-4 text-sm font-bold" style="background: #e8ffd4; border: 2px solid #050505; box-shadow: 3px 3px 0px #050505; color: #050505;">
                        {{ session('status') }}
                    </div>
                @endif

                <div class="grid gap-4 md:grid-cols-2">
                    @forelse ($products as $product)
                        @php
                            $stockPayload = [
                                'id' => $product['id'],
                                'name' => $product['name'],
                                'stock' => $product['stock'],
                                'stockUrl' => $product['stock_url'],
                            ];
                            $productPayload = [
                                'id' => $product['id'],
                                'name' => $product['name'],
                                'priceAmount' => $product['price_amount'],
                                'categoryId' => $product['category_id'],
                                'description' => $product['description'],
                                'features' => $product['features'],
                                'specifications' => $product['specifications'],
                                'isActive' => $product['is_active'],
                                'updateUrl' => $product['update_url'],
                                'stockUrl' => $product['stock_url'],
                                'stock' => $product['stock'],
                                'availableStockItemCount' => $product['available_stock_item_count'],
                                'stockItemsTruncated' => $product['stock_items_truncated'],
                                'stockItems' => $product['stock_items'],
                            ];
                        @endphp

                        @include('admin.dashboard.product-card')
                    @empty
                        <div class="rounded-xl p-6 text-center" style="background: #fff; border: 2px dashed #050505;">
                            <p class="font-display text-sm font-bold" style="color: #64748b;">Belum ada produk.</p>
                        </div>
                    @endforelse
                </div>

                @include('admin.dashboard.stock-modals')
            </div>
