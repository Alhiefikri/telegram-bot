<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head', ['metadata' => $metadata])
</head>
<body class="relative overflow-x-hidden text-[var(--sf-ink)] antialiased selection:bg-[var(--sf-ink)] selection:text-white motion-reduce:animate-none">
    <div class="storefront-shell relative min-h-screen z-10 flex flex-col justify-between overflow-x-clip">
        @include('storefront.partials.header')

        <main class="flex-grow pb-16 pt-8 sm:pt-12">
            <section class="mx-auto max-w-5xl px-5 sm:px-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.2em] text-[var(--sf-accent)]">Keranjang web</p>
                        <h1 class="mt-2 font-display text-3xl font-black tracking-tight text-[var(--sf-ink)] sm:text-5xl">Tinjau keranjang Anda.</h1>
                        <p class="mt-3 max-w-2xl text-sm font-semibold leading-7 text-[var(--sf-muted)]">Item disimpan di sesi browser ini. Stok belum dicadangkan sampai Anda membuat order.</p>
                    </div>
                    <a href="{{ $shopUrl }}" class="neo-button neo-button-stable inline-flex min-h-11 items-center justify-center bg-white px-4 text-sm font-black text-[var(--sf-ink)]">Tambah produk</a>
                </div>

                @if (session('storefront_cart_notice'))
                    <x-storefront.notice class="mt-6">
                        {{ session('storefront_cart_notice') }}
                    </x-storefront.notice>
                @endif
                @if (session('storefront_cart_error'))
                    <x-storefront.notice tone="danger" class="mt-6">
                        {{ session('storefront_cart_error') }}
                    </x-storefront.notice>
                @endif
                @error('cart')
                    <x-storefront.notice tone="danger" class="mt-6">
                        {{ $message }}
                    </x-storefront.notice>
                @enderror

                @if ($cartItems->isEmpty())
                    <div class="neo-panel mt-8 bg-[var(--sf-surface)] p-6 sm:p-8">
                        <p class="text-xs font-black uppercase tracking-[0.2em] text-[var(--sf-accent)]">Belum ada item</p>
                        <h2 class="mt-2 font-display text-2xl font-black text-[var(--sf-ink)]">Keranjang masih kosong.</h2>
                        <p class="mt-3 max-w-xl text-sm font-semibold leading-7 text-[var(--sf-muted)]">Pilih beberapa produk dari katalog untuk menyiapkan satu checkout dan satu invoice.</p>
                        <a href="{{ $shopUrl }}" class="neo-button mt-6 inline-flex min-h-12 items-center justify-center bg-[var(--sf-accent)] px-5 text-sm font-black text-white">Lihat katalog</a>
                    </div>
                @else
                    <div class="mt-8 grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem] lg:items-start">
                        <div class="grid gap-4">
                            @foreach ($cartItems as $item)
                                <article class="neo-panel bg-[var(--sf-surface)] p-4 sm:p-5" data-cart-item="{{ $item->productId }}">
                                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                        <div class="min-w-0">
                                            @if ($item->hasConflict())
                                                <span class="inline-flex rounded-full border-2 border-[var(--sf-border)] bg-[#ffd9df] px-2.5 py-1 text-[10px] font-black uppercase tracking-wide text-[var(--sf-ink)]">Perlu diperiksa</span>
                                            @endif
                                            <h2 class="mt-2 break-words font-display text-xl font-black text-[var(--sf-ink)]">
                                                @if ($item->unitPrice !== null)
                                                    <a href="{{ $item->productUrl }}" class="hover:text-[var(--sf-accent)]">{{ $item->name }}</a>
                                                @else
                                                    {{ $item->name }}
                                                @endif
                                            </h2>
                                            <p class="mt-2 text-sm font-semibold text-[var(--sf-muted)]">
                                                Harga satuan efektif:
                                                @if ($item->unitPrice !== null)
                                                    <span class="font-black text-[var(--sf-ink)]">{{ $storefront->idr($item->unitPrice) }}</span>
                                                @else
                                                    <span class="font-black text-[var(--sf-ink)]">Tidak tersedia</span>
                                                @endif
                                                · Mata uang IDR
                                            </p>
                                            @if ($item->usdtUnitPrice !== null)
                                                <p class="mt-1 text-xs font-bold text-[var(--sf-muted)]">Harga USDT satuan: <span class="font-black text-[var(--sf-ink)]">{{ $storefront->usdt($item->usdtUnitPrice) }}</span></p>
                                            @endif
                                            @if ($item->hasAppliedQuantityPriceTier())
                                                <p class="mt-1 text-xs font-bold text-[var(--sf-muted)]">Harga grosir diterapkan mulai {{ $item->appliedTierMinimum }} unit.</p>
                                            @endif
                                            @if ($item->conflictMessage)
                                                <p role="alert" class="mt-3 rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-2 text-sm font-black leading-6 text-[var(--sf-ink)]">{{ $item->conflictMessage }}</p>
                                            @elseif ($item->availableStock !== null)
                                                <p class="mt-2 text-xs font-bold text-[var(--sf-muted)]">Stok tersedia saat ini: {{ $item->availableStock }}</p>
                                            @endif

                                        </div>
                                        <div class="flex shrink-0 flex-col gap-3 sm:items-end">
                                            <form method="POST" action="{{ route('storefront.shop.cart.items.update', ['product' => $item->productId]) }}" class="flex items-center gap-2">
                                                @csrf
                                                @method('PATCH')
                                                <label for="cart-quantity-{{ $item->productId }}" class="sr-only">Jumlah {{ $item->name }}</label>
                                                <input id="cart-quantity-{{ $item->productId }}" name="quantity" type="number" min="0" max="20" value="{{ $item->quantity }}" class="neo-input h-11 w-20 bg-white px-2 text-center text-base font-black text-[var(--sf-ink)]">
                                                <button type="submit" class="neo-button inline-flex min-h-11 items-center justify-center bg-[var(--sf-yellow)] px-3 text-sm font-black text-[var(--sf-ink)]">Perbarui</button>
                                            </form>
                                            <p class="text-right text-sm font-black text-[var(--sf-ink)]">
                                                Subtotal efektif: {{ $item->subtotal === null ? '—' : $storefront->idr($item->subtotal) }}
                                            </p>
                                            @if ($item->usdtSubtotal !== null)
                                                <p class="text-right text-xs font-bold text-[var(--sf-muted)]">Subtotal USDT: <span class="font-black text-[var(--sf-ink)]">{{ $storefront->usdt($item->usdtSubtotal) }}</span></p>
                                            @endif
                                            <form method="POST" action="{{ route('storefront.shop.cart.items.destroy', ['product' => $item->productId]) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="inline-flex min-h-11 items-center justify-center px-2 text-sm font-black text-[var(--sf-accent)] underline underline-offset-2">Hapus dari keranjang</button>
                                            </form>
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>

                        <aside class="neo-panel bg-[var(--sf-surface)] p-5" aria-labelledby="cart-summary-title">
                            <p id="cart-summary-title" class="text-xs font-black uppercase tracking-[0.18em] text-[var(--sf-accent)]">Ringkasan keranjang</p>
                            <dl class="mt-4 grid gap-3 text-sm">
                                <div class="flex items-center justify-between gap-3 font-bold text-[var(--sf-muted)]">
                                    <dt>Item</dt>
                                    <dd class="font-black text-[var(--sf-ink)]">{{ $cartItemCount }} unit</dd>
                                </div>
                                <div class="flex items-center justify-between gap-3 border-t-2 border-dashed border-[var(--sf-border)] pt-3 font-black text-[var(--sf-ink)]">
                                    <dt>Subtotal (IDR)</dt>
                                    <dd>{{ $storefront->idr($cartSubtotal) }}</dd>
                                </div>
                                @if ($cartUsdtSubtotal !== null)
                                    <div class="flex items-center justify-between gap-3 font-bold text-[var(--sf-muted)]">
                                        <dt>Total USDT / estimasi</dt>
                                        <dd class="font-black text-[var(--sf-ink)]">{{ $storefront->usdt($cartUsdtSubtotal) }}</dd>
                                    </div>
                                @endif
                            </dl>
                            @if ($cartHasConflicts)
                                <p role="alert" class="mt-4 rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-2 text-sm font-black leading-6 text-[var(--sf-ink)]">Periksa item yang berubah sebelum checkout. Stok tidak disimpan hanya karena item berada di keranjang.</p>
                                <span class="mt-4 inline-flex min-h-12 w-full items-center justify-center rounded-[0.7rem] border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] px-4 text-center text-sm font-black text-[var(--sf-muted)]">Checkout belum siap</span>
                            @else
                                <a href="{{ $checkoutUrl }}" class="neo-button neo-button-stable mt-5 inline-flex min-h-12 w-full items-center justify-center bg-[var(--sf-accent)] px-4 text-center text-sm font-black text-white">Lanjut checkout</a>
                            @endif
                            <p class="mt-4 text-xs font-bold leading-5 text-[var(--sf-muted)]">Harga dan ketersediaan akan dibaca ulang saat order dibuat.</p>
                        </aside>
                    </div>
                @endif
            </section>
        </main>

        @include('storefront.partials.footer')
    </div>
</body>
</html>
