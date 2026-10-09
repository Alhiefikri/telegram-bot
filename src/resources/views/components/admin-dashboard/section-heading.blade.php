@props([
    'eyebrow' => null,
    'title',
])

<div {{ $attributes->class(['min-w-0']) }}>
    @if ($eyebrow)
        <p class="text-xs font-medium text-admin-muted">{{ $eyebrow }}</p>
    @endif

    <h2 @class([
        'text-2xl font-semibold leading-tight text-admin-ink',
        'mt-1' => $eyebrow,
    ])>{{ $title }}</h2>

    @if (! $slot->isEmpty())
        <p class="mt-2 text-sm font-normal leading-6 text-admin-muted">{{ $slot }}</p>
    @endif
</div>
