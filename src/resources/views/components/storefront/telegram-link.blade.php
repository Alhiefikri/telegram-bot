@props([
    'href',
    'variant' => 'primary',
    'size' => 'md',
    'iconClass' => 'h-4 w-4 shrink-0',
    'external' => true,
])

<x-storefront.button
    :href="$href"
    :variant="$variant"
    :size="$size"
    :external="$external"
    data-telegram-link
    data-icon="telegram"
    {{ $attributes }}
>
    @include('storefront.components.telegram-icon', ['class' => $iconClass])
    <span>{{ $slot }}</span>
</x-storefront.button>
