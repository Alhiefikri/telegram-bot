@props([
    'name' => null,
    'rows' => 4,
    'value' => null,
])

@php
    $textareaValue = trim((string) $slot) !== '' ? $slot : $value;
@endphp

<textarea
    @if ($name) name="{{ $name }}" @endif
    rows="{{ $rows }}"
    {{ $attributes->class([
        'min-h-28 w-full rounded-[1rem] border-[3px] border-neo-ink bg-white px-3 py-3 text-sm font-bold text-neo-ink shadow-[4px_4px_0_0_#050505] transition placeholder:font-semibold placeholder:text-neo-ink/35 focus:outline-none focus:ring-2 focus:ring-[#8758de] focus:ring-offset-2 focus:ring-offset-neo-cream',
    ]) }}
>{{ $textareaValue }}</textarea>
