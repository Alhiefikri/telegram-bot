@props([
    'name' => null,
    'type' => 'text',
    'value' => null,
])

<input
    type="{{ $type }}"
    @if ($name) name="{{ $name }}" @endif
    @if (! is_null($value)) value="{{ $value }}" @endif
    {{ $attributes->class([
        'min-h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-medium text-brand-navy shadow-sm shadow-slate-200/60 transition placeholder:text-slate-400 focus:border-brand-electric focus:outline-none focus:ring-2 focus:ring-brand-electric/20',
    ]) }}
>
