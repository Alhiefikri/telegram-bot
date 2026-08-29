<div class="mt-4 rounded-lg border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-3 text-center" data-payment-countdown data-payment-deadline="{{ $paymentDeadlineTimestamp ?? $paymentDeadline?->toIso8601String() }}" data-countdown-expired-title="{{ __('storefront.invoice.deadline.countdown_expired_title', [], $storefrontLocale ?? 'id') }}" data-countdown-expired-message="{{ __('storefront.invoice.deadline.countdown_expired_message', [], $storefrontLocale ?? 'id') }}">
    <p class="text-xs font-black uppercase tracking-[0.16em] text-[var(--sf-muted)]">{{ __('storefront.invoice.deadline.countdown_label', [], $storefrontLocale ?? 'id') }}</p>
    <time class="mt-1 block font-mono text-2xl font-black tabular-nums text-[var(--sf-ink)]" datetime="{{ $paymentDeadlineTimestamp ?? $paymentDeadline?->toIso8601String() }}" role="timer" aria-live="off" data-payment-countdown-value>--:--:--</time>
    <p class="mt-1 hidden text-sm font-black text-[var(--sf-ink)]" data-payment-countdown-state></p>
    <p class="sr-only" aria-live="polite" data-payment-countdown-announcement></p>
</div>
