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
            class="max-h-[92vh] w-full max-w-3xl overflow-y-auto !bg-[#d9c8ff]"
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

            <form method="POST" enctype="multipart/form-data" x-bind:action="familyFormAction" x-on:submit="submitProductFamilyForm($event)" class="rounded-[1rem] border-[3px] border-neo-ink bg-white/80 p-3 shadow-[3px_3px_0_#111827] sm:p-4">
                @csrf
                <input type="hidden" x-bind:name="familyFormMethod ? '_method' : ''" x-bind:value="familyFormMethod">
                <input type="hidden" name="family_id" x-bind:value="familyId">

                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-display text-sm font-black uppercase text-neo-ink" x-text="familyFormMode === 'edit' ? 'Edit Product Family' : 'Tambah Product Family'"></p>
                        <p class="mt-1 text-[11px] font-bold text-neo-ink/60">Slug dibuat sistem dan tetap stabil saat nama berubah.</p>
                    </div>
                    <button x-cloak x-show="familyFormMode === 'edit'" type="button" class="rounded-lg border-2 border-neo-ink bg-white px-2.5 py-1.5 text-[10px] font-black uppercase text-neo-ink shadow-[2px_2px_0_#111827]" x-on:click="resetProductFamilyForm()">Batal edit</button>
                </div>

                <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto]">
                    <div>
                        <x-admin-dashboard.field-label for="product-family-name">Nama family</x-admin-dashboard.field-label>
                        <x-admin-dashboard.input id="product-family-name" name="name" maxlength="160" placeholder="NETFLIX" class="mt-1.5" x-model="familyName" x-ref="familyName" required />
                        @error('name', 'productFamilyCreate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                        @error('name', 'productFamilyUpdate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                    </div>
                    <div>
                        <x-admin-dashboard.field-label for="product-family-description">Deskripsi opsional</x-admin-dashboard.field-label>
                        <x-admin-dashboard.input id="product-family-description" name="description" maxlength="1000" placeholder="Pilihan paket Netflix" class="mt-1.5" x-model="familyDescription" />
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

                <div class="mt-3 rounded-xl border-2 border-neo-ink bg-white p-3 shadow-[2px_2px_0_#111827]">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <x-admin-dashboard.field-label for="product-family-image">Gambar family</x-admin-dashboard.field-label>
                            <p class="mt-1 text-[10px] font-bold text-neo-ink/60">JPG, PNG, atau WebP. Maksimal 5 MB; otomatis menjadi WebP maksimal 1600px.</p>
                        </div>
                        <div x-show="familyImageUrl" x-cloak class="h-14 w-14 shrink-0 overflow-hidden rounded-xl border-[3px] border-neo-ink bg-white shadow-[3px_3px_0_#050505]"><img x-bind:src="familyImageUrl" alt="" class="h-full w-full object-cover"></div>
                    </div>
                    <div class="mt-2 flex min-w-0 items-center gap-3 rounded-lg border-2 border-neo-ink bg-white p-2">
                        <label for="product-family-image" class="shrink-0 cursor-pointer rounded-md bg-neo-ink px-3 py-2 text-xs font-black text-white">Pilih gambar</label>
                        <span class="min-w-0 flex-1 truncate text-xs font-black text-neo-ink" x-text="familyImageFileName">Tidak ada file yang dipilih</span>
                    </div>
                    <input type="file" id="product-family-image" name="image" accept="image/jpeg,image/png,image/webp" class="sr-only" x-on:change="familyImageFileName = $event.target.files[0]?.name ?? 'Tidak ada file yang dipilih'">
                    <p class="mt-2 text-[10px] font-bold text-neo-ink/60" x-show="familyFormMode === 'edit'">Kosongkan kalau gambar lama tetap dipakai.</p>
                    @error('image', 'productFamilyCreate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                    @error('image', 'productFamilyUpdate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                </div>
                <div class="mt-3 rounded-xl border-2 border-neo-ink bg-white p-3 shadow-[2px_2px_0_#111827]">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <x-admin-dashboard.field-label for="telegram-product-family-image">Gambar Telegram family</x-admin-dashboard.field-label>
                            <p class="mt-1 text-[10px] font-bold text-neo-ink/60">Opsional. Default gambar Telegram untuk semua produk dalam family yang tidak memiliki gambar Telegram sendiri. Tidak mengubah gambar Storefront.</p>
                        </div>
                        <div x-show="telegramFamilyImageUrl" x-cloak class="h-14 w-20 shrink-0 overflow-hidden rounded-xl border-[3px] border-neo-ink bg-white shadow-[3px_3px_0_#050505]"><img x-bind:src="telegramFamilyImageUrl" alt="" class="h-full w-full object-contain"></div>
                    </div>
                    <div class="mt-2 flex min-w-0 items-center gap-3 rounded-lg border-2 border-neo-ink bg-white p-2">
                        <label for="telegram-product-family-image" class="shrink-0 cursor-pointer rounded-md bg-neo-ink px-3 py-2 text-xs font-black text-white">Pilih gambar</label>
                        <span class="min-w-0 flex-1 truncate text-xs font-black text-neo-ink" x-text="telegramFamilyImageFileName">Tidak ada file yang dipilih</span>
                    </div>
                    <input type="file" id="telegram-product-family-image" name="telegram_image" accept="image/jpeg,image/png,image/webp" class="sr-only" x-on:change="telegramFamilyImageFileName = $event.target.files[0]?.name ?? 'Tidak ada file yang dipilih'">
                    <p class="mt-2 text-[10px] font-bold text-neo-ink/60" x-show="familyFormMode === 'edit'">Kosongkan kalau gambar Telegram family lama tetap dipakai.</p>
                    @error('telegram_image', 'productFamilyCreate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                    @error('telegram_image', 'productFamilyUpdate') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
                </div>

                <div class="mt-3 grid grid-cols-2 gap-2">
                    <x-admin-dashboard.button type="button" variant="neutral" x-on:click="closeProductFamilyModal()" x-bind:disabled="familyFormSubmitting">Batal</x-admin-dashboard.button>
                    <x-admin-dashboard.button type="submit" variant="pink" x-bind:disabled="familyFormSubmitting"><span x-text="familyFormSubmitting ? 'Menyimpan...' : (familyFormMode === 'edit' ? '⚡ Simpan' : '⚡ Tambah family')">⚡ Tambah family</span></x-admin-dashboard.button>
                </div>
            </form>

            <section class="mt-4 rounded-[1rem] border-[3px] border-neo-ink bg-white/80 p-3 shadow-[3px_3px_0_#111827] sm:p-4" aria-labelledby="product-family-list-title">
                <div class="flex items-end justify-between gap-3">
                    <div>
                        <h3 id="product-family-list-title" class="font-display text-sm font-black uppercase text-neo-ink">Daftar Product Family</h3>
                        <p class="mt-1 text-[11px] font-bold text-neo-ink/60">Hapus family hanya melepas grouping; semua state Product tetap ada.</p>
                    </div>
                    <span class="rounded-full border-2 border-neo-ink bg-neo-green px-2 py-1 text-[10px] font-black uppercase">{{ count($familyManagement['families']) }} total</span>
                </div>

                <div class="mt-3 space-y-2">
                    @forelse ($familyManagement['families'] as $family)
                        <article id="admin-product-family-{{ $family['id'] }}" x-data="{ familyEditPayload: @js($family['edit_payload']) }" class="rounded-[0.85rem] border-2 border-neo-ink bg-white p-3 shadow-[2px_2px_0_#111827]">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h4 class="break-words font-display text-base font-black text-neo-ink">{{ $family['name'] }}</h4>
                                        <span class="rounded-full border-2 border-neo-ink bg-neo-purple px-2 py-0.5 text-[10px] font-black uppercase">{{ $family['member_count'] }} Product</span>
                                    </div>
                                    <p class="mt-1 break-words text-xs font-semibold text-neo-ink/65">{{ $family['description'] ?: 'Tanpa deskripsi' }}</p>
                                    <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[11px] font-black uppercase text-neo-ink/70">
                                        <span>{{ $family['available_member_count'] }} tersedia</span>
                                        <span>{{ $family['sold_out_member_count'] }} habis</span>
                                        <span>Urutan {{ $family['sort_order'] }}</span>
                                    </div>
                                </div>
                                <div class="flex shrink-0 gap-2 sm:w-44 sm:flex-col">
                                    <x-admin-dashboard.button type="button" variant="secondary" size="sm" class="flex-1 text-[10px]" x-on:click="openProductFamilyEditModal(familyEditPayload)">✏️ Edit</x-admin-dashboard.button>
                                    <form method="POST" action="{{ $family['delete_url'] }}" class="flex-1" x-on:submit="if (! confirm(@js($family['delete_confirmation']))) $event.preventDefault()">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="confirm_delete" value="1">
                                        <x-admin-dashboard.button type="submit" variant="destructive" size="sm" class="w-full text-[10px]">🗑️ Hapus</x-admin-dashboard.button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    @empty
                        <x-admin-dashboard.empty-state title="Belum ada Product Family." />
                    @endforelse
                </div>
            </section>
        </x-admin-dashboard.modal-shell>
    </x-admin-dashboard.modal-backdrop>
@endif
