@props([
    'label',
    'value',
    'hint' => null,
    'tone' => 'slate',
    'size' => 'md',
])

<div
    {{ $attributes->class([
        'min-w-0 rounded-[1.1rem] border-[3px] border-neo-ink shadow-[5px_5px_0_0_#050505]',
        'p-3' => $size === 'compact',
        'p-3 md:p-4' => $size === 'md',
        'bg-[#e8ffd4] text-neo-ink' => $tone === 'emerald',
        'bg-[#d4f6fb] text-neo-ink' => $tone === 'blue',
        'bg-[#fef9c3] text-neo-ink' => $tone === 'amber',
        'bg-[#fce4f0] text-neo-ink' => $tone === 'rose',
        'bg-[#ede5fa] text-neo-ink' => ! in_array($tone, ['emerald', 'blue', 'amber', 'rose'], true),
    ]) }}
>
    <p class="text-[10px] font-black uppercase tracking-[0.18em]">{{ $label }}</p>
    <p @class([
        'mt-1 break-words font-sans font-black leading-none',
        'text-xl' => $size === 'compact',
        'text-lg sm:text-2xl' => $size === 'md',
    ])>{{ $value }}</p>

    @if ($hint)
        <p class="mt-1 text-[10px] font-bold opacity-70">{{ $hint }}</p>
    @endif
</div>
