@php
    $brand = $brand ?? 'Lhie Store';
@endphp

<div class="flex items-center gap-2.5">
    <div class="flex h-9 w-9 select-none items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] font-display text-lg font-black text-[var(--sf-ink)] shadow-[4px_4px_0_var(--sf-shadow)]">
        L
    </div>
    <div class="leading-none">
        <span class="block font-display text-base font-black tracking-tight text-[var(--sf-ink)]">{{ $brand }}</span>
        <span class="block text-[9px] font-extrabold uppercase tracking-[0.2em] text-[var(--sf-muted)]">Telegram Market</span>
    </div>
</div>
