@php
    $storefrontLocale = $storefrontLocale ?? 'id';
    $statusCopy = $paymentStatusCopy['qris'] ?? [];
    $hasNativeQris = $isPendingPayment && $isQrisPayable && filled($qrisImageSource ?? $qrisImageDataUri);
    $isManualQris = $isManualQris ?? false;
    $isHostedPakasir = $isHostedPakasir ?? false;
    $isSakurupiah = $isSakurupiah ?? strtolower((string) $payment?->provider) === 'sakurupiah';
    $isHostedSakurupiah = $isHostedSakurupiah ?? ($isSakurupiah && ! $hasNativeQris && filled($hostedPaymentUrl));
    $paymentDeadlineLabel = $paymentDeadline?->copy()->timezone((string) config('app.timezone'))->format('d M Y H:i T');
    $customerPaysQrisFee = isset($qrisFeePayer)
        ? $qrisFeePayer === 'customer'
        : (! $isSakurupiah && config('services.pakasir.qris_fee.payer', 'customer') === 'customer');
@endphp

<x-storefront.panel as="section" padding="none" class="invoice-payment-panel mt-5 overflow-hidden" aria-label="{{ __('storefront.invoice.qris.aria_label', [], $storefrontLocale) }}">
    <div class="invoice-payment-grid grid lg:grid-cols-2">
        <div class="invoice-payment-visual min-w-0 bg-[var(--sf-surface-warm)] p-4 sm:p-6">
            @if ($isPendingPayment && $paymentDeadline)
                @include('storefront.partials.shop.payment-countdown')
            @endif

            @if ($isManualQris)
                <div class="payment-qris-stage relative mt-4 overflow-hidden rounded-xl border-[3px] border-[var(--sf-border)] bg-white p-3 shadow-[5px_5px_0_var(--sf-shadow)]">
                    <div class="payment-qris-poster mx-auto max-w-sm">
                        <div class="qris-frame relative mx-auto max-w-[360px] overflow-hidden rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-3 shadow-[5px_5px_0_var(--sf-shadow)]">
                            <img src="{{ asset('qr-lhie.jpg') }}" alt="{{ __('storefront.invoice.qris.alt.native', ['invoice' => $order->invoice_number], $storefrontLocale) }}" class="relative z-10 aspect-square w-full object-contain">
                        </div>
                    </div>
                </div>
            @elseif ($hasNativeQris)
                <div class="payment-qris-stage relative mt-4 overflow-hidden rounded-xl border-[3px] border-[var(--sf-border)] bg-white p-3 shadow-[5px_5px_0_var(--sf-shadow)]">
                    <div class="payment-qris-poster mx-auto max-w-sm">
                        <div class="qris-frame relative mx-auto max-w-[360px] overflow-hidden rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-3 shadow-[5px_5px_0_var(--sf-shadow)]">
                            @if ($qrisImageSource !== null)
                                <div class="relative z-10 aspect-square w-full overflow-hidden" data-native-qris-crop="top-square">
                                    <img src="{{ $qrisImageSource }}" alt="{{ __('storefront.invoice.qris.alt.native', ['invoice' => $order->invoice_number], $storefrontLocale) }}" class="block h-auto w-full max-w-none" data-qris-image data-qris-fallback-src="{{ $qrisImageFallbackUrl ?? '' }}">
                                </div>
                            @else
                                <img src="{{ $qrisImageDataUri }}" alt="{{ __('storefront.invoice.qris.alt.native', ['invoice' => $order->invoice_number], $storefrontLocale) }}" class="relative z-10 aspect-square w-full object-contain">
                            @endif
                            <div class="qris-scan-line" aria-hidden="true"></div>
                        </div>
                        <div class="payment-qris-corners" aria-hidden="true"></div>
                    </div>
                </div>
            @elseif (($isHostedPakasir || $isHostedSakurupiah) && $isPendingPayment && filled($hostedPaymentUrl))
                <div class="invoice-payment-placeholder mt-4 grid min-h-64 place-items-center rounded-xl border-[3px] border-[var(--sf-border)] bg-white p-5 text-center shadow-[5px_5px_0_var(--sf-shadow)]">
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.18em] text-[var(--sf-accent)]">Pembayaran QRIS</p>
                        <p class="mt-2 font-display text-xl font-black text-[var(--sf-ink)]">Lanjut ke halaman pembayaran</p>
                        <a href="{{ $hostedPaymentUrl }}" target="_blank" rel="noreferrer" class="neo-button mt-4 inline-flex min-h-11 items-center justify-center bg-[var(--sf-accent)] px-4 text-sm font-black text-white">{{ __('storefront.invoice.qris.button.pakasir', [], $storefrontLocale) }}</a>
                    </div>
                </div>
            @else
                <div class="invoice-payment-placeholder mt-4 grid min-h-64 place-items-center rounded-xl border-[3px] border-[var(--sf-border)] bg-white p-5 text-center shadow-[5px_5px_0_var(--sf-shadow)]">
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.18em] text-[var(--sf-accent)]">QRIS</p>
                        <p class="mt-2 font-display text-xl font-black text-[var(--sf-ink)]">{{ $qrisReadinessTitle }}</p>
                        <p class="mt-2 text-sm font-bold leading-6 text-[var(--sf-muted)]">{{ $qrisReadinessMessage }}</p>
                    </div>
                </div>
            @endif
        </div>

        <div class="invoice-payment-details min-w-0 p-4 sm:p-6">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-xs font-black uppercase tracking-[0.18em] text-[var(--sf-accent)]">Pembayaran QRIS</p>
                    <h2 class="mt-1 font-display text-2xl font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.qris.title.default', [], $storefrontLocale) }}</h2>
                    <p class="mt-2 text-sm font-semibold leading-6 text-[var(--sf-muted)]">
                        {{ $isManualQris
                            ? 'Bayar dengan QRIS pada invoice ini, lalu hubungi admin dengan nomor invoice setelah pembayaran.'
                            : __($isHostedPakasir
                                ? 'storefront.invoice.qris.description.hosted'
                                : ($isSakurupiah
                                    ? 'storefront.invoice.qris.description.sakurupiah'
                                    : ($isQrisPayable
                                        ? 'storefront.invoice.qris.description.payable'
                                        : 'storefront.invoice.qris.description.not_ready')), [], $storefrontLocale) }}
                    </p>
                </div>
                <span class="inline-flex shrink-0 rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-1 text-xs font-black uppercase tracking-[0.14em] text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">
                    {{ $isPaid ? __('storefront.invoice.qris.badge.paid', [], $storefrontLocale) : ($isPendingPayment ? __('storefront.invoice.qris.badge.pending', [], $storefrontLocale) : $qrisReadinessTitle) }}
                </span>
            </div>

            <dl class="mt-5 grid gap-2 text-sm font-bold">
                <div class="flex min-w-0 items-start justify-between gap-4 border-b border-[var(--sf-border)] pb-3">
                    <dt class="text-[var(--sf-muted)]">{{ __('storefront.invoice.shop.products', [], $storefrontLocale) }}</dt>
                    <dd class="max-w-[65%] text-right font-black text-[var(--sf-ink)]">{{ $order->orderItems->pluck('product_name')->join(', ') }}</dd>
                </div>
                <div class="flex items-center justify-between gap-4 border-b border-[var(--sf-border)] pb-3">
                    <dt class="text-[var(--sf-muted)]">{{ __('storefront.invoice.qris.label.product_total', [], $storefrontLocale) }}</dt>
                    <dd class="text-right font-black text-[var(--sf-ink)]">{{ $storefront->idr($order->total_amount) }}</dd>
                </div>
                <div class="flex items-center justify-between gap-4 border-b border-[var(--sf-border)] pb-3">
                    <dt class="text-[var(--sf-muted)]">Metode</dt>
                    <dd class="text-right font-black text-[var(--sf-ink)]">QRIS</dd>
                </div>
                <div class="flex items-center justify-between gap-4 border-b border-[var(--sf-border)] pb-3">
                    <dt class="text-[var(--sf-muted)]">{{ $isManualQris ? __('storefront.invoice.qris.label.payment_fee', [], $storefrontLocale) : ($customerPaysQrisFee ? __('storefront.invoice.qris.label.qris_fee_customer', [], $storefrontLocale) : __('storefront.invoice.qris.label.qris_fee_store', [], $storefrontLocale)) }}</dt>
                    <dd class="text-right font-black text-[var(--sf-ink)]">{{ $payment?->provider_fee !== null ? $storefront->idr($payment->provider_fee) : '-' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-4 border-b-[3px] border-[var(--sf-border)] pb-3 text-lg">
                    <dt class="font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.qris.label.qris_total', [], $storefrontLocale) }}</dt>
                    <dd class="text-right font-display text-2xl font-black text-[var(--sf-accent)]">{{ $isManualQris ? $storefront->idr($order->total_amount) : ($isQrisPayable && $payableAmount !== null ? ($payableAmountDisplay ?? $storefront->idr($payableAmount)) : __('storefront.invoice.qris.label.waiting_provider', [], $storefrontLocale)) }}</dd>
                </div>
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-[var(--sf-muted)]">{{ __('storefront.invoice.qris.label.deadline', [], $storefrontLocale) }}</dt>
                    <dd class="text-right font-black text-[var(--sf-ink)]">{{ $paymentDeadlineLabel ?? '-' }}</dd>
                </div>
            </dl>


            @if ($isPendingPayment && ! $isManualQris)
                <div class="mt-4 rounded-lg border-2 border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-3 text-sm font-bold leading-6 text-[var(--sf-muted)]"
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
                    <button type="button" class="neo-button mt-3 inline-flex min-h-10 items-center justify-center bg-[var(--sf-accent)] px-4 text-sm font-black text-white" data-payment-status-check>{{ $qrisRetryLabel }}</button>
                    <div class="mt-3 {{ $isQrisPayable ? 'hidden' : 'flex' }} flex-wrap gap-3" data-payment-status-followup>
                        <a href="{{ $homeUrl }}" class="neo-button inline-flex min-h-10 items-center justify-center bg-[var(--sf-accent)] px-4 text-sm font-black text-white">{{ __('storefront.invoice.qris.button.shop_again', [], $storefrontLocale) }}</a>
                        <a href="#invoice-support" class="inline-flex min-h-10 items-center justify-center rounded-lg border-2 border-[var(--sf-border)] bg-white px-4 text-sm font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.qris.button.help', [], $storefrontLocale) }}</a>
                    </div>
                </div>
            @elseif ($isPendingPayment && $isManualQris)
                <div class="mt-4 rounded-lg border-2 border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-3 text-sm font-bold leading-6 text-[var(--sf-muted)]">
                    <p class="font-black text-[var(--sf-ink)]">Pembayaran QRIS belum terdeteksi</p>
                    <p>Setelah membayar, hubungi admin dengan nomor invoice ini.</p>
                </div>
            @elseif (! $isPaid)
                <div class="mt-4 rounded-lg border-2 border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-3 text-sm font-bold leading-6 text-[var(--sf-muted)]" data-terminal="true" role="status" aria-live="polite">
                    <p class="font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.qris.terminal.title', [], $storefrontLocale) }}</p>
                    <p>{{ $statusMessage }}</p>
                </div>
            @endif

        </div>
    </div>
</x-storefront.panel>
