@if ($canManageProducts)
    <x-admin-dashboard.modal-backdrop show="familyModalOpen" close="closeProductFamilyModal()">
        <x-admin-dashboard.modal-shell
            id="admin-product-family-management"
            show="familyModalOpen"
            title="Kelola Product Family"
            title-expression="familyFormMode === 'edit' ? 'Edit Product Family' : 'Kelola Product Family'"
            description="Atur identitas grouping tanpa mengubah harga, stok, credentials, atau checkout Product."
            data-admin-return-target
            tabindex="-1"
            class="max-h-[92vh] w-full max-w-3xl overflow-y-auto"
        >
            @if ($errors->productFamilyCreate->any() || $errors->productFamilyUpdate->any() || $errors->productFamilyDelete->any())
                <x-admin-dashboard.notice tone="danger" class="mb-3 animate-slide-up">
                    @if ($errors->productFamilyDelete->any())
                        {{ $errors->productFamilyDelete->first('confirm_delete') }}
                    @else
                        Periksa kembali field Product Family yang ditandai.
                    @endif
                </x-admin-dashboard.notice>
            @endif

            <form method="POST" enctype="multipart/form-data" x-bind:action="familyFormAction" x-on:submit="submitProductFamilyForm($event)" class="rounded-lg border border-admin-line bg-admin-surface p-3 sm:p-4">
                @csrf
                <input type="hidden" x-bind:name="familyFormMethod ? '_method' : ''" x-bind:value="familyFormMethod">
                <input type="hidden" name="family_id" x-bind:value="familyId">

                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-admin-ink" x-text="familyFormMode === 'edit' ? 'Edit Product Family' : 'Tambah Product Family'"></p>
                        <p class="mt-1 text-xs text-admin-muted">Slug dibuat sistem saat tambah dan dapat diubah saat edit; alamat lama tetap dialihkan.</p>
                    </div>
                    <x-admin-dashboard.button x-cloak x-show="familyFormMode === 'edit'" type="button" variant="neutral" size="sm" x-on:click="resetProductFamilyForm()">Batal edit</x-admin-dashboard.button>
                </div>

                <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto]">
                    <div>
                        <x-admin-dashboard.field-label for="product-family-name">Nama family</x-admin-dashboard.field-label>
                        <x-admin-dashboard.input id="product-family-name" name="name" maxlength="160" placeholder="NETFLIX" class="mt-1.5" x-model="familyName" x-ref="familyName" required />
                        @error('name', 'productFamilyCreate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                        @error('name', 'productFamilyUpdate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                    </div>
                    <div>
                        <x-admin-dashboard.field-label for="product-family-icon">Ikon katalog · emoji/karakter (opsional)</x-admin-dashboard.field-label>
                        <x-admin-dashboard.input id="product-family-icon" name="icon" maxlength="32" placeholder="🎨" autocomplete="off" class="mt-1.5" x-model="familyIcon" />
                        <p class="mt-1 text-xs leading-5 text-admin-muted">Dipakai semua varian family yang belum punya ikon sendiri. Kosongkan untuk ikon default. Emoji Telegram (Premium) diambil lewat menu 🎨 Ikon Produk di bot.</p>
                        @error('icon', 'productFamilyCreate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                        @error('icon', 'productFamilyUpdate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                    </div>
                    <div>
                        <x-admin-dashboard.field-label for="product-family-description">Deskripsi opsional</x-admin-dashboard.field-label>
                        <x-admin-dashboard.textarea id="product-family-description" name="description" rows="3" placeholder="Pilihan paket Netflix" class="mt-1.5" x-model="familyDescription" />
                        @error('description', 'productFamilyCreate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                        @error('description', 'productFamilyUpdate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                    </div>
                    <div>
                        <x-admin-dashboard.field-label for="product-family-sort-order">Urutan</x-admin-dashboard.field-label>
                        <x-admin-dashboard.input id="product-family-sort-order" type="number" name="sort_order" min="0" max="999999" step="1" inputmode="numeric" class="mt-1.5" x-model="familySortOrder" required />
                        @error('sort_order', 'productFamilyCreate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                        @error('sort_order', 'productFamilyUpdate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                    </div>
                </div>
                <div x-cloak x-show="familyFormMode === 'edit'" class="mt-3">
                    <x-admin-dashboard.field-label for="product-family-slug">Alamat publik (slug)</x-admin-dashboard.field-label>
                    <x-admin-dashboard.input id="product-family-slug" name="slug" maxlength="160" placeholder="netflix" class="mt-1.5" x-model="familySlug" x-bind:required="familyFormMode === 'edit'" />
                    <p class="mt-1 text-xs text-admin-muted">Gunakan huruf, angka, dan tanda hubung. Perubahan membuat alamat lama tetap bekerja lewat redirect.</p>
                    @error('slug', 'productFamilyUpdate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                </div>

                <div class="mt-3 rounded-lg border border-admin-line bg-admin-surface-muted p-3">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <x-admin-dashboard.field-label for="product-family-image">Kartu family · Web Storefront</x-admin-dashboard.field-label>
                            <p class="mt-1 text-xs leading-5 text-admin-muted">Untuk kartu family di toko web, bukan kartu produk. Cadangan terakhir poster stok Channel saat produk tanpa gambar lain. JPG, PNG, WebP; maks. 5 MB, otomatis WebP maks. 1600px.</p>
                        </div>
                        <div x-show="familyImageUrl" x-cloak class="h-14 w-14 shrink-0 overflow-hidden rounded-lg border border-admin-line bg-admin-surface"><img x-bind:src="familyImageUrl" alt="Gambar kartu family Storefront tersimpan" class="h-full w-full object-cover"></div>
                    </div>
                    <x-admin-dashboard.file-upload
                        id="product-family-image"
                        name="image"
                        label="Pilih gambar toko"
                        feedback-expression="familyImageFileName"
                        x-ref="familyImage"
                        x-on:change="familyImageFileName = $event.target.files[0]?.name ?? 'Tidak ada file yang dipilih'"
                    />
                    <p class="mt-2 text-xs text-admin-muted" x-show="familyFormMode === 'edit'">Tanpa upload baru, gambar toko family tersimpan tetap dipakai.</p>
                    @error('image', 'productFamilyCreate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                    @error('image', 'productFamilyUpdate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                </div>
                <div class="mt-3 rounded-lg border border-admin-line bg-admin-surface-muted p-3">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <x-admin-dashboard.field-label for="telegram-product-family-image">Default detail family · Bot Telegram</x-admin-dashboard.field-label>
                            <p class="mt-1 text-xs leading-5 text-admin-muted">Untuk detail produk tanpa gambar bot sendiri. Juga cadangan poster stok Store Channel setelah gambar detail produk; bukan gambar Storefront. Disarankan landscape 16:9.</p>
                        </div>
                        <div x-show="telegramFamilyImageUrl" x-cloak class="h-14 w-20 shrink-0 overflow-hidden rounded-lg border border-admin-line bg-admin-surface"><img x-bind:src="telegramFamilyImageUrl" alt="Gambar detail family Telegram tersimpan" class="h-full w-full object-contain"></div>
                    </div>
                    <x-admin-dashboard.file-upload
                        id="telegram-product-family-image"
                        name="telegram_image"
                        label="Pilih gambar bot"
                        feedback-expression="telegramFamilyImageFileName"
                        x-ref="telegramFamilyImage"
                        x-on:change="telegramFamilyImageFileName = $event.target.files[0]?.name ?? 'Tidak ada file yang dipilih'"
                    />
                    <p class="mt-2 text-xs text-admin-muted" x-show="familyFormMode === 'edit'">Tanpa upload baru, gambar detail family tersimpan tetap dipakai.</p>
                    @error('telegram_image', 'productFamilyCreate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                    @error('telegram_image', 'productFamilyUpdate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                </div>

                <div class="mt-3 grid grid-cols-2 gap-2">
                    <x-admin-dashboard.button type="button" variant="neutral" x-on:click="closeProductFamilyModal()" x-bind:disabled="familyFormSubmitting">Batal</x-admin-dashboard.button>
                    <x-admin-dashboard.button type="submit" variant="primary" x-bind:disabled="familyFormSubmitting"><span x-text="familyFormSubmitting ? 'Menyimpan...' : (familyFormMode === 'edit' ? 'Simpan' : 'Tambah family')">Tambah family</span></x-admin-dashboard.button>
                </div>
            </form>

            <x-admin-dashboard.card as="section" padding="compact" shadow="none" class="mt-4" aria-labelledby="product-family-list-title">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div class="min-w-0">
                        <h3 id="product-family-list-title" class="text-lg font-semibold text-admin-ink">Daftar Product Family</h3>
                        <p class="mt-1 text-sm leading-6 text-admin-muted">Hapus family hanya melepas grouping; semua state Product tetap ada.</p>
                    </div>
                    <x-admin-dashboard.badge tone="emerald">{{ count($familyManagement['families']) }} total</x-admin-dashboard.badge>
                </div>

                <div class="mt-3 space-y-2">
                    @forelse ($familyManagement['families'] as $family)
                        <article id="admin-product-family-{{ $family['id'] }}" x-data="{ familyEditPayload: @js($family['edit_payload']) }" class="rounded-lg border border-admin-line bg-admin-surface p-3">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h4 class="break-words text-base font-semibold text-admin-ink">{{ $family['name'] }}</h4>
                                        <x-admin-dashboard.badge tone="purple">{{ $family['member_count'] }} Product</x-admin-dashboard.badge>
                                    </div>
                                    <p class="mt-1 break-words text-xs text-admin-muted">{{ $family['description'] ?: 'Tanpa deskripsi' }}</p>
                                    <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-admin-muted">
                                        <span>{{ $family['available_member_count'] }} tersedia</span>
                                        <span>{{ $family['sold_out_member_count'] }} habis</span>
                                        <span>Urutan {{ $family['sort_order'] }}</span>
                                    </div>
                                </div>
                                <div class="flex shrink-0 gap-2 sm:w-44 sm:flex-col">
                                    <x-admin-dashboard.button type="button" variant="secondary" size="sm" class="flex-1" x-on:click="openProductFamilyEditModal(familyEditPayload)">Edit</x-admin-dashboard.button>
                                    <form method="POST" action="{{ $family['delete_url'] }}" class="flex-1" x-on:submit="if (! confirm(@js($family['delete_confirmation']))) $event.preventDefault()">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="confirm_delete" value="1">
                                        <x-admin-dashboard.button type="submit" variant="destructive" size="sm" class="w-full">Hapus</x-admin-dashboard.button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    @empty
                        <x-admin-dashboard.empty-state title="Belum ada Product Family." />
                    @endforelse
                </div>
            </x-admin-dashboard.card>
        </x-admin-dashboard.modal-shell>
    </x-admin-dashboard.modal-backdrop>
@endif
