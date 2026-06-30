@props([
    'eyebrow' => null,
    'title',
])

<div {{ $attributes->class(['min-w-0']) }}>
    @if ($eyebrow)
        <p class="text-[10px] font-black uppercase tracking-[0.2em] text-neo-ink/55">{{ $eyebrow }}</p>
    @endif

    <h2 @class([
        'font-sans text-2xl font-black uppercase leading-tight text-neo-ink',
        'mt-1' => $eyebrow,
    ])>{{ $title }}</h2>

    @if (! $slot->isEmpty())
        <p class="mt-2 text-sm font-bold leading-6 text-neo-ink/65">{{ $slot }}</p>
    @endif
</div>
