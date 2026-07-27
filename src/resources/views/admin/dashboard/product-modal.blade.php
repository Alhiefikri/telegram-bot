            @if ($canManageProducts)
            {{-- ╔═════════════════════════════════════════════════════╗
                 ║  PRODUCT CREATE/EDIT MODAL                         ║
                 ╚═════════════════════════════════════════════════════╝ --}}
            <x-admin-dashboard.modal-backdrop show="productModalOpen" close="closeProductModal()">
                <x-admin-dashboard.modal-shell
                    id="product-modal"
                    title="Tambah produk"
                    title-expression="productModalMode === 'edit' ? 'Edit produk' : 'Tambah produk'"
                    description="Nama, harga, kategori, deskripsi, dan gambar WebP otomatis."
                    class="max-h-[92vh] w-full max-w-xl overflow-y-auto"
                    x-bind:class="productModalMode === 'edit' ? '!bg-[#fee648]' : '!bg-[#7BD10A]'"
                >
                    @if ($errors->productCreate->any() || $errors->productUpdate->any())
                        <x-admin-dashboard.notice tone="danger" class="mb-3 animate-slide-up">
                            Periksa kembali field produk yang ditandai.
                        </x-admin-dashboard.notice>
                    @endif

                    <form method="POST" enctype="multipart/form-data" x-bind:action="productFormAction" x-on:submit="submitProductForm($event)" class="space-y-3">
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

                        <div class="grid gap-3 sm:grid-cols-3">
                            <div>
                                <x-admin-dashboard.field-label for="product-price">Harga Asli (IDR)</x-admin-dashboard.field-label>
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
                                <x-admin-dashboard.field-label for="product-usdt-price">Harga USDT (opsional)</x-admin-dashboard.field-label>
                                <x-admin-dashboard.input id="product-usdt-price" name="usdt_price_amount" min="0" step="0.000001" inputmode="decimal" placeholder="0.5" class="mt-1.5" x-model="productUsdtPrice" />
                                @error('usdt_price_amount', 'productCreate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                                @error('usdt_price_amount', 'productUpdate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
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
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <x-admin-dashboard.field-label for="product-image" class="text-sm normal-case tracking-normal">🖼️ Gambar produk</x-admin-dashboard.field-label>
                                    <p class="mt-1 text-[10px] font-extrabold uppercase text-neo-ink/60">JPG, PNG, atau WebP. Otomatis dikompresi jadi WebP maksimal 1600px.</p>
                                </div>
                                <div x-show="productImageUrl" class="h-14 w-14 shrink-0 overflow-hidden rounded-xl border-[3px] border-neo-ink bg-white shadow-[3px_3px_0_0_#050505]">
                                    <img x-bind:src="productImageUrl" alt="" class="h-full w-full object-cover">
                                </div>
                            </div>
                            <div class="mt-2 flex min-w-0 items-center gap-3 rounded-[1rem] border-[3px] border-neo-ink bg-white p-2 shadow-[4px_4px_0_0_#050505]">
                                <label for="product-image" class="shrink-0 cursor-pointer rounded-lg bg-neo-ink px-3 py-2 text-xs font-black text-white transition hover:bg-[#8758de]">
                                    Pilih gambar
                                </label>
                                <span class="min-w-0 flex-1 truncate text-xs font-black text-neo-ink" x-text="productImageFileName">Tidak ada file yang dipilih</span>
                            </div>
                            <input
                                type="file"
                                id="product-image"
                                name="image"
                                accept="image/jpeg,image/png,image/webp"
                                class="sr-only"
                                x-on:change="productImageFileName = $event.target.files[0]?.name ?? 'Tidak ada file yang dipilih'"
                            >
                            <p class="mt-2 text-[10px] font-bold text-neo-ink/60" x-show="productModalMode === 'edit'">Kosongkan kalau gambar lama tetap dipakai.</p>
                            @error('image', 'productCreate')
                                <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                            @enderror
                            @error('image', 'productUpdate')
                                <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                            @enderror
                        </x-admin-dashboard.card>

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

                        <x-admin-dashboard.card as="section" padding="sm" shadow="none" class="rounded-[0.6rem]">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="font-display text-sm font-extrabold text-neo-ink">🔐 Catatan pengiriman rahasia</p>
                                    <p class="mt-1 text-[10px] font-extrabold uppercase text-neo-ink/60" x-show="! productDeliveryNoteOpen">Hanya dikirim ke pembeli setelah pembayaran dan data akun tersedia.</p>
                                </div>
                                <x-admin-dashboard.button
                                    type="button"
                                    variant="primary"
                                    size="sm"
                                    class="min-h-9 px-2 py-1 text-[10px]"
                                    x-on:click="productDeliveryNoteOpen = ! productDeliveryNoteOpen"
                                    x-text="productDeliveryNoteOpen ? 'Tutup' : '+ Tambah'"
                                >+ Tambah</x-admin-dashboard.button>
                            </div>

                            <div class="mt-3" x-show="productDeliveryNoteOpen" x-transition>
                                <x-admin-dashboard.textarea
                                    name="delivery_note"
                                    rows="5"
                                    placeholder="Contoh: Login memakai email dan password di atas, lalu ganti password dan MFA setelah berhasil masuk."
                                    x-model="productDeliveryNote"
                                />
                                @error('delivery_note', 'productCreate')
                                    <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                                @enderror
                                @error('delivery_note', 'productUpdate')
                                    <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                                @enderror
                            </div>
                        </x-admin-dashboard.card>

                        <x-admin-dashboard.card as="section" padding="sm" shadow="none" class="rounded-[0.6rem]">
                            <div>
                                <p class="font-display text-sm font-extrabold text-neo-ink">📘 Panduan pemakaian produk</p>
                                <p class="mt-1 text-[10px] font-extrabold uppercase text-neo-ink/60">Opsional dan berbeda untuk setiap produk/provider. Hanya tampil ke pembeli jika kedua field diisi.</p>
                            </div>

                            <div class="mt-3 grid gap-3">
                                <div>
                                    <x-admin-dashboard.field-label for="product-usage-guide-title">Judul panduan</x-admin-dashboard.field-label>
                                    <x-admin-dashboard.input
                                        id="product-usage-guide-title"
                                        name="usage_guide_title"
                                        placeholder="Contoh: Panduan login ChatGPT"
                                        maxlength="160"
                                        class="mt-1.5"
                                        x-model="productUsageGuideTitle"
                                    />
                                </div>
                                @error('usage_guide_title', 'productCreate')
                                    <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                                @enderror
                                @error('usage_guide_title', 'productUpdate')
                                    <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                                @enderror

                                <div>
                                    <x-admin-dashboard.field-label for="product-usage-guide-url">URL panduan</x-admin-dashboard.field-label>
                                    <x-admin-dashboard.input
                                        id="product-usage-guide-url"
                                        name="usage_guide_url"
                                        placeholder="Contoh: /gpt/panduan-login-akun"
                                        maxlength="2048"
                                        class="mt-1.5"
                                        x-model="productUsageGuideUrl"
                                    />
                                </div>
                                @error('usage_guide_url', 'productCreate')
                                    <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                                @enderror
                                @error('usage_guide_url', 'productUpdate')
                                    <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                                @enderror
                            </div>
                        </x-admin-dashboard.card>

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
