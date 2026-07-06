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
                        <span class="font-display text-2xl font-black">QR</span>
                    </div>
                    <h1 class="mt-5 font-display text-3xl font-black tracking-tight text-[var(--sf-ink)] sm:text-4xl">Order QRIS dibuat</h1>
                    <p class="mt-2 text-sm font-semibold leading-6 text-[var(--sf-muted)]">Invoice {{ $order->invoice_number }} sudah mencadangkan stok. Bayar sesuai total QRIS sebelum masa order berakhir.</p>
                </div>

                <x-storefront.panel class="mt-6">
                    <dl class="space-y-3 text-sm font-bold">
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-[var(--sf-muted)]">Pembeli</dt>
                            <dd class="text-right font-black text-[var(--sf-ink)]">{{ $order->customer?->first_name ?? 'Pelanggan' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-[var(--sf-muted)]">Status order</dt>
                            <dd class="text-right font-black text-[var(--sf-ink)]">{{ $order->order_status?->value }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-[var(--sf-muted)]">Status pembayaran</dt>
                            <dd class="text-right font-black text-[var(--sf-ink)]">{{ $order->payment_status?->value }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 border-t-[3px] border-[var(--sf-border)] pt-4 text-lg">
                            <dt class="font-black text-[var(--sf-ink)]">Total produk</dt>
                            <dd class="font-display text-2xl font-black text-[var(--sf-ink)]">{{ $storefront->idr($order->total_amount) }}</dd>
                        </div>
                        @if ($payment?->provider_fee !== null)
                            <div class="flex items-center justify-between gap-4">
                                <dt class="text-[var(--sf-muted)]">Biaya QRIS</dt>
                                <dd class="text-right font-black text-[var(--sf-ink)]">{{ $storefront->idr($payment->provider_fee) }}</dd>
                            </div>
                        @endif
                        @if ($payment?->provider_total_payment !== null)
                            <div class="flex items-center justify-between gap-4">
                                <dt class="text-[var(--sf-muted)]">Total bayar QRIS</dt>
                                <dd class="text-right font-black text-[var(--sf-accent)]">{{ $storefront->idr($payment->provider_total_payment) }}</dd>
                            </div>
                        @endif
                    </dl>
                </x-storefront.panel>

                <x-storefront.panel as="section" class="mt-5" aria-label="Detail produk">
                    <h2 class="font-display text-xl font-black text-[var(--sf-ink)]">Produk</h2>
                    <ul class="mt-4 space-y-3 text-sm font-semibold leading-6 text-[var(--sf-muted)]">
                        @foreach ($order->orderItems as $item)
                            <li class="flex items-center justify-between gap-4 rounded-lg border-2 border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-3">
                                <span class="font-black text-[var(--sf-ink)]">{{ $item->product_name }}</span>
                                <span>x{{ $item->quantity }}</span>
                            </li>
                        @endforeach
                    </ul>
                </x-storefront.panel>

                @if ($payment?->provider_payment_number)
                    <x-storefront.panel as="section" class="mt-5" aria-label="QRIS native">
                        <h2 class="font-display text-xl font-black text-[var(--sf-ink)]">Kode QRIS</h2>
                        <p class="mt-1 text-sm font-semibold leading-6 text-[var(--sf-muted)]">Gunakan kode QRIS ini di aplikasi pembayaran yang mendukung scan dari teks/QR. Link Pakasir tetap tersedia sebagai cadangan.</p>
                        <textarea readonly class="mt-4 min-h-40 w-full rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-3 font-mono text-xs font-bold text-[var(--sf-ink)] shadow-[4px_4px_0_var(--sf-shadow)]">{{ $payment->provider_payment_number }}</textarea>
                    </x-storefront.panel>
                @endif

                <div class="mt-5 grid gap-3 sm:grid-cols-2">
                    <form method="POST" action="{{ $statusUrl }}">
                        @csrf
                        <button type="submit" class="w-full rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] px-5 py-3 font-display text-sm font-black uppercase tracking-[0.18em] text-[var(--sf-ink)] shadow-[4px_4px_0_var(--sf-shadow)] transition hover:-translate-y-0.5 hover:shadow-[6px_6px_0_var(--sf-shadow)]">
                            Cek status pembayaran
                        </button>
                    </form>

                    @if ($hostedPaymentUrl)
                        <a href="{{ $hostedPaymentUrl }}" class="inline-flex items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-blue)] px-5 py-3 text-center font-display text-sm font-black uppercase tracking-[0.18em] text-white shadow-[4px_4px_0_var(--sf-shadow)] transition hover:-translate-y-0.5 hover:shadow-[6px_6px_0_var(--sf-shadow)]">
                            Buka link Pakasir
                        </a>
                    @endif
                </div>
            </section>
        </main>

        @include('storefront.partials.footer')
    </div>
</body>
</html>
