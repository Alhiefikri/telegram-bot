@php
    $brand = $brand ?? 'Lhie Store';
@endphp

<div class="brand-wordmark flex items-center gap-2.5">
    <span class="brand-spark-icon" aria-hidden="true">✦</span>
    <span class="font-display text-[1.08rem] font-black leading-none tracking-tight text-[var(--sf-ink)] sm:text-[1.16rem]">
        {{ $brand }}
    </span>
</div>
