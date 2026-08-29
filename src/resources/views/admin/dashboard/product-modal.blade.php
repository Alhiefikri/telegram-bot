            @if ($canManageProducts)
            {{-- ╔═════════════════════════════════════════════════════╗
                 ║  PRODUCT CREATE/EDIT MODAL                         ║
                 ╚═════════════════════════════════════════════════════╝ --}}
            <x-admin-dashboard.modal-backdrop show="productModalOpen" close="closeProductModal()">
                <x-admin-dashboard.modal-shell
                    id="product-modal"
                    show="productModalOpen"
                    title="Tambah produk"
                    title-expression="productModalMode === 'edit' ? 'Edit produk' : 'Tambah produk'"
                    description="Nama, harga, detail publik, dan gambar WebP otomatis."
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
                                <x-admin-dashboard.input type="number" id="product-usdt-price" name="usdt_price_amount" min="0" step="0.001" inputmode="decimal" placeholder="0.5" class="mt-1.5" x-model="productUsdtPrice" />
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
                                    <p class="mt-1 text-[10px] font-extrabold uppercase text-neo-ink/60">JPG, PNG, atau WebP. Jika belum ada gambar, default akan tampil di toko. Upload gambar untuk menggantinya; otomatis dikompresi jadi WebP maksimal 1600px.</p>
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
                            <div>
                                <p class="font-display text-sm font-extrabold text-neo-ink">Detail yang dilihat pelanggan</p>
                                <p class="mt-1 text-[10px] font-extrabold uppercase leading-4 text-neo-ink/60">Opsional. Isi satu per satu supaya pelanggan tahu apa yang diterima sebelum checkout. Daftar item dan batasan memakai satu baris untuk setiap poin. Jangan masukkan password, token, atau instruksi pasca pembayaran di sini.</p>
                            </div>

                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <div>
                                    <x-admin-dashboard.field-label for="product-type">Tipe produk</x-admin-dashboard.field-label>
                                    <x-admin-dashboard.input id="product-type" name="product_type" maxlength="100" placeholder="Akun premium / lisensi" class="mt-1.5" x-model="productType" />
                                    @error('product_type', 'productCreate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                                    @error('product_type', 'productUpdate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                                </div>
                                <div>
                                    <x-admin-dashboard.field-label for="product-access-duration">Durasi akses</x-admin-dashboard.field-label>
                                    <x-admin-dashboard.input id="product-access-duration" name="access_duration" maxlength="100" placeholder="30 hari / lifetime" class="mt-1.5" x-model="productAccessDuration" />
                                    @error('access_duration', 'productCreate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                                    @error('access_duration', 'productUpdate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                                </div>
                                <div>
                                    <x-admin-dashboard.field-label for="product-region">Region / negara</x-admin-dashboard.field-label>
                                    <x-admin-dashboard.input id="product-region" name="region" maxlength="120" placeholder="Global / Indonesia" class="mt-1.5" x-model="productRegion" />
                                    @error('region', 'productCreate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                                    @error('region', 'productUpdate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                                </div>
                                <div>
                                    <x-admin-dashboard.field-label for="product-account-type">Tipe akun</x-admin-dashboard.field-label>
                                    <x-admin-dashboard.input id="product-account-type" name="account_type" maxlength="100" placeholder="Private / shared" class="mt-1.5" x-model="productAccountType" />
                                    @error('account_type', 'productCreate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                                    @error('account_type', 'productUpdate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                                </div>
                                <div>
                                    <x-admin-dashboard.field-label for="product-device-limit">Batas perangkat / seat</x-admin-dashboard.field-label>
                                    <x-admin-dashboard.input id="product-device-limit" name="device_limit" maxlength="100" placeholder="1 perangkat / 2 seat" class="mt-1.5" x-model="productDeviceLimit" />
                                    @error('device_limit', 'productCreate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                                    @error('device_limit', 'productUpdate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                                </div>
                                <div>
                                    <x-admin-dashboard.field-label for="product-featured-rank">Urutan pilihan utama</x-admin-dashboard.field-label>
                                    <x-admin-dashboard.input type="number" id="product-featured-rank" name="featured_rank" min="1" max="999" step="1" inputmode="numeric" placeholder="Kosong = tidak dipilih" class="mt-1.5" x-model="productFeaturedRank" />
                                    <p class="mt-1 text-[10px] font-bold leading-4 text-neo-ink/60">Angka lebih kecil tampil lebih dulu di area pilihan utama. Produk tetap harus aktif dan punya stok.</p>
                                    @error('featured_rank', 'productCreate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                                    @error('featured_rank', 'productUpdate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                                </div>
                            </div>

                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <div>
                                    <x-admin-dashboard.field-label for="product-included-items">Yang termasuk</x-admin-dashboard.field-label>
                                    <x-admin-dashboard.textarea id="product-included-items" name="included_items" rows="4" maxlength="2000" placeholder="Akun siap pakai&#10;Panduan login&#10;Dukungan awal" x-model="productIncludedItems" />
                                    <p class="mt-1 text-[10px] font-bold leading-4 text-neo-ink/60">Maksimal 20 poin, satu poin per baris.</p>
                                    @error('included_items', 'productCreate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                                    @error('included_items', 'productUpdate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                                </div>
                                <div>
                                    <x-admin-dashboard.field-label for="product-limitations">Batasan penggunaan</x-admin-dashboard.field-label>
                                    <x-admin-dashboard.textarea id="product-limitations" name="limitations" rows="4" maxlength="2000" placeholder="Tidak untuk dijual kembali&#10;Ikuti batas perangkat" x-model="productLimitations" />
                                    <p class="mt-1 text-[10px] font-bold leading-4 text-neo-ink/60">Tulis batasan yang perlu diketahui sebelum membayar.</p>
                                    @error('limitations', 'productCreate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                                    @error('limitations', 'productUpdate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                                </div>
                            </div>

                            <div class="mt-3 grid gap-3">
                                <div>
                                    <x-admin-dashboard.field-label for="product-fulfillment-expectation">Ekspektasi pemenuhan</x-admin-dashboard.field-label>
                                    <x-admin-dashboard.textarea id="product-fulfillment-expectation" name="fulfillment_expectation" rows="2" maxlength="1000" placeholder="Data akun dikirim setelah pembayaran terverifikasi, biasanya dalam beberapa menit." x-model="productFulfillmentExpectation" />
                                    @error('fulfillment_expectation', 'productCreate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                                    @error('fulfillment_expectation', 'productUpdate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                                </div>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <x-admin-dashboard.field-label for="product-warranty-policy">Kebijakan garansi</x-admin-dashboard.field-label>
                                        <x-admin-dashboard.textarea id="product-warranty-policy" name="warranty_policy" rows="3" maxlength="1000" placeholder="Garansi login 7 hari sejak data diterima." x-model="productWarrantyPolicy" />
                                        @error('warranty_policy', 'productCreate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                                        @error('warranty_policy', 'productUpdate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                                    </div>
                                    <div>
                                        <x-admin-dashboard.field-label for="product-support-policy">Dukungan pelanggan</x-admin-dashboard.field-label>
                                        <x-admin-dashboard.textarea id="product-support-policy" name="support_policy" rows="3" maxlength="1000" placeholder="Support membantu login sesuai jam operasional." x-model="productSupportPolicy" />
                                        @error('support_policy', 'productCreate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                                        @error('support_policy', 'productUpdate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                                    </div>
                                </div>
                            </div>

                            <div class="mt-3 rounded-lg border-2 border-neo-ink bg-white p-3 shadow-[3px_3px_0_0_#050505]">
                                <p class="font-display text-sm font-extrabold text-neo-ink">Pratinjau publik</p>
                                <p class="mt-1 whitespace-pre-line text-xs font-bold leading-5 text-neo-ink/70" x-text="productDescription || 'Deskripsi singkat akan tampil di sini.'">Deskripsi singkat akan tampil di sini.</p>
                                <dl class="mt-3 grid gap-2 text-xs sm:grid-cols-2">
                                    <div x-show="productType" x-cloak>
                                        <dt class="font-black uppercase tracking-wide text-neo-ink/50">Tipe produk</dt>
                                        <dd class="font-bold text-neo-ink" x-text="productType"></dd>
                                    </div>
                                    <div x-show="productAccessDuration" x-cloak>
                                        <dt class="font-black uppercase tracking-wide text-neo-ink/50">Durasi</dt>
                                        <dd class="font-bold text-neo-ink" x-text="productAccessDuration"></dd>
                                    </div>
                                    <div x-show="productRegion" x-cloak>
                                        <dt class="font-black uppercase tracking-wide text-neo-ink/50">Region</dt>
                                        <dd class="font-bold text-neo-ink" x-text="productRegion"></dd>
                                    </div>
                                    <div x-show="productAccountType" x-cloak>
                                        <dt class="font-black uppercase tracking-wide text-neo-ink/50">Tipe akun</dt>
                                        <dd class="font-bold text-neo-ink" x-text="productAccountType"></dd>
                                    </div>
                                    <div x-show="productDeviceLimit" x-cloak>
                                        <dt class="font-black uppercase tracking-wide text-neo-ink/50">Batas perangkat / seat</dt>
                                        <dd class="font-bold text-neo-ink" x-text="productDeviceLimit"></dd>
                                    </div>
                                </dl>
                                <div x-show="productIncludedItems" x-cloak class="mt-3">
                                    <p class="font-black uppercase tracking-wide text-neo-ink/50">Yang termasuk</p>
                                    <p class="mt-1 whitespace-pre-line text-xs font-bold leading-5 text-neo-ink" x-text="productIncludedItems"></p>
                                </div>
                                <div x-show="productLimitations" x-cloak class="mt-3">
                                    <p class="font-black uppercase tracking-wide text-neo-ink/50">Batasan</p>
                                    <p class="mt-1 whitespace-pre-line text-xs font-bold leading-5 text-neo-ink" x-text="productLimitations"></p>
                                </div>
                                <p x-show="productFulfillmentExpectation" x-cloak class="mt-3 whitespace-pre-line text-xs font-bold leading-5 text-neo-ink"><span class="font-black uppercase tracking-wide text-neo-ink/50">Pemenuhan: </span><span x-text="productFulfillmentExpectation"></span></p>
                                <p x-show="productWarrantyPolicy" x-cloak class="mt-2 whitespace-pre-line text-xs font-bold leading-5 text-neo-ink"><span class="font-black uppercase tracking-wide text-neo-ink/50">Garansi: </span><span x-text="productWarrantyPolicy"></span></p>
                                <p x-show="productSupportPolicy" x-cloak class="mt-2 whitespace-pre-line text-xs font-bold leading-5 text-neo-ink"><span class="font-black uppercase tracking-wide text-neo-ink/50">Dukungan: </span><span x-text="productSupportPolicy"></span></p>
                            </div>
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
