@php($featuredEntries = $catalogPage->entries()->take(2))

<section class="storefront-hero-card relative mx-auto max-w-6xl px-4 py-5 sm:px-6 sm:py-6" aria-labelledby="storefront-promise-title">
    <div class="hero-preview-panel relative grid overflow-hidden rounded-2xl border border-[var(--sf-border)] bg-[var(--sf-accent)] shadow-[0_12px_30px_rgb(18_35_63_/_0.12)] {{ $featuredEntries->isNotEmpty() ? 'lg:grid-cols-[minmax(0,1fr)_minmax(20rem,26rem)]' : '' }}">
        <div class="relative min-w-0 px-5 py-6 sm:p-8 lg:py-9">
            <div class="pointer-events-none absolute inset-0 bg-grid-pattern opacity-10" aria-hidden="true"></div>
            <div class="relative max-w-2xl">
            <p class="inline-flex max-w-full rounded-full bg-[var(--sf-yellow)] px-3 py-1.5 text-[10px] font-black uppercase tracking-[0.14em] text-[var(--sf-ink)] sm:text-xs">{{ $copy['hero']['eyebrow'] }}</p>
            <h1 id="storefront-promise-title" class="mt-4 max-w-xl font-display text-3xl font-black leading-[1.08] tracking-tight text-white text-balance sm:text-4xl lg:text-5xl">
                {{ $copy['hero']['headline'] }}
            </h1>
            <p class="mt-3 max-w-xl text-sm font-medium leading-6 text-white/90 sm:text-base">
                {{ $copy['hero']['body'] }}
            </p>
            <div class="mt-5 flex flex-wrap items-center gap-x-5 gap-y-2">
                <a href="{{ $shopUrl }}" class="neo-button neo-sheen inline-flex min-h-12 max-w-full items-center justify-center gap-3 bg-[var(--sf-yellow)] px-5 py-2 text-center text-sm font-black text-[var(--sf-ink)] focus-visible:outline focus-visible:outline-3 focus-visible:outline-offset-4 focus-visible:outline-white">
                    <span>{{ $copy['hero']['primary_cta'] }}</span>
                </a>
                <a href="#cara-beli" class="inline-flex min-h-11 items-center rounded-sm px-1 text-sm font-bold text-white underline decoration-white/50 underline-offset-4 hover:decoration-white focus-visible:outline focus-visible:outline-3 focus-visible:outline-offset-4 focus-visible:outline-white">
                    {{ $copy['hero']['secondary_cta'] }}
                </a>
            </div>
            </div>
        </div>

        @if ($featuredEntries->isNotEmpty())
            <section class="min-w-0 border-t border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4 sm:p-5 lg:border-l lg:border-t-0 lg:p-6" aria-labelledby="storefront-featured-title">
                <div class="mb-2 flex items-center justify-between gap-3">
                    <h2 id="storefront-featured-title" class="flex items-center gap-2 text-[10px] font-black uppercase tracking-[0.14em] text-[var(--sf-ink)] sm:text-xs"><span class="h-2 w-2 shrink-0 rounded-full bg-[var(--sf-accent)]" aria-hidden="true"></span>Dari katalog</h2>
                    <a href="{{ $shopUrl }}" class="inline-flex min-h-11 shrink-0 items-center rounded-sm text-xs font-black text-[var(--sf-accent)] underline underline-offset-4 focus-visible:outline focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-[var(--sf-accent)]">Lihat semua</a>
                </div>
                <ul class="storefront-stagger-in grid min-w-0 gap-3 sm:grid-cols-2 lg:grid-cols-1" aria-label="Pilihan dari katalog">
                    @foreach ($featuredEntries as $entry)
                        @if ($entry->isFamily())
                            @php($featured = $entry->family)
                            <li class="min-w-0 {{ $loop->iteration > 1 ? 'hidden sm:block' : '' }}">
                                <a href="{{ $featured->familyUrl }}" aria-label="Lihat pilihan {{ $featured->name }}" class="group flex h-full min-w-0 items-center gap-3 rounded-xl border border-[var(--sf-border)] bg-[var(--sf-surface)] p-3 transition-colors hover:bg-[var(--sf-accent-soft)] focus-visible:outline focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-[var(--sf-accent)]">
                                    <span class="relative flex aspect-square w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-[var(--sf-border)] bg-[var(--sf-yellow)] lg:w-20">
                                        @if ($featured->imageUrl)
                                            <img src="{{ $featured->imageUrl }}" alt="" width="80" height="80" loading="eager" decoding="async" class="absolute inset-0 h-full w-full object-cover">
                                        @else
                                            <span class="font-display text-3xl font-black text-[var(--sf-accent)]" aria-hidden="true">{{ mb_substr($featured->name, 0, 1) }}</span>
                                        @endif
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block text-[10px] font-black uppercase tracking-[0.14em] text-[var(--sf-muted)]">{{ $featured->optionCount }} pilihan</span>
                                        <span class="mt-1 line-clamp-2 break-words font-display text-base font-black leading-tight text-[var(--sf-ink)]">{{ $featured->name }}</span>
                                        <span class="mt-1 block text-xs font-bold leading-5 text-[var(--sf-accent)]">Mulai {{ $featured->minimumPriceAmount !== null ? $storefront->idr($featured->minimumPriceAmount) : '—' }} <span class="text-[var(--sf-muted)]">· {{ $featured->availableOptionCount }} tersedia</span></span>
                                    </span>
                                </a>
                            </li>
                        @else
                            @php($featured = $entry->product->presentation)
                            <li class="min-w-0 {{ $loop->iteration > 1 ? 'hidden sm:block' : '' }}">
                                <a href="{{ $featured->productUrl }}" aria-label="Lihat detail {{ $featured->name }}" class="group flex h-full min-w-0 items-center gap-3 rounded-xl border border-[var(--sf-border)] bg-[var(--sf-surface)] p-3 transition-colors hover:bg-[var(--sf-accent-soft)] focus-visible:outline focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-[var(--sf-accent)]">
                                    <span class="relative flex aspect-square w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-[var(--sf-border)] bg-[var(--sf-yellow)] lg:w-20">
                                        @if ($featured->cover->imageUrl)
                                            <img src="{{ $featured->cover->imageUrl }}" alt="" width="80" height="80" loading="eager" decoding="async" class="absolute inset-0 h-full w-full object-cover">
                                        @elseif (trim((string) ($featured->cover->icon ?? '')) !== '')
                                            <span class="font-display text-3xl font-black text-[var(--sf-accent)]" aria-hidden="true">{{ $featured->cover->icon }}</span>
                                        @else
                                            <span class="font-display text-3xl font-black text-[var(--sf-accent)]" aria-hidden="true">{{ mb_substr($featured->name, 0, 1) }}</span>
                                        @endif
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block text-[10px] font-black uppercase tracking-[0.14em] text-[var(--sf-muted)]">{{ $featured->isAvailable ? 'Tersedia sekarang' : 'Stok habis' }}</span>
                                        <span class="mt-1 line-clamp-2 break-words font-display text-base font-black leading-tight text-[var(--sf-ink)]">{{ $featured->name }}</span>
                                        <span class="mt-1 block text-xs font-bold leading-5 text-[var(--sf-accent)]">{{ $storefront->idr($featured->priceAmount) }} <span class="text-[var(--sf-muted)]">· {{ $featured->stockLabel }}</span></span>
                                    </span>
                                </a>
                            </li>
                        @endif
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</section>
