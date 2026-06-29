@props([
    'tone' => 'slate',
])

<x-admin-dashboard.badge :tone="$tone" dot {{ $attributes }}>
    {{ $slot }}
</x-admin-dashboard.badge>
