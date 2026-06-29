@props([
    'as' => 'div',
    'padding' => 'md',
])

@php
    $tag = $as;
@endphp

<{{ $tag }}
    {{ $attributes->class([
        'rounded-lg border border-slate-200 bg-white shadow-sm shadow-slate-200/70',
        'p-5' => $padding === 'md',
        'p-4 sm:p-5' => $padding === 'compact',
        'p-0' => $padding === 'none',
    ]) }}
>
    {{ $slot }}
</{{ $tag }}>
