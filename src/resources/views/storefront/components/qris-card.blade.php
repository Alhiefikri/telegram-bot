@php
    $qris = $copy['qris'];
@endphp

<aside class="qris-card neo-panel reveal-item relative overflow-hidden bg-[var(--sf-surface)] p-5 sm:p-6" aria-label="Ilustrasi QRIS Lhie Store">
    <div class="pointer-events-none absolute inset-0 bg-grid-pattern opacity-40"></div>
    <div class="relative z-10">
        <div class="mb-5 flex items-start justify-between gap-4">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.2em] text-[var(--sf-muted)]">{{ $qris['eyebrow'] }}</p>
                <h3 class="mt-2 font-display text-2xl font-black leading-tight text-[var(--sf-ink)]">{{ $qris['title'] }}</h3>
            </div>
            <span class="neo-float-badge rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-green)] px-2.5 py-1 text-[9px] font-black uppercase tracking-wider text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">QRIS</span>
        </div>

        <div class="qris-frame relative mx-auto max-w-[280px] overflow-hidden rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-3 shadow-[7px_7px_0_var(--sf-shadow)]">
            <div class="relative z-10 flex aspect-square w-full flex-col items-center justify-center gap-4 rounded-md border-2 border-dashed border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4 text-center">
                <div class="grid grid-cols-3 gap-2" aria-hidden="true">
                    <span class="h-10 w-10 rounded-sm border-2 border-[var(--sf-border)] bg-[var(--sf-ink)]"></span>
                    <span class="h-10 w-10 rounded-sm border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)]"></span>
                    <span class="h-10 w-10 rounded-sm border-2 border-[var(--sf-border)] bg-[var(--sf-ink)]"></span>
                    <span class="h-10 w-10 rounded-sm border-2 border-[var(--sf-border)] bg-white"></span>
                    <span class="h-10 w-10 rounded-sm border-2 border-[var(--sf-border)] bg-[var(--sf-accent)]"></span>
                    <span class="h-10 w-10 rounded-sm border-2 border-[var(--sf-border)] bg-white"></span>
                    <span class="h-10 w-10 rounded-sm border-2 border-[var(--sf-border)] bg-[var(--sf-ink)]"></span>
                    <span class="h-10 w-10 rounded-sm border-2 border-[var(--sf-border)] bg-[var(--sf-green)]"></span>
                    <span class="h-10 w-10 rounded-sm border-2 border-[var(--sf-border)] bg-[var(--sf-ink)]"></span>
                </div>
                <p class="max-w-[12rem] font-display text-sm font-black leading-tight text-[var(--sf-ink)]">QRIS final muncul setelah checkout web</p>
            </div>
            <div class="qris-scan-line" aria-hidden="true"></div>
        </div>

        <p class="mx-auto mt-5 max-w-sm text-center text-xs font-semibold leading-relaxed text-[var(--sf-muted)]">{{ $qris['body'] }}</p>

        <a href="{{ route('storefront.shop.index') }}" class="neo-button mt-5 inline-flex h-12 w-full items-center justify-center gap-2 bg-[var(--sf-accent)] px-5 text-sm font-black text-white">
            <span>Belanja Web via QRIS</span>
        </a>
    </div>
</aside>
