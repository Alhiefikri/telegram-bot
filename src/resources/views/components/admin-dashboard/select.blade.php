@props([
    'name' => null,
])

<select
    @if ($name) name="{{ $name }}" @endif
    {{ $attributes->class([
        'min-h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-brand-navy shadow-sm shadow-slate-200/60 transition focus:border-brand-electric focus:outline-none focus:ring-2 focus:ring-brand-electric/20',
    ]) }}
>
    {{ $slot }}
</select>
