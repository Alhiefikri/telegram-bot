@props([
    'tone' => 'info',
])

@php
    $toneClasses = match ($tone) {
        'success' => 'bg-[var(--sf-green)] text-[var(--sf-ink)]',
        'warning' => 'bg-[var(--sf-yellow)] text-[var(--sf-ink)]',
        'danger' => 'bg-[#ffd9df] text-[var(--sf-ink)]',
        default => 'bg-[var(--sf-accent-soft)] text-[var(--sf-ink)]',
    };
@endphp

<div {{ $attributes->class(['neo-panel p-4 text-sm font-black leading-6', $toneClasses]) }}>
    {{ $slot }}
</div>
