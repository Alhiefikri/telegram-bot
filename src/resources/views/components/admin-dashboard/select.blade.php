@props([
    'name' => null,
])

<select
    @if ($name) name="{{ $name }}" @endif
    {{ $attributes->class([
        'min-h-12 w-full rounded-[1rem] border-[3px] border-neo-ink bg-white px-3 text-sm font-bold text-neo-ink shadow-[4px_4px_0_0_#050505] transition focus:outline-none focus:ring-2 focus:ring-[#8758de] focus:ring-offset-2 focus:ring-offset-neo-cream',
    ]) }}
>
    {{ $slot }}
</select>
