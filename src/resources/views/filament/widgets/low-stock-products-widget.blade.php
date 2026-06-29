<x-filament-widgets::widget>
<x-lhie.dashboard-card>
    <x-lhie.section-header
        eyebrow="Stok perlu dicek"
        title="Produk perlu dicek"
        copy="Daftar akun premium atau lisensi digital dengan stok tipis atau perlu pembaruan status."
    />

    <div class="mt-5">
        @if ($isEmpty)
            <div class="lhie-empty-state">
                <div class="lhie-empty-state__icon">
                    <x-lhie.icon name="stock" class="h-5 w-5" />
                </div>

                <div class="space-y-1">
                    <p class="lhie-empty-state__title">Stok aman</p>
                    <p class="lhie-empty-state__description">Belum ada produk aktif yang masuk daftar pantau stok.</p>
                </div>
            </div>
        @else
            <div class="space-y-3">
                @foreach ($products as $product)
                    <article class="lhie-section-panel">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex min-w-0 items-start gap-3">
                                <div class="lhie-icon-tile" data-tone="{{ $product['tone'] }}">
                                    <x-lhie.icon name="stock" />
                                </div>

                                <div class="min-w-0">
                                    <p class="truncate font-semibold text-slate-900">{{ $product['name'] }}</p>
                                    <p class="mt-1 break-words text-sm text-slate-600">{{ $product['price'] }} · Tertahan {{ $product['reserved_stock'] }}</p>
                                    <a href="{{ $product['url'] }}" class="mt-3 inline-flex text-xs font-semibold text-slate-700 hover:text-slate-900">Buka produk →</a>
                                </div>
                            </div>

                            <div class="text-right">
                                <div class="text-2xl font-bold tracking-[-0.05em] text-slate-900">{{ $product['stock'] }}</div>
                                <x-lhie.status-pill class="mt-2" :label="$product['status_label']" :tone="$product['tone']" />
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</x-lhie.dashboard-card>
</x-filament-widgets::widget>
