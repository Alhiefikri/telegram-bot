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
            class="max-h-[92vh] w-full max-w-3xl overflow-y-auto"
        >
            @if ($errors->categoryCreate->any() || $errors->categoryUpdate->any() || $errors->categoryDelete->any())
                <x-admin-dashboard.notice tone="danger" class="mb-3 animate-slide-up">
                    @if ($errors->categoryDelete->any())
                        {{ $errors->categoryDelete->first('confirm_delete') }}
                    @else
                        Periksa kembali field kategori yang ditandai.
                    @endif
                </x-admin-dashboard.notice>
            @endif

            <div id="admin-category-management-content" data-admin-return-target tabindex="-1" class="space-y-4">
                <form method="POST" x-bind:action="categoryFormAction" x-on:submit="submitCategoryForm($event)" class="rounded-lg border border-admin-line bg-admin-surface p-3 sm:p-4">
                    @csrf
                    <input type="hidden" x-bind:name="categoryFormMethod ? '_method' : ''" x-bind:value="categoryFormMethod">
                    <input type="hidden" name="category_id" x-bind:value="categoryId">

                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-admin-ink" x-text="categoryFormMode === 'edit' ? 'Edit kategori' : 'Tambah kategori'"></p>
                            <p class="mt-1 text-xs text-admin-muted">Slug dibuat dan dijaga sistem. Kosongkan deskripsi jika tidak diperlukan.</p>
                        </div>
                        <x-admin-dashboard.button
                            x-cloak
                            x-show="categoryFormMode === 'edit'"
                            type="button"
                            variant="neutral"
                            size="sm"
                            x-on:click="resetCategoryForm()"
                        >
                            Batal edit
                        </x-admin-dashboard.button>
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
                            <select id="category-management-visibility" x-bind:value="categoryIsActive ? 'true' : 'false'" x-on:change="categoryIsActive = $event.target.value === 'true'" class="mt-1.5 block w-full min-h-11 rounded-lg border border-admin-line bg-admin-surface px-3 text-sm text-admin-ink focus:outline-none focus:ring-2 focus:ring-admin-focus focus:ring-offset-2 focus:ring-offset-admin-paper">
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
                        <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="self-end" x-bind:disabled="categoryFormSubmitting">
                            <span x-text="categoryFormMode === 'edit' ? 'Simpan perubahan' : '+ Kategori'"></span>
                        </x-admin-dashboard.button>
                    </div>
                </form>

                <x-admin-dashboard.card padding="compact" shadow="none">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-lg font-semibold text-admin-ink">Daftar kategori</p>
                            <p class="mt-1 text-sm leading-6 text-admin-muted">Kategori tersembunyi tetap bisa dipilih saat mengelola produk.</p>
                        </div>
                        <x-admin-dashboard.badge tone="emerald">{{ count($categoryManagement['categories']) }} total</x-admin-dashboard.badge>
                    </div>

                    <div class="mt-3 space-y-2">
                        @forelse ($categoryManagement['categories'] as $category)
                            <article id="admin-category-{{ $category['id'] }}" x-data="{ categoryEditPayload: @js($category['edit_payload']) }" class="rounded-lg border border-admin-line bg-admin-surface p-3">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="break-words text-base font-semibold text-admin-ink">{{ $category['name'] }}</h3>
                                            <span class="text-xs font-medium" style="color: {{ $category['status_color'] }}">{{ $category['status_label'] }}</span>
                                        </div>
                                        <p class="mt-1 break-words text-xs text-admin-muted">{{ $category['description'] ?: 'Tanpa deskripsi' }}</p>
                                        <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-admin-muted">
                                            <span>{{ $category['product_count'] }} produk</span>
                                            <span>Urutan {{ $category['sort_order'] }}</span>
                                        </div>
                                        <p class="mt-1 text-xs text-admin-muted">Status aktif, stok, dan reservasi produk tetap dipertahankan saat produk menjadi Tanpa kategori.</p>
                                    </div>

                                    <div class="flex shrink-0 gap-2 sm:w-44 sm:flex-col">
                                        <x-admin-dashboard.button type="button" variant="secondary" size="sm" class="flex-1" x-on:click="openCategoryEditModal(categoryEditPayload)">
                                            Edit
                                        </x-admin-dashboard.button>
                                        <form method="POST" action="{{ $category['delete_url'] }}" class="flex-1" data-category-delete-confirmation="{{ $category['delete_confirmation'] }}" x-on:submit="if (! confirm($el.dataset.categoryDeleteConfirmation)) $event.preventDefault()">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="confirm_delete" value="1">
                                            <x-admin-dashboard.button type="submit" variant="destructive" size="sm" class="w-full">
                                                Hapus
                                            </x-admin-dashboard.button>
                                        </form>
                                    </div>
                                </div>
                            </article>
                        @empty
                            <x-admin-dashboard.empty-state title="Belum ada kategori." />
                        @endforelse
                    </div>
                </x-admin-dashboard.card>
            </div>
        </x-admin-dashboard.modal-shell>
    </x-admin-dashboard.modal-backdrop>
@endif
