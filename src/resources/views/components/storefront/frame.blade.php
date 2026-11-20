@props(['as' => 'div'])

<{{ $as }} {{ $attributes->class('storefront-layout-frame') }}>
    {{ $slot }}
</{{ $as }}>
