@props([
    'title',
    'body' => null,
    'tone' => 'white',
])

<x-admin-dashboard.card :tone="$tone" border="dashed" shadow="none" {{ $attributes->class(['rounded-xl p-6 text-center']) }}>
    <p class="text-sm font-semibold text-admin-ink">{{ $title }}</p>
    @if ($body)
        <p class="mt-2 text-sm font-normal leading-5 text-admin-muted">{{ $body }}</p>
    @endif
    {{ $slot }}
</x-admin-dashboard.card>
