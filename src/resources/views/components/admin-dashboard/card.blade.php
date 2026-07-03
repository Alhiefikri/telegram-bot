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
        'bg-[#ede5fa] text-neo-ink' => $tone === 'purple',
        'bg-[#fef9c3] text-neo-ink' => $tone === 'yellow',
        'bg-[#e8ffd4] text-neo-ink' => $tone === 'green',
        'bg-[#d4f6fb] text-neo-ink' => $tone === 'cyan',
        'bg-[#fecaca] text-neo-ink' => $tone === 'rose',
        'bg-[#f1f5f9] text-neo-ink' => $tone === 'slate',
        'shadow-[6px_6px_0_0_#050505]' => $shadow === 'md',
        'shadow-[4px_4px_0_0_#050505]' => $shadow === 'sm',
        'shadow-[3px_3px_0_0_#050505]' => $shadow === 'xs',
        'shadow-none' => $shadow === 'none',
        'p-5 sm:p-6' => $padding === 'md',
        'p-4 sm:p-5' => $padding === 'compact',
        'p-3' => $padding === 'sm',
        'p-0' => $padding === 'none',
    ]) }}
>
    {{ $slot }}
</{{ $tag }}>
