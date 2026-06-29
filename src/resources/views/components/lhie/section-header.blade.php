@props([
    'eyebrow',
    'title',
    'copy' => null,
])

<div {{ $attributes->class('lhie-dashboard-header') }}>
    <div class="min-w-0">
        <p class="lhie-dashboard-eyebrow">{{ $eyebrow }}</p>
        <h2 class="lhie-dashboard-title">{{ $title }}</h2>
        @if ($copy)
            <p class="lhie-dashboard-copy">{{ $copy }}</p>
        @endif
    </div>

    @isset($aside)
        <div class="shrink-0">
            {{ $aside }}
        </div>
    @endisset
</div>
