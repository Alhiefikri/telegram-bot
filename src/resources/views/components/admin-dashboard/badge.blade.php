@props([
    'tone' => 'slate',
    'dot' => false,
])

<span
    {{ $attributes->class([
        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold ring-1',
        'bg-emerald-50 text-emerald-700 ring-emerald-100' => $tone === 'emerald',
        'bg-amber-50 text-amber-700 ring-amber-100' => $tone === 'amber',
        'bg-blue-50 text-blue-700 ring-blue-100' => $tone === 'blue',
        'bg-rose-50 text-rose-700 ring-rose-100' => $tone === 'rose',
        'bg-slate-100 text-slate-700 ring-slate-200' => ! in_array($tone, ['emerald', 'amber', 'blue', 'rose'], true),
    ]) }}
>
    @if ($dot)
        <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
    @endif

    {{ $slot }}
</span>
