@props([
    'label',
    'value',
    'hint' => null,
    'tone' => 'slate',
    'size' => 'md',
])

<div
    {{ $attributes->class([
        'min-w-0 rounded-xl border border-admin-line bg-admin-surface text-admin-ink',
        'p-3' => $size === 'compact',
        'p-3 md:p-4' => $size === 'md',
    ]) }}
>
    <p class="text-sm font-medium text-admin-muted">{{ $label }}</p>
    <p @class([
        'mt-1 break-words font-semibold leading-tight',
        'text-xl' => $size === 'compact',
        'text-lg sm:text-2xl' => $size === 'md',
    ])>{{ $value }}</p>

    @if ($hint)
        <p class="mt-1 text-xs font-normal text-admin-muted">{{ $hint }}</p>
    @endif
</div>
