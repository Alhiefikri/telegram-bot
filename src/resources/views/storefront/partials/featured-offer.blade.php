<section id="featured-offer" class="relative mx-auto max-w-6xl px-5 mt-16 pb-20 sm:px-6">
    @if ($featuredProduct)
        @php
            $featuredStock = $storefront->stockAvailable($featuredProduct);
            $featuredPayload = [
                'id' => $featuredProduct->id,
                'name' => $featuredProduct->name,
                'descriptionSnippet' => $storefront->descriptionSnippet($featuredProduct),
                'description' => $featuredProduct->description,
                'price_amount' => $featuredProduct->price_amount,
                'fake_discount_price' => $featuredProduct->fake_discount_price,
                'stock' => $featuredStock,
                'isAvailable' => $featuredStock > 0,
                'paymentUrl' => route('storefront.products.payment', $featuredProduct),
                'coverTheme' => $storefront->productCover($featuredProduct),
                'imageUrl' => $storefront->publicStorageUrl($featuredProduct->image_path),
            ];
        @endphp

        <div class="neo-panel relative w-full overflow-hidden bg-[var(--sf-surface)] p-6 text-[var(--sf-ink)] sm:p-8" x-data="fomoTimer()">
            <div class="absolute right-6 top-6 hidden rounded-full border-[3px] border-[var(--sf-border)] bg-[var(--sf-green)] px-4 py-2 text-xs font-black shadow-[4px_4px_0_var(--sf-shadow)] md:block">Pilihan hari ini</div>
            <div class="relative z-10 grid items-center gap-8 lg:grid-cols-12 lg:gap-12">
                <div class="flex justify-center lg:col-span-5">
                    @include('storefront.components.product-cover', [
                        'product' => $featuredProduct,
                        'context' => 'featured',
                    ])
                </div>

                <div class="space-y-6 lg:col-span-7">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <h2 class="max-w-2xl font-display text-3xl font-black leading-tight tracking-tight text-[var(--sf-ink)] sm:text-4xl">{{ $featuredProduct->name }}</h2>
                        <span class="rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-green)] px-3 py-1 text-[10px] font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">Stok siap</span>
                    </div>

                    <p class="max-w-2xl text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">{{ $featuredProduct->description }}</p>

                    <div class="grid items-end gap-6 sm:grid-cols-2">
                        <div class="space-y-1">
                            @if ($featuredProduct->fake_discount_price)
                                <div class="text-sm font-black text-[var(--sf-muted)] line-through">
                                    {{ $storefront->idr($featuredProduct->fake_discount_price) }}
                                </div>
                            @endif
                            <span class="font-display text-4xl font-black tracking-tight text-[var(--sf-ink)]">{{ $storefront->idr($featuredProduct->price_amount) }}</span>
                            <div class="pt-4 grid gap-3 sm:max-w-md">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-1 text-[10px] font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">Sisa {{ $featuredStock }}</span>
                                    <span class="inline-flex rounded-full border-2 border-red-700 bg-red-600 px-3 py-1 text-[10px] font-black uppercase tracking-wider text-white shadow-[3px_3px_0_var(--sf-shadow)]">Promo terbatas</span>
                                </div>
                                <div class="animate-pulse rounded-xl border-[3px] border-red-700 bg-red-50 p-4 text-red-700 shadow-[6px_6px_0_var(--sf-shadow)]">
                                    <div class="flex items-center gap-2 text-[11px] font-black uppercase tracking-[0.18em]">
                                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                        Promo berakhir dalam
                                    </div>
                                    <div class="mt-1 font-display text-4xl font-black leading-none tracking-tight text-red-700 sm:text-5xl" x-text="timeDisplay">15:00</div>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-col gap-2">
                            <button type="button" x-on:click.prevent="openProductModal(JSON.parse($el.dataset.product))" data-product='@json($featuredPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)' class="neo-button flex h-14 w-full select-none items-center justify-center gap-2 bg-[var(--sf-accent)] text-sm font-black text-white transition-colors hover:bg-[var(--sf-accent-deep)]">
                                Beli Sekarang
                            </button>
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

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('fomoTimer', () => ({
        timeRemaining: 15 * 60,
        timeDisplay: '15:00',
        init() {
            let savedTime = localStorage.getItem('fomo_timer');
            let savedTimestamp = localStorage.getItem('fomo_timestamp');
            let now = Math.floor(Date.now() / 1000);

            if (savedTime && savedTimestamp) {
                let elapsed = now - parseInt(savedTimestamp);
                this.timeRemaining = parseInt(savedTime) - elapsed;
                if (this.timeRemaining <= 0) {
                    this.timeRemaining = 15 * 60;
                }
            }

            this.updateDisplay();

            setInterval(() => {
                this.timeRemaining--;
                if (this.timeRemaining <= 0) {
                    this.timeRemaining = 15 * 60;
                }
                this.updateDisplay();
                localStorage.setItem('fomo_timer', this.timeRemaining);
                localStorage.setItem('fomo_timestamp', Math.floor(Date.now() / 1000));
            }, 1000);
        },
        updateDisplay() {
            let m = Math.floor(this.timeRemaining / 60).toString().padStart(2, '0');
            let s = (this.timeRemaining % 60).toString().padStart(2, '0');
            this.timeDisplay = `${m}:${s}`;
        }
    }))
})
</script>
