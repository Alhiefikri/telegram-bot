@props([
    'as' => 'div',
    'padding' => 'md',
    'tone' => 'white',
    'border' => 'solid',
    'shadow' => 'md',
])

@php
    $tag = $as;
@endphp

<{{ $tag }}
    {{ $attributes->class([
        'rounded-[var(--admin-radius)] border border-admin-line',
        'border-dashed' => $border === 'dashed',
        'bg-admin-surface text-admin-ink' => $tone === 'white',
        'bg-admin-accent-soft text-admin-ink' => $tone === 'purple',
        'bg-neo-warning-soft text-admin-ink' => $tone === 'yellow',
        'bg-neo-success-soft text-admin-ink' => $tone === 'green',
        'bg-neo-info-soft text-admin-ink' => $tone === 'cyan',
        'bg-neo-danger-soft text-admin-ink' => $tone === 'rose',
        'bg-admin-surface-muted text-admin-ink' => $tone === 'slate',
        'shadow-admin-sm' => $shadow === 'sm',
        'shadow-admin-lg' => $shadow === 'md',
        'shadow-none' => $shadow === 'none',
        'p-5 sm:p-6' => $padding === 'md',
        'p-4 sm:p-5' => $padding === 'compact',
        'p-3' => $padding === 'sm',
        'p-0' => $padding === 'none',
    ]) }}
>
    {{ $slot }}
</{{ $tag }}>
