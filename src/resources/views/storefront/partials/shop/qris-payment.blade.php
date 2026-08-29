@php
    $storefrontLocale = $storefrontLocale ?? 'id';
    $statusCopy = $paymentStatusCopy['qris'] ?? [];
    $hasNativeQris = $isPendingPayment && $isQrisPayable && filled($qrisImageSource ?? $qrisImageDataUri);
    $isManualQris = $isManualQris ?? false;
    $isHostedPakasir = $isHostedPakasir ?? false;
    $paymentDeadlineLabel = $paymentDeadline?->copy()->timezone((string) config('app.timezone'))->format('d M Y H:i T');
    $customerPaysQrisFee = config('services.pakasir.qris_fee.payer', 'customer') === 'customer';
@endphp

<x-storefront.panel as="section" class="mt-5" aria-label="{{ __('storefront.invoice.qris.aria_label', [], $storefrontLocale) }}">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-xs font-black uppercase tracking-[0.2em] text-[var(--sf-muted)]">
                {{ __($isManualQris ? 'storefront.invoice.qris.eyebrow.manual' : ($isHostedPakasir ? 'storefront.invoice.qris.eyebrow.hosted' : 'storefront.invoice.qris.eyebrow.default'), [], $storefrontLocale) }}
            </p>
            <h2 class="mt-1 font-display text-2xl font-black text-[var(--sf-ink)]">
                {{ __($isManualQris ? 'storefront.invoice.qris.title.manual' : ($isHostedPakasir ? 'storefront.invoice.qris.title.hosted' : 'storefront.invoice.qris.title.default'), [], $storefrontLocale) }}
            </h2>
            <p class="mt-2 text-sm font-semibold leading-6 text-[var(--sf-muted)]">
                {{ __($isManualQris
                    ? 'storefront.invoice.qris.description.manual'
                    : ($isHostedPakasir
                        ? 'storefront.invoice.qris.description.hosted'
                        : ($isQrisPayable
                            ? 'storefront.invoice.qris.description.payable'
                            : 'storefront.invoice.qris.description.not_ready')), [], $storefrontLocale) }}
            </p>
        </div>

        <span class="inline-flex w-fit rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-1 text-xs font-black uppercase tracking-[0.16em] text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">
            {{ $isPaid
                ? __('storefront.invoice.qris.badge.paid', [], $storefrontLocale)
                : ($isQrisPayable ? __('storefront.invoice.qris.badge.pending', [], $storefrontLocale) : $qrisReadinessTitle) }}
        </span>
    </div>

    <dl class="mt-5 space-y-3 text-sm font-bold">
        <div class="flex items-center justify-between gap-4">
            <dt class="text-[var(--sf-muted)]">{{ __('storefront.invoice.qris.label.product_total', [], $storefrontLocale) }}</dt>
            <dd class="text-right font-black text-[var(--sf-ink)]">{{ $storefront->idr($order->total_amount) }}</dd>
        </div>
        <div class="flex items-center justify-between gap-4">
            <dt class="text-[var(--sf-muted)]">
                {{ $isManualQris
                    ? __('storefront.invoice.qris.label.payment_fee', [], $storefrontLocale)
                    : ($customerPaysQrisFee
                        ? __('storefront.invoice.qris.label.qris_fee_customer', [], $storefrontLocale)
                        : __('storefront.invoice.qris.label.qris_fee_store', [], $storefrontLocale)) }}
            </dt>
            <dd class="text-right font-black text-[var(--sf-ink)]">{{ $payment?->provider_fee !== null ? $storefront->idr($payment->provider_fee) : '-' }}</dd>
        </div>
        <div class="flex items-center justify-between gap-4 border-t-[3px] border-[var(--sf-border)] pt-4 text-lg">
            <dt class="font-black text-[var(--sf-ink)]">{{ $isManualQris ? __('storefront.invoice.qris.label.manual_total', [], $storefrontLocale) : __('storefront.invoice.qris.label.qris_total', [], $storefrontLocale) }}</dt>
            <dd class="text-right font-display text-2xl font-black text-[var(--sf-accent)]">{{ $isManualQris ? $storefront->idr($order->total_amount) : ($isQrisPayable && $payableAmount !== null ? ($payableAmountDisplay ?? $storefront->idr($payableAmount)) : __('storefront.invoice.qris.label.waiting_provider', [], $storefrontLocale)) }}</dd>
        </div>
        <div class="flex items-center justify-between gap-4">
            <dt class="text-[var(--sf-muted)]">{{ __('storefront.invoice.qris.label.deadline', [], $storefrontLocale) }}</dt>
            <dd class="text-right font-black text-[var(--sf-ink)]">{{ $paymentDeadlineLabel ?? '-' }}</dd>
        </div>
    </dl>

    @if ($isPendingPayment && $paymentDeadline)
        @include('storefront.partials.shop.payment-countdown')
    @endif

    @if (! $isQrisPayable && ! $isManualQris && ! $isHostedPakasir)
        <p class="mt-4 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] p-3 text-sm font-black leading-6 text-[var(--sf-ink)] shadow-[4px_4px_0_var(--sf-shadow)]">
            {{ $qrisReadinessTitle }}. {{ $qrisReadinessMessage }}
        </p>
    @endif

    @if ($isManualQris)
        <div class="payment-qris-stage relative mt-6 overflow-hidden rounded-xl border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-5 shadow-[6px_6px_0_var(--sf-shadow)]">
            <div class="payment-qris-poster mx-auto max-w-sm">
                <div class="qris-frame relative mx-auto max-w-[300px] overflow-hidden rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-3 shadow-[7px_7px_0_var(--sf-shadow)]">
                    <img src="{{ asset('qr-lhie.jpg') }}" alt="{{ __('storefront.invoice.qris.alt.manual', ['invoice' => $order->invoice_number], $storefrontLocale) }}" class="relative z-10 aspect-square w-full object-contain">
                </div>
            </div>
        </div>
    @elseif ($hasNativeQris)
        <div class="payment-qris-stage relative mt-6 overflow-hidden rounded-xl border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-5 shadow-[6px_6px_0_var(--sf-shadow)]">
            <div class="payment-qris-poster mx-auto max-w-sm">
                <div class="qris-frame relative mx-auto max-w-[300px] overflow-hidden rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-3 shadow-[7px_7px_0_var(--sf-shadow)]">
                    @if ($qrisImageSource !== null)
                        <div class="relative z-10 aspect-square w-full overflow-hidden" data-native-qris-crop="top-square">
                            <img src="{{ $qrisImageSource }}"
                                 alt="{{ __('storefront.invoice.qris.alt.native', ['invoice' => $order->invoice_number], $storefrontLocale) }}"
                                 class="block h-auto w-full max-w-none"
                                 data-qris-image
                                 data-qris-fallback-src="{{ $qrisImageFallbackUrl ?? '' }}">
                        </div>
                    @else
                        <img src="{{ $qrisImageDataUri }}" alt="{{ __('storefront.invoice.qris.alt.native', ['invoice' => $order->invoice_number], $storefrontLocale) }}" class="relative z-10 aspect-square w-full object-contain">
                    @endif
                    <div class="qris-scan-line" aria-hidden="true"></div>
                </div>
                <div class="payment-qris-corners" aria-hidden="true"></div>
            </div>
        </div>
    @elseif ($isHostedPakasir && $isPendingPayment && filled($hostedPaymentUrl))
        <a href="{{ $hostedPaymentUrl }}" target="_blank" rel="noreferrer" class="mt-5 inline-flex min-h-12 w-full items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-accent)] px-4 py-3 text-sm font-black text-white shadow-[4px_4px_0_var(--sf-shadow)] transition hover:-translate-y-0.5 hover:shadow-[6px_6px_0_var(--sf-shadow)] sm:w-auto">{{ __('storefront.invoice.qris.button.pakasir', [], $storefrontLocale) }}</a>
    @elseif ($isPendingPayment && ! $isManualQris)
        <p class="mt-5 rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-3 text-sm font-bold leading-6 text-[var(--sf-muted)] shadow-[4px_4px_0_var(--sf-shadow)]">
            {{ $qrisReadinessMessage }}
        </p>
    @endif

    @if ($isPendingPayment && ! $isManualQris)
        <div class="mt-5 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4 text-sm font-bold leading-6 text-[var(--sf-muted)] shadow-[4px_4px_0_var(--sf-shadow)]"
             data-shop-payment-status
             data-payment-kind="qris"
             data-status-url="{{ $statusUrl }}"
             data-redirect-url="{{ $redirectUrl }}"
             data-terminal="{{ $isTerminal ? 'true' : 'false' }}"
             data-payment-deadline="{{ $paymentDeadlineTimestamp ?? '' }}"
             data-poll-ms="{{ $paymentStatusPollIntervalMs ?? 20000 }}"
             data-poll-max-attempts="{{ $paymentStatusPollMaxAttempts ?? 9 }}"
             data-poll-max-duration-ms="{{ $paymentStatusPollMaxDurationMs ?? 180000 }}"
             data-status-copy='@json($statusCopy)'>
            <p class="font-black text-[var(--sf-ink)]" data-payment-status-title>{{ $isQrisPayable ? $qrisRetryLabel : $qrisReadinessTitle }}</p>
            <p data-payment-status-message>{{ $isQrisPayable ? __('storefront.invoice.qris.status.after_payment', [], $storefrontLocale) : $qrisReadinessMessage }}</p>
            <p class="sr-only" aria-live="polite" data-payment-status-announcement></p>
            <button type="button" class="mt-4 inline-flex w-full items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-accent)] px-4 py-3 text-sm font-black text-white shadow-[4px_4px_0_var(--sf-shadow)] transition hover:-translate-y-0.5 hover:shadow-[6px_6px_0_var(--sf-shadow)] disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto" data-payment-status-check>
                {{ $qrisRetryLabel }}
            </button>
            <div class="mt-4 {{ $isQrisPayable ? 'hidden' : 'flex' }} flex-wrap gap-3" data-payment-status-followup>
                <a href="{{ $homeUrl }}" class="neo-button inline-flex min-h-11 items-center justify-center bg-[var(--sf-accent)] px-4 text-sm font-black text-white">{{ __('storefront.invoice.qris.button.shop_again', [], $storefrontLocale) }}</a>
                <a href="#invoice-support" class="inline-flex min-h-11 items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-white px-4 text-sm font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">{{ __('storefront.invoice.qris.button.help', [], $storefrontLocale) }}</a>
            </div>
        </div>
        @if ($canCancel)
            <form method="POST" action="{{ $cancelUrl }}" class="mt-5 rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-4 text-sm font-bold leading-6 text-[var(--sf-muted)] shadow-[4px_4px_0_var(--sf-shadow)]" onsubmit="return confirm(@js(__('storefront.invoice.qris.cancel.confirm', [], $storefrontLocale)));">
                @csrf
                <p class="font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.qris.cancel.not_paid', [], $storefrontLocale) }}</p>
                <p>{{ __('storefront.invoice.qris.cancel.description', [], $storefrontLocale) }}</p>
                <button type="submit" class="mt-4 inline-flex w-full items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[#ffd9df] px-4 py-3 text-sm font-black text-[var(--sf-ink)] shadow-[4px_4px_0_var(--sf-shadow)] transition hover:-translate-y-0.5 hover:shadow-[6px_6px_0_var(--sf-shadow)] sm:w-auto">
                    {{ __('storefront.invoice.qris.cancel.button', [], $storefrontLocale) }}
                </button>
            </form>
        @endif
    @elseif (! $isPaid)
        <div class="mt-5 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4 text-sm font-bold leading-6 text-[var(--sf-muted)] shadow-[4px_4px_0_var(--sf-shadow)]" data-terminal="true" role="status" aria-live="polite">
            <p class="font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.qris.terminal.title', [], $storefrontLocale) }}</p>
            <p>{{ $statusMessage }}</p>
            <div class="mt-4 flex flex-wrap gap-3">
                <a href="{{ $homeUrl }}" class="neo-button inline-flex min-h-11 items-center justify-center bg-[var(--sf-accent)] px-4 text-sm font-black text-white">{{ __('storefront.invoice.qris.button.shop_again', [], $storefrontLocale) }}</a>
                <a href="#invoice-support" class="inline-flex min-h-11 items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-white px-4 text-sm font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">{{ __('storefront.invoice.qris.button.help', [], $storefrontLocale) }}</a>
            </div>
        </div>
    @endif
</x-storefront.panel>
