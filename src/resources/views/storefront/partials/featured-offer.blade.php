<section id="featured-offer" class="relative mx-auto max-w-6xl px-5 pb-20 sm:px-6">
    @if ($featuredProduct)
        @php
            $featuredStock = $storefront->stockAvailable($featuredProduct);
            $featuredLink = $storefront->featuredLink($featuredProduct);
        @endphp

        <div class="neo-panel relative w-full overflow-hidden bg-[var(--sf-surface)] p-6 text-[var(--sf-ink)] sm:p-8">
            <div class="absolute right-6 top-6 hidden rounded-full border-[3px] border-[var(--sf-border)] bg-[var(--sf-green)] px-4 py-2 text-xs font-black shadow-[4px_4px_0_var(--sf-shadow)] md:block">Pilihan hari ini</div>
            <div class="relative z-10 grid items-center gap-8 lg:grid-cols-12 lg:gap-12">
                <div class="flex justify-center lg:col-span-4">
                    @include('storefront.components.product-cover', [
                        'product' => $featuredProduct,
                        'context' => 'featured',
                    ])
                </div>

                <div class="space-y-6 lg:col-span-8">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <h2 class="max-w-2xl font-display text-3xl font-black leading-tight tracking-tight text-[var(--sf-ink)] sm:text-4xl">{{ $featuredProduct->name }}</h2>
                        <span class="rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-green)] px-3 py-1 text-[10px] font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">Stok siap</span>
                    </div>

                    <p class="max-w-2xl text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">{{ $featuredProduct->description }}</p>

                    <div class="grid items-end gap-6 sm:grid-cols-2">
                        <div class="space-y-1">
                            <span class="font-display text-4xl font-black tracking-tight text-[var(--sf-ink)]">{{ $storefront->idr($featuredProduct->price_amount) }}</span>
                            <div class="pt-2">
                                <span class="inline-flex rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-1 text-[10px] font-black text-[var(--sf-ink)]">Sisa {{ $featuredStock }}</span>
                            </div>
                        </div>

                        <div class="flex flex-col gap-2">
                            <a href="{{ $featuredLink }}"
                                    class="neo-button flex h-14 w-full select-none items-center justify-center gap-2 bg-[var(--sf-accent)] text-sm font-black text-white">
                                Belanja Sekarang
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="neo-panel mx-auto flex w-full max-w-xl flex-col items-center border-dashed bg-[var(--sf-surface)] p-10 text-center">
            <h2 class="font-display text-xl font-black text-[var(--sf-ink)] sm:text-2xl">Promo pilihan menunggu stok siap.</h2>
            <p class="mt-3 max-w-sm text-xs font-semibold leading-relaxed text-[var(--sf-muted)]">Belum ada produk aktif dengan stok tersedia untuk dijadikan pilihan utama. Katalog tetap bisa dibaca, dan pelanggan dapat menghubungi support untuk rekomendasi.</p>
            <a href="{{ $supportHref ?: '#support' }}" class="neo-button mt-6 inline-flex h-12 items-center justify-center bg-[var(--sf-accent)] px-6 text-xs font-black text-white">Minta rekomendasi ke support</a>
        </div>
    @endif
</section>
