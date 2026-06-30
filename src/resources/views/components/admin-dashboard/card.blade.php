@props([
    'as' => 'div',
    'padding' => 'md',
])

@php
    $tag = $as;
@endphp

<{{ $tag }}
    {{ $attributes->class([
        'rounded-[1.25rem] border-[3px] border-neo-ink bg-white shadow-[6px_6px_0_0_#050505]',
        'p-5 sm:p-6' => $padding === 'md',
        'p-4 sm:p-5' => $padding === 'compact',
        'p-0' => $padding === 'none',
    ]) }}
>
    {{ $slot }}
</{{ $tag }}>
