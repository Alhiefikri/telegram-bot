@php
    $storefrontLocale = $storefrontLocale ?? 'id';
    $statusCopy = $paymentStatusCopy['bsc'] ?? [];
    $bscAmount = $storefront->usdt((int) ($bscPayableAmount ?? $payment?->amount ?? 0));
    $bscProductTotal = $storefront->usdt((int) ($bscBaseAmount ?? $bscPayableAmount ?? $payment?->amount ?? 0));
    $bscStatus = $bscVerificationStatus ?? 'awaiting_transfer';
    $bscClosed = $bscVerificationClosed ?? false;
    $bscDeadlineLabel = $paymentDeadline?->copy()->timezone((string) config('app.timezone'))->format('d M Y H:i T');
@endphp

<x-storefront.panel as="section" class="mt-5" aria-label="{{ __('storefront.invoice.bsc.aria_label', [], $storefrontLocale) }}">
    <p class="text-xs font-black uppercase tracking-[0.2em] text-[var(--sf-muted)]">{{ __('storefront.invoice.bsc.eyebrow', [], $storefrontLocale) }}</p>
    <h2 class="mt-1 font-display text-2xl font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.bsc.title', [], $storefrontLocale) }}</h2>
    <p class="mt-2 text-base font-semibold leading-7 text-[var(--sf-muted)]">{{ __('storefront.invoice.bsc.'.(($bscVerificationAutomatic ?? false) ? 'description_auto' : 'description'), [], $storefrontLocale) }}</p>

    <dl class="mt-5 grid gap-4 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4 text-sm font-bold leading-6 shadow-[4px_4px_0_var(--sf-shadow)]">
        <div>
            <dt class="text-[var(--sf-muted)]">{{ __('storefront.invoice.bsc.label.product_total', [], $storefrontLocale) }}</dt>
            <dd class="font-display text-xl font-black text-[var(--sf-accent)]">{{ $bscProductTotal }}</dd>
        </div>
        <div>
            <dt class="text-[var(--sf-muted)]">{{ __('storefront.invoice.bsc.label.send_exact', [], $storefrontLocale) }}</dt>
            <dd class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <code class="break-all font-mono text-xl font-black text-[var(--sf-ink)]">{{ $bscAmount }}</code>
                <button type="button" class="neo-button h-11 min-w-11 w-fit bg-white px-3 text-sm font-black text-[var(--sf-ink)]" data-copy-text="{{ $bscAmount }}" data-copy-success="{{ __('storefront.invoice.bsc.copy.amount_success', [], $storefrontLocale) }}">{{ __('storefront.invoice.bsc.button.copy_amount', [], $storefrontLocale) }}</button>
                <span class="sr-only" aria-live="polite" data-copy-status></span>
            </dd>
        </div>
        <div>
            <dt class="text-[var(--sf-muted)]">{{ __('storefront.invoice.bsc.label.receiving_address', [], $storefrontLocale) }}</dt>
            <dd class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <code class="break-all font-mono text-sm font-black text-[var(--sf-ink)]">{{ $bscReceiverAddress ?: __('storefront.invoice.bsc.unavailable', [], $storefrontLocale) }}</code>
                @if (filled($bscReceiverAddress))
                    <button type="button" class="neo-button h-11 min-w-11 w-fit bg-white px-3 text-sm font-black text-[var(--sf-ink)]" data-copy-text="{{ $bscReceiverAddress }}" data-copy-success="{{ __('storefront.invoice.bsc.copy.address_success', [], $storefrontLocale) }}">{{ __('storefront.invoice.bsc.button.copy_address', [], $storefrontLocale) }}</button>
                    <span class="sr-only" aria-live="polite" data-copy-status></span>
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-[var(--sf-muted)]">{{ __('storefront.invoice.bsc.label.network', [], $storefrontLocale) }}</dt>
            <dd class="font-black text-[var(--sf-ink)]">{{ $bscNetworkLabel }}</dd>
        </div>
    </dl>

    <div class="mt-5 rounded-lg border-[3px] border-[#9f1d2d] bg-[#fff1f3] p-4 text-sm font-black leading-6 text-[#781526]" role="alert">
        {{ __('storefront.invoice.bsc.warning', [], $storefrontLocale) }}
    </div>

    <div class="mt-5 rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-4 text-sm font-bold leading-6 text-[var(--sf-muted)]">
        <p class="font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.bsc.deadline', [], $storefrontLocale) }} {{ $bscDeadlineLabel ?? __('storefront.invoice.bsc.deadline_fallback', [], $storefrontLocale) }}</p>
        <p class="mt-1">{{ __('storefront.invoice.bsc.confirmation_policy', ['required' => $bscRequiredConfirmations], $storefrontLocale) }}</p>
        <p class="mt-2 font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.bsc.status', [], $storefrontLocale) }} {{ $bscStatusLabel }} ({{ $bscConfirmations }}/{{ $bscRequiredConfirmations }} {{ __('storefront.invoice.bsc.confirmations', [], $storefrontLocale) }})</p>
        <p class="mt-1 text-sm" aria-live="polite">{{ $statusMessage }}</p>
        @if ($isPendingPayment && $paymentDeadline)
            @include('storefront.partials.shop.payment-countdown')
        @endif
    </div>

    @if (filled($bscExplorerUrl))
        <a href="{{ $bscExplorerUrl }}" target="_blank" rel="noopener noreferrer" class="mt-4 inline-flex min-h-11 items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-white px-4 py-2 text-sm font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">{{ __('storefront.invoice.bsc.explorer', [], $storefrontLocale) }}</a>
    @endif

    @if ($isPendingPayment && ! $bscClosed && ! ($bscVerificationAutomatic ?? false))
        <form method="POST" action="{{ $bscTxHashUrl }}" class="mt-5 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4 text-base font-bold leading-7 text-[var(--sf-muted)] shadow-[4px_4px_0_var(--sf-shadow)]">
            @csrf
            <label for="bsc-tx-hash" class="font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.bsc.tx_form.label', [], $storefrontLocale) }}</label>
            <p class="mt-1 text-sm">{{ __('storefront.invoice.bsc.tx_form.help', [], $storefrontLocale) }}</p>
            <div class="mt-3 flex flex-col gap-3 sm:flex-row">
                <input id="bsc-tx-hash" name="bsc_tx_hash" inputmode="text" autocomplete="off" maxlength="66" pattern="0x[0-9a-fA-F]{64}" required class="min-h-12 min-w-0 flex-1 rounded-lg border-[3px] border-[var(--sf-border)] bg-white px-3 font-mono text-base text-[var(--sf-ink)]" placeholder="0x...">
                <button type="submit" class="neo-button inline-flex min-h-12 items-center justify-center bg-[var(--sf-accent)] px-4 text-sm font-black text-white">{{ __('storefront.invoice.bsc.tx_form.button', [], $storefrontLocale) }}</button>
            </div>
            @error('bsc_tx_hash')
                <p class="mt-2 text-sm font-black text-red-700" role="alert">{{ $message }}</p>
            @enderror
        </form>
    @endif

    @if ($isPendingPayment && ! $bscClosed)
        <div class="mt-5 rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-4" data-shop-payment-status data-payment-kind="bsc" data-status-url="{{ $statusUrl }}" data-redirect-url="{{ $redirectUrl }}" data-terminal="{{ $isTerminal ? 'true' : 'false' }}" data-payment-deadline="{{ $paymentDeadlineTimestamp ?? '' }}" data-poll-ms="{{ $paymentStatusPollIntervalMs ?? 20000 }}" data-poll-max-attempts="{{ $paymentStatusPollMaxAttempts ?? 9 }}" data-poll-max-duration-ms="{{ $paymentStatusPollMaxDurationMs ?? 180000 }}" data-status-copy='@json($statusCopy)'>
            <p data-payment-status-title class="font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.bsc.status.check_title', [], $storefrontLocale) }}</p>
            <p data-payment-status-message class="mt-1 text-sm font-bold leading-6 text-[var(--sf-muted)]">{{ __('storefront.invoice.bsc.status.check_message', [], $storefrontLocale) }}</p>
            <p data-payment-status-announcement class="sr-only" aria-live="polite"></p>
            <button type="button" data-payment-status-check class="neo-button mt-3 min-h-12 bg-[var(--sf-surface)] px-4 text-sm font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.bsc.status.check_button', [], $storefrontLocale) }}</button>
            <div data-payment-status-followup class="hidden"></div>
        </div>
    @endif


    @if ($canCancel)
        <form method="POST" action="{{ $cancelUrl }}" class="mt-5 rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-4 text-base font-bold leading-6 text-[var(--sf-muted)] shadow-[4px_4px_0_var(--sf-shadow)]" onsubmit="return confirm(@js(__('storefront.invoice.bsc.cancel.confirm', [], $storefrontLocale)));">
            @csrf
            <p class="font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.bsc.cancel.not_paid', [], $storefrontLocale) }}</p>
            <p class="mt-1">{{ __('storefront.invoice.bsc.cancel.description', [], $storefrontLocale) }}</p>
            <button type="submit" class="mt-4 inline-flex min-h-12 items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[#ffd9df] px-4 py-3 text-sm font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">{{ __('storefront.invoice.bsc.cancel.button', [], $storefrontLocale) }}</button>
        </form>
    @endif
</x-storefront.panel>
