@props([
    'as' => 'div',
    'variant' => 'default',
    'tone' => 'surface',
    'padding' => 'md',
])

@php
    $tag = $as;
    $panelClass = $variant === 'tight' ? 'neo-panel-tight' : 'neo-panel';
    $toneClasses = match ($tone) {
        'warm' => 'bg-[var(--sf-surface-warm)] text-[var(--sf-ink)]',
        'accent' => 'bg-[var(--sf-accent)] text-white',
        'yellow' => 'bg-[var(--sf-yellow)] text-[var(--sf-ink)]',
        'green' => 'bg-[var(--sf-green)] text-[var(--sf-ink)]',
        'ink' => 'bg-[var(--sf-ink)] text-white',
        default => 'bg-[var(--sf-surface)] text-[var(--sf-ink)]',
    };
    $paddingClasses = match ($padding) {
        'none' => '',
        'sm' => 'p-3',
        'lg' => 'p-6 sm:p-8',
        default => 'p-5 sm:p-6',
    };
@endphp

<{{ $tag }} {{ $attributes->class([$panelClass, $toneClasses, $paddingClasses]) }}>
    {{ $slot }}
</{{ $tag }}>
