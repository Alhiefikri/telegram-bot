@php
    use App\Storefront\StorefrontPresenter;

    /** @var StorefrontPresenter $storefront */
    $storefront = app(StorefrontPresenter::class);
    $brand = $storefront->brand();
    $copy = config('storefront');
    $qris = $copy['qris'];
    $homeUrl = route('storefront.home');
    $pageTitle = 'Scan QRIS untuk Bayar | '.$brand;
    $pageDescription = 'Scan QRIS Lhie Store untuk pembayaran sementara produk '.$product->name.'.';
    $canonicalUrl = route('storefront.products.payment', $product);
    $supportHref = $storefront->supportHref();
    $supportDestination = $storefront->supportDestination();
    $adminUsername = $storefront->adminTelegramUsername();
    $paymentConfirmationLink = $storefront->paymentConfirmationLink($product, $orderId, $total);
@endphp
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
                <a href="{{ route('storefront.products.show', $product) }}" class="inline-flex items-center gap-2 text-sm font-black text-[var(--sf-ink)] hover:text-[var(--sf-accent)]">
                    <span aria-hidden="true">&larr;</span> Kembali
                </a>

                <div class="mx-auto mt-6 max-w-xl text-center">
                    <div class="mx-auto grid h-16 w-16 place-items-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-accent-soft)] shadow-[6px_6px_0_var(--sf-shadow)]">
                        <svg class="h-8 w-8 text-[var(--sf-accent)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                            <path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4z" />
                            <path d="M14 14h2v2h-2zM18 14h2v6h-6v-2h4zM14 20h2" />
                        </svg>
                    </div>
                    <h1 class="mt-5 font-display text-3xl font-black tracking-tight text-[var(--sf-ink)] sm:text-4xl">Scan QRIS untuk Bayar</h1>
                    <p class="mt-2 text-sm font-semibold leading-6 text-[var(--sf-muted)]">Bayar langsung ke QRIS sementara Lhie Store, lalu kirim bukti ke bot/admin.</p>
                </div>

                <div class="neo-panel mt-6 bg-[var(--sf-surface)] p-5">
                    <dl class="space-y-3 text-sm font-bold">
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-[var(--sf-muted)]">Order ID</dt>
                            <dd class="font-black text-[var(--sf-accent)]">{{ $orderId }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-[var(--sf-muted)]">Paket</dt>
                            <dd class="text-right font-black text-[var(--sf-ink)]">{{ $product->name }} × {{ $quantity }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-[var(--sf-muted)]">Harga satuan</dt>
                            <dd class="font-black text-[var(--sf-ink)]">{{ $storefront->idr($product->price_amount) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 border-t-2 border-dashed border-[var(--sf-border)] pt-3">
                            <dt class="text-[var(--sf-muted)]">Subtotal</dt>
                            <dd class="font-black text-[var(--sf-ink)]">{{ $storefront->idr($subtotal) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-[var(--sf-muted)]">Biaya QRIS</dt>
                            <dd class="font-black text-[var(--sf-ink)]">+ {{ $storefront->idr($qrisFee) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 border-t-[3px] border-[var(--sf-border)] pt-4 text-lg">
                            <dt class="font-black text-[var(--sf-ink)]">Total Bayar</dt>
                            <dd class="font-display text-2xl font-black text-[var(--sf-ink)]">{{ $storefront->idr($total) }}</dd>
                        </div>
                    </dl>
                </div>

                <section class="neo-panel mt-5 overflow-hidden bg-[var(--sf-surface)]" aria-label="QRIS pembayaran">
                    <div class="flex items-center justify-between border-b-[3px] border-[var(--sf-border)] bg-[var(--sf-accent)] px-4 py-3 text-white">
                        <div class="flex items-center gap-2 text-xs font-black uppercase tracking-wider">
                            <span class="grid h-8 w-8 place-items-center rounded-md border-2 border-white bg-white text-[var(--sf-accent)]">QR</span>
                            <span>Lhie payment - QRIS</span>
                        </div>
                        <span class="rounded-full border-2 border-white px-2 py-0.5 text-[10px] font-black">{{ now()->format('H:i') }}</span>
                    </div>

                    <div class="payment-qris-stage relative bg-[var(--sf-surface)] px-4 py-8">
                        <div class="pointer-events-none absolute inset-0 bg-grid-pattern opacity-30"></div>
                        <div class="relative mx-auto max-w-sm">
                            <div class="payment-qris-corners" aria-hidden="true"></div>
                            <div class="payment-qris-poster">
                                <img src="{{ $qris['asset'] }}" alt="QRIS Lhie Store untuk pembayaran" class="w-full object-contain">
                            </div>
                        </div>

                        <div class="relative mt-5 text-center">
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--sf-muted)]">Didukung oleh</p>
                            <div class="mt-2 flex flex-wrap justify-center gap-1.5">
                                @foreach (['GoPay', 'OVO', 'DANA', 'ShopeePay', 'm-Bank'] as $wallet)
                                    <span class="rounded border-2 border-[var(--sf-border)] bg-white px-2 py-1 text-[9px] font-black text-[var(--sf-ink)]">{{ $wallet }}</span>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-2 border-t-[3px] border-[var(--sf-border)] bg-white p-3">
                        <a href="{{ $qris['asset'] }}" download class="neo-button inline-flex h-10 items-center justify-center bg-white text-xs font-black text-[var(--sf-ink)]">QR</a>
                        <button type="button" class="neo-button inline-flex h-10 items-center justify-center bg-white text-xs font-black text-[var(--sf-ink)]" data-copy-text="{{ $storefront->idr($total) }}">Nominal</button>
                        <button type="button" class="neo-button inline-flex h-10 items-center justify-center bg-white text-xs font-black text-[var(--sf-ink)]" data-share-text="Order {{ $orderId }} - {{ $product->name }} x{{ $quantity }} - {{ $storefront->idr($total) }}">Bagikan</button>
                    </div>
                </section>

                <details class="neo-panel mt-4 bg-[var(--sf-surface)] p-4">
                    <summary class="cursor-pointer text-sm font-black text-[var(--sf-ink)]">Cara Bayar (3 langkah)</summary>
                    <ol class="mt-4 space-y-2 text-sm font-semibold leading-6 text-[var(--sf-muted)]">
                        <li>1. Scan QRIS di atas dari aplikasi bank atau e-wallet.</li>
                        <li>2. Masukkan nominal persis: <strong class="text-[var(--sf-ink)]">{{ $storefront->idr($total) }}</strong>.</li>
                        <li>3. Kirim bukti bayar ke bot/admin agar pesanan diproses.</li>
                    </ol>
                </details>

                <div class="neo-panel mt-4 bg-[var(--sf-accent-soft)] p-4 text-sm font-semibold leading-6 text-[var(--sf-muted)]">
                    <p class="font-black text-[var(--sf-ink)]">Menunggu pembayaran...</p>
                    <p>Status otomatis belum terhubung untuk QR sementara ini. Gunakan tombol di bawah untuk konfirmasi.</p>
                </div>

                <a href="{{ $paymentConfirmationLink }}" target="_blank" rel="noopener noreferrer" class="neo-button mt-4 inline-flex h-12 w-full items-center justify-center bg-[#2d9cff] px-5 text-sm font-black text-white">
                    Hubungi Admin {{ '@'.$adminUsername }}
                </a>
            </section>
        </main>

        @include('storefront.partials.footer')
    </div>
</body>
</html>
