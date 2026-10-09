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
        'min-h-28 w-full rounded-lg border border-admin-line bg-admin-surface px-3 py-3 text-sm font-normal text-admin-ink transition-colors placeholder:text-admin-muted focus:outline-none focus:ring-2 focus:ring-admin-focus focus:ring-offset-2 focus:ring-offset-admin-paper',
    ]) }}
>{{ $textareaValue }}</textarea>
