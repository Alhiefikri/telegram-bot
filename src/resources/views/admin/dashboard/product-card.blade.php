                        <article class="neo-card min-w-0">
                            {{-- Product name + status badge --}}
                            <div class="flex min-w-0 items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate font-display text-base font-bold" style="color: #050505;">{{ $product['name'] }}</p>
                                    <span class="neo-badge mt-1" style="background: {{ $product['status_badge']['background'] }}; color: {{ $product['status_badge']['color'] }};">
                                        {{ $product['status_label'] }}
                                    </span>
                                </div>
                                <span class="neo-badge" style="background: #ede5fa; color: #8758de;">
                                    {{ $product['category'] }}
                                </span>
                            </div>

                            {{-- Price --}}
                            <p class="mt-4 font-display text-2xl font-bold" style="color: #050505;">{{ $product['price'] }}</p>

                            {{-- Short description --}}
                            <p class="mt-2 break-words text-xs font-bold leading-5" style="color: #64748b;">{{ $product['description'] }}</p>

                            {{-- Stock info --}}
                            <div class="mt-3 grid grid-cols-3 gap-2">
                                <div class="rounded-lg p-2 text-center" style="background: #f1f5f9; border: 1px solid #e2e8f0;">
                                    <p class="text-[9px] font-bold uppercase" style="color: #64748b;">Stok</p>
                                    <p class="mt-0.5 font-display text-sm font-bold" style="color: #050505;">{{ $product['stock'] }}</p>
                                </div>
                                <div class="rounded-lg p-2 text-center" style="background: #d4f6fb; border: 1px solid #16b4ca;">
                                    <p class="text-[9px] font-bold uppercase" style="color: #0e7490;">Tersedia</p>
                                    <p class="mt-0.5 font-display text-sm font-bold" style="color: #050505;">{{ $product['available_stock'] }}</p>
                                </div>
                                <div class="rounded-lg p-2 text-center" style="background: #fef9c3; border: 1px solid #f2da3d;">
                                    <p class="text-[9px] font-bold uppercase" style="color: #92400e;">Dipesan</p>
                                    <p class="mt-0.5 font-display text-sm font-bold" style="color: #050505;">{{ $product['reserved_stock'] }}</p>
                                </div>
                            </div>

                            {{-- Visibility status --}}
                            <p class="mt-2 text-[11px] font-semibold" style="color: {{ $product['visibility_color'] }};">
                                {{ $product['visibility_label'] }}
                            </p>

                            @if ($canManageProducts)
                            {{-- Action buttons: in-page only --}}
                            <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3">
                                <button
                                    type="button"
                                    class="product-action-btn"
                                    style="background: #d4f6fb; color: #0e7490;"
                                    x-on:click="openProductEditModal(@js($product['edit_payload']))"
                                >
                                    ✏️ Ubah
                                </button>
                                <button
                                    type="button"
                                    class="product-action-btn"
                                    style="background: #7BD10A; color: #166534;"
                                    x-on:click="openStockModal(@js($product['stock_payload']))"
                                >
                                    ➕ Stok
                                </button>
                                <button
                                    type="button"
                                    class="product-action-btn"
                                    style="background: #f2da3d; color: #92400e;"
                                    x-on:click="openManageStockModal(@js($product['edit_payload']))"
                                >
                                    📦 Kelola
                                </button>
                                <button
                                    type="button"
                                    class="product-action-btn"
                                    style="background: #8758de; color: #fff;"
                                    x-on:click="copyProductShareLink(@js($product['share_url']))"
                                >
                                    🔗 Share
                                </button>
                                <form method="POST" action="{{ $product['visibility_url'] }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="is_active" value="{{ $product['is_active'] ? 0 : 1 }}">
                                    <button
                                        type="submit"
                                        class="product-action-btn w-full"
                                        style="background: #fef9c3; color: #92400e;"
                                    >
                                        {{ $product['visibility_action_label'] }}
                                    </button>
                                </form>
                                <form method="POST" action="{{ $product['delete_url'] }}" x-on:submit="if (! confirm('Arsipkan produk {{ addslashes($product['name']) }} dari toko?')) $event.preventDefault()">
                                    @csrf
                                    @method('DELETE')
                                    <button
                                        type="submit"
                                        class="product-action-btn w-full"
                                        style="background: #fecaca; color: #dc2626;"
                                    >
                                        🗑️ Hapus
                                    </button>
                                </form>
                            </div>
                            @else
                                <div class="mt-4 rounded-[0.75rem] border-[3px] border-neo-ink bg-[#f1f5f9] p-3 text-xs font-bold text-neo-ink/70">
                                    Role ini hanya bisa melihat produk. Aksi stok khusus owner/admin.
                                </div>
                            @endif
                        </article>
