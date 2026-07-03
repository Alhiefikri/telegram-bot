@props([
    'tone' => 'info',
])

<div
    {{ $attributes->class([
        'rounded-[0.8rem] border-[3px] border-neo-ink p-3 text-sm font-bold shadow-[3px_3px_0_0_#050505]',
        'bg-[#e8ffd4] text-neo-ink' => $tone === 'success',
        'bg-[#fef9c3] text-neo-ink' => $tone === 'warning',
        'bg-[#fee2e2] text-[#991b1b]' => $tone === 'danger',
        'bg-white text-neo-ink/70' => $tone === 'neutral',
        'bg-[#ede5fa] text-neo-ink' => ! in_array($tone, ['success', 'warning', 'danger', 'neutral'], true),
    ]) }}
>
    {{ $slot }}
</div>
