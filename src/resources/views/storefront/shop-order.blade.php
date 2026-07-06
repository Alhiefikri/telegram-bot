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

                <div class="mx-auto mt-6 max-w-xl text-center">
                    <div class="mx-auto grid h-16 w-16 place-items-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-accent-soft)] shadow-[6px_6px_0_var(--sf-shadow)]">
                        <span class="font-display text-2xl font-black">{{ $isPaid ? 'OK' : 'QR' }}</span>
                    </div>
                    <h1 class="mt-5 font-display text-3xl font-black tracking-tight text-[var(--sf-ink)] sm:text-4xl">
                        {{ $isPaid ? 'Pembayaran terverifikasi' : 'Order QRIS dibuat' }}
                    </h1>
                    <p class="mt-2 text-sm font-semibold leading-6 text-[var(--sf-muted)]">
                        Invoice {{ $order->invoice_number }} {{ $isPaid ? 'sudah lunas. Data akun tersedia di bawah.' : 'sudah mencadangkan stok. Bayar sesuai total QRIS sebelum masa order berakhir.' }}
                    </p>
                </div>

                @include('storefront.partials.shop.order-summary')
                @include('storefront.partials.shop.qris-payment')
                @include('storefront.partials.shop.account-delivery')
            </section>
        </main>

        @include('storefront.partials.footer')
    </div>
</body>
</html>
