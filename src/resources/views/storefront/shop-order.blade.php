<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head', ['metadata' => $metadata])
</head>
<body class="storefront-invoice-page overflow-x-hidden antialiased selection:bg-[var(--sf-ink)] selection:text-white relative text-[var(--sf-ink)]" data-credential-copy-failed="{{ __('storefront_funnel.copy_failed') }}">
    <div class="storefront-shell relative min-h-screen z-10 flex flex-col justify-between overflow-x-clip">
        @include('storefront.partials.header')

        <main class="invoice-main flex-grow">
            <section class="mx-auto min-w-0 max-w-6xl px-4 pb-2 sm:px-6">
                @php
                    $orderBackUrl = $storefront->catalogUrl();
                    $orderBackLabel = __('storefront_funnel.back_catalog');
                    if (request()->routeIs('customer.orders.show')) {
                        $orderBackUrl = route('customer.orders.index');
                        $orderBackLabel = __('storefront_funnel.my_orders_back');
                    } elseif (auth('web')->check() && \Illuminate\Support\Facades\Gate::forUser(auth('web')->user())->allows('view', $order)) {
                        $orderBackUrl = route('admin.dashboard', ['tab' => 'penjualan']);
                        $orderBackLabel = __('storefront_funnel.admin_orders_back');
                    } elseif (auth('customer')->check() && (string) auth('customer')->id() === (string) $order->customer_id) {
                        $orderBackUrl = route('customer.orders.index');
                        $orderBackLabel = __('storefront_funnel.my_orders_back');
                    }
                    $hasManualUnlimited = $order->orderItems->contains(fn ($item) => $item->manual_unlimited);
                    $manualSupport = $hasManualUnlimited
                        ? $storefront->support()->whatsappOption(__('storefront_funnel.manual_order_message', ['invoice' => $order->invoice_number]))
                        : null;
                @endphp
                <a href="{{ $orderBackUrl }}" data-order-back-link class="inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-[var(--sf-ink)] hover:text-[var(--sf-accent)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--sf-accent)]">
                    <span aria-hidden="true">&larr;</span> {{ $orderBackLabel }}
                </a>

                @php
                    $finalBadge = __('storefront_funnel.final_status');
                    $qrisHeroCopy = [
                        'deadline' => [__('storefront_funnel.payment_deadline_passed'), __('storefront_funnel.expired_badge'), __('storefront_funnel.qris_expired_summary')],
                        'paid' => [__('storefront_funnel.payment_verified'), __('storefront_funnel.verified'), __('storefront_funnel.paid_summary')],
                        'terminal' => [__('storefront_funnel.order_inactive'), $finalBadge, __('storefront_funnel.terminal_summary')],
                        'cancelled' => [__('storefront_funnel.order_cancelled'), $finalBadge, __('storefront_funnel.cancelled_summary')],
                        'expired' => [__('storefront_funnel.order_expired'), $finalBadge, __(strtolower((string) $payment?->provider) === 'sakurupiah' ? 'storefront_funnel.expired_qris_summary' : 'storefront_funnel.expired_summary')],
                    ];
                    $pendingBadge = __('storefront_funnel.pending_badge');
                    [$headline, $badge, $summary] = match (true) {
                        $isPaid => $qrisHeroCopy['paid'],
                        $isTerminal => $qrisHeroCopy[$order->order_status?->value] ?? $qrisHeroCopy['terminal'],
                        $isBscPayment => [__('storefront_funnel.bsc_order_created'), $pendingBadge, __('storefront_funnel.bsc_summary')],
                        $isBinancePay => [__('storefront_funnel.binance_order_created'), $pendingBadge, __('storefront_funnel.binance_summary')],
                        $qrisExpired => $qrisHeroCopy['deadline'],
                        $isManualQris || $isQrisPayable || $isHostedQris => [__('storefront_funnel.qris_invoice'), $pendingBadge, __('storefront_funnel.pending_summary')],
                        default => [$qrisReadinessTitle, $pendingBadge, __('storefront_funnel.qris_unready_summary')],
                    };
                @endphp

                <div class="invoice-order-hero mx-auto flex min-w-0 max-w-6xl flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" data-invoice-state="{{ $isPaid ? 'paid' : ($isPendingPayment ? 'pending' : 'terminal') }}"
                    @if ($isPendingPayment && ! $isBscPayment && ! $isBinancePay)
                        data-qris-hero-copy='@json($qrisHeroCopy)'
                    @endif>
                    <div class="min-w-0">
                        <p class="invoice-order-label text-sm font-semibold">{{ __('storefront_funnel.web_order') }}</p>
                        <h1 data-invoice-headline class="mt-1 text-balance font-display text-2xl font-black leading-tight text-[var(--sf-ink)] sm:text-3xl">{{ $headline }}</h1>
                        <p class="mt-1 text-sm font-semibold leading-6 text-[var(--sf-muted)]">
                            {{ __('storefront_funnel.invoice') }} <span class="break-all font-mono font-bold text-[var(--sf-ink)]">{{ $order->invoice_number }}</span> <span data-invoice-summary>{{ $summary }}</span>
                        </p>
                    </div>
                    <span data-invoice-badge class="invoice-status inline-flex w-fit shrink-0 items-center rounded-lg px-3 py-2 text-sm font-bold">
                        {{ $badge }}
                    </span>
                </div>

                @if (session('status'))
                    <x-storefront.notice tone="success" class="mt-6" role="status">{{ session('status') }}</x-storefront.notice>
                @endif
                @if ($errors->has('order'))
                    <x-storefront.notice tone="danger" class="mt-6" role="alert">{{ $errors->first('order') }}</x-storefront.notice>
                @endif

                @if ($isPendingPayment)
                    @if ($isBscPayment)
                        @include('storefront.partials.shop.bsc-payment')
                    @elseif ($isBinancePay)
                        @include('storefront.partials.shop.binance-payment')
                    @else
                        @include('storefront.partials.shop.qris-payment')
                    @endif

                    <details id="invoice-support" class="invoice-followup-details mt-4 overflow-hidden rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] shadow-[5px_5px_0_var(--sf-shadow)]" data-invoice-followup-details>
                        <summary class="flex min-h-12 cursor-pointer items-center justify-between gap-3 px-4 py-3 text-sm font-black text-[var(--sf-ink)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)] focus:ring-inset">
                            <span class="inline-flex items-center gap-2"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18h.01M9.5 9a2.5 2.5 0 1 1 4.2 1.84c-.95.74-1.7 1.3-1.7 2.66M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>{{ __('storefront_funnel.invoice_info') }}</span>
                            <span aria-hidden="true" class="text-lg">+</span>
                        </summary>
                        <div class="grid gap-4 border-t-[3px] border-[var(--sf-border)] p-3 sm:p-4">
                            <div class="rounded-lg border-2 border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-3 text-sm font-bold leading-6 text-[var(--sf-muted)]">
                                @if ($hasManualUnlimited)
                                    <p class="font-black text-[var(--sf-ink)]">{{ __('storefront_funnel.manual_after_payment') }}</p>
                                    <p>{{ __('storefront_funnel.manual_after_payment_help') }}</p>
                                @else
                                    <p class="font-black text-[var(--sf-ink)]">{{ __('storefront_funnel.account_safe') }}</p>
                                    <p>{{ __('storefront_funnel.save_invoice_link') }}</p>
                                @endif
                            </div>
                            @if ($canCancel && $isPendingPayment)
                                <form method="POST" action="{{ $cancelUrl }}" class="rounded-lg border-2 border-[var(--sf-border)] bg-white p-3" onsubmit="return confirm(@js(__('storefront.invoice.qris.cancel.confirm', [], $storefrontLocale)));">
                                    @csrf
                                    <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-lg border-2 border-[var(--sf-border)] bg-[#ffd9df] px-4 py-2 text-sm font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.qris.cancel.button', [], $storefrontLocale) }}</button>
                                </form>
                            @endif
                            @if ($accessKey)
                                @include('storefront.partials.shop.recovery-key')
                            @endif
                            @include('storefront.partials.support-chooser', [
                                'supportContext' => $supportContext,
                                'supportId' => 'invoice-support-content',
                                'supportClass' => 'mt-0',
                            ])
                            @include('storefront.partials.shop.order-summary')
                        </div>
                    </details>
                @elseif ($isPaid)
                    <div class="invoice-paid-workspace">
                        <div class="invoice-paid-primary">
                            @if ($hasManualUnlimited)
                                <section class="invoice-delivery-panel mt-5 rounded-lg border-2 border-[var(--sf-border)] bg-[var(--sf-surface)] p-4 text-sm leading-6 text-[var(--sf-ink)]">
                                    <h2 class="font-display text-xl font-bold">{{ __('storefront_funnel.manual_products') }}</h2>
                                    <p class="mt-2">{{ __('storefront_funnel.manual_paid_help') }}</p>
                                    @if ($manualSupport['available'])
                                        <a href="{{ $manualSupport['href'] }}" class="credential-action mt-3">{{ __('storefront_funnel.contact_whatsapp') }}</a>
                                    @else
                                        <p class="mt-2">{{ __('storefront_funnel.manual_whatsapp_unavailable') }}</p>
                                    @endif
                                </section>
                            @endif
                            @include('storefront.partials.shop.account-delivery')
                            @include('storefront.partials.support-chooser', [
                                'supportContext' => $supportContext,
                                'supportId' => 'invoice-support',
                                'supportClass' => 'mt-0',
                            ])
                        </div>
                        <div class="invoice-paid-sidebar">
                            @include('storefront.partials.shop.order-summary')
                            @if ($accessKey)
                                <div class="invoice-recovery-section">
                                    @include('storefront.partials.shop.recovery-key')
                                </div>
                            @endif
                        </div>
                    </div>
                @else
                    <x-storefront.panel as="section" class="mt-6" aria-label="{{ __('storefront_funnel.final_invoice_status') }}" role="status" aria-live="polite">
                        <p class="text-xs font-black uppercase tracking-[0.2em] text-[var(--sf-muted)]">{{ __('storefront_funnel.final_status') }}</p>
                        <h2 class="mt-2 font-display text-2xl font-black text-[var(--sf-ink)]">{{ __('storefront_funnel.invoice_not_payable') }}</h2>
                        <p class="mt-3 text-sm font-bold leading-6 text-[var(--sf-muted)]">{{ $statusMessage }}</p>
                        <div class="mt-5 flex flex-wrap gap-3">
                            <a href="{{ $homeUrl }}" class="neo-button inline-flex min-h-11 items-center justify-center bg-[var(--sf-accent)] px-4 text-sm font-black text-white">{{ __('storefront_funnel.new_order') }}</a>
                            <a href="#invoice-support" class="inline-flex min-h-11 items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-white px-4 text-sm font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">{{ __('storefront_funnel.need_help') }}</a>
                        </div>
                    </x-storefront.panel>
                    @include('storefront.partials.support-chooser', [
                        'supportContext' => $supportContext,
                        'supportId' => 'invoice-support',
                    ])
                    @if ($accessKey)
                        @include('storefront.partials.shop.recovery-key')
                    @endif
                    @include('storefront.partials.shop.account-delivery')
                    @include('storefront.partials.shop.order-summary')
                @endif
            </section>
        </main>

        <footer class="invoice-footer">
            <div class="mx-auto flex max-w-6xl flex-col gap-2 px-4 sm:px-6 md:flex-row md:items-center md:justify-between">
                <p class="text-xs font-semibold text-[var(--sf-muted)]">&copy; {{ now()->year }} {{ __('storefront_funnel.copyright') }}</p>
                <nav class="flex flex-wrap gap-x-4" aria-label="{{ __('storefront_funnel.invoice_links') }}">
                    <a href="{{ route('storefront.order-access.create') }}">{{ __('storefront_funnel.order_access') }}</a>
                    <a href="{{ route('storefront.guides.index') }}">{{ __('storefront_funnel.guides') }}</a>
                    <a href="{{ route('storefront.policies.privacy') }}">{{ __('storefront_funnel.privacy') }}</a>
                    <a href="{{ route('storefront.policies.terms') }}">{{ __('storefront_funnel.terms') }}</a>
                    <a href="{{ route('storefront.policies.refund') }}">{{ __('storefront_funnel.refund') }}</a>
                </nav>
            </div>
        </footer>
    </div>
</body>
</html>
