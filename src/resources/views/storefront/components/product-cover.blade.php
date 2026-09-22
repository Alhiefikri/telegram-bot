@php
    $context = $context ?? 'card';
    $presentation = $presentation ?? $storefront->productPresentation($product);
    $coverTheme = $presentation->cover->theme;
    $imageUrl = $presentation->cover->imageUrl;
    $coverIcon = trim((string) ($presentation->cover->icon ?? ''));
    $isAvailable = $isAvailable ?? $presentation->isAvailable;
    $isPopular = $isPopular ?? false;
    $priority = $priority ?? false;
    $imageLoading = ($priority || $context === 'featured') ? 'eager' : 'lazy';
    $fetchPriority = $imageLoading === 'eager' ? 'high' : 'auto';
@endphp

@if ($context === 'featured')
    <div class="storefront-media-frame relative mx-auto flex aspect-square w-full flex-col items-center justify-center overflow-hidden rounded-lg border border-[var(--sf-border)] {{ $imageUrl ? 'bg-white p-0' : $coverTheme['bg'].' p-6' }} text-center shadow-[0_12px_30px_rgb(18_35_63_/_0.12)]" data-media-frame>
        <div class="pointer-events-none absolute inset-0 bg-grid-pattern opacity-10" aria-hidden="true"></div>

        @if ($imageUrl)
            <img src="{{ $imageUrl }}" alt="{{ $presentation->name }}" width="800" height="800" loading="{{ $imageLoading }}" fetchpriority="{{ $fetchPriority }}" decoding="async" class="absolute inset-0 h-full w-full object-cover object-center">
        @else
            <div class="pointer-events-none absolute h-4/5 w-4/5 rounded-full border border-white/20" aria-hidden="true"></div>
            <div class="relative z-10 flex max-w-full flex-col items-center justify-center gap-5 text-center">
                @if ($coverIcon !== '')
                    <div class="flex h-24 w-24 -rotate-6 items-center justify-center rounded-2xl bg-white text-6xl leading-none text-[var(--sf-accent)] shadow-[6px_6px_0_var(--sf-yellow)] sm:h-32 sm:w-32 sm:text-7xl" aria-hidden="true">{{ $coverIcon }}</div>
                @else
                    <div class="flex h-24 w-24 -rotate-6 items-center justify-center rounded-2xl bg-white text-[var(--sf-accent)] shadow-[6px_6px_0_var(--sf-yellow)] [&>svg]:h-14 [&>svg]:w-14 sm:h-32 sm:w-32 sm:[&>svg]:h-20 sm:[&>svg]:w-20" aria-hidden="true">
                        {!! $coverTheme['logo'] ?? '<svg class="w-12 h-12" viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="18" height="18" rx="4"/></svg>' !!}
                    </div>
                @endif
                <div class="max-w-full rounded-full bg-[var(--sf-yellow)] px-3 py-1.5 text-[10px] font-black uppercase tracking-wider text-[var(--sf-ink)]">{{ $coverTheme['desc'] }}</div>
            </div>
        @endif

        @if (!$imageUrl)
            <span class="absolute left-3 top-3 z-10 max-w-[calc(100%-1.5rem)] rounded-md {{ $coverTheme['badge_bg'] }} px-2 py-1 text-[9px] font-black uppercase tracking-wider">{{ $coverTheme['badge'] }}</span>
        @endif
        <span class="sr-only">Gambar produk</span>
    </div>
@else
    <div class="storefront-media-frame relative flex aspect-square w-full flex-col items-center justify-center overflow-hidden {{ $imageUrl ? 'bg-white' : $coverTheme['bg'] }}" data-media-frame>
        <div class="pointer-events-none absolute inset-0 bg-grid-pattern opacity-10" aria-hidden="true"></div>

        @if ($imageUrl)
            <img src="{{ $imageUrl }}" alt="{{ $presentation->name }}" width="400" height="400" loading="{{ $imageLoading }}" fetchpriority="{{ $fetchPriority }}" decoding="async" class="absolute inset-0 h-full w-full object-cover object-center transition-transform duration-700 ease-out group-hover:scale-[1.07]">
        @else
            <div class="pointer-events-none absolute h-4/5 w-4/5 rounded-full border border-white/20" aria-hidden="true"></div>
            <div class="relative z-10 flex h-full w-full max-w-full flex-col items-center justify-center gap-2 px-3 text-center sm:gap-3 {{ $isAvailable ? 'pb-3 pt-10' : 'pb-12 pt-3' }}">
                @if ($coverIcon !== '')
                    <div class="flex h-14 w-14 -rotate-6 items-center justify-center rounded-xl bg-white text-4xl leading-none text-[var(--sf-accent)] shadow-[4px_4px_0_var(--sf-yellow)] transition-transform duration-300 group-hover:rotate-0 sm:h-20 sm:w-20 sm:text-5xl" aria-hidden="true">{{ $coverIcon }}</div>
                @else
                    <div class="flex h-14 w-14 -rotate-6 items-center justify-center rounded-xl bg-white text-[var(--sf-accent)] shadow-[4px_4px_0_var(--sf-yellow)] transition-transform duration-300 group-hover:rotate-0 [&>svg]:h-9 [&>svg]:w-9 sm:h-20 sm:w-20 sm:[&>svg]:h-12 sm:[&>svg]:w-12" aria-hidden="true">
                        {!! $coverTheme['logo'] !!}
                    </div>
                @endif
                <div class="max-w-full rounded-full bg-[var(--sf-yellow)] px-2 py-1 text-[8px] font-black uppercase leading-3 tracking-wider text-[var(--sf-ink)] sm:text-[9px]">{{ $coverTheme['desc'] }}</div>
            </div>
            <span class="sr-only">Gambar default {{ $presentation->name }}</span>
        @endif

        <div class="absolute left-2.5 right-2.5 top-2.5 z-10 flex items-start justify-between gap-1.5">
            @if (!$imageUrl && $isAvailable)
                <span class="min-w-0 rounded-md px-2 py-1 text-[8px] font-black uppercase leading-3 tracking-wider {{ $coverTheme['badge_bg'] }}">
                    {{ $coverTheme['badge'] }}
                </span>
            @endif

            @if($isPopular)
                <span class="ml-auto rounded-md bg-[var(--sf-accent)] px-2 py-1 text-[8px] font-black uppercase leading-3 tracking-wider text-white">
                    POPULER
                </span>
            @endif
        </div>

        @if (!$isAvailable)
            <div class="absolute inset-x-0 bottom-0 z-20 border-t border-[var(--sf-border)] bg-[var(--sf-surface)] px-2 py-1.5 text-center">
                <span class="block text-[9px] font-black uppercase leading-3 tracking-wider text-[#b42335]">{{ $presentation->cover->emptyStampTitle }}</span>
                <small class="block text-[8px] font-semibold leading-3 text-[var(--sf-muted)]">{{ $presentation->cover->emptyStampSubtitle }}</small>
            </div>
        @endif
    </div>
@endif
