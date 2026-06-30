@props([
    'tone' => 'slate',
    'dot' => false,
])

<span
    {{ $attributes->class([
        'inline-flex items-center gap-1.5 rounded-full border-[3px] border-neo-ink px-2.5 py-0.5 text-[10px] font-black uppercase leading-5 shadow-[2px_2px_0_0_#050505]',
        'bg-[#7BD10A] text-neo-ink' => $tone === 'emerald',
        'bg-neo-yellow text-neo-ink' => $tone === 'amber',
        'bg-[#16B4CA] text-white' => $tone === 'blue',
        'bg-neo-red text-white' => $tone === 'rose',
        'bg-white text-neo-ink' => ! in_array($tone, ['emerald', 'amber', 'blue', 'rose'], true),
    ]) }}
>
    @if ($dot)
        <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
    @endif

    {{ $slot }}
</span>
