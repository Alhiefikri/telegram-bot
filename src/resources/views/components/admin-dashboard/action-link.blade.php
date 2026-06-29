@props([
    'href' => '#',
    'variant' => 'primary',
])

<x-admin-dashboard.button
    :href="$href"
    :variant="$variant === 'primary' ? 'primary' : 'neutral'"
    {{ $attributes }}
>
    {{ $slot }}
</x-admin-dashboard.button>
