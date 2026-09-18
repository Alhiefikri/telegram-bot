@php
    $catalogProduct = $catalogProduct ?? null;
    $presentation = $catalogProduct?->presentation ?? ($presentation ?? null);
    $catalogReturnUrl = isset($catalogPage)
        ? $catalogPage->productReturnUrl($presentation->id)
        : $storefront->catalogUrl($presentation->id);
    $productUrl = $storefront->productUrl($presentation->id, $catalogReturnUrl);
    $rank = $rank ?? 1;
@endphp

<article
    id="trending-product-{{ $presentation->id }}"
    class="trending-product-card group min-w-0"
    data-category="{{ $presentation->categoryKey }}"
>
    <a
        href="{{ $productUrl }}"
        class="block h-full rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--sf-trend-accent)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--sf-trend-panel)]"
        aria-label="Lihat detail {{ $presentation->name }}"
    >
        <div class="trending-product-card__media relative">
            @include('storefront.components.product-cover', [
                'presentation' => $presentation,
                'isPopular' => false,
                'context' => 'trending',
            ])
            <span class="trending-product-card__rank" aria-label="Peringkat {{ $rank }}">#{{ $rank }}</span>
        </div>

        <div class="trending-product-card__body">
            <p class="trending-product-card__eyebrow">Produk digital</p>
            <h3 class="trending-product-card__name">
                {{ $presentation->name }}
            </h3>
            <div class="trending-product-card__meta">
                <span class="trending-product-card__price">{{ $storefront->idr($presentation->priceAmount) }}</span>
                <span class="trending-product-card__stock">{{ $presentation->stockLabel }}</span>
            </div>
            <p class="trending-product-card__sold">{{ $presentation->soldLabel }}</p>
        </div>
    </a>
</article>
