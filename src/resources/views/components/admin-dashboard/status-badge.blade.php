@props([
    'tone' => 'slate',
])

<span
    {{ $attributes->class([
        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold',
        'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100' => $tone === 'emerald',
        'bg-amber-50 text-amber-700 ring-1 ring-amber-100' => $tone === 'amber',
        'bg-slate-100 text-slate-700 ring-1 ring-slate-200' => ! in_array($tone, ['emerald', 'amber'], true),
    ]) }}
>
    <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
    {{ $slot }}
</span>
