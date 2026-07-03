            @if ($canManageProducts)
            {{-- ╔═════════════════════════════════════════════════════╗
                 ║  PRODUCT CREATE/EDIT MODAL                         ║
                 ╚═════════════════════════════════════════════════════╝ --}}
            <x-admin-dashboard.modal-backdrop show="productModalOpen" close="closeProductModal()">
                <x-admin-dashboard.modal-shell
                    id="product-modal"
                    title="Tambah produk"
                    title-expression="productModalMode === 'edit' ? 'Edit produk' : 'Tambah produk'"
                    description="Nama, harga, kategori, deskripsi."
                    class="max-h-[92vh] w-full max-w-xl overflow-y-auto"
                    x-bind:class="productModalMode === 'edit' ? '!bg-[#fee648]' : '!bg-[#7BD10A]'"
                >
                    @if ($errors->productCreate->any() || $errors->productUpdate->any())
                        <x-admin-dashboard.notice tone="danger" class="mb-3 animate-slide-up">
                            Periksa kembali field produk yang ditandai.
                        </x-admin-dashboard.notice>
                    @endif

                    <form method="POST" x-bind:action="productFormAction" x-on:submit="submitProductForm($event)" class="space-y-3">
                        @csrf
                        <input type="hidden" x-bind:name="productFormMethod ? '_method' : ''" x-bind:value="productFormMethod">
                        <input type="hidden" name="product_id" x-bind:value="productId">
                        <input type="hidden" name="is_active" x-bind:value="productIsActive ? 1 : 0">

                        <div>
                            <x-admin-dashboard.field-label for="product-name">Nama produk</x-admin-dashboard.field-label>
                            <x-admin-dashboard.input
                                id="product-name"
                                name="name"
                                placeholder="NETFLIX PREMIUM"
                                class="mt-1.5"
                                x-model="productName"
                                x-ref="productName"
                                required
                            />
                            @error('name', 'productCreate')
                                <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                            @enderror
                            @error('name', 'productUpdate')
                                <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                            @enderror
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <x-admin-dashboard.field-label for="product-price">Harga (IDR)</x-admin-dashboard.field-label>
                                <x-admin-dashboard.input
                                    type="number"
                                    id="product-price"
                                    name="price_amount"
                                    min="0"
                                    step="1"
                                    inputmode="numeric"
                                    placeholder="25000"
                                    class="mt-1.5"
                                    x-model="productPrice"
                                    required
                                />
                                @error('price_amount', 'productCreate')
                                    <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                                @enderror
                                @error('price_amount', 'productUpdate')
                                    <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                                @enderror
                            </div>

                            <div>
                                <x-admin-dashboard.field-label for="product-category">Kategori</x-admin-dashboard.field-label>
                                <x-admin-dashboard.select id="product-category" name="category_id" class="mt-1.5" x-model="productCategoryId">
                                    <option value="">— Pilih —</option>
                                    @foreach ($categoryOptions as $category)
                                        <option value="{{ $category['id'] }}">{{ $category['name'] }}</option>
                                    @endforeach
                                </x-admin-dashboard.select>
                                @error('category_id', 'productCreate')
                                    <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                                @enderror
                                @error('category_id', 'productUpdate')
                                    <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                                @enderror
                            </div>
                        </div>

                        <x-admin-dashboard.card padding="sm" shadow="none" class="rounded-[0.6rem]">
                            <x-admin-dashboard.field-label for="product-description" class="text-sm normal-case tracking-normal">📝 Deskripsi singkat</x-admin-dashboard.field-label>
                            <x-admin-dashboard.textarea
                                id="product-description"
                                name="description"
                                rows="3"
                                placeholder="Deskripsi singkat produk (1–3 kalimat)..."
                                class="mt-2"
                                x-model="productDescription"
                                required
                            />
                            @error('description', 'productCreate')
                                <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                            @enderror
                            @error('description', 'productUpdate')
                                <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                            @enderror
                        </x-admin-dashboard.card>

                        <div class="grid gap-3">
                            <x-admin-dashboard.card as="section" padding="sm" shadow="none" class="rounded-[0.6rem]">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <p class="font-display text-sm font-extrabold text-neo-ink">✨ Fitur utama</p>
                                        <p class="mt-1 text-[10px] font-extrabold uppercase text-neo-ink/60" x-show="! productFeaturesOpen">Tap “+ tambah” untuk menambah fitur.</p>
                                    </div>
                                    <x-admin-dashboard.button
                                        type="button"
                                        variant="primary"
                                        size="sm"
                                        class="min-h-9 px-2 py-1 text-[10px]"
                                        x-on:click="productFeaturesOpen = ! productFeaturesOpen"
                                        x-text="productFeaturesOpen ? 'Tutup' : '+ Tambah'"
                                    >+ Tambah</x-admin-dashboard.button>
                                </div>

                                <div class="mt-3" x-show="productFeaturesOpen" x-transition>
                                    <x-admin-dashboard.textarea
                                        name="features"
                                        rows="3"
                                        placeholder="Satu fitur per baris"
                                        x-model="productFeatures"
                                    />
                                    @error('features', 'productCreate')
                                        <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                                    @enderror
                                    @error('features', 'productUpdate')
                                        <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                                    @enderror
                                </div>
                            </x-admin-dashboard.card>

                            <x-admin-dashboard.card as="section" padding="sm" shadow="none" class="rounded-[0.6rem]">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <p class="font-display text-sm font-extrabold text-neo-ink">🌐 Paket / spesifikasi</p>
                                        <p class="mt-1 text-[10px] font-extrabold uppercase text-neo-ink/60" x-show="! productSpecificationsOpen">Opsional. Cocok untuk produk multi-tier.</p>
                                    </div>
                                    <x-admin-dashboard.button
                                        type="button"
                                        variant="secondary"
                                        size="sm"
                                        class="min-h-9 px-2 py-1 text-[10px]"
                                        x-on:click="productSpecificationsOpen = ! productSpecificationsOpen"
                                        x-text="productSpecificationsOpen ? 'Tutup' : '+ Paket'"
                                    >+ Paket</x-admin-dashboard.button>
                                </div>

                                <div class="mt-3" x-show="productSpecificationsOpen" x-transition>
                                    <x-admin-dashboard.textarea
                                        name="specifications"
                                        rows="3"
                                        placeholder="Satu spesifikasi per baris"
                                        x-model="productSpecifications"
                                    />
                                    @error('specifications', 'productCreate')
                                        <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                                    @enderror
                                    @error('specifications', 'productUpdate')
                                        <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                                    @enderror
                                </div>
                            </x-admin-dashboard.card>
                        </div>

                        <div class="grid grid-cols-2 gap-2 pt-1">
                            <x-admin-dashboard.button type="button" variant="neutral" x-on:click="closeProductModal()" x-bind:disabled="productFormSubmitting">
                                Batal
                            </x-admin-dashboard.button>
                            <x-admin-dashboard.button type="submit" variant="pink" x-bind:disabled="productFormSubmitting" x-bind:class="{ 'cursor-wait opacity-70': productFormSubmitting }">
                                <span x-text="productFormSubmitting ? 'Menyimpan...' : (productModalMode === 'edit' ? '⚡ Simpan' : '⚡ Submit produk')">⚡ Submit produk</span>
                            </x-admin-dashboard.button>
                        </div>
                    </form>
                </x-admin-dashboard.modal-shell>
            </x-admin-dashboard.modal-backdrop>
            @endif
