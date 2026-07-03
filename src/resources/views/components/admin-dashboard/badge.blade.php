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
        'inline-flex items-center gap-1.5 rounded-full border-[3px] border-neo-ink px-2.5 py-0.5 text-[10px] font-black uppercase leading-5 shadow-[2px_2px_0_0_#050505]',
        'bg-[#e8ffd4] text-[#166534]' => ! $background && $tone === 'emerald',
        'bg-[#fef9c3] text-[#92400e]' => ! $background && $tone === 'amber',
        'bg-[#d4f6fb] text-[#0e7490]' => ! $background && $tone === 'blue',
        'bg-[#fecaca] text-[#dc2626]' => ! $background && $tone === 'rose',
        'bg-[#ede5fa] text-[#8758de]' => ! $background && $tone === 'purple',
        'bg-[#f1f5f9] text-[#64748b]' => ! $background && ! in_array($tone, ['emerald', 'amber', 'blue', 'rose', 'purple'], true),
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
