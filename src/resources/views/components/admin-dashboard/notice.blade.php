@props([
    'tone' => 'info',
])

<div
    {{ $attributes->class([
        'rounded-lg border border-admin-line p-3 text-sm font-medium',
        'bg-neo-success-soft text-neo-success-text' => $tone === 'success',
        'bg-neo-warning-soft text-neo-warning-text' => $tone === 'warning',
        'bg-neo-danger-pale text-neo-danger-text' => $tone === 'danger',
        'bg-admin-surface text-admin-muted' => $tone === 'neutral',
        'bg-admin-accent-soft text-admin-ink' => ! in_array($tone, ['success', 'warning', 'danger', 'neutral'], true),
    ]) }}
>
    {{ $slot }}
</div>
