@php
    $context = $context ?? 'card';
    $coverTheme = $storefront->productCover($product);
    $imageUrl = $storefront->publicStorageUrl($product->image_path);
    $isAvailable = $isAvailable ?? $storefront->stockAvailable($product) > 0;
    $isPopular = $isPopular ?? false;
@endphp

@if ($context === 'featured')
    <div class="relative flex aspect-square w-full max-w-[230px] flex-col items-center justify-center overflow-hidden rounded-lg border-[3px] border-[var(--sf-border)] {{ $coverTheme['bg'] }} p-6 text-center text-white shadow-[7px_7px_0_var(--sf-shadow)]">
        <div class="pointer-events-none absolute inset-0 bg-grid-pattern opacity-20"></div>

        @if ($imageUrl)
            <img src="{{ $imageUrl }}" alt="{{ $product->name }}" class="absolute inset-0 h-full w-full object-cover">
        @else
            <div class="relative z-10 flex flex-col items-center justify-center space-y-4 text-center">
                <div class="{{ $coverTheme['accent'] }}">
                    {!! $coverTheme['logo'] ?? '<svg class="w-12 h-12" viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="18" height="18" rx="4"/></svg>' !!}
                </div>
                <div class="{{ $coverTheme['accent'] }} text-[8px] font-black uppercase tracking-widest opacity-80">{{ $coverTheme['desc'] }}</div>
            </div>
        @endif

        <span class="absolute left-3 top-3 rounded-md border-2 border-[var(--sf-border)] {{ $coverTheme['badge_bg'] }} px-2 py-0.5 text-[8px] font-black uppercase tracking-widest shadow-[3px_3px_0_var(--sf-shadow)]">{{ $coverTheme['badge'] }}</span>
        <span class="sr-only">Gambar produk menyusul</span>
    </div>
@else
    <div class="relative flex aspect-[4/3] w-full flex-col items-center justify-center overflow-hidden bg-[#f3eee3] p-4 text-white">
        <div class="pointer-events-none absolute inset-0 bg-grid-pattern opacity-70"></div>
        <div class="absolute inset-0 {{ $coverTheme['bg'] }} opacity-15"></div>

        @if ($imageUrl)
            <img src="{{ $imageUrl }}" alt="{{ $product->name }}" class="absolute inset-0 h-full w-full object-cover transition-transform duration-500 group-hover:scale-105">
        @else
            <div class="relative z-10 flex flex-col items-center justify-center space-y-3 text-center">
                <div class="{{ $coverTheme['accent'] }} rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-3 text-[var(--sf-ink)] shadow-[5px_5px_0_var(--sf-shadow)]">
                    {!! $coverTheme['logo'] !!}
                </div>
                <div class="{{ $coverTheme['accent'] }} rounded-full border-2 border-[var(--sf-border)] bg-white px-2 py-1 text-[8px] font-black uppercase tracking-widest text-[var(--sf-ink)]">{{ $coverTheme['desc'] }}</div>
            </div>
            <div class="absolute bottom-2 left-3 text-[7px] font-black uppercase tracking-widest text-[var(--sf-muted)] opacity-70">LHIE STORE</div>
            <span class="sr-only">Gambar produk menyusul</span>
        @endif

        <div class="absolute left-2.5 right-2.5 top-2.5 flex items-start justify-between gap-2">
            @if ($isAvailable)
                <span class="rounded-md border-2 border-[var(--sf-border)] px-2 py-0.5 text-[8px] font-black uppercase tracking-wider shadow-[3px_3px_0_var(--sf-shadow)] {{ $coverTheme['badge_bg'] }}">
                    {{ $coverTheme['badge'] }}
                </span>

                @if($isPopular)
                    <span class="rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-accent)] px-2 py-0.5 text-[8px] font-black uppercase tracking-wider text-white shadow-[3px_3px_0_var(--sf-shadow)]">
                        POPULER
                    </span>
                @endif
            @else
                <span class="neo-float-badge rounded-md border-2 border-[var(--sf-border)] bg-[#c94856] px-2 py-0.5 text-[8px] font-black uppercase tracking-wider text-white shadow-[3px_3px_0_var(--sf-shadow)]">
                    STOK HABIS
                </span>
            @endif
        </div>
    </div>
@endif
