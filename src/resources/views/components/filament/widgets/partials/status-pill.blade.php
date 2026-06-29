@props([
    'label',
    'tone' => 'slate',
])

@php
    $tones = [
        'blue' => 'border-slate-200 bg-slate-100 text-slate-700',
        'cyan' => 'border-stone-200 bg-stone-100 text-stone-700',
        'emerald' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
        'amber' => 'border-amber-200 bg-amber-50 text-amber-800',
        'rose' => 'border-rose-200 bg-rose-50 text-rose-800',
        'slate' => 'border-slate-200 bg-slate-100 text-slate-700',
    ];

    $classes = $tones[$tone] ?? $tones['slate'];
@endphp

<span {{ $attributes->class([
    'inline-flex max-w-full items-center rounded-full border px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.14em] sm:text-[11px] sm:tracking-[0.18em]',
    $classes,
]) }}>
    <span class="min-w-0 truncate">{{ $label }}</span>
</span>
