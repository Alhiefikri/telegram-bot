@php
    $family = $entry->family ?? ($family ?? null);
    $catalogReturnUrl = isset($catalogPage) ? $catalogPage->catalogReturnUrl : $storefront->catalogUrl();
    $familyUrl = $family->familyUrl.(str_contains($family->familyUrl, '?') ? '&' : '?').'from='.urlencode($catalogReturnUrl);
@endphp
<a href="{{ $familyUrl }}" id="family-{{ $family->id }}" aria-label="{{ $family->name }} Product Family" class="family-card group block min-w-0 w-full text-left reveal-item" data-family-id="{{ $family->id }}">
    <article class="neo-panel relative flex h-full min-w-0 flex-col overflow-hidden bg-[var(--sf-surface)] transition-all duration-300 neo-hover">
        <div class="relative flex aspect-[4/3] w-full items-center justify-center overflow-hidden border-b-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)]">
            <div class="pointer-events-none absolute inset-0 bg-grid-pattern opacity-20"></div>
            @if ($family->imageUrl)
                <img src="{{ $family->imageUrl }}" alt="{{ $family->name }}" loading="lazy" decoding="async" class="absolute inset-0 h-full w-full object-cover transition-transform duration-700 ease-out group-hover:scale-[1.07]">
            @else
                <span class="relative z-10 font-display text-7xl font-black text-[var(--sf-accent)] transition-transform duration-500 group-hover:scale-110" aria-hidden="true">{{ mb_substr($family->name, 0, 1) }}</span>
                <span class="sr-only">Gambar {{ $family->name }}</span>
            @endif
            <span class="absolute left-3 top-3 rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-accent)] px-2 py-1 text-[9px] font-black uppercase tracking-wider text-white shadow-[3px_3px_0_var(--sf-shadow)]">Product Family</span>
        </div>

        <div class="flex flex-grow flex-col justify-between space-y-4 p-4 sm:p-5">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-[var(--sf-muted)]">{{ $family->optionCount }} pilihan produk</p>
                <h3 class="mt-2 font-display text-xl font-black leading-tight text-[var(--sf-ink)] sm:text-2xl">{{ $family->name }}</h3>
                @if ($family->description)
                    <p class="mt-2 h-10 overflow-hidden text-xs font-semibold leading-5 text-[var(--sf-muted)] line-clamp-2">{{ $family->description }}</p>
                @endif
            </div>

            <div class="flex flex-col gap-3 border-t-[3px] border-dashed border-[var(--sf-border)] pt-3">
                <div class="flex flex-wrap items-center gap-2 text-[10px] font-black">
                    <span class="rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-2.5 py-1.5">Mulai {{ $family->minimumPriceAmount !== null ? $storefront->idr($family->minimumPriceAmount) : '—' }}</span>
                    <span class="rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-surface-warm)] px-2.5 py-1.5">{{ $family->availableOptionCount }} tersedia</span>
                    @if ($family->minimumUsdtPriceAmount !== null)
                        <span class="rounded-full border-2 border-[var(--sf-border)] bg-white px-2.5 py-1.5">{{ $storefront->usdt($family->minimumUsdtPriceAmount) }}</span>
                    @endif
                </div>
                <span class="neo-sheen inline-flex h-11 items-center justify-center gap-2 rounded-[0.7rem] border-[3px] border-[var(--sf-border)] bg-[var(--sf-accent)] px-3 text-sm font-black text-white transition-colors group-hover:bg-[var(--sf-accent-deep)]">
                    Lihat pilihan <span aria-hidden="true" class="transition-transform duration-300 group-hover:translate-x-1">→</span>
                </span>
            </div>
        </div>
    </article>
</a>
