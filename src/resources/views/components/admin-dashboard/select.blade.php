@props([
    'name' => null,
])

<select
    @if ($name) name="{{ $name }}" @endif
    {{ $attributes->class([
        'min-h-11 w-full rounded-lg border border-admin-line bg-admin-surface px-3 text-sm font-normal text-admin-ink transition-colors focus:outline-none focus:ring-2 focus:ring-admin-focus focus:ring-offset-2 focus:ring-offset-admin-paper',
    ]) }}
>
    {{ $slot }}
</select>
