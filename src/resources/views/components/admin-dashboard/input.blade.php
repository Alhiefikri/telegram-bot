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
        'min-h-11 w-full rounded-lg border border-admin-line bg-admin-surface px-3 text-sm font-normal text-admin-ink transition-colors placeholder:text-admin-muted focus:outline-none focus:ring-2 focus:ring-admin-focus focus:ring-offset-2 focus:ring-offset-admin-paper',
    ]) }}
>
