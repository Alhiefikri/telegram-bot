@php
    $catalogProduct = $catalogProduct ?? null;
    $presentation = $catalogProduct?->presentation ?? ($presentation ?? $storefront->productPresentation($product));
    $isPopular = $catalogProduct?->isPopular ?? ($isPopular ?? false);
    $catalogReturnUrl = isset($catalogPage) ? $storefront->catalogUrl($presentation->id, $catalogPage->search) : $storefront->catalogUrl($presentation->id);
    $productUrl = $presentation->productUrl.(str_contains($presentation->productUrl, '?') ? '&' : '?').'from='.urlencode($catalogReturnUrl);
@endphp

@if ($presentation->canOpenModal)
    <a href="{{ $productUrl }}" x-on:click.prevent="openProductModal(JSON.parse($el.dataset.product))" data-product='@json($presentation->modalPayload(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)' id="product-{{ $presentation->id }}" class="product-card text-left reveal-item group block transition-all duration-500 w-full" data-category="{{ $presentation->categoryKey }}">
        <article class="neo-panel h-full overflow-hidden bg-[var(--sf-surface)] transition-all duration-300 neo-hover flex flex-col relative">
            @include('storefront.components.product-cover', [
                'presentation' => $presentation,
                'isPopular' => $isPopular,
            ])

            <div class="flex flex-grow flex-col justify-between space-y-4 border-t-[3px] border-[var(--sf-border)] p-4">
                <div>
                    <h3 class="h-10 overflow-hidden font-display text-sm font-black leading-tight text-[var(--sf-ink)] transition-colors duration-300 line-clamp-2">{{ $presentation->name }}</h3>
                    <p class="mt-2 h-12 overflow-hidden text-sm font-semibold leading-6 text-[var(--sf-muted)] line-clamp-2">{{ $presentation->descriptionSnippet }}</p>
                </div>

                <div class="flex flex-col gap-3 border-t-[3px] border-dashed border-[var(--sf-border)] pt-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-2.5 py-1 font-display text-xs font-black text-[var(--sf-ink)] shadow-[2px_2px_0_var(--sf-shadow)]">
                            {{ $storefront->idr($presentation->priceAmount) }}
                        </span>
                        <span class="rounded-full border-2 border-[var(--sf-border)] bg-white px-2 py-1 text-[9px] font-black text-[var(--sf-ink)]">
                            {{ $presentation->soldLabel }}
                        </span>
                        <span class="rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-surface-warm)] px-2 py-1 text-[9px] font-black text-[var(--sf-ink)]">
                            {{ $presentation->stockLabel }}
                        </span>
                    </div>

                    <span class="neo-sheen inline-flex h-11 items-center justify-center gap-2 rounded-[0.7rem] border-[3px] border-[var(--sf-border)] bg-[var(--sf-accent)] px-3 text-sm font-black text-white transition-colors group-hover:bg-[var(--sf-accent-deep)]">
                        <span>{{ $presentation->cardActionLabel }}</span>
                        <span aria-hidden="true" class="transition-transform duration-300 group-hover:translate-x-1">→</span>
                    </span>
                </div>
            </div>
        </article>
    </a>
@else
    <article id="product-{{ $presentation->id }}" class="product-card text-left reveal-item group block transition-all duration-500 w-full" data-category="{{ $presentation->categoryKey }}" aria-disabled="true">
        <div class="neo-panel h-full overflow-hidden bg-[var(--sf-surface)] transition-all duration-300 flex flex-col relative">
            @include('storefront.components.product-cover', [
                'presentation' => $presentation,
                'isPopular' => $isPopular,
            ])

            <div class="flex flex-grow flex-col justify-between space-y-4 border-t-[3px] border-[var(--sf-border)] p-4">
                <div>
                    <h3 class="h-10 overflow-hidden font-display text-sm font-black leading-tight text-[var(--sf-ink)] line-clamp-2">{{ $presentation->name }}</h3>
                    <p class="mt-2 h-12 overflow-hidden text-sm font-semibold leading-6 text-[var(--sf-muted)] line-clamp-2">{{ $presentation->descriptionSnippet }}</p>
                </div>

                <div class="flex flex-col gap-3 border-t-[3px] border-dashed border-[var(--sf-border)] pt-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-2.5 py-1 font-display text-xs font-black text-[var(--sf-ink)]">
                            {{ $storefront->idr($presentation->priceAmount) }}
                        </span>
                        <span class="rounded-full border-2 border-[var(--sf-border)] bg-[#c94856] px-2 py-1 text-[9px] font-black text-white">
                            {{ $presentation->soldLabel }}
                        </span>
                    </div>

                    <span class="inline-flex h-11 items-center justify-center rounded-[0.7rem] border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] px-3 text-sm font-black text-[var(--sf-ink)]">
                        {{ $presentation->cardActionLabel }}
                    </span>
                </div>
            </div>
        </div>
    </article>
@endif
