@props([
    'label',
    'value',
    'tone' => 'slate',
    'align' => 'center',
])

<div
    {{ $attributes->class([
        'rounded-lg border p-2',
        'text-center' => $align === 'center',
        'text-right' => $align === 'right',
        'text-left' => $align === 'left',
        'border-[#e2e8f0] bg-[#f1f5f9]' => $tone === 'slate',
        'border-[#16b4ca] bg-[#d4f6fb]' => $tone === 'cyan',
        'border-[#f2da3d] bg-[#fef9c3]' => $tone === 'amber',
        'border-[#7BD10A] bg-[#e8ffd4]' => $tone === 'emerald',
        'border-[#e2e8f0] bg-white' => ! in_array($tone, ['slate', 'cyan', 'amber', 'emerald'], true),
    ]) }}
>
    <p @class([
        'text-[9px] font-bold uppercase',
        'text-[#64748b]' => $tone === 'slate',
        'text-[#0e7490]' => $tone === 'cyan',
        'text-[#92400e]' => $tone === 'amber',
        'text-[#166534]' => $tone === 'emerald',
    ])>{{ $label }}</p>
    <p class="mt-0.5 break-words font-display text-sm font-bold text-neo-ink">
        @if ($slot->isNotEmpty())
            {{ $slot }}
        @else
            {{ $value }}
        @endif
    </p>
</div>
