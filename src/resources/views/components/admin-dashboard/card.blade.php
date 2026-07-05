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
        'rounded-[1.25rem] border-[3px] border-neo-ink',
        'border-dashed' => $border === 'dashed',
        'bg-white text-neo-ink' => $tone === 'white',
        'bg-neo-purple-soft text-neo-ink' => $tone === 'purple',
        'bg-neo-warning-soft text-neo-ink' => $tone === 'yellow',
        'bg-neo-success-soft text-neo-ink' => $tone === 'green',
        'bg-neo-info-soft text-neo-ink' => $tone === 'cyan',
        'bg-neo-danger-soft text-neo-ink' => $tone === 'rose',
        'bg-[#f1f5f9] text-neo-ink' => $tone === 'slate',
        'shadow-neo-xl' => $shadow === 'md',
        'shadow-neo-md' => $shadow === 'sm',
        'shadow-neo-sm' => $shadow === 'xs',
        'shadow-none' => $shadow === 'none',
        'p-5 sm:p-6' => $padding === 'md',
        'p-4 sm:p-5' => $padding === 'compact',
        'p-3' => $padding === 'sm',
        'p-0' => $padding === 'none',
    ]) }}
>
    {{ $slot }}
</{{ $tag }}>
