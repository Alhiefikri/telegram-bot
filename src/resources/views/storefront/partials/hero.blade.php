@php($featuredEntries = $catalogPage->entries()->take(2))

<section class="storefront-hero-card relative mx-auto max-w-6xl px-4 py-5 sm:px-6 sm:py-6" aria-labelledby="storefront-promise-title">
    <div class="grid gap-5 rounded-2xl border border-[var(--sf-border)] bg-[var(--sf-surface)] p-4 shadow-[0_12px_30px_rgb(18_35_63_/_0.08)] sm:p-6 lg:grid-cols-[minmax(0,1fr)_minmax(20rem,26rem)] lg:items-center">
        <div class="max-w-2xl">
            <p class="text-xs font-black uppercase tracking-[0.18em] text-[var(--sf-accent)]">{{ $copy['hero']['eyebrow'] }}</p>
            <h1 id="storefront-promise-title" class="mt-2 max-w-xl font-display text-2xl font-black leading-tight tracking-tight text-[var(--sf-ink)] text-balance sm:text-4xl">
                {{ $copy['hero']['headline'] }}
            </h1>
            <p class="mt-3 max-w-xl text-sm font-semibold leading-6 text-[var(--sf-muted)] sm:text-base">
                {{ $copy['hero']['body'] }}
            </p>
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <a href="{{ $shopUrl }}" class="neo-button inline-flex min-h-12 items-center justify-center gap-2 bg-[var(--sf-accent)] px-5 text-sm font-black text-white">
                    <span>{{ $copy['hero']['primary_cta'] }}</span>
                </a>
                <a href="#cara-beli" class="inline-flex min-h-11 items-center px-1 text-sm font-black text-[var(--sf-accent)] underline decoration-1 underline-offset-4 hover:text-[var(--sf-accent-deep)]">
                    {{ $copy['hero']['secondary_cta'] }}
                </a>
            </div>
        </div>

        @if ($featuredEntries->isNotEmpty())
            <section class="min-w-0" aria-labelledby="storefront-featured-title">
                <div class="mb-2 flex items-center justify-between gap-3">
                    <h2 id="storefront-featured-title" class="text-xs font-black uppercase tracking-[0.16em] text-[var(--sf-muted)]">Dari katalog</h2>
                    <a href="{{ $shopUrl }}" class="inline-flex min-h-11 items-center text-xs font-black text-[var(--sf-accent)] underline underline-offset-4">Lihat semua</a>
                </div>
                <ul class="grid min-w-0 gap-3 lg:grid-cols-1" aria-label="Pilihan dari katalog">
                    @foreach ($featuredEntries as $entry)
                        @if ($entry->isFamily())
                            @php($featured = $entry->family)
                            <li class="min-w-0 {{ $loop->iteration > 1 ? 'hidden sm:block' : '' }}">
                                <a href="{{ $featured->familyUrl }}" aria-label="Lihat pilihan {{ $featured->name }}" class="group flex min-w-0 items-center gap-3 rounded-xl border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-3 transition-colors hover:bg-[var(--sf-surface)] focus-visible:outline focus-visible:outline-3 focus-visible:outline-[var(--sf-accent)]">
                                    <span class="relative flex aspect-square w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-[var(--sf-border)] bg-[var(--sf-accent-soft)]">
                                        @if ($featured->imageUrl)
                                            <img src="{{ $featured->imageUrl }}" alt="" width="80" height="80" loading="eager" decoding="async" class="absolute inset-0 h-full w-full object-cover">
                                        @else
                                            <span class="font-display text-3xl font-black text-[var(--sf-accent)]" aria-hidden="true">{{ mb_substr($featured->name, 0, 1) }}</span>
                                        @endif
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block text-[10px] font-black uppercase tracking-[0.14em] text-[var(--sf-muted)]">{{ $featured->optionCount }} pilihan</span>
                                        <span class="mt-1 block truncate font-display text-base font-black text-[var(--sf-ink)]">{{ $featured->name }}</span>
                                        <span class="mt-1 block text-xs font-bold leading-5 text-[var(--sf-muted)]">Mulai {{ $featured->minimumPriceAmount !== null ? $storefront->idr($featured->minimumPriceAmount) : '—' }} · {{ $featured->availableOptionCount }} tersedia</span>
                                    </span>
                                </a>
                            </li>
                        @else
                            @php($featured = $entry->product->presentation)
                            <li class="min-w-0 {{ $loop->iteration > 1 ? 'hidden sm:block' : '' }}">
                                <a href="{{ $featured->productUrl }}" aria-label="Lihat detail {{ $featured->name }}" class="group flex min-w-0 items-center gap-3 rounded-xl border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-3 transition-colors hover:bg-[var(--sf-surface)] focus-visible:outline focus-visible:outline-3 focus-visible:outline-[var(--sf-accent)]">
                                    <span class="relative flex aspect-square w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-[var(--sf-border)] bg-[var(--sf-accent-soft)]">
                                        @if ($featured->cover->imageUrl)
                                            <img src="{{ $featured->cover->imageUrl }}" alt="" width="80" height="80" loading="eager" decoding="async" class="absolute inset-0 h-full w-full object-cover">
                                        @else
                                            <span class="font-display text-3xl font-black text-[var(--sf-accent)]" aria-hidden="true">{{ mb_substr($featured->name, 0, 1) }}</span>
                                        @endif
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block text-[10px] font-black uppercase tracking-[0.14em] text-[var(--sf-muted)]">{{ $featured->isAvailable ? 'Tersedia sekarang' : 'Stok habis' }}</span>
                                        <span class="mt-1 block truncate font-display text-base font-black text-[var(--sf-ink)]">{{ $featured->name }}</span>
                                        <span class="mt-1 block text-xs font-bold leading-5 text-[var(--sf-muted)]">{{ $storefront->idr($featured->priceAmount) }} · {{ $featured->stockLabel }}</span>
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
