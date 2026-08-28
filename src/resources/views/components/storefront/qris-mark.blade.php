@props([
    'size' => 'md',
])

@php
    $sizeClasses = match ($size) {
        'lg' => [
            'wrapper' => 'h-16 w-16 gap-0.5 rounded-xl p-2',
            'icon' => 'h-9 w-9',
            'label' => 'text-[9px]',
        ],
        'xs' => [
            'wrapper' => 'h-8 w-8 gap-0 rounded-md p-1',
            'icon' => 'h-4 w-4',
            'label' => 'text-[6px]',
        ],
        default => [
            'wrapper' => 'h-12 w-12 gap-0.5 rounded-lg p-1.5',
            'icon' => 'h-7 w-7',
            'label' => 'text-[7px]',
        ],
    };
@endphp

<span data-storefront-qris-mark aria-hidden="true" {{ $attributes->class(['inline-flex shrink-0 flex-col items-center justify-center border-2 border-[var(--sf-border)] bg-white text-[var(--sf-accent)] shadow-[3px_3px_0_var(--sf-shadow)]', $sizeClasses['wrapper']]) }}>
    <svg viewBox="0 0 48 48" fill="none" class="{{ $sizeClasses['icon'] }}" aria-hidden="true">
        <path d="M3 3h13v13H3zM7 7h5v5H7zM32 3h13v13H32zM36 7h5v5H36zM3 32h13v13H3zM7 36h5v5H7z" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/>
        <path d="M22 3h5v5h-5zM22 12h4v4h-4zM28 18h6v6h-6zM38 19h6v6h-6zM20 22h5v5h-5zM22 31h7v7h-7zM32 29h4v4h-4zM39 31h6v6h-6zM32 39h5v5h-5zM40 41h5v4h-5z" fill="currentColor"/>
    </svg>
    <span class="font-display font-black leading-none tracking-[0.08em] {{ $sizeClasses['label'] }}">QRIS</span>
</span>
