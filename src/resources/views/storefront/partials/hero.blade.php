<section class="storefront-hero-card relative mx-auto max-w-6xl px-4 py-5 sm:px-6 sm:py-7">
    <div class="motion-safe:animate-storefront-reveal grid gap-5 rounded-2xl border border-[var(--sf-border)] bg-[var(--sf-surface)] p-5 shadow-[0_12px_30px_rgb(18_35_63_/_0.08)] sm:p-7 lg:grid-cols-[minmax(0,1fr)_minmax(22rem,30rem)] lg:items-center">
        <div class="max-w-2xl">
            <p class="text-xs font-black uppercase tracking-[0.18em] text-[var(--sf-accent)]">{{ $copy['hero']['eyebrow'] }}</p>
            <h1 class="mt-2 max-w-2xl font-display text-3xl font-black leading-tight tracking-tight text-[var(--sf-ink)] text-balance sm:text-5xl">
                {{ $copy['hero']['headline'] }}
            </h1>
            <p class="mt-3 max-w-xl text-sm font-semibold leading-6 text-[var(--sf-muted)] sm:text-base">
                {{ $copy['hero']['body'] }}
            </p>
            <div class="mt-5 flex flex-col gap-2.5 sm:flex-row">
                <a href="#products" class="neo-button inline-flex min-h-12 items-center justify-center gap-2 bg-[var(--sf-accent)] px-6 text-sm font-black text-white">
                    <span>{{ $copy['hero']['primary_cta'] }}</span>
                </a>
                <a href="#cara-beli" class="neo-button inline-flex min-h-12 items-center justify-center gap-2 bg-[var(--sf-surface-warm)] px-6 text-sm font-black text-[var(--sf-ink)]">
                    <span>{{ $copy['hero']['secondary_cta'] }}</span>
                </a>
            </div>
        </div>

        @php($featuredEntries = $catalogPage->entries()->take(2))
        @if ($featuredEntries->isNotEmpty())
            <div class="min-w-0" aria-label="Pilihan yang tersedia">
                <div class="mb-2 flex items-center justify-between gap-3">
                    <p class="text-xs font-black uppercase tracking-[0.16em] text-[var(--sf-muted)]">Pilihan tersedia</p>
                    <a href="{{ $shopUrl }}" class="min-h-11 inline-flex items-center text-xs font-black text-[var(--sf-accent)] underline underline-offset-4">Lihat semua</a>
                </div>
                <div class="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-1">
                    @foreach ($featuredEntries as $entry)
                        @if ($entry->isFamily())
                            @php($featured = $entry->family)
                            <a href="{{ $storefront->familyUrl($featured->slug) }}" class="rounded-xl border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-3 transition-colors hover:bg-white focus-visible:outline focus-visible:outline-3 focus-visible:outline-[var(--sf-accent)]">
                                <span class="text-[10px] font-black uppercase tracking-[0.14em] text-[var(--sf-muted)]">{{ $featured->optionCount }} pilihan</span>
                                <span class="mt-1 block truncate font-display text-base font-black text-[var(--sf-ink)]">{{ $featured->name }}</span>
                                <span class="mt-1 block text-xs font-bold text-[var(--sf-muted)]">Mulai {{ $featured->minimumPriceAmount !== null ? $storefront->idr($featured->minimumPriceAmount) : '—' }} · {{ $featured->availableOptionCount }} tersedia</span>
                            </a>
                        @else
                            @php($featured = $entry->product->presentation)
                            <a href="{{ $storefront->productUrl($featured->id) }}" class="rounded-xl border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-3 transition-colors hover:bg-white focus-visible:outline focus-visible:outline-3 focus-visible:outline-[var(--sf-accent)]">
                                <span class="text-[10px] font-black uppercase tracking-[0.14em] text-[var(--sf-muted)]">{{ $featured->isAvailable ? 'Tersedia sekarang' : 'Stok habis' }}</span>
                                <span class="mt-1 block truncate font-display text-base font-black text-[var(--sf-ink)]">{{ $featured->name }}</span>
                                <span class="mt-1 block text-xs font-bold text-[var(--sf-muted)]">{{ $storefront->idr($featured->priceAmount) }} · {{ $featured->stockLabel }}</span>
                            </a>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</section>
