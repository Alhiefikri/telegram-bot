@php
    $storefrontLocale = $storefrontLocale ?? 'id';
    $statusCopy = $paymentStatusCopy['binance'] ?? [];
    $binanceAmount = $storefront->usdt((int) $payment->amount);
    $paymentCaption = '/payment '.$order->invoice_number;
    $binanceDeadlineLabel = $paymentDeadline?->copy()->timezone((string) config('app.timezone'))->format('d M Y H:i T');
@endphp

<x-storefront.panel as="section" class="mt-5" aria-label="{{ __('storefront.invoice.binance.aria_label', [], $storefrontLocale) }}">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-sm font-black uppercase tracking-[0.2em] text-[var(--sf-muted)]">{{ __('storefront.invoice.binance.eyebrow', [], $storefrontLocale) }}</p>
            <h2 class="mt-1 font-display text-2xl font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.binance.title', [], $storefrontLocale) }}</h2>
            <p class="mt-2 text-base font-semibold leading-7 text-[var(--sf-muted)]">{{ __('storefront.invoice.binance.description', [], $storefrontLocale) }}</p>
        </div>
        <span class="inline-flex w-fit rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-1 text-sm font-black uppercase tracking-[0.16em] text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">
            {{ $isPaid ? __('storefront.invoice.binance.badge.paid', [], $storefrontLocale) : __('storefront.invoice.binance.badge.pending', [], $storefrontLocale) }}
        </span>
    </div>

    <div class="mt-5 rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-4 shadow-[4px_4px_0_var(--sf-shadow)]">
        <img src="{{ asset('binance.jpeg') }}" alt="{{ __('storefront.invoice.binance.title', [], $storefrontLocale) }}" width="614" height="658" loading="lazy" decoding="async" class="mx-auto h-auto w-full max-w-[320px] rounded-md">
    </div>

    <dl class="mt-5 space-y-3 text-sm font-bold">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4" data-copy-group>
            <dt class="text-[var(--sf-muted)]">{{ __('storefront.invoice.binance.label.exact_amount', [], $storefrontLocale) }}</dt>
            <dd class="flex items-center gap-2 text-left sm:text-right">
                <span class="font-display text-2xl font-black text-[var(--sf-accent)]">{{ $binanceAmount }}</span>
                <button type="button" class="neo-button h-11 min-w-11 bg-[var(--sf-surface)] px-3 text-sm font-black text-[var(--sf-ink)]" data-copy-text="{{ $binanceAmount }}" data-copy-success="{{ __('storefront.invoice.binance.copy.amount_success', [], $storefrontLocale) }}">{{ __('storefront.invoice.binance.button.copy', [], $storefrontLocale) }}</button>
                <span class="sr-only" aria-live="polite" data-copy-status></span>
            </dd>
        </div>
        <div class="flex flex-col gap-2 border-t-[3px] border-[var(--sf-border)] pt-4 sm:flex-row sm:items-center sm:justify-between sm:gap-4" data-copy-group>
            <dt class="text-[var(--sf-muted)]">{{ $binanceReceiverLabel }}</dt>
            <dd class="flex items-center gap-2 text-left sm:text-right">
                <span class="font-black text-[var(--sf-ink)]">{{ $binancePayId }}</span>
                <button type="button" class="neo-button h-11 min-w-11 bg-[var(--sf-surface)] px-3 text-sm font-black text-[var(--sf-ink)]" data-copy-text="{{ $binancePayId }}" data-copy-success="{{ __('storefront.invoice.binance.copy.receiver_success', ['label' => $binanceReceiverLabel], $storefrontLocale) }}">{{ __('storefront.invoice.binance.button.copy', [], $storefrontLocale) }}</button>
                <span class="sr-only" aria-live="polite" data-copy-status></span>
            </dd>
        </div>
        <div class="flex items-center justify-between gap-4 border-t-[3px] border-[var(--sf-border)] pt-4">
            <dt class="text-[var(--sf-muted)]">{{ __('storefront.invoice.qris.label.deadline', [], $storefrontLocale) }}</dt>
            <dd class="text-right font-black text-[var(--sf-ink)]">{{ $binanceDeadlineLabel ?? '-' }}</dd>
        </div>
    </dl>

    @if ($isPendingPayment && $paymentDeadline)
        @include('storefront.partials.shop.payment-countdown')
    @endif

    @if ($isPendingPayment)
        <div class="mt-5 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] p-4 text-base font-bold leading-7 text-[var(--sf-ink)] shadow-[4px_4px_0_var(--sf-shadow)]">
            <p class="font-black">{{ __('storefront.invoice.binance.warning.title', [], $storefrontLocale) }}</p>
            <p>{{ __('storefront.invoice.binance.warning.exact', ['amount' => $binanceAmount], $storefrontLocale) }}</p>
            <p>{{ __('storefront.invoice.binance.warning.no_round', [], $storefrontLocale) }}</p>
        </div>

        @if ($binanceVerificationAutomatic && $binanceVerificationMode === 'amount_unique' && $binanceVerificationStatus !== 'mismatch' && ! $binanceVerificationClosed)
            <div class="mt-5 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4 text-base font-bold leading-7 text-[var(--sf-muted)] shadow-[4px_4px_0_var(--sf-shadow)]" data-shop-payment-status data-payment-kind="binance" data-status-url="{{ $statusUrl }}" data-redirect-url="{{ $redirectUrl }}" data-terminal="{{ $isTerminal ? 'true' : 'false' }}" data-payment-deadline="{{ $paymentDeadlineTimestamp ?? '' }}" data-poll-ms="{{ $paymentStatusPollIntervalMs ?? 20000 }}" data-poll-max-attempts="{{ $paymentStatusPollMaxAttempts ?? 9 }}" data-poll-max-duration-ms="{{ $paymentStatusPollMaxDurationMs ?? 180000 }}" data-status-copy='@json($statusCopy)'>
                <p data-payment-status-title class="font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.binance.status.auto_title', [], $storefrontLocale) }}</p>
                <p data-payment-status-message class="mt-1 text-sm font-bold leading-6 text-[var(--sf-muted)]">{{ __('storefront.invoice.binance.status.auto_message', [], $storefrontLocale) }}</p>
                <p data-payment-status-announcement class="sr-only" aria-live="polite"></p>
                <button type="button" data-payment-status-check class="neo-button mt-3 min-h-12 bg-[var(--sf-surface)] px-4 text-sm font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.binance.button.check', [], $storefrontLocale) }}</button>
                <div data-payment-status-followup class="hidden"></div>
            </div>
        @elseif ($binanceVerificationAutomatic && $binanceVerificationMode === 'order_id' && $binanceVerificationStatus !== 'mismatch' && ! $binanceVerificationClosed)
            <form method="POST" action="{{ $binanceOrderIdUrl }}" class="mt-5 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4 text-base font-bold leading-7 text-[var(--sf-muted)] shadow-[4px_4px_0_var(--sf-shadow)]">
                @csrf
                <p class="font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.binance.order_id.prompt', [], $storefrontLocale) }}</p>
                <p class="mt-2">{{ __('storefront.invoice.binance.order_id.instructions', [], $storefrontLocale) }}</p>
                <label for="binance-order-id" class="mt-4 block text-sm font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.binance.order_id.label', [], $storefrontLocale) }}</label>
                <div class="mt-2 flex flex-col gap-3 sm:flex-row">
                    <input id="binance-order-id" name="binance_order_id" inputmode="text" autocomplete="off" maxlength="128" pattern="[A-Za-z0-9][A-Za-z0-9._:-]{8,126}[A-Za-z0-9]" required class="min-h-12 min-w-0 flex-1 rounded-lg border-[3px] border-[var(--sf-border)] bg-white px-3 font-mono text-base text-[var(--sf-ink)]" placeholder="445011487581044736">
                    <button type="submit" class="neo-button inline-flex min-h-12 items-center justify-center bg-[var(--sf-accent)] px-4 text-sm font-black text-white">{{ __('storefront.invoice.binance.order_id.button', [], $storefrontLocale) }}</button>
                </div>
                @error('binance_order_id')
                    <p class="mt-2 text-sm font-black text-red-700" role="alert">{{ $message }}</p>
                @enderror
            </form>
            <div class="mt-4 rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-4" data-shop-payment-status data-payment-kind="binance" data-status-url="{{ $statusUrl }}" data-redirect-url="{{ $redirectUrl }}" data-terminal="{{ $isTerminal ? 'true' : 'false' }}" data-payment-deadline="{{ $paymentDeadlineTimestamp ?? '' }}" data-poll-ms="{{ $paymentStatusPollIntervalMs ?? 20000 }}" data-poll-max-attempts="{{ $paymentStatusPollMaxAttempts ?? 9 }}" data-poll-max-duration-ms="{{ $paymentStatusPollMaxDurationMs ?? 180000 }}" data-status-copy='@json($statusCopy)'>
                <p data-payment-status-title class="font-black text-[var(--sf-ink)]">{{ $binanceVerificationStatus === 'checking' ? __('storefront.invoice.binance.status.checking', [], $storefrontLocale) : __('storefront.invoice.binance.status.waiting', [], $storefrontLocale) }}</p>
                <p data-payment-status-message class="mt-1 text-sm font-bold leading-6 text-[var(--sf-muted)]">{{ $binanceVerificationStatus === 'checking' ? __('storefront.invoice.binance.status.checking_message', [], $storefrontLocale) : __('storefront.invoice.binance.status.waiting_message', [], $storefrontLocale) }}</p>
                <p data-payment-status-announcement class="sr-only" aria-live="polite"></p>
                <button type="button" data-payment-status-check class="neo-button mt-3 min-h-12 bg-[var(--sf-surface)] px-4 text-sm font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.binance.button.check', [], $storefrontLocale) }}</button>
                <div data-payment-status-followup class="hidden"></div>
            </div>
        @else
            <div class="mt-5 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4 text-base font-bold leading-7 text-[var(--sf-muted)] shadow-[4px_4px_0_var(--sf-shadow)]" @if ($binanceVerificationStatus === 'mismatch') role="status" aria-live="polite" @endif>
                @if ($binanceVerificationStatus === 'mismatch')
                    <p class="font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.status_ui.common.manual_review_title', [], $storefrontLocale) }}</p>
                    <p>{{ $statusMessage }}</p>
                @else
                    <p class="font-black">{{ __('storefront.invoice.binance.fallback.prompt', [], $storefrontLocale) }}</p>
                    <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center" data-copy-group>
                        <p class="min-w-0">{{ __('storefront.invoice.binance.fallback.caption', [], $storefrontLocale) }} <code class="rounded bg-white px-1">{{ $paymentCaption }}</code>.</p>
                        <button type="button" class="neo-button h-11 shrink-0 bg-white px-3 text-sm font-black text-[var(--sf-ink)]" data-copy-text="{{ $paymentCaption }}" data-copy-success="{{ __('storefront.invoice.binance.copy.caption_success', [], $storefrontLocale) }}">{{ __('storefront.invoice.binance.fallback.caption_button', [], $storefrontLocale) }}</button>
                        <span class="sr-only" aria-live="polite" data-copy-status></span>
                    </div>
                @endif
            </div>
        @endif
        @if ($canCancel)
            <form method="POST" action="{{ $cancelUrl }}" class="mt-5 rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-4 text-base font-bold leading-7 text-[var(--sf-muted)] shadow-[4px_4px_0_var(--sf-shadow)]" onsubmit="return confirm(@js(__('storefront.invoice.binance.cancel.confirm', [], $storefrontLocale)));">
                @csrf
                <p class="font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.binance.cancel.not_paid', [], $storefrontLocale) }}</p>
                <p>{{ __('storefront.invoice.binance.cancel.description', [], $storefrontLocale) }}</p>
                <button type="submit" class="mt-4 inline-flex min-h-12 w-full items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[#ffd9df] px-4 py-3 text-sm font-black text-[var(--sf-ink)] shadow-[4px_4px_0_var(--sf-shadow)] transition hover:-translate-y-0.5 hover:shadow-[6px_6px_0_var(--sf-shadow)] sm:w-auto">{{ __('storefront.invoice.binance.cancel.button', [], $storefrontLocale) }}</button>
            </form>
        @endif
    @endif
</x-storefront.panel>
