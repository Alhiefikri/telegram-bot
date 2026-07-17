<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head')
</head>
<body class="overflow-x-hidden antialiased selection:bg-[var(--sf-ink)] selection:text-white relative text-[var(--sf-ink)]">
    <div class="storefront-shell relative min-h-screen z-10 flex flex-col justify-between overflow-x-hidden">
        @include('storefront.partials.header')

        <main class="flex-grow pb-20 pt-12">
            <section class="mx-auto max-w-3xl px-5 sm:px-6">
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

                <div class="mx-auto mt-6 max-w-xl text-center">
                    <div class="mx-auto grid h-16 w-16 place-items-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-accent-soft)] shadow-[6px_6px_0_var(--sf-shadow)]">
                        <span class="font-display text-2xl font-black">{{ $isPaid ? 'OK' : ($isTerminal ? 'END' : ($isBinancePay ? 'USDT' : 'QR')) }}</span>
                    </div>
                    <h1 class="mt-5 font-display text-3xl font-black tracking-tight text-[var(--sf-ink)] sm:text-4xl">
                        {{ $headline }}
                    </h1>
                    <p class="mt-2 text-sm font-semibold leading-6 text-[var(--sf-muted)]">
                        Invoice {{ $order->invoice_number }} {{ $summary }}
                    </p>
                </div>

                @if (session('status'))
                    <x-storefront.notice tone="success" class="mt-6" role="status">{{ session('status') }}</x-storefront.notice>
                @endif
                @if ($errors->has('order'))
                    <x-storefront.notice tone="danger" class="mt-6" role="alert">{{ $errors->first('order') }}</x-storefront.notice>
                @endif

                @include('storefront.partials.shop.order-summary')
                @if ($accessKey)
                    @include('storefront.partials.shop.recovery-key')
                @endif
                @if ($isBinancePay)
                    @include('storefront.partials.shop.binance-payment')
                @else
                    @include('storefront.partials.shop.qris-payment')
                @endif
                @include('storefront.partials.shop.account-delivery')
            </section>
        </main>

        @include('storefront.partials.footer')
    </div>
</body>
</html>
