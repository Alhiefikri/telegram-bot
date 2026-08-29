@php
    $catalogProduct = $catalogProduct ?? null;
    $presentation = $catalogProduct?->presentation ?? ($presentation ?? $storefront->productPresentation($product));
    $isPopular = $catalogProduct?->isPopular ?? ($isPopular ?? false);
    $catalogReturnUrl = isset($catalogPage) ? $storefront->catalogUrl($presentation->id, $catalogPage->search) : $storefront->catalogUrl($presentation->id);
    $productUrl = $presentation->productUrl.(str_contains($presentation->productUrl, '?') ? '&' : '?').'from='.urlencode($catalogReturnUrl);
@endphp

@if ($presentation->isAvailable)
    <a href="{{ $productUrl }}" @if ($presentation->isBuyable) x-on:click.prevent="openProductModal(JSON.parse($el.dataset.product))" data-product='@json($presentation->modalPayload(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)' @endif id="product-{{ $presentation->id }}" class="product-card text-left reveal-item group block min-w-0 w-full transition-all duration-500" data-category="{{ $presentation->categoryKey }}">
        <article class="neo-panel relative flex h-full min-w-0 flex-col overflow-hidden bg-[var(--sf-surface)] transition-all duration-300 neo-hover">
            @include('storefront.components.product-cover', [
                'presentation' => $presentation,
                'isPopular' => $isPopular,
            ])

            <div class="flex flex-grow flex-col justify-between space-y-3 border-t-[3px] border-[var(--sf-border)] p-3 sm:space-y-4 sm:p-4">
                <div>
                    <h3 class="h-10 overflow-hidden font-display text-[0.8rem] font-black leading-tight text-[var(--sf-ink)] transition-colors duration-300 line-clamp-2 sm:text-sm">{{ $presentation->name }}</h3>
                    <p class="mt-2 h-10 overflow-hidden text-xs font-semibold leading-5 text-[var(--sf-muted)] line-clamp-2 sm:h-12 sm:text-sm sm:leading-6">{{ $presentation->descriptionSnippet }}</p>
                </div>

                <div class="flex flex-col gap-3 border-t-[3px] border-dashed border-[var(--sf-border)] pt-3">
                    <div class="flex flex-wrap items-center gap-1.5 sm:gap-2">
                        <span class="rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-2 py-1 font-display text-xs font-black text-[var(--sf-ink)] shadow-[2px_2px_0_var(--sf-shadow)] sm:px-2.5">
                            {{ $storefront->idr($presentation->priceAmount) }}
                        </span>
                        <span class="rounded-full border-2 border-[var(--sf-border)] bg-white px-2 py-1 text-[9px] font-black text-[var(--sf-ink)]">
                            {{ $presentation->soldLabel }}
                        </span>
                        <span class="rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-surface-warm)] px-2 py-1 text-[9px] font-black text-[var(--sf-ink)]">
                            {{ $presentation->stockLabel }}
                        </span>
                    </div>

                    <span class="{{ $presentation->isBuyable ? 'neo-sheen inline-flex h-11 items-center justify-center gap-1.5 rounded-[0.7rem] border-[3px] border-[var(--sf-border)] bg-[var(--sf-accent)] px-2 text-xs font-black text-white transition-colors group-hover:bg-[var(--sf-accent-deep)] sm:gap-2 sm:px-3 sm:text-sm' : 'inline-flex h-11 items-center justify-center rounded-[0.7rem] border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] px-2 text-xs font-black text-[var(--sf-ink)] sm:px-3 sm:text-sm' }}">
                        <span>{{ $presentation->cardActionLabel }}</span>
                        @if ($presentation->isBuyable)
                            <span aria-hidden="true" class="transition-transform duration-300 group-hover:translate-x-1">→</span>
                        @endif
                    </span>
                </div>
            </div>
        </article>
    </a>
@else
    <article id="product-{{ $presentation->id }}" class="product-card text-left reveal-item group block min-w-0 w-full transition-all duration-500" data-category="{{ $presentation->categoryKey }}" aria-disabled="true">
        <div class="neo-panel relative flex h-full min-w-0 flex-col overflow-hidden bg-[var(--sf-surface)] transition-all duration-300">
            @include('storefront.components.product-cover', [
                'presentation' => $presentation,
                'isPopular' => $isPopular,
            ])

            <div class="flex flex-grow flex-col justify-between space-y-3 border-t-[3px] border-[var(--sf-border)] p-3 sm:space-y-4 sm:p-4">
                <div>
                    <h3 class="h-10 overflow-hidden font-display text-[0.8rem] font-black leading-tight text-[var(--sf-ink)] line-clamp-2 sm:text-sm">{{ $presentation->name }}</h3>
                    <p class="mt-2 h-10 overflow-hidden text-xs font-semibold leading-5 text-[var(--sf-muted)] line-clamp-2 sm:h-12 sm:text-sm sm:leading-6">{{ $presentation->descriptionSnippet }}</p>
                </div>

                <div class="flex flex-col gap-3 border-t-[3px] border-dashed border-[var(--sf-border)] pt-3">
                    <div class="flex flex-wrap items-center gap-1.5 sm:gap-2">
                        <span class="rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-2 py-1 font-display text-xs font-black text-[var(--sf-ink)] sm:px-2.5">
                            {{ $storefront->idr($presentation->priceAmount) }}
                        </span>
                        <span class="rounded-full border-2 border-[var(--sf-border)] bg-[#c94856] px-2 py-1 text-[9px] font-black text-white">
                            {{ $presentation->soldLabel }}
                        </span>
                    </div>

                    <span class="inline-flex h-11 items-center justify-center rounded-[0.7rem] border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] px-2 text-xs font-black text-[var(--sf-ink)] sm:px-3 sm:text-sm">
                        {{ $presentation->cardActionLabel }}
                    </span>
                </div>
            </div>
        </div>
    </article>
@endif
