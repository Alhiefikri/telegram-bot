<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head')
</head>
<body class="overflow-x-hidden antialiased selection:bg-[var(--sf-ink)] selection:text-white relative text-[var(--sf-ink)]">
    <div class="storefront-shell relative min-h-screen z-10 flex flex-col justify-between overflow-x-hidden">
        @include('storefront.partials.header')

        <main class="flex-grow pb-20 pt-8 sm:pt-12">
            <section class="mx-auto min-w-0 max-w-3xl px-4 pb-2 sm:px-6">
                <a href="{{ route('storefront.shop.index') }}" class="inline-flex items-center gap-2 text-sm font-black text-[var(--sf-ink)] hover:text-[var(--sf-accent)]">
                    <span aria-hidden="true">&larr;</span> Kembali ke katalog
                </a>

                @php
                    $headline = $isPaid ? 'Pembayaran terverifikasi' : ($isBinancePay ? 'Order Binance Pay dibuat' : 'Order QRIS dibuat');
                    $summary = $isPaid
                        ? 'sudah lunas. Data akun tersedia di bawah.'
                        : ($isBinancePay ? 'sudah mencadangkan stok. Bayar USDT sesuai instruksi sebelum masa order berakhir.' : 'sudah mencadangkan stok. Bayar sesuai total QRIS sebelum masa order berakhir.');

                    if ($isTerminal && ! $isPaid) {
                        $headline = match ($order->order_status?->value) {
                            'cancelled' => 'Order dibatalkan',
                            'expired' => 'Order berakhir',
                            default => 'Order tidak aktif',
                        };
                        $summary = match ($order->order_status?->value) {
                            'cancelled' => 'sudah dibatalkan. Stok dan data akun sudah dilepas. Jangan bayar invoice ini.',
                            'expired' => 'sudah kedaluwarsa. Buat order baru jika masih ingin membeli.',
                            default => 'sudah berada di status akhir. Jangan lakukan pembayaran untuk invoice ini.',
                        };
                    }
                @endphp

                <div class="mx-auto mt-6 min-w-0 max-w-xl text-center">
                    <div class="mx-auto grid h-14 min-w-14 place-items-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-accent-soft)] px-2 shadow-[5px_5px_0_var(--sf-shadow)] sm:h-16 sm:min-w-16">
                        <span class="font-display text-xl font-black sm:text-2xl">{{ $isPaid ? 'OK' : ($isTerminal ? 'END' : ($isBinancePay ? 'USDT' : 'QR')) }}</span>
                    </div>
                    <h1 class="mt-5 text-balance font-display text-[1.75rem] font-black leading-tight text-[var(--sf-ink)] sm:text-4xl">
                        {{ $headline }}
                    </h1>
                    <p class="mx-auto mt-3 max-w-lg text-sm font-semibold leading-6 text-[var(--sf-muted)]">
                        Invoice <span class="break-all font-mono font-bold text-[var(--sf-ink)]">{{ $order->invoice_number }}</span> {{ $summary }}
                    </p>
                </div>

                @if (session('status'))
                    <x-storefront.notice tone="success" class="mt-6" role="status">{{ session('status') }}</x-storefront.notice>
                @endif
                @if ($errors->has('order'))
                    <x-storefront.notice tone="danger" class="mt-6" role="alert">{{ $errors->first('order') }}</x-storefront.notice>
                @endif

                @if ($isPendingPayment)
                    @if ($isBinancePay)
                        @include('storefront.partials.shop.binance-payment')
                    @else
                        @include('storefront.partials.shop.qris-payment')
                    @endif
                    @if ($accessKey)
                        @include('storefront.partials.shop.recovery-key')
                    @endif
                    @include('storefront.partials.shop.account-delivery')
                    @include('storefront.partials.shop.order-summary')
                @elseif ($isPaid)
                    @include('storefront.partials.shop.account-delivery')
                    @if ($accessKey)
                        @include('storefront.partials.shop.recovery-key')
                    @endif
                    @include('storefront.partials.shop.order-summary')
                @else
                    <x-storefront.panel as="section" class="mt-6" aria-label="Status akhir invoice">
                        <p class="text-xs font-black uppercase tracking-[0.2em] text-[var(--sf-muted)]">Status akhir</p>
                        <h2 class="mt-2 font-display text-2xl font-black text-[var(--sf-ink)]">Invoice ini tidak bisa dibayar lagi</h2>
                        <p class="mt-3 text-sm font-bold leading-6 text-[var(--sf-muted)]">{{ $statusMessage }}</p>
                        <div class="mt-5 flex flex-wrap gap-3">
                            <a href="{{ $homeUrl }}" class="neo-button inline-flex min-h-11 items-center justify-center bg-[var(--sf-accent)] px-4 text-sm font-black text-white">Buat order baru</a>
                            <a href="{{ $supportHref }}" class="inline-flex min-h-11 items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-white px-4 text-sm font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">Butuh bantuan?</a>
                        </div>
                    </x-storefront.panel>
                    @if ($accessKey)
                        @include('storefront.partials.shop.recovery-key')
                    @endif
                    @include('storefront.partials.shop.account-delivery')
                    @include('storefront.partials.shop.order-summary')
                @endif
            </section>
        </main>

        @include('storefront.partials.footer')
    </div>
</body>
</html>
