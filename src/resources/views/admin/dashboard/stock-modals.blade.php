                @if ($canManageProducts)
                {{-- ════════════════════════════════════════════
                     STOCK MODAL
                     ════════════════════════════════════════════ --}}
                <div
                    x-cloak
                    x-show="stockModalOpen"
                    x-transition.opacity
                    x-on:keydown.escape.window="closeStockModal()"
                    class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4"
                    style="background: rgba(5,5,5,0.6);"
                >
                    <div class="absolute inset-0" x-on:click="closeStockModal()" aria-hidden="true"></div>

                    <x-admin-dashboard.modal-shell
                        id="stock-modal"
                        title="Tambah stok"
                        description="1 baris = 1 item"
                        class="w-full max-w-md bg-[#7BD10A]"
                    >
                        <form method="POST" x-bind:action="stockFormAction" class="space-y-3">
                            @csrf
                            <input type="hidden" name="stock_product_id" x-bind:value="stockProductId">

                            <div class="flex flex-wrap items-center justify-between gap-2 text-xs font-extrabold uppercase text-neo-ink">
                                <span class="max-w-[13rem] truncate" x-text="stockProductName">Produk</span>
                                <span x-text="`${stockItemCount()} item`">0 item</span>
                            </div>

                            <div>
                                <textarea
                                    id="stock-items"
                                    name="stock_items"
                                    rows="6"
                                    placeholder="email@example.com|password&#10;KEY-XXXX-1234"
                                    class="neo-textarea"
                                    x-model="stockItems"
                                    x-ref="stockItems"
                                ></textarea>

                                @error('stock_items', 'stockAdjustment')
                                    <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="grid grid-cols-2 gap-2 pt-1">
                                <button type="button" class="neo-btn neo-btn-white" x-on:click="closeStockModal()">
                                    Batal
                                </button>
                                <button type="submit" class="neo-btn neo-btn-pink">
                                    ⚡ Simpan
                                </button>
                            </div>
                        </form>
                    </x-admin-dashboard.modal-shell>
                </div>

                <div
                    x-cloak
                    x-show="manageStockModalOpen"
                    x-transition.opacity
                    x-on:keydown.escape.window="closeManageStockModal()"
                    class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4"
                    style="background: rgba(5,5,5,0.6);"
                >
                    <div class="absolute inset-0" x-on:click="closeManageStockModal()" aria-hidden="true"></div>

                    <x-admin-dashboard.modal-shell
                        id="manage-stock-modal"
                        title="Kelola stok"
                        description="Akun tersedia, belum dipesan pembeli."
                        class="max-h-[92vh] w-full max-w-xl overflow-y-auto bg-[#fee648] p-3 sm:p-4"
                    >
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2 text-xs font-extrabold uppercase text-neo-ink">
                            <span class="max-w-[15rem] truncate" x-text="manageStockProductName">Produk</span>
                            <span x-text="`${manageStockItems.length} dari ${manageStockAvailableCount} akun`">0 akun</span>
                        </div>

                        <p x-show="manageStockTruncated" class="mb-2 rounded-[0.45rem] border-[3px] border-neo-ink bg-white p-2 text-[11px] font-bold text-neo-ink shadow-[2px_2px_0_0_#050505]">
                            25 akun pertama ditampilkan. Hapus/edit yang lama dulu untuk melihat sisanya.
                        </p>

                        <div class="space-y-2">
                            <template x-for="(item, index) in manageStockItems" :key="item.id">
                                <article class="rounded-[0.45rem] border-[3px] border-neo-ink bg-white p-2 shadow-[3px_3px_0_0_#050505]">
                                    <div class="grid grid-cols-[1fr_auto] gap-2">
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <span class="font-display text-[11px] font-black text-neo-ink" x-text="`#${index + 1}`">#1</span>
                                                <span class="rounded-[0.35rem] border-2 border-[#93c5fd] bg-[#dbeafe] px-1.5 py-0.5 text-[8px] font-black uppercase text-[#1d4ed8]">Tersedia</span>
                                            </div>
                                            <p class="mt-1.5 break-all font-mono text-[10px] font-bold leading-4 text-neo-ink" x-text="item.secret"></p>
                                        </div>
                                        <div class="flex flex-col gap-1.5">
                                            <button
                                                type="button"
                                                class="inline-flex h-9 w-9 items-center justify-center rounded-[0.35rem] border-[3px] border-neo-ink bg-[#16b4ca] text-sm font-black text-white shadow-[2px_2px_0_0_#050505]"
                                                aria-label="Ubah akun stok"
                                                x-bind:aria-expanded="item.editing"
                                                x-on:click="item.editing = ! item.editing"
                                            >✏️</button>
                                            <form method="POST" x-bind:action="item.delete_url" x-on:submit="if (! confirm('Hapus akun stok ini?')) $event.preventDefault()">
                                                @csrf
                                                <input type="hidden" name="_method" value="DELETE">
                                                <button
                                                    type="submit"
                                                    class="inline-flex h-9 w-9 items-center justify-center rounded-[0.35rem] border-[3px] border-neo-ink bg-[#db3f98] text-sm font-black text-white shadow-[2px_2px_0_0_#050505]"
                                                    aria-label="Hapus akun stok"
                                                >🗑️</button>
                                            </form>
                                        </div>
                                    </div>
                                    <form method="POST" x-bind:action="item.update_url" class="mt-2 grid gap-2 sm:grid-cols-[1fr_auto]" x-show="item.editing" x-transition>
                                        @csrf
                                        <input type="hidden" name="_method" value="PATCH">
                                        <textarea name="secret" rows="2" class="neo-textarea min-h-16 font-mono text-[11px]" x-model="item.secret"></textarea>
                                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-1">
                                            <button type="submit" class="product-action-btn min-h-9 self-start" style="background: #16b4ca; color: #fff;">Simpan</button>
                                            <button type="button" class="product-action-btn min-h-9 self-start" style="background: #fff; color: #050505;" x-on:click="item.editing = false">Batal</button>
                                        </div>
                                    </form>
                                </article>
                            </template>
                        </div>

                        <div x-show="manageStockItems.length === 0" class="rounded-[0.6rem] border-[3px] border-dashed border-neo-ink bg-white p-4 text-center text-sm font-bold text-neo-ink">
                            Belum ada akun tersedia. Tambahkan stok baru.
                        </div>

                        <button type="button" class="neo-btn neo-btn-green mt-4 w-full" x-on:click="openStockModalFromManage()">
                            ➕ Tambah stok baru
                        </button>
                    </x-admin-dashboard.modal-shell>
                </div>
                @endif
