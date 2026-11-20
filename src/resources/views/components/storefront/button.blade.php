@props([
    'href' => null,
    'type' => 'button',
    'variant' => 'primary',
    'size' => 'md',
    'external' => false,
])

@php
    $baseClasses = 'neo-button storefront-action inline-flex items-center justify-center gap-2 text-center font-bold';
    $sizeClasses = match ($size) {
        'sm' => 'h-10 px-3 text-[11px]',
        'compact' => 'min-h-11 px-3.5 py-2 text-sm',
        'lg' => 'h-14 px-7 text-sm',
        default => 'h-12 px-5 text-sm',
    };
    $variantClasses = match ($variant) {
        'secondary', 'surface' => 'bg-[var(--sf-surface)] text-[var(--sf-ink)] hover:bg-[var(--sf-surface-warm)]',
        'outline' => 'bg-transparent text-[var(--sf-accent)] hover:bg-[var(--sf-accent-soft)]',
        'yellow', 'warning' => 'bg-[var(--sf-yellow)] text-[var(--sf-ink)]',
        'green', 'success' => 'bg-[var(--sf-green)] text-[var(--sf-ink)]',
        'blue', 'info' => 'bg-[var(--sf-blue)] text-white',
        'danger' => 'bg-[#c94856] text-white',
        'ghost' => 'bg-transparent text-[var(--sf-ink)] shadow-none hover:bg-[var(--sf-surface)]',
        default => 'bg-[var(--sf-accent)] text-white hover:bg-[var(--sf-accent-deep)]',
    };
@endphp

@if ($href)
    <a
        href="{{ $href }}"
        data-button-variant="{{ $variant }}"
        @if ($external) target="_blank" rel="noopener noreferrer" @endif
        {{ $attributes->class([$baseClasses, $sizeClasses, $variantClasses]) }}
    >
        {{ $slot }}
    </a>
@else
    <button
        type="{{ $type }}"
        data-button-variant="{{ $variant }}"
        {{ $attributes->class([$baseClasses, $sizeClasses, $variantClasses]) }}
    >
        {{ $slot }}
    </button>
@endif
