@props([
    'tone' => 'slate',
    'dot' => false,
    'background' => null,
    'color' => null,
    'dotColor' => null,
])

@php
    $style = collect([
        $background ? 'background: '.$background : null,
        $color ? 'color: '.$color : null,
    ])->filter()->implode('; ');
@endphp

<span
    {{ $attributes
        ->class([
        'inline-flex items-center gap-1.5 rounded-full border border-admin-line px-2.5 py-0.5 text-xs font-medium leading-5',
        'bg-neo-success-soft text-neo-success-text' => ! $background && $tone === 'emerald',
        'bg-neo-warning-soft text-neo-warning-text' => ! $background && $tone === 'amber',
        'bg-neo-info-soft text-neo-info-text' => ! $background && $tone === 'blue',
        'bg-neo-danger-soft text-neo-danger-strong' => ! $background && $tone === 'rose',
        'bg-admin-accent-soft text-admin-accent-strong' => ! $background && $tone === 'purple',
        'bg-admin-surface-muted text-admin-muted' => ! $background && ! in_array($tone, ['emerald', 'amber', 'blue', 'rose', 'purple'], true),
    ])
        ->merge($style ? ['style' => $style.';'] : []) }}
>
    @if ($dot)
        <span
            class="h-1.5 w-1.5 rounded-full bg-current"
            @if ($dotColor) style="background: {{ $dotColor }};" @endif
        ></span>
    @endif

    {{ $slot }}
</span>
