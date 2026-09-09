@php
    $catalogProduct = $catalogProduct ?? null;
    $presentation = $catalogProduct?->presentation ?? ($presentation ?? null);
    $isPopular = $catalogProduct?->isPopular ?? ($isPopular ?? false);
    $catalogReturnUrl = isset($catalogPage)
        ? $catalogPage->productReturnUrl($presentation->id)
        : $storefront->catalogUrl($presentation->id);
    $productUrl = $storefront->productUrl($presentation->id, $catalogReturnUrl);
    $checkoutUrl = $storefront->checkoutProductUrl($presentation->id, $productUrl);
@endphp

<article
    id="product-{{ $presentation->id }}"
    class="product-card reveal-item group block min-w-0 w-full text-left transition-all duration-500"
    data-category="{{ $presentation->categoryKey }}"
    @if (! $presentation->isAvailable) aria-disabled="true" @endif
>
    <div class="neo-panel relative flex h-full min-w-0 flex-col overflow-hidden bg-[var(--sf-surface)] transition-all duration-300 neo-hover">
        <a
            href="{{ $productUrl }}"
            class="block rounded-t-[0.7rem] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--sf-accent)] focus-visible:ring-inset"
            aria-label="Lihat detail {{ $presentation->name }}"
        >
            @include('storefront.components.product-cover', [
                'presentation' => $presentation,
                'isPopular' => $isPopular,
            ])
        </a>

        <div class="flex flex-grow flex-col justify-between space-y-3 border-t-[3px] border-[var(--sf-border)] p-3 sm:space-y-4 sm:p-4">
            <div class="min-w-0">
                <h3 class="min-h-[44px] max-h-[44px] overflow-hidden font-display text-base font-black leading-tight text-[var(--sf-ink)] sm:min-h-[48px] sm:max-h-[48px] sm:text-lg">
                    <a
                        href="{{ $productUrl }}"
                        class="flex min-h-[44px] w-full items-center rounded-sm focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--sf-accent)] sm:min-h-[48px]"
                    >
                        {{ $presentation->name }}
                    </a>
                </h3>
                <p class="mt-2 hidden text-sm font-semibold leading-6 text-[var(--sf-muted)] line-clamp-2 sm:block">
                    {{ $presentation->descriptionSnippet }}
                </p>
            </div>

            <div class="flex flex-col gap-3 border-t-[3px] border-dashed border-[var(--sf-border)] pt-3">
                <div class="flex flex-wrap items-center gap-1.5 sm:gap-2">
                    <span class="rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-2 py-1 font-display text-sm font-black text-[var(--sf-ink)] shadow-[2px_2px_0_var(--sf-shadow)] sm:px-2.5">
                        {{ $storefront->idr($presentation->priceAmount) }}
                    </span>
                    <span class="hidden rounded-full border-2 border-[var(--sf-border)] bg-white px-2 py-1 text-xs font-black text-[var(--sf-ink)] sm:inline-flex">
                        {{ $presentation->soldLabel }}
                    </span>
                    <span class="rounded-full border-2 border-[var(--sf-border)] {{ $presentation->isAvailable ? 'bg-[var(--sf-surface-warm)] text-[var(--sf-ink)]' : 'bg-[#c94856] text-white' }} px-2 py-1 text-xs font-black">
                        {{ $presentation->stockLabel }}
                    </span>
                </div>

                @if ($presentation->isAvailable)
                    <a
                        href="{{ $checkoutUrl }}"
                        class="neo-button neo-sheen inline-flex min-h-12 items-center justify-center gap-2 rounded-[0.7rem] border-[3px] border-[var(--sf-border)] bg-[var(--sf-accent)] px-3 text-sm font-black text-white transition-colors hover:bg-[var(--sf-accent-deep)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--sf-accent)]"
                    >
                        <span>Beli sekarang</span>
                        <span aria-hidden="true" class="transition-transform duration-300 group-hover:translate-x-1">→</span>
                    </a>
                    <div class="grid grid-cols-2 gap-2">
                        <a
                            href="{{ $productUrl }}"
                            class="neo-button inline-flex min-h-[44px] items-center justify-center rounded-[0.7rem] border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] px-2 text-sm font-black text-[var(--sf-ink)] transition-colors hover:bg-[var(--sf-surface-warm)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--sf-accent)]"
                        >
                            Detail
                        </a>
                        <form method="POST" action="{{ route('storefront.shop.cart.items.store') }}" class="min-w-0">
                            @csrf
                            <input type="hidden" name="product_id" value="{{ $presentation->id }}">
                            <input type="hidden" name="quantity" value="1">
                            <button
                                type="submit"
                                class="neo-button inline-flex min-h-[44px] w-full items-center justify-center rounded-[0.7rem] border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] px-2 text-sm font-black text-[var(--sf-ink)] transition-colors hover:bg-[var(--sf-surface-warm)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--sf-accent)]"
                            >
                                + Keranjang
                            </button>
                        </form>
                    </div>
                @else
                    <div class="grid grid-cols-2 gap-2">
                        <a
                            href="{{ $productUrl }}"
                            class="neo-button inline-flex min-h-[44px] items-center justify-center rounded-[0.7rem] border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] px-2 text-sm font-black text-[var(--sf-ink)] transition-colors hover:bg-[var(--sf-surface-warm)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--sf-accent)]"
                        >
                            Detail
                        </a>
                        <a
                            href="{{ $productUrl }}#waitlist"
                            class="neo-button inline-flex min-h-[44px] items-center justify-center rounded-[0.7rem] border-[3px] border-[var(--sf-border)] bg-[var(--sf-accent)] px-2 text-sm font-black text-white transition-colors hover:bg-[var(--sf-accent-deep)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--sf-accent)]"
                        >
                            {{ $presentation->waitlistCtaLabel }}
                        </a>
                    </div>
                @endif
            </div>
        </div>
    </div>
</article>
