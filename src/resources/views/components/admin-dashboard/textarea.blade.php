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
        'min-h-28 w-full rounded-lg border border-slate-200 bg-white px-3 py-3 text-sm font-medium text-brand-navy shadow-sm shadow-slate-200/60 transition placeholder:text-slate-400 focus:border-brand-electric focus:outline-none focus:ring-2 focus:ring-brand-electric/20',
    ]) }}
>{{ $textareaValue }}</textarea>
