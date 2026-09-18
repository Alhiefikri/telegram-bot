@php
    $catalogProduct = $catalogProduct ?? null;
    $presentation = $catalogProduct?->presentation ?? ($presentation ?? null);
    $isPopular = $catalogProduct?->isPopular ?? ($isPopular ?? false);
    $catalogReturnUrl = isset($catalogPage)
        ? $catalogPage->productReturnUrl($presentation->id)
        : $storefront->catalogUrl($presentation->id);
    $productUrl = $storefront->productUrl($presentation->id, $catalogReturnUrl);
    $checkoutUrl = $storefront->checkoutProductUrl($presentation->id, $catalogReturnUrl);
@endphp

<article
    id="product-{{ $presentation->id }}"
    class="product-card reveal-item group block min-w-0 w-full text-left transition-all duration-500"
    data-category="{{ $presentation->categoryKey }}"
>
    <div class="neo-panel relative flex h-full min-w-0 flex-col overflow-hidden bg-[var(--sf-surface)] transition-all duration-300 neo-hover">
        <a
            href="{{ $productUrl }}"
            class="relative z-10 block rounded-t-[0.7rem] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--sf-accent)] focus-visible:ring-inset"
            aria-label="Lihat detail {{ $presentation->name }}"
        >
            @include('storefront.components.product-cover', [
                'presentation' => $presentation,
                'isPopular' => $isPopular,
            ])
        </a>

        <div class="flex flex-grow flex-col justify-between space-y-3 border-t border-[var(--sf-border)] p-3 sm:space-y-4 sm:p-4">
            <div class="min-w-0">
                <h3 class="min-w-0 font-display text-sm font-black leading-snug text-[var(--sf-ink)] sm:text-lg">
                    <a
                        href="{{ $productUrl }}"
                        class="block min-h-[44px] w-full rounded-sm py-0.5 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--sf-accent)] sm:min-h-[52px]"
                    >
                        <span class="line-clamp-2 break-words">{{ $presentation->name }}</span>
                    </a>
                </h3>
                <p class="product-card-description mt-2 hidden text-xs font-medium leading-5 text-[var(--sf-muted)] sm:line-clamp-2">
                    {{ $presentation->descriptionSnippet }}
                </p>
            </div>

            <div class="flex flex-col gap-3 border-t border-dashed border-[var(--sf-border)] pt-3">
                <div class="flex min-w-0 flex-wrap items-center gap-1.5 sm:gap-2">
                    <span class="w-full break-words font-display text-base font-black leading-tight tracking-tight text-[var(--sf-accent)] sm:text-xl">
                        {{ $storefront->idr($presentation->priceAmount) }}
                    </span>
                    <span class="hidden text-[10px] font-bold text-[var(--sf-muted)] sm:inline-flex">
                        {{ $presentation->soldLabel }}
                    </span>
                    <span class="max-w-full rounded-md {{ $presentation->isAvailable ? 'bg-[var(--sf-yellow)] text-[var(--sf-ink)]' : 'bg-[#c94856] text-white' }} px-2 py-1 text-[10px] font-bold leading-4">
                        {{ $presentation->stockLabel }}
                    </span>
                </div>

                @if ($presentation->isAvailable)
                    <a
                        href="{{ $checkoutUrl }}"
                        class="neo-button neo-sheen inline-flex min-h-12 min-w-0 items-center justify-center gap-1.5 rounded-[0.7rem] border border-[var(--sf-border)] bg-[var(--sf-accent)] px-2 py-2 text-center text-xs font-black leading-4 text-white transition-colors hover:bg-[var(--sf-accent-deep)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--sf-accent)] sm:text-sm"
                    >
                        <span>Beli sekarang</span>
                        <span aria-hidden="true" class="shrink-0 transition-transform duration-300 group-hover:translate-x-1">→</span>
                    </a>
                    <div class="flex min-w-0 justify-end">
                        <form method="POST" action="{{ route('storefront.shop.cart.items.store') }}" class="shrink-0">
                            @csrf
                            <input type="hidden" name="product_id" value="{{ $presentation->id }}">
                            <input type="hidden" name="quantity" value="1">
                            <button
                                type="submit"
                                aria-label="Tambahkan {{ $presentation->name }} ke keranjang"
                                title="Tambah ke keranjang"
                                class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center rounded-[0.7rem] border border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 text-[var(--sf-ink)] transition-colors hover:bg-[var(--sf-surface-warm)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--sf-accent)]"
                            >
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3h2l2.4 12h11.2l2-8H7"/><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/><path d="M13 3v6m-3-3h6"/></svg>
                            </button>
                        </form>
                    </div>
                @else
                    <div class="grid min-w-0 grid-cols-1 gap-2">
                        <a
                            href="{{ $productUrl }}"
                            class="inline-flex min-h-[44px] min-w-0 items-center justify-center rounded-[0.7rem] border border-[var(--sf-border)] bg-[var(--sf-surface)] px-2 py-2 text-center text-xs font-bold text-[var(--sf-ink)] transition-colors hover:bg-[var(--sf-surface-warm)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--sf-accent)]"
                        >
                            Detail
                        </a>
                        <a
                            href="{{ $productUrl }}#waitlist"
                            class="neo-button inline-flex min-h-[44px] min-w-0 items-center justify-center rounded-[0.7rem] border border-[var(--sf-border)] bg-[var(--sf-accent)] px-2 py-2 text-center text-xs font-black leading-4 text-white transition-colors hover:bg-[var(--sf-accent-deep)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--sf-accent)]"
                        >
                            {{ $presentation->waitlistCtaLabel }}
                        </a>
                    </div>
                @endif
            </div>
        </div>
    </div>
</article>
