                        <x-admin-dashboard.card as="article" padding="compact" shadow="sm" class="min-w-0 rounded-[1.125rem]">
                            <div
                                class="contents"
                                x-data="{
                                    editPayload: @js($product['edit_payload']),
                                    stockPayload: @js($product['stock_payload']),
                                    shareUrl: @js($product['share_url'])
                                }"
                            >
                            @if ($product['image_url'])
                                <div class="-mx-1 -mt-1 mb-3 flex aspect-[16/9] items-center justify-center overflow-hidden rounded-[0.9rem] border-[3px] border-neo-ink bg-white shadow-[4px_4px_0_0_#050505]">
                                    <img
                                        src="{{ $product['image_url'] }}"
                                        alt="{{ $product['name'] }}"
                                        class="h-full w-full object-contain object-center"
                                        loading="lazy"
                                        decoding="async"
                                    >
                                </div>
                            @endif
                            {{-- Product name + status badge --}}
                            <div class="flex min-w-0 items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate font-display text-base font-bold text-neo-ink">{{ $product['name'] }}</p>
                                    <x-admin-dashboard.badge
                                        :tone="$product['is_active'] ? 'emerald' : 'slate'"
                                        class="mt-1"
                                    >
                                        {{ $product['status_label'] }}
                                    </x-admin-dashboard.badge>
                                </div>
                                <x-admin-dashboard.badge tone="purple">
                                    {{ $product['category'] }}
                                </x-admin-dashboard.badge>
                            </div>

                            {{-- Price --}}
                            <p class="mt-4 font-display text-2xl font-bold text-neo-ink">{{ $product['price'] }}</p>

                            {{-- Short description --}}
                            <p class="mt-2 break-words text-xs font-bold leading-5 text-brand-muted">{{ $product['description'] }}</p>

                            {{-- Stock info --}}
                            <div class="mt-3 grid grid-cols-3 gap-2">
                                <x-admin-dashboard.mini-stat label="Stok" :value="$product['stock']" />
                                <x-admin-dashboard.mini-stat label="Tersedia" :value="$product['available_stock']" tone="cyan" />
                                <x-admin-dashboard.mini-stat label="Dipesan" :value="$product['reserved_stock']" tone="amber" />
                            </div>

                            {{-- Visibility status --}}
                            <p @class([
                                'mt-2 text-[11px] font-semibold',
                                'text-neo-green' => $product['is_active'],
                                'text-neo-red' => ! $product['is_active'],
                            ])>
                                {{ $product['visibility_label'] }}
                            </p>

                            @if ($canManageProducts)
                            {{-- Action buttons: in-page only --}}
                            <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3">
                                <x-admin-dashboard.button
                                    type="button"
                                    variant="secondary"
                                    size="sm"
                                    x-on:click="openProductEditModal(editPayload)"
                                >
                                    ✏️ Ubah
                                </x-admin-dashboard.button>
                                <x-admin-dashboard.button
                                    type="button"
                                    variant="primary"
                                    size="sm"
                                    x-on:click="openStockModal(stockPayload)"
                                >
                                    ➕ Stok
                                </x-admin-dashboard.button>
                                <x-admin-dashboard.button
                                    type="button"
                                    variant="warning"
                                    size="sm"
                                    x-on:click="openManageStockModal(editPayload)"
                                >
                                    📦 Kelola
                                </x-admin-dashboard.button>
                                <x-admin-dashboard.button
                                    type="button"
                                    variant="purple"
                                    size="sm"
                                    x-on:click="copyProductShareLink(shareUrl)"
                                >
                                    🔗 Share
                                </x-admin-dashboard.button>
                                <form method="POST" action="{{ $product['visibility_url'] }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="is_active" value="{{ $product['is_active'] ? 0 : 1 }}">
                                    <x-admin-dashboard.button
                                        type="submit"
                                        variant="warning"
                                        size="sm"
                                        class="w-full"
                                    >
                                        {{ $product['visibility_action_label'] }}
                                    </x-admin-dashboard.button>
                                </form>
                                <form method="POST" action="{{ $product['delete_url'] }}" x-on:submit="if (! confirm('Arsipkan produk {{ addslashes($product['name']) }} dari toko?')) $event.preventDefault()">
                                    @csrf
                                    @method('DELETE')
                                    <x-admin-dashboard.button
                                        type="submit"
                                        variant="destructive"
                                        size="sm"
                                        class="w-full"
                                    >
                                        🗑️ Hapus
                                    </x-admin-dashboard.button>
                                </form>
                            </div>
                            @else
                                <x-admin-dashboard.notice tone="neutral" class="mt-4 text-xs">
                                    Role ini hanya bisa melihat produk. Aksi stok khusus owner/admin.
                                </x-admin-dashboard.notice>
                            @endif
                            </div>
                        </x-admin-dashboard.card>
