@props([
    'show',
    'close',
])

<div
    x-cloak
    x-show="{{ $show }}"
    x-transition.opacity
    x-on:keydown.escape.window="if ({{ $show }}) { {{ $close }} }"
    {{ $attributes->class([
        'fixed inset-0 z-50 flex items-center justify-center bg-neo-ink/60 p-3 sm:p-4',
    ]) }}
>
    <div class="absolute inset-0" x-on:click="{{ $close }}" aria-hidden="true"></div>

    {{ $slot }}
</div>
