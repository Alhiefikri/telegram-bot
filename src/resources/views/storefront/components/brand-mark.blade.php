@php
    $brand = $brand ?? 'Lhie Store';
@endphp

<div class="brand-wordmark flex items-center gap-2.5">
    <img
        src="{{ Vite::asset('resources/images/chibi-favicon-256.png') }}"
        alt=""
        aria-hidden="true"
        width="64"
        height="64"
        loading="eager"
        decoding="async"
        class="brand-logo-image"
    >
    <span class="font-display text-[1.08rem] font-black leading-none tracking-tight text-[var(--sf-ink)] sm:text-[1.16rem]">
        {{ $brand }}
    </span>
</div>
