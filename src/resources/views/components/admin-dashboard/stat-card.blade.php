@props([
    'label',
    'value',
    'hint' => null,
    'tone' => 'slate',
])

<div
    {{ $attributes->class([
        'rounded-lg border bg-white p-4 shadow-sm shadow-slate-200/70',
        'border-emerald-100' => $tone === 'emerald',
        'border-blue-100' => $tone === 'blue',
        'border-amber-100' => $tone === 'amber',
        'border-slate-200' => ! in_array($tone, ['emerald', 'blue', 'amber'], true),
    ]) }}
>
    <p class="text-[11px] font-bold uppercase tracking-wide text-slate-500">{{ $label }}</p>
    <p class="mt-2 break-words font-display text-2xl font-bold text-brand-navy">{{ $value }}</p>

    @if ($hint)
        <p class="mt-1 text-xs font-medium text-slate-500">{{ $hint }}</p>
    @endif
</div>
