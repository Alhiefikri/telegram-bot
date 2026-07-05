@props([
    'href' => null,
    'type' => 'button',
    'variant' => 'primary',
    'size' => 'md',
    'external' => false,
])

@php
    $baseClasses = 'neo-button inline-flex items-center justify-center gap-2 font-black transition-colors';
    $sizeClasses = match ($size) {
        'sm' => 'h-10 px-3 text-[11px]',
        'lg' => 'h-14 px-7 text-sm',
        default => 'h-12 px-5 text-sm',
    };
    $variantClasses = match ($variant) {
        'secondary', 'surface' => 'bg-[var(--sf-surface)] text-[var(--sf-ink)] hover:bg-[var(--sf-surface-warm)]',
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
        @if ($external) target="_blank" rel="noopener noreferrer" @endif
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
