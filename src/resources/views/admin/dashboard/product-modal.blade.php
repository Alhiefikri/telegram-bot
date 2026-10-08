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
                    description="Atur ikon katalog, gambar Storefront, detail bot, dan poster stok Store Channel secara terpisah."
                    class="max-h-[92vh] w-full max-w-2xl overflow-y-auto"
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
                        <input type="hidden" name="quantity_price_tiers_present" value="1">

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

                        <div>
                            <x-admin-dashboard.field-label for="product-icon">Ikon katalog · emoji/karakter (opsional)</x-admin-dashboard.field-label>
                            <x-admin-dashboard.input
                                id="product-icon"
                                name="icon"
                                maxlength="32"
                                placeholder="🎨"
                                autocomplete="off"
                                class="mt-1.5"
                                x-model="productIcon"
                            />
                            <p class="mt-1 text-[10px] font-bold text-neo-ink/60">Bukan upload gambar. Tampil di katalog bot Telegram dan Web Storefront; kosongkan untuk memakai ikon family (jika ada) atau ikon default. Emoji Telegram (Premium) diambil per produk lewat menu 🎨 Ikon Produk di bot.</p>
                            @error('icon', 'productCreate')
                                <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                            @enderror
                            @error('icon', 'productUpdate')
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
                        <x-admin-dashboard.card as="section" padding="sm" shadow="none" class="rounded-[0.6rem]" aria-labelledby="product-quantity-price-tiers-title">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <p id="product-quantity-price-tiers-title" class="font-display text-sm font-extrabold text-neo-ink">Harga bertingkat berdasarkan jumlah</p>
                                    <p class="mt-1 text-[10px] font-extrabold uppercase leading-4 text-neo-ink/60">Opsional, maksimal 5 tingkat. Harga setiap tingkat harus lebih rendah daripada tingkat sebelumnya dan harga dasar.</p>
                                </div>
                                <x-admin-dashboard.button
                                    type="button"
                                    variant="primary"
                                    size="sm"
                                    class="min-h-11 shrink-0 px-3 text-[10px]"
                                    x-on:click="addProductQuantityPriceTier()"
                                    x-bind:disabled="productQuantityPriceTiers.length >= 5"
                                >+ Tambah tingkat harga</x-admin-dashboard.button>
                            </div>

                            <p
                                x-show="productQuantityPriceTierScheduleError()"
                                x-text="productQuantityPriceTierScheduleError()"
                                id="product-quantity-price-tiers-error"
                                role="alert"
                                aria-live="polite"
                                class="mt-3 rounded-lg border-2 border-neo-red bg-red-50 px-2.5 py-2 text-xs font-black text-neo-red"
                            ></p>
                            @error('quantity_price_tiers', 'productCreate')
                                <x-admin-dashboard.field-error role="alert" aria-live="polite">{{ $message }}</x-admin-dashboard.field-error>
                            @enderror
                            @error('quantity_price_tiers', 'productUpdate')
                                <x-admin-dashboard.field-error role="alert" aria-live="polite">{{ $message }}</x-admin-dashboard.field-error>
                            @enderror

                            <p x-show="productQuantityPriceTiers.length === 0" class="mt-3 rounded-lg border-2 border-dashed border-neo-ink/40 bg-white/70 px-3 py-2 text-xs font-bold text-neo-ink/65">
                                Tidak ada tingkat harga. Harga dasar tetap digunakan.
                            </p>

                            <div id="product-quantity-price-tiers" class="mt-3 space-y-3" x-show="productQuantityPriceTiers.length > 0">
                                <template x-for="(tier, index) in productQuantityPriceTiers" :key="tier._key">
                                    <article data-tier-row class="rounded-[0.55rem] border-[3px] border-neo-ink bg-white p-3 shadow-[3px_3px_0_0_#050505]">
                                        <div class="flex items-start justify-between gap-3">
                                            <p class="font-display text-xs font-black uppercase text-neo-ink" x-text="`Tingkat #${index + 1}`">Tingkat #1</p>
                                            <button
                                                type="button"
                                                class="min-h-11 shrink-0 rounded-lg border-2 border-neo-ink bg-[#fee648] px-3 py-2 text-[10px] font-black text-neo-ink shadow-[2px_2px_0_0_#050505] transition hover:-translate-y-0.5"
                                                x-on:click="removeProductQuantityPriceTier(index)"
                                                x-bind:aria-label="`Hapus tingkat harga #${index + 1}`"
                                            >Hapus</button>
                                        </div>

                                        <div class="mt-3 grid gap-3 sm:grid-cols-3">
                                            <div>
                                                <label class="font-display text-[11px] font-extrabold uppercase tracking-wider text-neo-ink" x-bind:for="'product-quantity-tier-' + index + '-minimum'">Mulai quantity</label>
                                                <input
                                                    type="number"
                                                    min="2"
                                                    max="20"
                                                    step="1"
                                                    inputmode="numeric"
                                                    class="mt-1.5 min-h-11 w-full rounded-lg border-[3px] border-neo-ink bg-white px-3 text-sm font-bold text-neo-ink shadow-[2px_2px_0_0_#050505] focus:outline-none focus:ring-2 focus:ring-neo-focus"
                                                    x-bind:id="'product-quantity-tier-' + index + '-minimum'"
                                                    x-bind:name="'quantity_price_tiers[' + index + '][minimum_quantity]'"
                                                    x-model="tier.minimumQuantity"
                                                    x-bind:aria-invalid="productQuantityPriceTierError(index, 'minimum_quantity') ? 'true' : 'false'"
                                                    x-bind:aria-describedby="productQuantityPriceTierError(index, 'minimum_quantity') ? 'product-quantity-tier-' + index + '-minimum-error' : null"
                                                    required
                                                >
                                                <p
                                                    x-show="productQuantityPriceTierError(index, 'minimum_quantity')"
                                                    x-text="productQuantityPriceTierError(index, 'minimum_quantity')"
                                                    x-bind:id="'product-quantity-tier-' + index + '-minimum-error'"
                                                    role="alert"
                                                    aria-live="polite"
                                                    class="mt-1 text-xs font-black text-neo-red"
                                                ></p>
                                            </div>

                                            <div>
                                                <label class="font-display text-[11px] font-extrabold uppercase tracking-wider text-neo-ink" x-bind:for="'product-quantity-tier-' + index + '-idr'">Harga IDR / unit</label>
                                                <input
                                                    type="number"
                                                    min="1"
                                                    step="1"
                                                    inputmode="numeric"
                                                    class="mt-1.5 min-h-11 w-full rounded-lg border-[3px] border-neo-ink bg-white px-3 text-sm font-bold text-neo-ink shadow-[2px_2px_0_0_#050505] focus:outline-none focus:ring-2 focus:ring-neo-focus"
                                                    x-bind:id="'product-quantity-tier-' + index + '-idr'"
                                                    x-bind:name="'quantity_price_tiers[' + index + '][price_amount]'"
                                                    x-model="tier.priceAmount"
                                                    x-bind:aria-invalid="productQuantityPriceTierError(index, 'price_amount') ? 'true' : 'false'"
                                                    x-bind:aria-describedby="productQuantityPriceTierError(index, 'price_amount') ? 'product-quantity-tier-' + index + '-idr-error' : null"
                                                    required
                                                >
                                                <p
                                                    x-show="productQuantityPriceTierError(index, 'price_amount')"
                                                    x-text="productQuantityPriceTierError(index, 'price_amount')"
                                                    x-bind:id="'product-quantity-tier-' + index + '-idr-error'"
                                                    role="alert"
                                                    aria-live="polite"
                                                    class="mt-1 text-xs font-black text-neo-red"
                                                ></p>
                                            </div>

                                            <div>
                                                <label class="font-display text-[11px] font-extrabold uppercase tracking-wider text-neo-ink" x-bind:for="'product-quantity-tier-' + index + '-usdt'">Harga USDT / unit</label>
                                                <input
                                                    type="number"
                                                    min="0.001"
                                                    step="0.001"
                                                    inputmode="decimal"
                                                    placeholder="Opsional"
                                                    class="mt-1.5 min-h-11 w-full rounded-lg border-[3px] border-neo-ink bg-white px-3 text-sm font-bold text-neo-ink shadow-[2px_2px_0_0_#050505] focus:outline-none focus:ring-2 focus:ring-neo-focus"
                                                    x-bind:id="'product-quantity-tier-' + index + '-usdt'"
                                                    x-bind:name="'quantity_price_tiers[' + index + '][usdt_price_amount]'"
                                                    x-model="tier.usdtPriceAmount"
                                                    x-bind:required="productUsdtPrice !== ''"
                                                    x-bind:aria-invalid="productQuantityPriceTierError(index, 'usdt_price_amount') ? 'true' : 'false'"
                                                    x-bind:aria-describedby="productQuantityPriceTierError(index, 'usdt_price_amount') ? 'product-quantity-tier-' + index + '-usdt-error' : null"
                                                >
                                                <p
                                                    x-show="productQuantityPriceTierError(index, 'usdt_price_amount')"
                                                    x-text="productQuantityPriceTierError(index, 'usdt_price_amount')"
                                                    x-bind:id="'product-quantity-tier-' + index + '-usdt-error'"
                                                    role="alert"
                                                    aria-live="polite"
                                                    class="mt-1 text-xs font-black text-neo-red"
                                                ></p>
                                            </div>
                                        </div>
                                    </article>
                                </template>
                            </div>
                        </x-admin-dashboard.card>

                        <section class="space-y-3" aria-labelledby="product-media-title">
                            <div>
                                <h3 id="product-media-title" class="font-display text-sm font-black uppercase text-neo-ink">Upload gambar produk</h3>
                                <p class="mt-1 text-[11px] font-semibold text-neo-ink/70">JPG, PNG, atau WebP; maksimal 5 MB. Gambar toko menjadi WebP maks. 800px, poster Telegram maks. 1600px.</p>
                            </div>
                        <x-admin-dashboard.card padding="sm" shadow="none" class="rounded-[0.6rem]">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <x-admin-dashboard.field-label for="product-image" class="text-sm normal-case tracking-normal">Kartu produk · Web Storefront</x-admin-dashboard.field-label>
                                    <p class="mt-1 text-[11px] font-semibold text-neo-ink/65">Gambar kartu produk di toko web. Jika kosong, tampil ikon katalog atau gambar default toko; bukan poster detail bot.</p>
                                </div>
                                <div x-show="productImageUrl" x-cloak class="h-14 w-14 shrink-0 overflow-hidden rounded-xl border-[3px] border-neo-ink bg-white shadow-[3px_3px_0_0_#050505]">
                                    <img x-bind:src="productImageUrl" alt="Gambar kartu Storefront tersimpan" class="h-full w-full object-cover">
                                </div>
                            </div>
                            <div class="mt-2 flex min-w-0 items-center gap-3 rounded-[1rem] border-[3px] border-neo-ink bg-white p-2 shadow-[4px_4px_0_0_#050505]">
                                <label for="product-image" class="shrink-0 cursor-pointer rounded-lg bg-neo-ink px-3 py-2 text-xs font-black text-white transition hover:bg-[#8758de]">
                                    Pilih gambar toko
                                </label>
                                <span class="min-w-0 flex-1 truncate text-xs font-black text-neo-ink" x-text="productImageFileName">Tidak ada file yang dipilih</span>
                            </div>
                            <input
                                type="file"
                                id="product-image"
                                name="image"
                                accept="image/jpeg,image/png,image/webp"
                                class="sr-only"
                                x-ref="productImage"
                                x-on:change="productImageFileName = $event.target.files[0]?.name ?? 'Tidak ada file yang dipilih'"
                            >
                            <p class="mt-2 text-[10px] font-bold text-neo-ink/60" x-show="productModalMode === 'edit'">Tanpa upload baru, gambar toko tersimpan tetap dipakai.</p>
                            @error('image', 'productCreate')
                                <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                            @enderror
                            @error('image', 'productUpdate')
                                <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                            @enderror
                        </x-admin-dashboard.card>
                        <x-admin-dashboard.card padding="sm" shadow="none" class="rounded-[0.6rem]">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <x-admin-dashboard.field-label for="telegram-product-image" class="text-sm normal-case tracking-normal">Detail produk · Bot Telegram</x-admin-dashboard.field-label>
                                    <p class="mt-1 text-[11px] font-semibold text-neo-ink/65">Untuk detail saat produk diklik di bot. Disarankan poster landscape 16:9. Jika kosong, gunakan gambar detail family; jika tidak ada, detail tetap berupa teks.</p>
                                </div>
                                <div x-show="telegramProductImageUrl" x-cloak class="h-14 w-20 shrink-0 overflow-hidden rounded-xl border-[3px] border-neo-ink bg-white shadow-[3px_3px_0_0_#050505]">
                                    <img x-bind:src="telegramProductImageUrl" alt="Gambar detail bot Telegram tersimpan" class="h-full w-full object-contain">
                                </div>
                            </div>
                            <div class="mt-2 flex min-w-0 items-center gap-3 rounded-[1rem] border-[3px] border-neo-ink bg-white p-2 shadow-[4px_4px_0_0_#050505]">
                                <label for="telegram-product-image" class="shrink-0 cursor-pointer rounded-lg bg-neo-ink px-3 py-2 text-xs font-black text-white transition hover:bg-[#8758de]">Pilih gambar bot</label>
                                <span class="min-w-0 flex-1 truncate text-xs font-black text-neo-ink" x-text="telegramProductImageFileName">Tidak ada file yang dipilih</span>
                            </div>
                            <input type="file" id="telegram-product-image" name="telegram_image" accept="image/jpeg,image/png,image/webp" class="sr-only" x-ref="telegramProductImage" x-on:change="updateTelegramProductImageFileName($event)">
                            <p class="mt-2 text-[10px] font-bold text-neo-ink/60" x-show="productModalMode === 'edit'">Tanpa upload baru, gambar detail bot tersimpan tetap dipakai.</p>
                            @error('telegram_image', 'productCreate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                            @error('telegram_image', 'productUpdate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                        </x-admin-dashboard.card>
                        <x-admin-dashboard.card padding="sm" shadow="none" class="rounded-[0.6rem]">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <x-admin-dashboard.field-label for="telegram-channel-product-image" class="text-sm normal-case tracking-normal">Poster stok bertambah · Store Channel</x-admin-dashboard.field-label>
                                    <p class="mt-1 text-[11px] font-semibold text-neo-ink/65">Hanya untuk posting stok bertambah di Store Channel; bukan sales proof, banner /start, detail bot, pesan pelanggan, atau broadcast. Jika kosong: gambar detail produk, detail family, kartu produk, lalu kartu family.</p>
                                </div>
                                <div x-show="telegramChannelImageUrl" x-cloak class="h-14 w-20 shrink-0 overflow-hidden rounded-xl border-[3px] border-neo-ink bg-white shadow-[3px_3px_0_0_#050505]">
                                    <img x-bind:src="telegramChannelImageUrl" alt="Poster stok Store Channel tersimpan" class="h-full w-full object-contain">
                                </div>
                            </div>
                            <div class="mt-2 flex min-w-0 items-center gap-3 rounded-[1rem] border-[3px] border-neo-ink bg-white p-2 shadow-[4px_4px_0_0_#050505]">
                                <label for="telegram-channel-product-image" class="shrink-0 cursor-pointer rounded-lg bg-neo-ink px-3 py-2 text-xs font-black text-white transition hover:bg-[#8758de]">Pilih poster stok</label>
                                <span class="min-w-0 flex-1 truncate text-xs font-black text-neo-ink" x-text="telegramChannelImageFileName">Tidak ada file yang dipilih</span>
                            </div>
                            <input type="file" id="telegram-channel-product-image" name="telegram_channel_image" accept="image/jpeg,image/png,image/webp" class="sr-only" x-ref="telegramChannelImage" x-on:change="telegramChannelImageFileName = $event.target.files[0]?.name ?? 'Tidak ada file yang dipilih'">
                            <p class="mt-2 text-[10px] font-bold text-neo-ink/60" x-show="productModalMode === 'edit'">Tanpa upload baru, poster tersimpan tetap dipakai.</p>
                            @error('telegram_channel_image', 'productCreate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                            @error('telegram_channel_image', 'productUpdate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                        </x-admin-dashboard.card>
                        </section>

                        <x-admin-dashboard.card padding="sm" shadow="none" class="rounded-[0.6rem]">
                            <x-admin-dashboard.field-label for="product-description" class="text-sm normal-case tracking-normal">📝 Deskripsi produk</x-admin-dashboard.field-label>
                            <x-admin-dashboard.textarea
                                id="product-description"
                                name="description"
                                rows="3"
                                placeholder="Deskripsi lengkap produk; emoji boleh digunakan..."
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
                            <x-admin-dashboard.field-label for="product-warranty-policy" class="text-sm normal-case tracking-normal">⚠️ Replacement &amp; Refund Policy</x-admin-dashboard.field-label>
                            <p class="mt-1 text-[10px] font-extrabold uppercase leading-4 text-neo-ink/60">Tampil sebagai alert di detail produk Telegram. Judul mengikuti bahasa pelanggan; isi ini berbeda untuk setiap produk.</p>
                            <x-admin-dashboard.textarea
                                id="product-warranty-policy"
                                name="warranty_policy"
                                rows="4"
                                placeholder="Contoh: Penggantian akun maksimal 1 kali dalam 7 hari. Refund mengikuti hasil pemeriksaan admin."
                                class="mt-2"
                                x-model="productWarrantyPolicy"
                            />
                            @error('warranty_policy', 'productCreate')
                                <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                            @enderror
                            @error('warranty_policy', 'productUpdate')
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
