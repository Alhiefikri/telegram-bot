@props([
    'href' => '#',
])

<a
    href="{{ $href }}"
    {{ $attributes->class([
        'flex min-h-11 items-center justify-between gap-3 rounded-[1rem] border-[3px] border-neo-ink bg-white px-3 py-2 text-sm font-black uppercase text-neo-ink shadow-[3px_3px_0_0_#050505] transition-all duration-150 hover:-translate-y-0.5 hover:bg-[#fff4bd] active:translate-x-[2px] active:translate-y-[2px] active:shadow-none focus:outline-none focus:ring-2 focus:ring-[#8758de] focus:ring-offset-2 focus:ring-offset-neo-cream',
    ]) }}
>
    <span>{{ $slot }}</span>
    <span aria-hidden="true" class="text-neo-ink/60 font-bold">→</span>
</a>
