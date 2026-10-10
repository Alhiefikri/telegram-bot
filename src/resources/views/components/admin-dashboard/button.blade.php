@props([
    'href' => null,
    'type' => 'button',
    'variant' => 'primary',
    'size' => 'md',
])

@php
    // `hover:*` colors live on each variant: a shared hover fill cannot serve
    // accent, neutral, semantic and ink fills with a readable foreground.
    $baseClasses = 'admin-dashboard-action inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-admin-line px-4 py-2 text-center text-sm font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-admin-focus focus:ring-offset-2 focus:ring-offset-admin-paper';
    $sizeClasses = match ($size) {
        'sm' => 'min-h-11 px-3 py-2 text-sm',
        'icon' => 'h-11 min-h-11 w-11 px-0 py-0 text-sm',
        default => 'min-h-11 px-4 py-2 text-sm',
    };
    $variantClasses = match ($variant) {
        'secondary', 'cyan' => 'border-transparent bg-admin-accent text-admin-accent-ink hover:bg-admin-accent-hover',
        'neutral' => 'bg-admin-surface text-admin-ink hover:border-admin-accent hover:bg-admin-accent-soft',
        'warning' => 'border-amber-700 bg-amber-100 text-amber-950 hover:bg-amber-200',
        'destructive', 'rose' => 'border-red-700 bg-red-600 text-white hover:bg-red-700',
        'pink', 'purple' => 'border-transparent bg-admin-accent text-admin-accent-ink hover:bg-admin-accent-hover',
        'ink' => 'bg-admin-ink text-admin-paper hover:bg-admin-ink/90',
        default => 'border-transparent bg-admin-accent text-admin-accent-ink hover:bg-admin-accent-hover',
    };
@endphp

@if ($href)
    <a
        href="{{ $href }}"
        {{ $attributes->class([$baseClasses, $sizeClasses, $variantClasses]) }}
    >
        {{ $slot }}
    </a>
@else
    <button
        type="{{ $type }}"
        {{ $attributes->class([$baseClasses, $sizeClasses, $variantClasses]) }}
    >
        {{ $slot }}
    </button>
@endif
