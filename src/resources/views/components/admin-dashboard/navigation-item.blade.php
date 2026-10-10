@props([
    'href',
    'label',
    'icon' => 'home',
    'active' => false,
    'current' => 'page',
    'badge' => null,
])

@php
    // Only internal path data is accepted; labels and attributes remain escaped.
    $paths = [
        'home' => 'M3 10 12 3l9 7v11h-6v-7H9v7H3Z',
        'package' => 'm12 3 9 5v9l-9 5-9-5V8Zm0 9 9-4M12 12 3 8m9 4v10M7 5.8l9 5',
        'receipt' => 'M4 3v18l4-2 4 2 4-2 4 2V3l-4 2-4-2-4 2ZM8 9h8M8 13h8',
        'truck' => 'M3 6h11v10H3ZM14 9h4l3 3v4h-7M5 18a2 2 0 1 0 4 0 2 2 0 0 0-4 0m11 0a2 2 0 1 0 4 0 2 2 0 0 0-4 0',
        'megaphone' => 'm3 10 18-5v14L3 14Zm1 4v6h4l2-4',
        'message' => 'M21 11a9 9 0 0 1-9 9H8l-5 2 2-5a9 9 0 1 1 16-6Z',
        'history' => 'M3 12a9 9 0 1 0 3-6.7M3 4v5h5M12 7v5l3 2',
        'wallet' => 'M4 5h16v15H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h13M20 11h-5v5h5',
        'chart' => 'M4 3v17h17M7 15l3-4 3 2 5-7',
        'alert' => 'M12 3 2 21h20ZM12 9v5m0 3v1',
        'report' => 'M4 21V3h16v18ZM8 7h8M8 11h8M8 15h5',
        'adjustment' => 'M4 21v-7m0-4V3m8 18v-9m0-4V3m8 18v-5m0-4V3M1 14h6M9 8h6m2 8h6',
        'shield' => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Zm-3-10 2 2 4-4',
        'settings' => 'M9 3h6l1 3 3 1 2 5-2 5-3 1-1 3H9l-1-3-3-1-2-5 2-5 3-1Zm3 6a3 3 0 1 0 0 6 3 3 0 0 0 0-6',
    ];
@endphp

<a href="{{ $href }}" {{ $attributes->class([
    'flex min-h-11 items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-admin-focus',
    'bg-admin-accent-soft text-admin-accent-strong' => $active,
    'text-admin-muted hover:bg-admin-surface-muted hover:text-admin-ink' => ! $active,
])->merge($active ? ['aria-current' => $current] : []) }}>
    <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5 shrink-0">
        <path d="{{ $paths[$icon] ?? $paths['home'] }}" />
    </svg>
    <span class="min-w-0 flex-1 break-words">{{ $label }}</span>
    @if ($badge !== null && $badge !== '')
        <x-admin-dashboard.badge tone="purple" class="shrink-0">{{ $badge }}</x-admin-dashboard.badge>
    @endif
    {{ $slot }}
</a>
