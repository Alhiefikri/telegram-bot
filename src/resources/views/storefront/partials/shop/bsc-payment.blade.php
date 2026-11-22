@php
    $storefrontLocale = $storefrontLocale ?? 'id';
    $statusCopy = $paymentStatusCopy['bsc'] ?? [];
    $bscAmount = $storefront->usdt((int) ($bscPayableAmount ?? $payment?->amount ?? 0));
    $bscProductTotal = $storefront->usdt((int) ($bscBaseAmount ?? $bscPayableAmount ?? $payment?->amount ?? 0));
    $bscClosed = $bscVerificationClosed ?? false;
    $bscDeadlineLabel = $paymentDeadline?->copy()->timezone((string) config('app.timezone'))->format('d M Y H:i T');
@endphp

<x-storefront.panel as="section" class="mt-5" aria-label="{{ __('storefront.invoice.bsc.aria_label', [], $storefrontLocale) }}">
    <p class="text-xs font-black uppercase tracking-[0.2em] text-[var(--sf-muted)]">{{ __('storefront.invoice.bsc.eyebrow', [], $storefrontLocale) }}</p>
    <h2 class="mt-1 font-display text-2xl font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.bsc.title', [], $storefrontLocale) }}</h2>
    <p class="mt-2 text-base font-semibold leading-7 text-[var(--sf-muted)]">{{ __('storefront.invoice.bsc.'.(($bscVerificationAutomatic ?? false) ? 'description_auto' : 'description'), [], $storefrontLocale) }}</p>

    <div class="invoice-crypto-grid mt-4 grid min-w-0 gap-4 lg:grid-cols-2 lg:items-start">
    <div data-crypto-payment-details class="min-w-0 rounded-xl border border-[var(--sf-border)] bg-[var(--sf-paper)] p-3 sm:p-4">
    <dl class="grid gap-3 text-sm font-bold leading-6">
        <div>
            <dt class="text-[var(--sf-muted)]">{{ __('storefront.invoice.bsc.label.product_total', [], $storefrontLocale) }}</dt>
            <dd class="font-display text-xl font-black text-[var(--sf-accent)]">{{ $bscProductTotal }}</dd>
        </div>
        <div>
            <dt class="text-[var(--sf-muted)]">{{ __('storefront.invoice.bsc.label.send_exact', [], $storefrontLocale) }}</dt>
            <dd class="flex flex-wrap items-center gap-2">
                <code class="break-all font-mono text-xl font-black text-[var(--sf-ink)]">{{ $bscAmount }}</code>
                <button type="button" class="neo-button h-11 min-w-11 w-fit bg-white px-3 text-sm font-black text-[var(--sf-ink)]" data-copy-text="{{ $bscAmount }}" data-copy-success="{{ __('storefront.invoice.bsc.copy.amount_success', [], $storefrontLocale) }}">{{ __('storefront.invoice.bsc.button.copy_amount', [], $storefrontLocale) }}</button>
                <span class="sr-only" aria-live="polite" data-copy-status></span>
            </dd>
        </div>
        <div>
            <dt class="text-[var(--sf-muted)]">{{ __('storefront.invoice.bsc.label.receiving_address', [], $storefrontLocale) }}</dt>
            <dd class="flex flex-wrap items-center gap-2">
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

    <div class="mt-3 rounded-lg border border-[#9f1d2d] bg-[#fff1f3] p-3 text-sm font-black leading-6 text-[#781526]">
        {{ __('storefront.invoice.bsc.warning', [], $storefrontLocale) }}
    </div>

    <div class="mt-3 text-sm font-bold leading-6 text-[var(--sf-muted)]">
        <p class="font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.bsc.deadline', [], $storefrontLocale) }} {{ $bscDeadlineLabel ?? __('storefront.invoice.bsc.deadline_fallback', [], $storefrontLocale) }}</p>
        <p class="mt-1">{{ __('storefront.invoice.bsc.confirmation_policy', ['required' => $bscRequiredConfirmations], $storefrontLocale) }}</p>
        @if ($isPendingPayment && $paymentDeadline)
            @include('storefront.partials.shop.payment-countdown')
        @endif
    </div>
    </div>

    <div data-crypto-verification class="grid min-w-0 content-start gap-3">

    @if ($isPendingPayment && ! $bscClosed && ! ($bscVerificationAutomatic ?? false))
        <form method="POST" action="{{ $bscTxHashUrl }}" class="rounded-xl border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-3 text-sm font-bold leading-6 text-[var(--sf-muted)] sm:p-4">
            @csrf
            <label for="bsc-tx-hash" class="font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.bsc.tx_form.label', [], $storefrontLocale) }}</label>
            <p class="mt-1 text-sm">{{ __('storefront.invoice.bsc.tx_form.help', [], $storefrontLocale) }}</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <input id="bsc-tx-hash" name="bsc_tx_hash" inputmode="text" autocomplete="off" spellcheck="false" maxlength="66" pattern="0[xX][0-9a-fA-F]{64}" required class="min-h-12 min-w-0 flex-[1_1_14rem] rounded-lg border border-[var(--sf-muted)] bg-[var(--sf-surface)] px-3 font-mono text-base text-[var(--sf-ink)]" placeholder="0x...">
                <button type="submit" class="neo-button inline-flex min-h-12 items-center justify-center bg-[var(--sf-accent)] px-4 text-sm font-black text-white">{{ __('storefront.invoice.bsc.tx_form.button', [], $storefrontLocale) }}</button>
            </div>
            @error('bsc_tx_hash')
                <p class="mt-2 text-sm font-black text-red-700" role="alert">{{ $message }}</p>
            @enderror
        </form>
    @endif

        <div class="rounded-xl border border-[var(--sf-border)] bg-[var(--sf-paper)] p-3 sm:p-4" @if (! $bscClosed) data-shop-payment-status data-payment-kind="bsc" data-status-url="{{ $statusUrl }}" data-redirect-url="{{ $redirectUrl }}" data-terminal="{{ $isTerminal ? 'true' : 'false' }}" data-payment-deadline="{{ $paymentDeadlineTimestamp ?? '' }}" data-poll-ms="{{ $paymentStatusPollIntervalMs ?? 20000 }}" data-poll-max-attempts="{{ $paymentStatusPollMaxAttempts ?? 9 }}" data-poll-max-duration-ms="{{ $paymentStatusPollMaxDurationMs ?? 180000 }}" data-status-copy='@json($statusCopy)' @endif>
            <p data-payment-status-title class="font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.bsc.status.check_title', [], $storefrontLocale) }}</p>
            <p data-payment-status-message class="mt-1 text-sm font-bold leading-6 text-[var(--sf-muted)]">{{ $statusMessage ?: __('storefront.invoice.bsc.status.check_message', [], $storefrontLocale) }}</p>
            <p class="mt-1 text-sm font-bold text-[var(--sf-muted)]">{{ __('storefront.invoice.bsc.status', [], $storefrontLocale) }} {{ $bscStatusLabel }} ({{ $bscConfirmations }}/{{ $bscRequiredConfirmations }} {{ __('storefront.invoice.bsc.confirmations', [], $storefrontLocale) }})</p>
            <p data-payment-status-announcement class="sr-only" aria-live="polite"></p>
            @if (! $bscClosed)
            <button type="button" data-payment-status-check class="neo-button mt-3 min-h-12 bg-[var(--sf-surface)] px-4 text-sm font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.bsc.status.check_button', [], $storefrontLocale) }}</button>
            <div data-payment-status-followup class="hidden"></div>
            @endif
            @if (filled($bscExplorerUrl))
                <a href="{{ $bscExplorerUrl }}" target="_blank" rel="noopener noreferrer" class="mt-2 inline-flex min-h-11 items-center px-2 text-sm font-bold underline">{{ __('storefront.invoice.bsc.explorer', [], $storefrontLocale) }}</a>
            @endif
        </div>
    </div>
    </div>
</x-storefront.panel>
