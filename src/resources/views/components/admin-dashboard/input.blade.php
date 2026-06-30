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
        'min-h-12 w-full rounded-[1rem] border-[3px] border-neo-ink bg-white px-3 text-sm font-bold text-neo-ink shadow-[4px_4px_0_0_#050505] transition placeholder:font-semibold placeholder:text-neo-ink/35 focus:outline-none focus:ring-2 focus:ring-[#8758de] focus:ring-offset-2 focus:ring-offset-neo-cream',
    ]) }}
>
