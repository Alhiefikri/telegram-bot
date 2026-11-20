<aside id="verified-purchase-activity"
       class="storefront-purchase-activity hidden"
       data-endpoint="{{ route('storefront.verified-purchase-activity') }}"
       data-activity-state="hidden"
       inert
       aria-hidden="true"
       aria-label="{{ __('storefront_funnel.verified_purchases') }}">
    <div class="purchase-activity-content">
        <span class="purchase-activity-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="9" />
                <path d="m8.5 12 2.3 2.3 4.7-4.7" />
            </svg>
        </span>
        <div class="min-w-0">
            <p class="text-sm font-bold leading-tight text-[var(--sf-ink)]">{{ __('storefront_funnel.verified_purchases') }}</p>
            <p class="purchase-activity-message mt-1 text-sm leading-snug text-[var(--sf-muted)]" data-activity-message></p>
        </div>
        <p class="sr-only" aria-live="polite" data-activity-live></p>
        <button type="button" class="purchase-activity-close" data-activity-dismiss aria-label="{{ __('storefront_funnel.close_verified_purchases') }}">✕</button>
    </div>
</aside>
