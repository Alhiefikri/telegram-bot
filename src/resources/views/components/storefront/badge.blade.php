@props([
    'tone' => 'yellow',
    'size' => 'sm',
    'shape' => 'pill',
])

@php
    $toneClasses = match ($tone) {
        'surface' => 'bg-[var(--sf-surface)] text-[var(--sf-ink)]',
        'warm' => 'bg-[var(--sf-surface-warm)] text-[var(--sf-ink)]',
        'accent' => 'bg-[var(--sf-accent)] text-white',
        'green', 'success' => 'bg-[var(--sf-green)] text-[var(--sf-ink)]',
        'blue', 'info' => 'bg-[var(--sf-blue)] text-white',
        'danger' => 'bg-[#c94856] text-white',
        default => 'bg-[var(--sf-yellow)] text-[var(--sf-ink)]',
    };
    $sizeClasses = $size === 'xs'
        ? 'px-2 py-0.5 text-[8px]'
        : 'px-3 py-1 text-[10px]';
    $shapeClasses = $shape === 'rounded' ? 'rounded-md' : 'rounded-full';
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1.5 border-2 border-[var(--sf-border)] font-black uppercase shadow-[3px_3px_0_var(--sf-shadow)]', $toneClasses, $sizeClasses, $shapeClasses]) }}>
    {{ $slot }}
</span>
