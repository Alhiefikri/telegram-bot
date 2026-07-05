@props([
    'tone' => 'info',
])

<div
    {{ $attributes->class([
        'rounded-neo-xl border-[3px] border-neo-ink p-3 text-sm font-bold shadow-neo-sm',
        'bg-neo-success-soft text-neo-ink' => $tone === 'success',
        'bg-neo-warning-soft text-neo-ink' => $tone === 'warning',
        'bg-neo-danger-pale text-neo-danger-text' => $tone === 'danger',
        'bg-white text-neo-ink/70' => $tone === 'neutral',
        'bg-neo-purple-soft text-neo-ink' => ! in_array($tone, ['success', 'warning', 'danger', 'neutral'], true),
    ]) }}
>
    {{ $slot }}
</div>
