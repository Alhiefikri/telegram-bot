@php
    $stock = $storefront->stockAvailable($product);
    $isAvailable = $stock > 0;
    $descriptionSnippet = $storefront->descriptionSnippet($product);
    $productPayload = $isAvailable ? $storefront->productModalPayload($product, $stock) : null;
@endphp

@if ($isAvailable)
    <a href="{{ route('storefront.products.show', $product) }}" x-on:click.prevent="openProductModal(JSON.parse($el.dataset.product))" data-product='@json($productPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)' id="product-{{ $product->id }}" class="product-card text-left reveal-item group block transition-all duration-500 w-full" data-category="{{ $product->category_id ?? 'lainnya' }}">
        <article class="neo-panel h-full overflow-hidden bg-[var(--sf-surface)] transition-all duration-300 neo-hover flex flex-col relative">
            @include('storefront.components.product-cover', [
                'product' => $product,
                'isAvailable' => $isAvailable,
                'isPopular' => $isPopular ?? false,
            ])

            <div class="flex flex-grow flex-col justify-between space-y-4 border-t-[3px] border-[var(--sf-border)] p-4">
                <div>
                    <h3 class="h-10 overflow-hidden font-display text-sm font-black leading-tight text-[var(--sf-ink)] transition-colors duration-300 line-clamp-2">{{ $product->name }}</h3>
                    <p class="mt-2 h-10 overflow-hidden text-[11px] font-semibold leading-5 text-[var(--sf-muted)] line-clamp-2">{{ $descriptionSnippet }}</p>
                </div>

                <div class="flex flex-col gap-3 border-t-[3px] border-dashed border-[var(--sf-border)] pt-3">
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($product->fake_discount_price)
                            <span class="text-[10px] font-black text-[var(--sf-muted)] line-through">
                                {{ $storefront->idr($product->fake_discount_price) }}
                            </span>
                        @endif
                        <span class="rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-2.5 py-1 font-display text-xs font-black text-[var(--sf-ink)]">
                            {{ $storefront->idr($product->price_amount) }}
                        </span>
                        <span class="rounded-full border-2 border-[var(--sf-border)] bg-white px-2 py-1 text-[9px] font-black text-[var(--sf-ink)]">
                            Tersedia
                        </span>
                        <span class="rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-surface-warm)] px-2 py-1 text-[9px] font-black text-[var(--sf-ink)]">
                            Stok: {{ $stock }}
                        </span>
                    </div>

                    <span class="neo-button inline-flex h-10 items-center justify-center bg-[var(--sf-accent)] px-3 text-[11px] font-black text-white transition-colors hover:bg-[var(--sf-accent-deep)]">
                        Pesan via Admin
                    </span>
                </div>
            </div>
        </article>
    </a>
@else
    <article id="product-{{ $product->id }}" class="product-card text-left reveal-item group block transition-all duration-500 w-full" data-category="{{ $product->category_id ?? 'lainnya' }}" aria-disabled="true">
        <div class="neo-panel h-full overflow-hidden bg-[var(--sf-surface)] transition-all duration-300 flex flex-col relative">
            @include('storefront.components.product-cover', [
                'product' => $product,
                'isAvailable' => $isAvailable,
                'isPopular' => $isPopular ?? false,
            ])

            <div class="flex flex-grow flex-col justify-between space-y-4 border-t-[3px] border-[var(--sf-border)] p-4">
                <div>
                    <h3 class="h-10 overflow-hidden font-display text-sm font-black leading-tight text-[var(--sf-ink)] line-clamp-2">{{ $product->name }}</h3>
                    <p class="mt-2 h-10 overflow-hidden text-[11px] font-semibold leading-5 text-[var(--sf-muted)] line-clamp-2">{{ $descriptionSnippet }}</p>
                </div>

                <div class="flex flex-col gap-3 border-t-[3px] border-dashed border-[var(--sf-border)] pt-3">
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($product->fake_discount_price)
                            <span class="text-[10px] font-black text-[var(--sf-muted)] line-through">
                                {{ $storefront->idr($product->fake_discount_price) }}
                            </span>
                        @endif
                        <span class="rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-2.5 py-1 font-display text-xs font-black text-[var(--sf-ink)]">
                            {{ $storefront->idr($product->price_amount) }}
                        </span>
                        <span class="rounded-full border-2 border-[var(--sf-border)] bg-[#c94856] px-2 py-1 text-[9px] font-black text-white">
                            Habis
                        </span>
                    </div>

                    <span class="neo-button inline-flex h-10 items-center justify-center bg-[var(--sf-surface-warm)] px-3 text-[11px] font-black text-[var(--sf-ink)]">
                        Stok kosong
                    </span>
                </div>
            </div>
        </div>
    </article>
@endif
