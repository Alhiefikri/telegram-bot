@props([
    'eyebrow' => null,
    'title',
])

<div {{ $attributes->class(['min-w-0']) }}>
    @if ($eyebrow)
        <p class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ $eyebrow }}</p>
    @endif

    <h2 @class([
        'font-display text-xl font-bold text-brand-navy',
        'mt-2' => $eyebrow,
    ])>{{ $title }}</h2>

    @if (! $slot->isEmpty())
        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $slot }}</p>
    @endif
</div>
