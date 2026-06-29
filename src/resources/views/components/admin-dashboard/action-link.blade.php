@props([
    'href' => '#',
    'variant' => 'primary',
])

<a
    href="{{ $href }}"
    {{ $attributes->class([
        'inline-flex min-h-11 items-center justify-center gap-2 rounded-lg px-4 text-sm font-semibold transition focus:outline-none focus:ring-2 focus:ring-offset-2',
        'bg-brand-electric text-white shadow-sm shadow-blue-950/10 hover:bg-brand-electricDark focus:ring-brand-electric' => $variant === 'primary',
        'border border-slate-200 bg-white text-brand-navy hover:border-slate-300 hover:bg-slate-50 focus:ring-slate-300' => $variant !== 'primary',
    ]) }}
>
    {{ $slot }}
</a>
