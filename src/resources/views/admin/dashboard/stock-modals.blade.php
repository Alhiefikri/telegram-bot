                @if ($canManageProducts)
                {{-- ════════════════════════════════════════════
                     STOCK MODAL
                     ════════════════════════════════════════════ --}}
                <x-admin-dashboard.modal-backdrop show="stockModalOpen" close="closeStockModal()">
                    <x-admin-dashboard.modal-shell
                        id="stock-modal"
                        show="stockModalOpen"
                        title="Tambah stok"
                        description="1 baris = 1 item"
                        class="w-full max-w-md bg-[#7BD10A]"
                    >
                        <form method="POST" x-bind:action="stockFormAction" x-on:submit="submitStockForm($event)" class="space-y-3">
                            @csrf
                            <input type="hidden" name="stock_product_id" x-bind:value="stockProductId">

                            <div class="flex flex-wrap items-center justify-between gap-2 text-xs font-extrabold uppercase text-neo-ink">
                                <span class="max-w-[13rem] truncate" x-text="stockProductName">Produk</span>
                                <span x-text="`${stockItemCount()} item`">0 item</span>
                            </div>

                            <div>
                                <x-admin-dashboard.textarea
                                    id="stock-items"
                                    name="stock_items"
                                    rows="6"
                                    placeholder="email@example.com|password&#10;KEY-XXXX-1234"
                                    x-model="stockItems"
                                    x-ref="stockItems"
                                    required
                                />

                                @error('stock_items', 'stockAdjustment')
                                    <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                                @enderror
                            </div>

                            <div class="grid grid-cols-2 gap-2 pt-1">
                                <x-admin-dashboard.button type="button" variant="neutral" x-on:click="closeStockModal()" x-bind:disabled="stockFormSubmitting">
                                    Batal
                                </x-admin-dashboard.button>
                                <x-admin-dashboard.button type="submit" variant="pink" x-bind:disabled="stockFormSubmitting" x-bind:class="{ 'cursor-wait opacity-70': stockFormSubmitting }">
                                    <span x-text="stockFormSubmitting ? 'Menyimpan...' : '⚡ Simpan'">⚡ Simpan</span>
                                </x-admin-dashboard.button>
                            </div>
                        </form>
                    </x-admin-dashboard.modal-shell>
                </x-admin-dashboard.modal-backdrop>

                <x-admin-dashboard.modal-backdrop show="manageStockModalOpen" close="closeManageStockModal()">
                    <x-admin-dashboard.modal-shell
                        id="manage-stock-modal"
                        show="manageStockModalOpen"
                        title="Kelola stok"
                        description="Ready dikelompokkan paling atas, paid paling bawah."
                        class="max-h-[92vh] w-full max-w-xl overflow-y-auto bg-[#fee648] p-3 sm:p-4"
                    >
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2 text-xs font-extrabold uppercase text-neo-ink">
                            <span class="max-w-[15rem] truncate" x-text="manageStockProductName">Produk</span>
                            <span x-text="`${manageStockSummary.available} tersedia · ${manageStockSummary.reserved} dipesan · ${manageStockSummary.paid} paid`">0 tersedia · 0 dipesan · 0 paid</span>
                        </div>

                        <x-admin-dashboard.notice tone="neutral" x-show="manageStockTruncated" class="mb-2 rounded-[0.45rem] p-2 text-[11px]">
                            Ringkasan menghitung semua akun. Hanya 25 akun terbaru per status yang ditampilkan di riwayat.
                        </x-admin-dashboard.notice>

                        <div class="space-y-3">
                            <template x-for="group in manageStockGroups()" :key="group.key">
                                <section class="rounded-[0.55rem] border-2 border-neo-ink bg-white/80 p-2 shadow-[3px_3px_0_0_#050505]">
                                    <div class="flex flex-wrap items-center justify-between gap-2 border-b-2 border-dashed border-neo-ink pb-2">
                                        <div class="flex min-w-0 items-center gap-2">
                                            <span class="h-3 w-3 shrink-0 rounded-full border-2 border-neo-ink" x-bind:style="{ backgroundColor: group.color }"></span>
                                            <span class="font-display text-[11px] font-black uppercase text-neo-ink" x-text="group.label">READY / TERSEDIA</span>
                                        </div>
                                        <span class="rounded-[0.35rem] border-2 border-neo-ink bg-[#fff6cf] px-2 py-0.5 text-[9px] font-black uppercase text-neo-ink" x-text="`${group.items.length} akun`">0 akun</span>
                                    </div>
                                    <p class="mt-2 text-[10px] font-bold leading-4 text-[#475569]" x-text="group.helper"></p>

                                    <div class="mt-2 space-y-2">
                                        <template x-for="item in group.items" :key="item.id">
                                            <x-admin-dashboard.card as="article" padding="sm" shadow="xs" class="rounded-[0.45rem]">
                                                <div class="grid grid-cols-[1fr_auto] gap-2">
                                                    <div class="min-w-0">
                                                        <div class="flex flex-wrap items-center gap-1.5">
                                                            <span class="font-display text-[11px] font-black text-neo-ink" x-text="`#${item.displayIndex}`">#1</span>
                                                            <span class="rounded-[0.35rem] border-2 border-neo-ink px-1.5 py-0.5 text-[8px] font-black uppercase shadow-none" x-bind:class="{ 'bg-[#38bdf8]': item.status === 'TERSEDIA', 'bg-[#facc15]': item.status === 'DIPESAN', 'bg-[#7BD10A]': item.status === 'PAID', 'bg-[#fb7185] text-white': item.status === 'EXPIRED' || item.status === 'CANCELLED' }" x-text="item.status">TERSEDIA</span>
                                                            <span x-show="item.metadata" class="text-[9px] font-extrabold normal-case text-[#475569]" x-text="item.metadata"></span>
                                                            <span x-show="item.repair_needed" class="rounded-[0.35rem] border-2 border-neo-ink bg-white px-1 py-0.5 text-[8px] font-black uppercase">Perlu repair</span>
                                                        </div>
                                                        <p class="mt-1.5 break-all font-mono text-[10px] font-bold leading-4 text-neo-ink" x-text="item.secret"></p>
                                                    </div>
                                                    <div class="flex flex-col gap-1.5">
                                                        <x-admin-dashboard.button
                                                            type="button"
                                                            variant="secondary"
                                                            size="icon"
                                                            class="rounded-[0.35rem]"
                                                            aria-label="Ubah akun stok"
                                                            x-bind:aria-expanded="item.editing"
                                                            x-on:click="item.editing = ! item.editing"
                                                            x-show="item.editable"
                                                        >✏️</x-admin-dashboard.button>
                                                        <form method="POST" x-bind:action="item.delete_url" x-show="item.editable" x-on:submit="if (! confirm('Hapus akun stok ini?')) $event.preventDefault()">
                                                            @csrf
                                                            <input type="hidden" name="_method" value="DELETE">
                                                            <x-admin-dashboard.button
                                                                type="submit"
                                                                variant="pink"
                                                                size="icon"
                                                                class="rounded-[0.35rem]"
                                                                aria-label="Hapus akun stok"
                                                            >🗑️</x-admin-dashboard.button>
                                                        </form>
                                                        <form method="POST" x-bind:action="item.cancel_release_url" x-show="item.cancel_release_url" x-on:submit="if (! confirm('Batalkan order dan lepas stok ini?')) $event.preventDefault()">
                                                            @csrf
                                                            <x-admin-dashboard.button
                                                                type="submit"
                                                                variant="warning"
                                                                size="icon"
                                                                class="rounded-[0.35rem]"
                                                                aria-label="Batalkan order dan lepas stok"
                                                            >↩</x-admin-dashboard.button>
                                                        </form>
                                                    </div>
                                                </div>
                                                <form method="POST" x-bind:action="item.update_url" class="mt-2 grid gap-2 sm:grid-cols-[1fr_auto]" x-show="item.editing" x-transition>
                                                    @csrf
                                                    <input type="hidden" name="_method" value="PATCH">
                                                    <x-admin-dashboard.textarea name="secret" rows="2" class="min-h-16 font-mono text-[11px]" x-model="item.secret" />
                                                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-1">
                                                        <x-admin-dashboard.button type="submit" variant="secondary" size="sm" class="self-start">Simpan</x-admin-dashboard.button>
                                                        <x-admin-dashboard.button type="button" variant="neutral" size="sm" class="self-start" x-on:click="item.editing = false">Batal</x-admin-dashboard.button>
                                                    </div>
                                                </form>
                                            </x-admin-dashboard.card>
                                        </template>
                                    </div>
                                </section>
                            </template>
                        </div>

                        <x-admin-dashboard.empty-state
                            title="Belum ada akun tersedia."
                            body="Tambahkan stok baru."
                            x-show="manageStockItems.length === 0"
                            class="rounded-neo-md p-4 text-sm font-bold text-neo-ink"
                        />

                        <x-admin-dashboard.button type="button" variant="primary" class="mt-4 w-full" x-on:click="openStockModalFromManage()">
                            ➕ Tambah stok baru
                        </x-admin-dashboard.button>
                    </x-admin-dashboard.modal-shell>
                </x-admin-dashboard.modal-backdrop>
                @endif
