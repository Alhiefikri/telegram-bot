            @if ($canManageProducts)
            {{-- ╔═════════════════════════════════════════════════════╗
                 ║  PRODUCT CREATE/EDIT MODAL                         ║
                 ╚═════════════════════════════════════════════════════╝ --}}
            <div
                x-cloak
                x-show="productModalOpen"
                x-transition.opacity
                x-on:keydown.escape.window="closeProductModal()"
                class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4"
                style="background: rgba(5,5,5,0.6);"
            >
                <div class="absolute inset-0" x-on:click="closeProductModal()" aria-hidden="true"></div>

                <x-admin-dashboard.modal-shell
                    id="product-modal"
                    title="Tambah produk"
                    title-expression="productModalMode === 'edit' ? 'Edit produk' : 'Tambah produk'"
                    description="Nama, harga, kategori, deskripsi."
                    class="max-h-[92vh] w-full max-w-xl overflow-y-auto"
                    x-bind:style="{ backgroundColor: productModalMode === 'edit' ? '#fee648' : '#7BD10A' }"
                >
                    @if ($errors->productCreate->any() || $errors->productUpdate->any())
                        <div class="mb-3 animate-slide-up rounded-[0.6rem] p-3 text-sm font-bold text-white" style="background: #ef4444; border: 3px solid #050505; box-shadow: 3px 3px 0px #050505;">
                            Periksa kembali field produk yang ditandai.
                        </div>
                    @endif

                    <form method="POST" x-bind:action="productFormAction" class="space-y-3">
                        @csrf
                        <input type="hidden" x-bind:name="productFormMethod ? '_method' : ''" x-bind:value="productFormMethod">
                        <input type="hidden" name="product_id" x-bind:value="productId">
                        <input type="hidden" name="is_active" x-bind:value="productIsActive ? 1 : 0">

                        <div>
                            <label for="product-name" class="font-display text-[11px] font-extrabold uppercase tracking-wider" style="color: #050505;">Nama produk</label>
                            <input
                                type="text"
                                id="product-name"
                                name="name"
                                placeholder="NETFLIX PREMIUM"
                                class="neo-input mt-1.5"
                                x-model="productName"
                                x-ref="productName"
                            >
                            @error('name', 'productCreate')
                                <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                            @enderror
                            @error('name', 'productUpdate')
                                <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label for="product-price" class="font-display text-[11px] font-extrabold uppercase tracking-wider" style="color: #050505;">Harga (IDR)</label>
                                <input
                                    type="number"
                                    id="product-price"
                                    name="price_amount"
                                    min="0"
                                    step="1"
                                    inputmode="numeric"
                                    placeholder="25000"
                                    class="neo-input mt-1.5"
                                    x-model="productPrice"
                                >
                                @error('price_amount', 'productCreate')
                                    <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                                @enderror
                                @error('price_amount', 'productUpdate')
                                    <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="product-category" class="font-display text-[11px] font-extrabold uppercase tracking-wider" style="color: #050505;">Kategori</label>
                                <select id="product-category" name="category_id" class="neo-select mt-1.5" x-model="productCategoryId">
                                    <option value="">— Pilih —</option>
                                    @foreach ($categoryOptions as $category)
                                        <option value="{{ $category['id'] }}">{{ $category['name'] }}</option>
                                    @endforeach
                                </select>
                                @error('category_id', 'productCreate')
                                    <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                                @enderror
                                @error('category_id', 'productUpdate')
                                    <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <div class="rounded-[0.6rem] border-[3px] border-neo-ink p-3">
                            <label for="product-description" class="font-display text-sm font-extrabold text-neo-ink">📝 Deskripsi singkat</label>
                            <textarea
                                id="product-description"
                                name="description"
                                rows="3"
                                placeholder="Deskripsi singkat produk (1–3 kalimat)..."
                                class="neo-textarea mt-2"
                                x-model="productDescription"
                            ></textarea>
                            @error('description', 'productCreate')
                                <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                            @enderror
                            @error('description', 'productUpdate')
                                <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid gap-3">
                            <section class="rounded-[0.6rem] border-[3px] border-neo-ink p-3">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <p class="font-display text-sm font-extrabold text-neo-ink">✨ Fitur utama</p>
                                        <p class="mt-1 text-[10px] font-extrabold uppercase text-neo-ink/60" x-show="! productFeaturesOpen">Tap “+ tambah” untuk menambah fitur.</p>
                                    </div>
                                    <button
                                        type="button"
                                        class="product-action-btn min-h-9 px-2 py-1 text-[10px]"
                                        style="background: #7BD10A; color: #050505;"
                                        x-on:click="productFeaturesOpen = ! productFeaturesOpen"
                                        x-text="productFeaturesOpen ? 'Tutup' : '+ Tambah'"
                                    >+ Tambah</button>
                                </div>

                                <div class="mt-3" x-show="productFeaturesOpen" x-transition>
                                    <textarea
                                        name="features"
                                        rows="3"
                                        placeholder="Satu fitur per baris"
                                        class="neo-textarea"
                                        x-model="productFeatures"
                                    ></textarea>
                                    @error('features', 'productCreate')
                                        <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                                    @enderror
                                    @error('features', 'productUpdate')
                                        <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                                    @enderror
                                </div>
                            </section>

                            <section class="rounded-[0.6rem] border-[3px] border-neo-ink p-3">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <p class="font-display text-sm font-extrabold text-neo-ink">🌐 Paket / spesifikasi</p>
                                        <p class="mt-1 text-[10px] font-extrabold uppercase text-neo-ink/60" x-show="! productSpecificationsOpen">Opsional. Cocok untuk produk multi-tier.</p>
                                    </div>
                                    <button
                                        type="button"
                                        class="product-action-btn min-h-9 px-2 py-1 text-[10px]"
                                        style="background: #16b4ca; color: #050505;"
                                        x-on:click="productSpecificationsOpen = ! productSpecificationsOpen"
                                        x-text="productSpecificationsOpen ? 'Tutup' : '+ Paket'"
                                    >+ Paket</button>
                                </div>

                                <div class="mt-3" x-show="productSpecificationsOpen" x-transition>
                                    <textarea
                                        name="specifications"
                                        rows="3"
                                        placeholder="Satu spesifikasi per baris"
                                        class="neo-textarea"
                                        x-model="productSpecifications"
                                    ></textarea>
                                    @error('specifications', 'productCreate')
                                        <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                                    @enderror
                                    @error('specifications', 'productUpdate')
                                        <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                                    @enderror
                                </div>
                            </section>
                        </div>

                        <div class="grid grid-cols-2 gap-2 pt-1">
                            <button type="button" class="neo-btn neo-btn-white" x-on:click="closeProductModal()">
                                Batal
                            </button>
                            <button type="submit" class="neo-btn neo-btn-pink">
                                <span x-text="productModalMode === 'edit' ? '⚡ Simpan' : '⚡ Submit produk'">⚡ Submit produk</span>
                            </button>
                        </div>
                    </form>
                </x-admin-dashboard.modal-shell>
            </div>
            @endif
