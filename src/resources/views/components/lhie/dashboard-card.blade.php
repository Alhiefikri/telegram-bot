@props(['padded' => true])

<section {{ $attributes->class([
    'lhie-dashboard-card',
    'p-5' => $padded,
]) }}>
    {{ $slot }}
</section>
