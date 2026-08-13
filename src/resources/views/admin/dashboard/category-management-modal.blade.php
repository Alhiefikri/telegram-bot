@if ($canManageProducts)
    <x-admin-dashboard.modal-backdrop show="categoryModalOpen" close="closeCategoryManagement()">
        <x-admin-dashboard.modal-shell
            id="admin-category-management"
            show="categoryModalOpen"
            title="Kelola Kategori"
            title-expression="categoryFormMode === 'edit' ? 'Edit kategori' : 'Kelola kategori'"
            description="Atur nama, deskripsi, visibilitas, urutan, dan hubungan produk dari satu tempat."
            data-admin-return-target
            tabindex="-1"
            class="max-h-[92vh] w-full max-w-3xl overflow-y-auto !bg-[#fff3a3]"
        >
            @if ($errors->categoryCreate->any() || $errors->categoryUpdate->any())
                <x-admin-dashboard.notice tone="danger" class="mb-3 animate-slide-up">
                    Periksa kembali field kategori yang ditandai.
                </x-admin-dashboard.notice>
            @endif

            <div id="admin-category-management-content" data-admin-return-target tabindex="-1" class="space-y-4">
                <form method="POST" x-bind:action="categoryFormAction" x-on:submit="submitCategoryForm($event)" class="rounded-[1rem] border-[3px] border-neo-ink bg-white/80 p-3 shadow-[3px_3px_0_#111827] sm:p-4">
                    @csrf
                    <input type="hidden" x-bind:name="categoryFormMethod ? '_method' : ''" x-bind:value="categoryFormMethod">
                    <input type="hidden" name="category_id" x-bind:value="categoryId">

                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-display text-sm font-black uppercase text-neo-ink" x-text="categoryFormMode === 'edit' ? 'Edit kategori' : 'Tambah kategori'"></p>
                            <p class="mt-1 text-[11px] font-bold text-neo-ink/60">Slug dibuat dan dijaga sistem. Kosongkan deskripsi jika tidak diperlukan.</p>
                        </div>
                        <button
                            x-cloak
                            x-show="categoryFormMode === 'edit'"
                            type="button"
                            class="rounded-lg border-2 border-neo-ink bg-white px-2.5 py-1.5 text-[10px] font-black uppercase text-neo-ink shadow-[2px_2px_0_#111827]"
                            x-on:click="resetCategoryForm()"
                        >
                            Batal edit
                        </button>
                    </div>

                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <x-admin-dashboard.field-label for="category-management-name">Nama kategori</x-admin-dashboard.field-label>
                            <x-admin-dashboard.input
                                id="category-management-name"
                                x-bind:name="categoryFormMode === 'edit' ? 'name' : 'category_name'"
                                class="mt-1.5"
                                placeholder="AI Tools"
                                x-model="categoryName"
                                x-ref="categoryName"
                                required
                            />
                            @error('category_name', 'categoryCreate')
                                <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                            @enderror
                            @error('name', 'categoryUpdate')
                                <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                            @enderror
                        </div>
                        <div>
                            <x-admin-dashboard.field-label for="category-management-description">Deskripsi opsional</x-admin-dashboard.field-label>
                            <x-admin-dashboard.input
                                id="category-management-description"
                                x-bind:name="categoryFormMode === 'edit' ? 'description' : 'category_description'"
                                class="mt-1.5"
                                placeholder="Kategori akun premium"
                                x-model="categoryDescription"
                            />
                            @error('category_description', 'categoryCreate')
                                <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                            @enderror
                            @error('description', 'categoryUpdate')
                                <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                            @enderror
                        </div>
                    </div>

                    <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto]">
                        <div>
                            <x-admin-dashboard.field-label for="category-management-visibility">Visibilitas pelanggan</x-admin-dashboard.field-label>
                            <select id="category-management-visibility" x-bind:value="categoryIsActive ? 'true' : 'false'" x-on:change="categoryIsActive = $event.target.value === 'true'" class="mt-1.5 block w-full rounded-lg border-[3px] border-neo-ink bg-white px-3 py-2.5 text-sm font-bold text-neo-ink shadow-[2px_2px_0_#111827] focus:outline-none focus:ring-2 focus:ring-neo-ink">
                                <option value="true">Tampil</option>
                                <option value="false">Disembunyikan</option>
                            </select>
                            <input x-bind:name="categoryFormMode === 'edit' ? 'is_active' : ''" type="hidden" x-bind:value="categoryIsActive ? 1 : 0">
                            @error('is_active', 'categoryUpdate')
                                <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                            @enderror
                        </div>
                        <div>
                            <x-admin-dashboard.field-label for="category-management-sort-order">Urutan tampil</x-admin-dashboard.field-label>
                            <x-admin-dashboard.input id="category-management-sort-order" type="number" step="1" inputmode="numeric" x-bind:name="categoryFormMode === 'edit' ? 'sort_order' : ''" x-model="categorySortOrder" class="mt-1.5" />
                            @error('sort_order', 'categoryUpdate')
                                <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                            @enderror
                        </div>
                        <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="self-end text-[11px]" x-bind:disabled="categoryFormSubmitting">
                            <span x-text="categoryFormMode === 'edit' ? 'Simpan perubahan' : '+ Kategori'"></span>
                        </x-admin-dashboard.button>
                    </div>
                </form>

                <div class="rounded-[1rem] border-[3px] border-neo-ink bg-white/80 p-3 shadow-[3px_3px_0_#111827] sm:p-4">
                    <div class="flex items-end justify-between gap-3">
                        <div>
                            <p class="font-display text-sm font-black uppercase text-neo-ink">Daftar kategori</p>
                            <p class="mt-1 text-[11px] font-bold text-neo-ink/60">Kategori tersembunyi tetap bisa dipilih saat mengelola produk.</p>
                        </div>
                        <span class="rounded-full border-2 border-neo-ink bg-[#7BD10A] px-2 py-1 text-[10px] font-black uppercase">{{ count($categoryManagement['categories']) }} total</span>
                    </div>

                    <div class="mt-3 space-y-2">
                        @forelse ($categoryManagement['categories'] as $category)
                            <article id="admin-category-{{ $category['id'] }}" x-data="{ categoryEditPayload: @js($category['edit_payload']) }" class="rounded-[0.85rem] border-2 border-neo-ink bg-white p-3 shadow-[2px_2px_0_#111827]">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="break-words font-display text-base font-black text-neo-ink">{{ $category['name'] }}</h3>
                                            <span class="rounded-full border-2 border-neo-ink px-2 py-0.5 text-[10px] font-black uppercase" style="color: {{ $category['status_color'] }}">{{ $category['status_label'] }}</span>
                                        </div>
                                        <p class="mt-1 break-words text-xs font-semibold text-neo-ink/65">{{ $category['description'] ?: 'Tanpa deskripsi' }}</p>
                                        <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[11px] font-black uppercase text-neo-ink/70">
                                            <span>{{ $category['product_count'] }} produk</span>
                                            <span>Urutan {{ $category['sort_order'] }}</span>
                                        </div>
                                        <p class="mt-1 text-[10px] font-bold text-neo-ink/55">Status aktif, stok, dan reservasi produk tetap dipertahankan saat produk menjadi Tanpa kategori.</p>
                                    </div>

                                    <div class="flex shrink-0 gap-2 sm:w-44 sm:flex-col">
                                        <x-admin-dashboard.button type="button" variant="secondary" size="sm" class="flex-1 text-[10px]" x-on:click="openCategoryEditModal(categoryEditPayload)">
                                            ✏️ Edit
                                        </x-admin-dashboard.button>
                                        <form method="POST" action="{{ $category['delete_url'] }}" class="flex-1" data-category-delete-confirmation="{{ $category['delete_confirmation'] }}" x-on:submit="if (! confirm($el.dataset.categoryDeleteConfirmation)) $event.preventDefault()">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="confirm_delete" value="1">
                                            <x-admin-dashboard.button type="submit" variant="destructive" size="sm" class="w-full text-[10px]">
                                                🗑️ Hapus
                                            </x-admin-dashboard.button>
                                        </form>
                                    </div>
                                </div>
                            </article>
                        @empty
                            <x-admin-dashboard.empty-state title="Belum ada kategori." />
                        @endforelse
                    </div>
                </div>
            </div>
        </x-admin-dashboard.modal-shell>
    </x-admin-dashboard.modal-backdrop>
@endif
