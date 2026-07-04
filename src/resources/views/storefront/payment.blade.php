@php
    use App\Storefront\StorefrontPresenter;

    /** @var StorefrontPresenter $storefront */
    $storefront = app(StorefrontPresenter::class);
    $brand = $storefront->brand();
    $copy = config('storefront');
    $homeUrl = route('storefront.home');
    $pageTitle = 'Checkout via Telegram Admin | '.$brand;
    $pageDescription = 'Lanjutkan checkout produk '.$product->name.' melalui Telegram admin agar order, payment, stok, dan receipt tercatat resmi.';
    $canonicalUrl = route('storefront.products.payment', $product);
    $supportHref = $storefront->supportHref();
    $supportDestination = $storefront->supportDestination();
    $adminUsername = $storefront->adminTelegramUsername();
    $checkoutSupportLink = $storefront->checkoutSupportLink($product, $quantity, $subtotal);
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
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 8h10M7 12h6m-8 8 3-3h8a4 4 0 0 0 4-4V7a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v6a4 4 0 0 0 4 4" />
                        </svg>
                    </div>
                    <h1 class="mt-5 font-display text-3xl font-black tracking-tight text-[var(--sf-ink)] sm:text-4xl">Checkout via Telegram Admin</h1>
                    <p class="mt-2 text-sm font-semibold leading-6 text-[var(--sf-muted)]">Halaman ini bukan halaman pembayaran. Admin akan membuat order resmi dulu, lalu memberi instruksi pembayaran yang tercatat di sistem.</p>
                </div>

                <div class="neo-panel mt-6 bg-[var(--sf-surface)] p-5">
                    <dl class="space-y-3 text-sm font-bold">
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-[var(--sf-muted)]">Produk</dt>
                            <dd class="text-right font-black text-[var(--sf-ink)]">{{ $product->name }} × {{ $quantity }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-[var(--sf-muted)]">Harga satuan</dt>
                            <dd class="font-black text-[var(--sf-ink)]">{{ $storefront->idr($product->price_amount) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 border-t-[3px] border-[var(--sf-border)] pt-4 text-lg">
                            <dt class="font-black text-[var(--sf-ink)]">Estimasi produk</dt>
                            <dd class="font-display text-2xl font-black text-[var(--sf-ink)]">{{ $storefront->idr($subtotal) }}</dd>
                        </div>
                    </dl>
                </div>

                <section class="neo-panel mt-5 bg-[var(--sf-surface)] p-5" aria-label="Alur checkout resmi">
                    <div class="flex items-center gap-3">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] font-display text-sm font-black text-[var(--sf-ink)] shadow-[4px_4px_0_var(--sf-shadow)]">!</span>
                        <div>
                            <h2 class="font-display text-xl font-black text-[var(--sf-ink)]">Belum ada order dibuat</h2>
                            <p class="mt-1 text-sm font-semibold leading-6 text-[var(--sf-muted)]">Jangan transfer dari halaman ini. Pembayaran valid hanya setelah admin/bot membuat order, payment pending, reservasi stok, dan instruksi resmi.</p>
                        </div>
                    </div>

                    <ol class="mt-5 grid gap-3 text-sm font-semibold leading-6 text-[var(--sf-muted)]">
                        <li class="rounded-lg border-2 border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-3"><strong class="text-[var(--sf-ink)]">1.</strong> Buka Telegram admin dari tombol di bawah.</li>
                        <li class="rounded-lg border-2 border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-3"><strong class="text-[var(--sf-ink)]">2.</strong> Admin mencatat checkout lewat flow resmi agar order, payment, stok, dan receipt durable.</li>
                        <li class="rounded-lg border-2 border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-3"><strong class="text-[var(--sf-ink)]">3.</strong> Ikuti instruksi pembayaran resmi yang dikirim setelah order pending dibuat.</li>
                    </ol>
                </section>

                <div class="neo-panel mt-4 bg-[var(--sf-accent-soft)] p-4 text-sm font-semibold leading-6 text-[var(--sf-muted)]">
                    <p class="font-black text-[var(--sf-ink)]">Role halaman: handoff support</p>
                    <p>Web storefront hanya membantu memilih produk. Checkout produksi tetap melewati modul order/payment/inventory supaya Paid Order Proof Event dan receipt tidak bisa terlewati.</p>
                </div>

                <a href="{{ $checkoutSupportLink }}" target="_blank" rel="noopener noreferrer" class="neo-button mt-4 inline-flex h-12 w-full items-center justify-center bg-[#2d9cff] px-5 text-sm font-black text-white">
                    Buka Telegram Admin {{ '@'.$adminUsername }}
                </a>
            </section>
        </main>

        @include('storefront.partials.footer')
    </div>
</body>
</html>
