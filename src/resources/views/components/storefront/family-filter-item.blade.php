@props(['href', 'label', 'selected' => false, 'image' => null, 'icon' => null, 'all' => false])

<li class="min-w-0 shrink-0">
    <a href="{{ $href }}" data-catalog-link @if ($selected) aria-current="true" @endif class="home-family-link catalog-family-button flex min-h-13 w-full items-center justify-between gap-2 rounded-xl px-2.5 py-2 xl:min-h-16 xl:px-3">
        <span class="flex min-w-0 items-center gap-2.5 xl:gap-3">
            <span aria-hidden="true" class="catalog-family-icon grid size-8 shrink-0 place-items-center rounded-lg bg-[var(--sf-surface-warm)] text-lg xl:size-10 xl:text-2xl">
                @if ($all)
                    <svg class="size-5 text-[var(--sf-accent)] xl:size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <rect x="3.5" y="3.5" width="7" height="7" rx="1.5" />
                        <rect x="13.5" y="3.5" width="7" height="7" rx="1.5" />
                        <rect x="3.5" y="13.5" width="7" height="7" rx="1.5" />
                        <rect x="13.5" y="13.5" width="7" height="7" rx="1.5" />
                    </svg>
                @elseif ($image)
                    <img src="{{ $image }}" alt="" width="40" height="40" class="size-full rounded-lg object-contain">
                @else
                    {{ $icon ?: '🛍️' }}
                @endif
            </span>
            <span class="wrap-anywhere leading-snug">{{ $label }}</span>
        </span>
        <span data-catalog-check aria-hidden="true" class="shrink-0 text-[var(--sf-accent)]">✓</span>
    </a>
</li>
