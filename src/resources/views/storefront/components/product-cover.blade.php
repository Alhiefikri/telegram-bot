@php
    $context = $context ?? 'card';
    $coverTheme = $storefront->productCover($product);
    $imageUrl = $storefront->publicStorageUrl($product->image_path);
    $isAvailable = $isAvailable ?? $storefront->stockAvailable($product) > 0;
    $isPopular = $isPopular ?? false;
@endphp

@if ($context === 'featured')
    <div class="w-full max-w-[220px] aspect-square rounded-xl overflow-hidden {{ $coverTheme['bg'] }} p-6 flex flex-col justify-center items-center text-center shadow-lg shadow-zinc-900/10 relative text-white">
        <div class="absolute inset-0 bg-grid-pattern opacity-5 pointer-events-none"></div>

        @if ($imageUrl)
            <img src="{{ $imageUrl }}" alt="{{ $product->name }}" class="absolute inset-0 w-full h-full object-cover">
        @else
            <div class="relative z-10 flex flex-col items-center justify-center text-center space-y-4">
                <div class="{{ $coverTheme['accent'] }}">
                    {!! $coverTheme['logo'] ?? '<svg class="w-12 h-12" viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="18" height="18" rx="4"/></svg>' !!}
                </div>
                <div class="text-[8px] font-semibold tracking-widest {{ $coverTheme['accent'] }} uppercase opacity-80">{{ $coverTheme['desc'] }}</div>
            </div>
        @endif

        <span class="absolute top-3 left-3 {{ $coverTheme['badge_bg'] }} text-[8px] font-semibold tracking-widest uppercase px-2 py-0.5 rounded shadow-sm">{{ $coverTheme['badge'] }}</span>
        <span class="sr-only">Gambar produk menyusul</span>
    </div>
@else
    <div class="aspect-square w-full relative overflow-hidden bg-stone-900 flex flex-col items-center justify-center p-4 text-white">
        <div class="absolute inset-0 bg-grid-pattern opacity-10 pointer-events-none"></div>
        <div class="absolute inset-0 {{ $coverTheme['bg'] }} opacity-0"></div>

        @if ($imageUrl)
            <img src="{{ $imageUrl }}" alt="{{ $product->name }}" class="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
        @else
            <div class="relative z-10 flex flex-col items-center justify-center text-center space-y-3">
                <div class="{{ $coverTheme['accent'] }} drop-shadow-[0_0_8px_rgba(249,115,22,0.15)]">
                    {!! $coverTheme['logo'] !!}
                </div>
                <div class="text-[8px] font-semibold tracking-widest {{ $coverTheme['accent'] }} uppercase opacity-80">{{ $coverTheme['desc'] }}</div>
            </div>
            <div class="absolute bottom-2 left-3 text-[7px] font-semibold text-zinc-600 tracking-widest uppercase opacity-40">LHIE STORE</div>
            <span class="sr-only">Gambar produk menyusul</span>
        @endif

        <div class="absolute top-2.5 left-2.5 right-2.5 flex justify-between items-start">
            @if ($isAvailable)
                <span class="px-2 py-0.5 text-[8px] font-bold uppercase tracking-wider rounded {{ $coverTheme['badge_bg'] }} shadow-sm">
                    {{ $coverTheme['badge'] }}
                </span>

                @if($isPopular)
                    <span class="px-2 py-0.5 text-[8px] font-bold uppercase tracking-wider rounded bg-zinc-900 text-white shadow-sm">
                        POPULER
                    </span>
                @endif
            @else
                <span class="px-2 py-0.5 text-[8px] font-bold uppercase tracking-wider rounded bg-zinc-600 text-white shadow-sm">
                    ❌ HABIS
                </span>
            @endif
        </div>
    </div>
@endif
