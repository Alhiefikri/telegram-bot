<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head', ['metadata' => $metadata])
</head>
<body class="storefront-invoice-page overflow-x-hidden antialiased selection:bg-[var(--sf-ink)] selection:text-white relative text-[var(--sf-ink)]">
    <div class="storefront-shell relative min-h-screen z-10 flex flex-col justify-between overflow-x-clip">
        @include('storefront.partials.header')

        <main class="invoice-main flex-grow">
            <section class="mx-auto min-w-0 max-w-6xl px-4 pb-2 sm:px-6">
                @php
                    $orderBackUrl = $storefront->catalogUrl();
                    $orderBackLabel = 'Kembali ke katalog';
                    if (request()->routeIs('customer.orders.show')) {
                        $orderBackUrl = route('customer.orders.index');
                        $orderBackLabel = 'Kembali ke pesanan saya';
                    } elseif (auth('web')->check() && \Illuminate\Support\Facades\Gate::forUser(auth('web')->user())->allows('view', $order)) {
                        $orderBackUrl = route('admin.dashboard', ['tab' => 'penjualan']);
                        $orderBackLabel = 'Kembali ke pesanan admin';
                    } elseif (auth('customer')->check() && (string) auth('customer')->id() === (string) $order->customer_id) {
                        $orderBackUrl = route('customer.orders.index');
                        $orderBackLabel = 'Kembali ke pesanan saya';
                    }
                    $hasManualUnlimited = $order->orderItems->contains(fn ($item) => $item->manual_unlimited);
                    $manualSupport = $hasManualUnlimited
                        ? $storefront->support()->whatsappOption('Halo Admin Lhie Store, saya ingin mendapatkan akun atau kode redeem untuk invoice '.$order->invoice_number.'.')
                        : null;
                @endphp
                <a href="{{ $orderBackUrl }}" data-order-back-link class="inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-[var(--sf-ink)] hover:text-[var(--sf-accent)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--sf-accent)]">
                    <span aria-hidden="true">&larr;</span> {{ $orderBackLabel }}
                </a>

                @php
                    $headline = $isPaid ? 'Pembayaran terverifikasi' : ($isBscPayment ? 'Order USDT BEP20 dibuat' : ($isBinancePay ? 'Order Binance Pay dibuat' : ($qrisExpired ? 'Batas pembayaran terlewati' : (($isManualQris || $isQrisPayable || $isHostedQris) ? 'Invoice QRIS' : $qrisReadinessTitle))));
                    $summary = $isPaid
                        ? 'sudah lunas. Detail pesanan dan pengiriman tersedia di bawah.'
                        : ($isBscPayment ? 'sudah mencadangkan stok. Bayar tepat USDT melalui BNB Smart Chain (BEP20) sebelum masa order berakhir.' : ($isBinancePay ? 'sudah mencadangkan stok. Bayar USDT sesuai instruksi sebelum masa order berakhir.' : ($qrisExpired ? 'sudah melewati batas pembayaran. Jangan bayar QR lama. Jika sudah membayar, hubungi bantuan dengan bukti pembayaran.' : (($isManualQris || $isQrisPayable || $isHostedQris) ? 'menunggu pembayaran.' : 'QRIS belum siap dibayar. Cek status di bawah atau hubungi bantuan.'))));

                    if ($isTerminal && ! $isPaid) {
                        $headline = match ($order->order_status?->value) {
                            'cancelled' => 'Order dibatalkan',
                            'expired' => 'Order berakhir',
                            default => 'Order tidak aktif',
                        };
                        $summary = match ($order->order_status?->value) {
                            'cancelled' => 'sudah dibatalkan. Stok dan data akun sudah dilepas. Jangan bayar invoice ini.',
                            'expired' => strtolower((string) $payment?->provider) === 'sakurupiah'
                                ? 'sudah kedaluwarsa. Jangan bayar QR lama. Jika terlanjur bayar, hubungi bantuan dengan nomor invoice dan bukti pembayaran.'
                                : 'sudah kedaluwarsa. Buat order baru jika masih ingin membeli.',
                            default => 'sudah berada di status akhir. Jangan lakukan pembayaran untuk invoice ini.',
                        };
                    }
                @endphp

                <div class="invoice-order-hero mx-auto flex min-w-0 max-w-6xl flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" data-invoice-state="{{ $isPaid ? 'paid' : ($isPendingPayment ? 'pending' : 'terminal') }}">
                    <div class="min-w-0">
                        <p class="invoice-order-label text-sm font-semibold">Order website</p>
                        <h1 class="mt-1 text-balance font-display text-2xl font-black leading-tight text-[var(--sf-ink)] sm:text-3xl">{{ $headline }}</h1>
                        <p class="mt-1 text-sm font-semibold leading-6 text-[var(--sf-muted)]">
                            Invoice <span class="break-all font-mono font-bold text-[var(--sf-ink)]">{{ $order->invoice_number }}</span> {{ $summary }}
                        </p>
                    </div>
                    <span class="invoice-status inline-flex w-fit shrink-0 items-center rounded-lg px-3 py-2 text-sm font-bold">
                        {{ $isPaid ? 'Terverifikasi' : ($isPendingPayment ? (($qrisExpired && ! $isBscPayment && ! $isBinancePay) ? 'Batas bayar terlewati' : 'Menunggu bayar') : 'Status akhir') }}
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
                            <span class="inline-flex items-center gap-2"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18h.01M9.5 9a2.5 2.5 0 1 1 4.2 1.84c-.95.74-1.7 1.3-1.7 2.66M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>Info invoice, Recovery Key, dan bantuan</span>
                            <span aria-hidden="true" class="text-lg">+</span>
                        </summary>
                        <div class="grid gap-4 border-t-[3px] border-[var(--sf-border)] p-3 sm:p-4">
                            <div class="rounded-lg border-2 border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-3 text-sm font-bold leading-6 text-[var(--sf-muted)]">
                                @if ($hasManualUnlimited)
                                    <p class="font-black text-[var(--sf-ink)]">Produk manual diproses setelah pembayaran</p>
                                    <p>Setelah pembayaran terverifikasi, hubungi WhatsApp Support dengan nomor invoice untuk mendapatkan akun atau kode redeem produk manual.</p>
                                @else
                                    <p class="font-black text-[var(--sf-ink)]">Data akun aman sampai pembayaran masuk</p>
                                    <p>Setelah pembayaran terverifikasi, data akun muncul di halaman ini. Simpan link invoice ini sampai akun diterima.</p>
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
                                    <h2 class="font-display text-xl font-bold">Produk diproses manual</h2>
                                    <p class="mt-2">Hubungi WhatsApp Support dengan nomor invoice untuk mendapatkan akun atau kode redeem produk manual.</p>
                                    @if ($manualSupport['available'])
                                        <a href="{{ $manualSupport['href'] }}" class="credential-action mt-3">Hubungi WhatsApp Support</a>
                                    @else
                                        <p class="mt-2">Nomor WhatsApp Support belum dikonfigurasi.</p>
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
                    <x-storefront.panel as="section" class="mt-6" aria-label="Status akhir invoice" role="status" aria-live="polite">
                        <p class="text-xs font-black uppercase tracking-[0.2em] text-[var(--sf-muted)]">Status akhir</p>
                        <h2 class="mt-2 font-display text-2xl font-black text-[var(--sf-ink)]">Invoice ini tidak bisa dibayar lagi</h2>
                        <p class="mt-3 text-sm font-bold leading-6 text-[var(--sf-muted)]">{{ $statusMessage }}</p>
                        <div class="mt-5 flex flex-wrap gap-3">
                            <a href="{{ $homeUrl }}" class="neo-button inline-flex min-h-11 items-center justify-center bg-[var(--sf-accent)] px-4 text-sm font-black text-white">Buat order baru</a>
                            <a href="#invoice-support" class="inline-flex min-h-11 items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-white px-4 text-sm font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">Butuh bantuan?</a>
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
                <p class="text-xs font-semibold text-[var(--sf-muted)]">&copy; {{ now()->year }} {{ $copy['footer']['copyright_suffix'] }}</p>
                <nav class="flex flex-wrap gap-x-4" aria-label="Tautan invoice">
                    <a href="{{ route('storefront.order-access.create') }}">Akses pesanan</a>
                    <a href="{{ route('storefront.guides.index') }}">Panduan</a>
                    <a href="{{ route('storefront.policies.privacy') }}">Privasi</a>
                    <a href="{{ route('storefront.policies.terms') }}">Ketentuan</a>
                    <a href="{{ route('storefront.policies.refund') }}">Refund</a>
                </nav>
            </div>
        </footer>
    </div>
</body>
</html>
