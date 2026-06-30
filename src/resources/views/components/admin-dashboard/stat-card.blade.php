@props([
    'label',
    'value',
    'hint' => null,
    'tone' => 'slate',
])

<div
    {{ $attributes->class([
        'min-w-0 rounded-[1.1rem] border-[3px] border-neo-ink p-3 shadow-[5px_5px_0_0_#050505]',
        'bg-[#7BD10A] text-neo-ink' => $tone === 'emerald',
        'bg-[#16B4CA] text-white' => $tone === 'blue',
        'bg-[#f2da3d] text-neo-ink' => $tone === 'amber',
        'bg-[#8758de] text-white' => ! in_array($tone, ['emerald', 'blue', 'amber'], true),
    ]) }}
>
    <p class="text-[10px] font-black uppercase tracking-[0.18em]">{{ $label }}</p>
    <p class="mt-1 break-words font-sans text-[1.05rem] font-black leading-none sm:text-2xl">{{ $value }}</p>

    @if ($hint)
        <p class="mt-1 text-[11px] font-bold opacity-80">{{ $hint }}</p>
    @endif
</div>
