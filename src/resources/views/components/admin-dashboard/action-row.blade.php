@props([
    'href' => '#',
])

<a
    href="{{ $href }}"
    {{ $attributes->class([
        'flex min-h-11 items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-brand-navy transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-300 focus:ring-offset-2',
    ]) }}
>
    <span>{{ $slot }}</span>
    <span aria-hidden="true">-&gt;</span>
</a>
