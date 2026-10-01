@php
    $family = $entry->family ?? ($family ?? null);
    $catalogReturnUrl = isset($catalogPage) ? $catalogPage->catalogReturnUrl : $storefront->catalogUrl();
    $familyUrl = $storefront->familyUrl($family->slug, $catalogReturnUrl);
@endphp
<a href="{{ $familyUrl }}" id="family-{{ $family->id }}" aria-label="Lihat pilihan {{ $family->displayName }}" class="family-card group block min-w-0 w-full rounded-[0.875rem] text-left reveal-item focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--sf-accent)] focus-visible:ring-offset-4" data-family-id="{{ $family->id }}">
    <article class="neo-panel relative flex h-full min-w-0 flex-col overflow-hidden bg-[var(--sf-surface)] transition-all duration-300 neo-hover">
        <div class="storefront-media-frame relative flex aspect-square w-full items-center justify-center overflow-hidden border-b border-[var(--sf-border)] bg-[var(--sf-surface-warm)]" data-media-frame>
            <div class="pointer-events-none absolute inset-0 bg-grid-pattern opacity-10" aria-hidden="true"></div>
            @if ($family->imageUrl)
                <img src="{{ $family->imageUrl }}" alt="{{ $family->displayName }}" loading="lazy" decoding="async" class="absolute inset-0 h-full w-full object-contain transition-transform duration-700 ease-out group-hover:scale-[1.02]">
            @else
                <div class="pointer-events-none absolute h-4/5 w-4/5 rounded-full border border-[var(--sf-accent)] opacity-15" aria-hidden="true"></div>
                <span class="relative flex h-20 w-20 -rotate-6 items-center justify-center rounded-2xl border-2 border-[var(--sf-accent)] bg-[var(--sf-surface)] font-display text-5xl font-black text-[var(--sf-accent)] shadow-[5px_5px_0_var(--sf-accent)] transition-transform duration-300 group-hover:rotate-0 sm:h-24 sm:w-24 sm:text-6xl" aria-hidden="true">{{ mb_substr($family->displayName, 0, 1) }}</span>
            @endif
            <span class="absolute left-2.5 top-2.5 rounded-md bg-[var(--sf-accent)] px-2 py-1 text-[9px] font-black uppercase tracking-wider text-white">Pilihan</span>
        </div>

        <div class="flex flex-grow flex-col justify-between space-y-3 p-3 sm:space-y-4 sm:p-4">
            <div class="min-w-0">
                <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-[var(--sf-muted)]">{{ $family->optionCount }} pilihan</p>
                <h3 class="mt-1 min-h-[44px] break-words font-display text-sm font-black leading-snug text-[var(--sf-ink)] sm:min-h-[52px] sm:text-lg"><span class="line-clamp-2">{{ $family->displayName }}</span></h3>
                @if ($family->description)
                    <p class="mt-2 hidden text-xs font-medium leading-5 text-[var(--sf-muted)] sm:line-clamp-2">{{ $family->description }}</p>
                @endif
            </div>

            <div class="flex flex-col gap-3 border-t border-dashed border-[var(--sf-border)] pt-3">
                <div class="flex min-w-0 flex-wrap items-center gap-1.5 sm:gap-2">
                    <span class="w-full break-words font-display text-base font-black leading-tight tracking-tight text-[var(--sf-accent)] sm:text-xl"><span class="block text-[10px] font-bold tracking-normal text-[var(--sf-muted)]">Mulai</span>{{ $family->minimumPriceAmount !== null ? $storefront->idr($family->minimumPriceAmount) : '—' }}</span>
                    <span class="max-w-full rounded-md bg-[var(--sf-yellow)] px-2 py-1 text-[10px] font-bold leading-4 text-[var(--sf-ink)]">{{ $family->availableOptionCount }} tersedia</span>
                    @if ($family->minimumUsdtPriceAmount !== null)
                        <span class="max-w-full break-words text-[10px] font-bold leading-4 text-[var(--sf-muted)]">{{ $storefront->usdt($family->minimumUsdtPriceAmount) }}</span>
                    @endif
                </div>
                <span class="neo-sheen inline-flex min-h-12 min-w-0 items-center justify-center gap-1.5 rounded-[0.7rem] border border-[var(--sf-border)] bg-[var(--sf-accent)] px-2 py-2 text-center text-xs font-black leading-4 text-white transition-colors group-hover:bg-[var(--sf-accent-deep)] sm:text-sm">
                    Lihat pilihan <span aria-hidden="true" class="shrink-0 transition-transform duration-300 group-hover:translate-x-1">→</span>
                </span>
            </div>
        </div>
    </article>
</a>
