@props([
    'name' => null,
])

<select
    @if ($name) name="{{ $name }}" @endif
    {{ $attributes->class([
        'min-h-12 w-full rounded-neo-2xl border-[3px] border-neo-ink bg-white px-3 text-sm font-bold text-neo-ink shadow-neo-md transition focus:outline-none focus:ring-2 focus:ring-neo-focus focus:ring-offset-2 focus:ring-offset-neo-cream',
    ]) }}
>
    {{ $slot }}
</select>
