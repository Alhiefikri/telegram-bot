<aside id="verified-purchase-activity"
       class="pointer-events-auto fixed right-4 top-24 z-40 hidden max-w-[calc(100vw-2rem)] sm:max-w-sm"
       data-endpoint="{{ route('storefront.verified-purchase-activity') }}"
       aria-label="Aktivitas pembelian terverifikasi">
    <div class="neo-panel-tight flex items-start gap-3 bg-[var(--sf-yellow)] p-3 sm:p-4">
        <span class="grid h-8 w-8 shrink-0 place-items-center border-[3px] border-[var(--sf-border)] bg-[var(--sf-ink)] font-display text-sm font-black text-white" aria-hidden="true">✓</span>
        <div class="min-w-0">
            <p class="font-display text-[11px] font-black uppercase tracking-[0.14em] text-[var(--sf-ink)]">Pembelian terverifikasi</p>
            <p class="mt-1 text-sm font-bold leading-5 text-[var(--sf-ink)] transition-[opacity,transform] duration-500 ease-out motion-reduce:transition-none" data-activity-message></p>
            <p class="sr-only" aria-live="polite" data-activity-live></p>
        </div>
    </div>
</aside>
