                        <x-admin-dashboard.card id="product-{{ $product['id'] }}" data-admin-return-target tabindex="-1" as="article" padding="compact" shadow="sm" class="min-w-0">
                            <div
                                class="contents"
                                x-data="{
                                    editPayload: @js($product['edit_payload']),
                                    stockPayload: @js($product['stock_payload']),
                                    shareUrl: @js($product['share_url']),
                                    moreActionsOpen: false
                                }"
                            >
                            @if ($product['image_url'])
                                <div class="-mx-1 -mt-1 mb-3 flex aspect-square items-center justify-center overflow-hidden rounded-lg border border-admin-line bg-admin-surface">
                                    <img
                                        src="{{ $product['image_url'] }}"
                                        alt="{{ $product['name'] }}"
                                        class="h-full w-full object-cover object-center"
                                        loading="lazy"
                                        decoding="async"
                                    >
                                </div>
                            @endif
                            {{-- Variant label + status badge --}}
                            <div class="flex min-w-0 items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-admin-ink">{{ trim((string) ($product['family_option_label'] ?? '')) ?: $product['name'] }}</p>
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

                                @if ($product['featured_label'])
                                    <x-admin-dashboard.badge tone="amber">
                                        {{ $product['featured_label'] }}
                                    </x-admin-dashboard.badge>
                                @endif
                            </div>

                            {{-- Price --}}
                            <p class="mt-4 text-xl font-semibold text-admin-ink">{{ $product['price'] }}</p>

                            {{-- Short description --}}
                            <p class="mt-2 break-words text-xs leading-5 text-admin-muted">{{ $product['description'] }}</p>

                            {{-- Stock info --}}
                            <div class="mt-3 grid grid-cols-3 gap-2">
                                <x-admin-dashboard.mini-stat label="Stok" :value="$product['stock']"><span x-text="stockCountSnapshots[{{ $product['id'] }}]?.stock ?? {{ $product['stock'] }}">{{ $product['stock'] }}</span></x-admin-dashboard.mini-stat>
                                <x-admin-dashboard.mini-stat label="Tersedia" :value="$product['available_stock']" tone="cyan"><span x-text="stockCountSnapshots[{{ $product['id'] }}]?.available ?? {{ $product['available_stock'] }}">{{ $product['available_stock'] }}</span></x-admin-dashboard.mini-stat>
                                <x-admin-dashboard.mini-stat label="Dipesan" :value="$product['reserved_stock']" tone="amber"><span x-text="stockCountSnapshots[{{ $product['id'] }}]?.reserved ?? {{ $product['reserved_stock'] }}">{{ $product['reserved_stock'] }}</span></x-admin-dashboard.mini-stat>
                            </div>

                            {{-- Visibility status --}}
                            <p @class([
                                'mt-2 text-xs font-medium',
                                'text-neo-success-text' => $product['is_active'],
                                'text-neo-danger-text' => ! $product['is_active'],
                            ])>
                                {{ $product['visibility_label'] }}
                            </p>


                            <section class="mt-3 rounded-lg border border-admin-line bg-admin-surface-muted p-3" aria-label="Grouping Product">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-xs font-medium text-admin-muted">Grouping katalog</p>
                                    @if ($product['family_name'])
                                        <span class="truncate text-xs font-medium text-admin-accent-strong">{{ $product['family_name'] }}</span>
                                    @endif
                                </div>
                                @if ($canManageProducts)
                                    <form method="POST" action="{{ $product['family_membership']['update_url'] }}" class="mt-2 space-y-2">
                                        @csrf
                                        @method('PATCH')
                                        <label class="sr-only" for="product-family-select-{{ $product['id'] }}">Product Family</label>
                                        <x-admin-dashboard.select id="product-family-select-{{ $product['id'] }}" name="product_family_id">
                                            <option value="">Tanpa family</option>
                                            @foreach ($familyOptions as $familyOption)
                                                <option value="{{ $familyOption['id'] }}" @selected((int) ($product['family_id'] ?? 0) === (int) $familyOption['id'])>{{ $familyOption['name'] }}</option>
                                            @endforeach
                                        </x-admin-dashboard.select>
                                        <div class="grid grid-cols-2 gap-2">
                                            <label class="sr-only" for="product-family-order-{{ $product['id'] }}">Urutan option</label>
                                            <x-admin-dashboard.input id="product-family-order-{{ $product['id'] }}" type="number" name="family_sort_order" min="0" max="999999" step="1" value="{{ $product['family_sort_order'] }}" placeholder="Urutan" />
                                            <label class="sr-only" for="product-family-label-{{ $product['id'] }}">Label option</label>
                                            <x-admin-dashboard.input id="product-family-label-{{ $product['id'] }}" type="text" name="family_option_label" maxlength="160" value="{{ $product['family_option_label'] }}" placeholder="Label option (opsional)" />
                                        </div>
                                        <x-admin-dashboard.button type="submit" variant="secondary" size="sm" class="w-full">Simpan grouping</x-admin-dashboard.button>
                                    </form>
                                    @if (($dashboardState['familyMembershipProductId'] ?? null) == $product['id'] && ! empty($dashboardState['familyMembershipErrors']))
                                        <x-admin-dashboard.field-error class="mt-2">{{ collect($dashboardState['familyMembershipErrors'])->flatten()->first() }}</x-admin-dashboard.field-error>
                                    @endif
                                @else
                                    <p class="mt-1 text-xs text-admin-muted">{{ $product['family_name'] ?: 'Belum memiliki Product Family.' }}</p>
                                @endif
                            </section>
                            @if ($canManageProducts)
                            {{-- Action buttons: in-page only --}}
                            <div class="mt-4 space-y-2" data-product-actions>
                                <div class="flex" data-product-primary-action>
                                    <x-admin-dashboard.button
                                        type="button"
                                        variant="primary"
                                        size="sm"
                                        class="w-full"
                                        aria-label="Stok"
                                        x-on:click="openStockModal(stockPayload)"
                                    >
                                        Stok
                                    </x-admin-dashboard.button>
                                </div>

                                <div class="grid grid-cols-2 gap-2" data-product-secondary-actions>
                                    <x-admin-dashboard.button
                                        type="button"
                                        variant="neutral"
                                        size="sm"
                                        class="w-full"
                                        aria-label="Ubah"
                                        x-on:click="openProductEditModal(editPayload)"
                                    >
                                        Ubah
                                    </x-admin-dashboard.button>
                                    <x-admin-dashboard.button
                                        type="button"
                                        variant="neutral"
                                        size="sm"
                                        class="w-full"
                                        aria-label="Kelola"
                                        x-on:click="openManageStockModal(editPayload)"
                                    >
                                        Kelola
                                    </x-admin-dashboard.button>
                                </div>

                                <x-admin-dashboard.button
                                    type="button"
                                    variant="neutral"
                                    size="sm"
                                    class="w-full"
                                    data-product-more-trigger
                                    id="product-more-actions-trigger-{{ $product['id'] }}"
                                    aria-controls="product-more-actions-{{ $product['id'] }}"
                                    aria-expanded="false"
                                    x-bind:aria-expanded="moreActionsOpen.toString()"
                                    x-on:click="moreActionsOpen = ! moreActionsOpen"
                                    x-on:keydown.escape.prevent.stop="moreActionsOpen = false; $nextTick(() => $el.focus())"
                                >
                                    <span>Aksi lainnya</span>
                                    <span aria-hidden="true" x-text="moreActionsOpen ? '−' : '+'"></span>
                                </x-admin-dashboard.button>

                                <div
                                    x-cloak
                                    x-show="moreActionsOpen"
                                    id="product-more-actions-{{ $product['id'] }}"
                                    role="region"
                                    aria-labelledby="product-more-actions-trigger-{{ $product['id'] }}"
                                    class="grid grid-cols-1 gap-2 sm:grid-cols-3"
                                    data-product-more-panel
                                    x-on:keydown.escape.prevent.stop="moreActionsOpen = false; $nextTick(() => document.getElementById('product-more-actions-trigger-{{ $product['id'] }}')?.focus())"
                                >
                                    <x-admin-dashboard.button
                                        type="button"
                                        variant="neutral"
                                        size="sm"
                                        class="w-full"
                                        aria-label="Bagikan"
                                        x-on:click="copyProductShareLink(shareUrl)"
                                    >
                                        Bagikan
                                    </x-admin-dashboard.button>
                                    <form method="POST" action="{{ $product['visibility_url'] }}" class="min-w-0">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="is_active" value="{{ $product['is_active'] ? 0 : 1 }}">
                                        <x-admin-dashboard.button
                                            type="submit"
                                            variant="neutral"
                                            size="sm"
                                            class="w-full"
                                        >
                                            {{ $product['visibility_action_label'] }}
                                        </x-admin-dashboard.button>
                                    </form>
                                    <form method="POST" action="{{ $product['delete_url'] }}" class="min-w-0" x-on:submit="if (! confirm('Arsipkan produk {{ addslashes($product['name']) }} dari toko?')) $event.preventDefault()">
                                        @csrf
                                        @method('DELETE')
                                        <x-admin-dashboard.button
                                            type="submit"
                                            variant="destructive"
                                            size="sm"
                                            class="w-full"
                                            aria-label="Arsipkan"
                                        >
                                            Arsipkan
                                        </x-admin-dashboard.button>
                                    </form>
                                </div>
                            </div>
                            @else
                                <x-admin-dashboard.notice tone="neutral" class="mt-4 text-xs">
                                    Role ini hanya bisa melihat produk. Aksi stok khusus owner/admin.
                                </x-admin-dashboard.notice>
                            @endif
                            </div>
                        </x-admin-dashboard.card>
