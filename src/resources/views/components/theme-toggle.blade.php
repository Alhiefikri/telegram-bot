@props([
    'showLabel' => false,
    'iconClass' => 'h-4 w-4',
])

<button
    type="button"
    data-theme-toggle
    aria-label="Aktifkan mode gelap"
    aria-pressed="false"
    {{ $attributes->class(['theme-toggle inline-flex min-h-11 items-center justify-center gap-2']) }}
>
    <svg data-theme-icon="light" class="{{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <circle cx="12" cy="12" r="4" />
        <path stroke-linecap="round" d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42" />
    </svg>
    <svg data-theme-icon="dark" class="{{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M20.7 15.3A8.5 8.5 0 0 1 8.7 3.3 8.5 8.5 0 1 0 20.7 15.3Z" />
    </svg>
    <span @class(['sr-only' => ! $showLabel]) data-theme-label>Mode gelap</span>
</button>
