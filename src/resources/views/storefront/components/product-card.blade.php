@php
    $stock = $storefront->stockAvailable($product);
    $isAvailable = $stock > 0;
    $productLink = $storefront->productLink($product);
    $cardLink = $isAvailable ? $productLink : '#support';
    $descriptionSnippet = $storefront->descriptionSnippet($product);
@endphp

<a href="{{ $cardLink }}" class="product-card reveal-item block transition-all duration-500 group" data-category="{{ $product->category_id ?? 'lainnya' }}">
    <div class="h-full bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm hover:shadow-md hover:border-slate-300 transition-all duration-300 flex flex-col hover-lift relative">
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
                <div>
                    <span class="text-xs font-bold text-slate-900">{{ $storefront->idr($product->price_amount) }}</span>
                </div>

                <div class="flex justify-between items-center text-[9px] font-bold text-zinc-500">
                    <span>{{ $isAvailable ? 'Sisa '.$stock : 'Stok 0' }}</span>

                    @if ($isAvailable)
                        <span>✅ Tersedia</span>
                    @else
                        <span class="text-zinc-400">❌ Stok habis</span>
                    @endif
                </div>
            </div>
        </div>
    </div>
</a>
