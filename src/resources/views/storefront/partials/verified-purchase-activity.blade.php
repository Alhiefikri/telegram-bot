<aside id="verified-purchase-activity"
       class="storefront-purchase-activity hidden opacity-0 transition-opacity duration-300 ease-out motion-reduce:transition-none"
       data-endpoint="{{ route('storefront.verified-purchase-activity') }}"
       data-activity-state="hidden"
       aria-hidden="true"
       aria-label="Aktivitas pembelian terverifikasi">
    <div class="purchase-activity-content">
        <span class="purchase-activity-label font-display text-sm font-black text-[var(--sf-ink)]">Aktivitas pembelian terverifikasi</span>
        <p class="purchase-activity-message text-sm font-bold leading-5 text-[var(--sf-ink)]" data-activity-message></p>
        <p class="sr-only" aria-live="polite" data-activity-live></p>
        <button type="button" class="purchase-activity-close" data-activity-dismiss aria-label="Tutup aktivitas pembelian">✕</button>
    </div>
</aside>
