<section id="featured-offer" class="relative mx-auto max-w-6xl px-5 mt-16 pb-20 sm:px-6">
    @if ($catalogPage->featuredProduct)
        @php
            $featuredPresentation = $catalogPage->featuredProduct->presentation;
            $featuredPayload = $featuredPresentation->modalPayload();
            $featuredReturnUrl = $storefront->catalogUrl($featuredPresentation->id, $catalogPage->search);
            $featuredCheckoutUrl = $featuredPresentation->productUrl.(str_contains($featuredPresentation->productUrl, '?') ? '&' : '?').'checkout=1&from='.urlencode($featuredReturnUrl);
        @endphp

        <div class="neo-panel-pop relative w-full overflow-hidden bg-[var(--sf-surface)] p-6 text-[var(--sf-ink)] sm:p-8">
            <div class="absolute right-6 top-6 hidden rounded-full border-[3px] border-[var(--sf-border)] bg-[var(--sf-green)] px-4 py-2 text-xs font-black shadow-[4px_4px_0_var(--sf-shadow)] md:block">Produk tersedia</div>
            <div class="relative z-10 grid items-center gap-8 lg:grid-cols-12 lg:gap-12">
                <div class="relative flex justify-center lg:col-span-5">
                    <div class="neo-ai-sticker absolute -left-2 -top-2 z-20 grid !h-[4.5rem] !w-[4.5rem]" aria-hidden="true">
                        <svg class="neo-spin-slow" viewBox="0 0 100 100" fill="var(--sf-violet)" stroke="var(--sf-ink)" stroke-width="3" stroke-linejoin="round">
                            <polygon points="98,50 84.8,59.3 91.6,74 75.5,75.5 74,91.6 59.3,84.8 50,98 40.7,84.8 26,91.6 24.5,75.5 8.4,74 15.2,59.3 2,50 15.2,40.7 8.4,26 24.5,24.5 26,8.4 40.7,15.2 50,2 59.3,15.2 74,8.4 75.5,24.5 91.6,26 84.8,40.7" />
                        </svg>
                        <span class="text-xs uppercase tracking-wide text-white">Best</span>
                    </div>
                    @include('storefront.components.product-cover', [
                        'presentation' => $featuredPresentation,
                        'context' => 'featured',
                    ])
                </div>

                <div class="space-y-6 lg:col-span-7">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <h2 class="max-w-2xl font-display text-3xl font-black leading-tight tracking-tight text-[var(--sf-ink)] sm:text-4xl">{{ $featuredPresentation->name }}</h2>
                        <span class="rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-green)] px-3 py-1 text-[10px] font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">Tersedia</span>
                    </div>

                    <p class="max-w-2xl text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">{{ $featuredPresentation->description }}</p>

                    <div class="space-y-4">
                        <div class="space-y-2">
                            <div class="flex flex-wrap items-center gap-4">
                                <span class="inline-flex -rotate-2 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] px-4 py-1.5 font-display text-3xl font-black tracking-tight text-[var(--sf-ink)] shadow-[5px_5px_0_var(--sf-shadow)] sm:text-4xl">{{ $storefront->idr($featuredPresentation->priceAmount) }}</span>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-violet-soft)] px-3 py-1 text-[10px] font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">Sisa {{ $featuredPresentation->stockAvailable }}</span>
                                </div>
                            </div>
                        </div>

                        @if ($catalogPage->checkoutMaintenance->active)
                            <div role="status" class="flex min-h-14 w-full items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] px-6 py-4 text-center text-sm font-black text-[var(--sf-ink)]">
                                Checkout sedang maintenance
                            </div>
                        @else
                            <a href="{{ $featuredCheckoutUrl }}" x-on:click.prevent="openProductModal(JSON.parse($el.dataset.product), { opener: $el })" data-product='@json($featuredPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)' class="neo-button neo-sheen flex min-h-14 w-full select-none items-center justify-center gap-2 bg-[var(--sf-accent)] px-6 py-4 text-sm font-black text-white transition-colors hover:bg-[var(--sf-accent-deep)]">
                                Pilih Checkout
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="neo-panel mx-auto flex w-full max-w-xl flex-col items-center border-dashed bg-[var(--sf-surface)] p-10 text-center">
            <h2 class="font-display text-xl font-black text-[var(--sf-ink)] sm:text-2xl">Belum ada produk tersedia.</h2>
            <p class="mt-3 max-w-sm text-xs font-semibold leading-relaxed text-[var(--sf-muted)]">Saat ini belum ada produk aktif dengan stok tersedia. Lihat katalog untuk ketersediaan terbaru.</p>
            <a href="{{ $storefront->catalogUrl() }}" class="neo-button mt-6 inline-flex h-12 items-center justify-center bg-[var(--sf-accent)] px-6 text-xs font-black text-white">
                <span>Lihat katalog</span>
            </a>
        </div>
    @endif
</section>
