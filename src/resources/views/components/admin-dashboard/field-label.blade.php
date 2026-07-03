@props([
    'for' => null,
])

<label
    @if ($for) for="{{ $for }}" @endif
    {{ $attributes->class([
        'font-display text-[11px] font-extrabold uppercase tracking-wider text-neo-ink',
    ]) }}
>
    {{ $slot }}
</label>
