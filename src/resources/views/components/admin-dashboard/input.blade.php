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
        'min-h-12 w-full rounded-neo-2xl border-[3px] border-neo-ink bg-white px-3 text-sm font-bold text-neo-ink shadow-neo-md transition placeholder:font-semibold placeholder:text-neo-ink/35 focus:outline-none focus:ring-2 focus:ring-neo-focus focus:ring-offset-2 focus:ring-offset-neo-cream',
    ]) }}
>
