<x-storefront.panel as="section" tone="yellow" class="mt-5" aria-labelledby="recovery-key-title">
    <h2 id="recovery-key-title" class="text-balance font-display text-xl font-black leading-tight text-[var(--sf-ink)]">{{ __('storefront_funnel.save_recovery_key') }}</h2>
    <p class="mt-3 text-sm font-bold leading-6 text-[var(--sf-ink)] sm:text-base sm:leading-7">{{ __('storefront_funnel.recovery_key_scope') }}</p>
    <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center" data-copy-group>
        <code class="min-w-0 flex-1 break-all rounded-md border-[3px] border-[var(--sf-border)] bg-white px-3 py-3 font-mono text-sm font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">{{ $accessKey }}</code>
        <button type="button" class="neo-button h-11 shrink-0 bg-[var(--sf-surface)] px-4 text-sm font-black text-[var(--sf-ink)]" data-copy-text="{{ $accessKey }}" data-copy-success="{{ __('storefront_funnel.key_copied') }}">{{ __('storefront_funnel.copy_key') }}</button>
        <span class="sr-only" aria-live="polite" data-copy-status></span>
    </div>
    <p class="mt-4 text-sm font-bold leading-6 text-[var(--sf-muted)]">{{ __('storefront_funnel.keep_key_private') }}</p>
</x-storefront.panel>
