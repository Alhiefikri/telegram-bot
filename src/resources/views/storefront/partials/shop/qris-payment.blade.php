@php
    $storefrontLocale = $storefrontLocale ?? 'id';
    $statusCopy = $paymentStatusCopy['qris'] ?? [];
    $hasNativeQris = $isQrisPayable && ! $qrisExpired && filled($qrisImageSource ?? $qrisImageDataUri);
    $showQris = ! $qrisExpired && ($isManualQris || $hasNativeQris);
    $paymentDeadlineLabel = $qrisDeadline?->copy()->timezone((string) config('app.timezone'))->format('d M Y H:i T');
@endphp

<x-storefront.panel as="section" padding="none" class="invoice-qris-panel mt-3" aria-label="{{ __('storefront.invoice.qris.aria_label', [], $storefrontLocale) }}">
    <div class="invoice-qris-layout">
        <div class="invoice-qris-main">
            <div class="invoice-qris-total">
                <span>{{ __('storefront.invoice.qris.label.qris_total', [], $storefrontLocale) }}</span>
                <strong>{{ $qrisExpired ? 'Tidak dapat dibayar' : ($qrisTotal ?? __('storefront.invoice.qris.label.waiting_provider', [], $storefrontLocale)) }}</strong>
            </div>

            @if ($showQris)
                <div class="invoice-qris-image" data-invoice-qris>
                    <span class="invoice-qris-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h2v2h-2zM18 14h2v6h-2M14 18h2v2h-2z" /></svg> QRIS</span>
                    <div class="invoice-qris-frame">
                        @if ($isManualQris)
                            <img src="{{ asset('qr-lhie.jpg') }}" alt="{{ __('storefront.invoice.qris.alt.manual', ['invoice' => $order->invoice_number], $storefrontLocale) }}">
                        @elseif ($qrisImageSource !== null)
                            <div class="invoice-qris-crop" data-native-qris-crop="top-square"><img src="{{ $qrisImageSource }}" alt="{{ __('storefront.invoice.qris.alt.native', ['invoice' => $order->invoice_number], $storefrontLocale) }}" data-qris-image data-qris-fallback-src="{{ $qrisImageFallbackUrl ?? '' }}"></div>
                        @else
                            <img src="{{ $qrisImageDataUri }}" alt="{{ __('storefront.invoice.qris.alt.native', ['invoice' => $order->invoice_number], $storefrontLocale) }}">
                        @endif
                    </div>
                </div>
            @elseif ($isHostedQris)
                <div class="invoice-qris-placeholder" data-invoice-qris>
                    <p>Buka halaman pembayaran untuk melihat QRIS dan total final.</p>
                    <a href="{{ $hostedPaymentUrl }}" target="_blank" rel="noopener noreferrer" class="neo-button inline-flex min-h-11 items-center justify-center bg-[var(--sf-accent)] px-4 text-sm font-black text-white">{{ __('storefront.invoice.qris.button.pakasir', [], $storefrontLocale) }}</a>
                </div>
            @else
                <div class="invoice-qris-placeholder" role="status">
                    <strong>{{ $qrisExpired ? 'Batas pembayaran terlewati' : $qrisReadinessTitle }}</strong>
                    <p>{{ $qrisExpired ? 'Jangan bayar QR lama. Jika sudah membayar, hubungi bantuan dengan nomor invoice dan bukti pembayaran.' : $qrisReadinessMessage }}</p>
                </div>
            @endif

            <div class="invoice-qris-deadline">
                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 2" /></svg> {{ __('storefront.invoice.qris.label.deadline', [], $storefrontLocale) }}</span>
                <time @if ($qrisDeadline) datetime="{{ $qrisDeadline->toIso8601String() }}" @endif>{{ $paymentDeadlineLabel ?? '-' }}</time>
            </div>
        </div>

        <div class="invoice-qris-next">
            @if ($showQris)
                <p data-invoice-qris-instruction>{{ $isManualQris ? 'Pindai QRIS dan bayar sesuai total di invoice. Kirim nomor invoice dan bukti bayar ke admin.' : 'Pindai QRIS dan bayar sesuai total di invoice. Setelah bayar, cek status di halaman ini.' }}</p>
                @if (! $isManualQris)
                    <p>Jangan bayar QR lama setelah kedaluwarsa atau dibatalkan. Jika sudah membayar, hubungi bantuan dengan nomor invoice dan bukti pembayaran.</p>
                @endif
                <p class="invoice-qris-expired-message" data-invoice-qris-expired hidden>Batas pembayaran terlewati. Jangan bayar QR lama. Jika sudah membayar, hubungi bantuan dengan nomor invoice dan bukti pembayaran.</p>
            @endif

            @if ($qrisDeadline)
                @include('storefront.partials.shop.payment-countdown', ['paymentDeadline' => $qrisDeadline, 'paymentDeadlineTimestamp' => $qrisDeadline->toIso8601String()])
            @endif

            @if (! $isManualQris)
                <div class="invoice-qris-status"
                     data-shop-payment-status
                     data-payment-kind="qris"
                     data-qris-waiting="{{ $qrisAutoReadinessPoll ? 'true' : 'false' }}"
                     data-status-url="{{ $statusUrl }}"
                     data-redirect-url="{{ $redirectUrl }}"
                     data-terminal="{{ $isTerminal ? 'true' : 'false' }}"
                     data-payment-deadline="{{ $paymentDeadlineTimestamp ?? '' }}"
                     data-poll-ms="{{ $paymentStatusPollIntervalMs ?? 20000 }}"
                     data-poll-max-attempts="{{ $paymentStatusPollMaxAttempts ?? 9 }}"
                     data-poll-max-duration-ms="{{ $paymentStatusPollMaxDurationMs ?? 180000 }}"
                     data-status-copy='@json($statusCopy)'>
                    <p class="font-black" data-payment-status-title>{{ $qrisExpired ? 'Batas pembayaran terlewati' : ($isQrisPayable ? $qrisRetryLabel : $qrisReadinessTitle) }}</p>
                    <p data-payment-status-message>{{ $qrisExpired ? 'Jika sudah membayar, jangan bayar lagi. Hubungi bantuan dengan nomor invoice dan bukti pembayaran.' : ($isQrisPayable ? __('storefront.invoice.qris.status.after_payment', [], $storefrontLocale) : $qrisReadinessMessage) }}</p>
                    <p class="sr-only" aria-live="polite" data-payment-status-announcement></p>
                    <button type="button" class="neo-button mt-3 inline-flex min-h-11 items-center justify-center bg-[var(--sf-accent)] px-4 text-sm font-black text-white" data-payment-status-check>{{ $qrisRetryLabel }}</button>
                    <div class="mt-3 {{ $isQrisPayable ? 'hidden' : 'flex' }} flex-wrap gap-3" data-payment-status-followup>
                        <a href="#invoice-support" class="inline-flex min-h-11 items-center justify-center rounded-lg border-2 border-[var(--sf-border)] bg-[var(--sf-surface)] px-4 text-sm font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.qris.button.help', [], $storefrontLocale) }}</a>
                    </div>
                </div>
            @else
                <a href="#invoice-support" class="invoice-qris-help">Hubungi admin untuk konfirmasi</a>
            @endif
        </div>
    </div>
</x-storefront.panel>
