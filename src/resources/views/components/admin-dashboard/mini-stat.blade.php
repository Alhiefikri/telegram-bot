@props([
    'label',
    'value',
    'tone' => 'slate',
    'align' => 'center',
])

<div
    {{ $attributes->class([
        'rounded-lg border border-admin-line bg-admin-surface-muted p-2 text-admin-ink',
        'text-center' => $align === 'center',
        'text-right' => $align === 'right',
        'text-left' => $align === 'left',
    ]) }}
>
    <p @class([
        'text-xs font-medium text-admin-muted',
    ])>{{ $label }}</p>
    <p class="mt-0.5 break-words text-sm font-semibold text-admin-ink">
        @if ($slot->isNotEmpty())
            {{ $slot }}
        @else
            {{ $value }}
        @endif
    </p>
</div>
