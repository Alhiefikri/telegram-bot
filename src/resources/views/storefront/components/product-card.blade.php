@php
    $stock = $storefront->stockAvailable($product);
    $isAvailable = $stock > 0;
    $productLink = $storefront->productLink($product);
    $cardLink = $isAvailable ? $productLink : '#support';
    $originalPrice = $product->price_amount * 1.35;
    $mockRating = number_format(4.7 + ($product->id % 4) * 0.1, 1);
    $descriptionSnippet = $storefront->descriptionSnippet($product);
@endphp

<div class="product-card reveal-item opacity-0 transform translate-y-8 transition-all duration-500 group cursor-pointer" data-category="{{ $product->category_id ?? 'lainnya' }}" onclick="window.location.href = @js($cardLink)">
    <div class="h-full bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm hover:shadow-md hover:border-slate-300 transition-all duration-300 flex flex-col hover-lift relative">
        <div class="scanner-line"></div>

        @include('storefront.components.product-cover', [
            'product' => $product,
            'isAvailable' => $isAvailable,
            'isPopular' => $isPopular ?? false,
        ])

        <div class="p-4 flex-grow flex flex-col justify-between space-y-4">
            <div>
                <h3 class="text-xs font-bold text-slate-900 leading-snug group-hover:text-slate-700 transition-colors duration-300 h-8 overflow-hidden line-clamp-2">{{ $product->name }}</h3>
                <p class="mt-2 text-[10px] leading-4 text-zinc-500 h-8 overflow-hidden line-clamp-2">{{ $descriptionSnippet }}</p>
            </div>

            <div class="pt-2.5 border-t border-zinc-100 flex flex-col space-y-1.5">
                <div class="flex items-baseline gap-1.5">
                    <span class="text-[10px] text-zinc-400 line-through font-medium">{{ $storefront->idr($originalPrice) }}</span>
                    <span class="text-xs font-bold text-slate-900">{{ $storefront->idr($product->price_amount) }}</span>
                </div>

                <div class="flex justify-between items-center text-[9px] font-bold text-zinc-500">
                    <span class="flex items-center gap-0.5">
                        <span class="text-slate-400">★</span>
                        <span>{{ $mockRating }}</span>
                    </span>

                    @if ($isAvailable)
                        <span>✅ Tersedia</span>
                    @else
                        <span class="text-zinc-400">❌ Stok habis</span>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="sr-only">
        @if ($isAvailable)
            <span>✅ Tersedia</span>
            <a href="{{ $productLink }}">Order Sekarang</a>
        @else
            <span>❌ Stok habis</span>
            <a href="#support">Tanya stok ke support</a>
        @endif
    </div>
</div>
