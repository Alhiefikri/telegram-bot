@props([
    'title',
    'body' => null,
    'tone' => 'white',
])

<x-admin-dashboard.card :tone="$tone" border="dashed" shadow="none" {{ $attributes->class(['rounded-xl p-6 text-center']) }}>
    <p class="font-display text-sm font-bold text-brand-muted">{{ $title }}</p>
    @if ($body)
        <p class="mt-2 text-xs font-semibold leading-5 text-brand-muted">{{ $body }}</p>
    @endif
    {{ $slot }}
</x-admin-dashboard.card>
