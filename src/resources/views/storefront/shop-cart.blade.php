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
                        <p class="text-xs font-black uppercase tracking-[0.2em] text-[var(--sf-accent)]">{{ __('storefront_funnel.web_cart') }}</p>
                        <h1 class="mt-2 font-display text-2xl font-black tracking-tight text-[var(--sf-ink)] sm:text-5xl">{{ __('storefront_funnel.review_cart') }}</h1>
                        <p class="mt-3 max-w-2xl text-sm font-semibold leading-7 text-[var(--sf-muted)]">{{ __('storefront_funnel.cart_session') }}</p>
                    </div>
                    <a href="{{ $shopUrl }}" class="neo-button neo-button-stable inline-flex min-h-11 items-center justify-center bg-white px-4 text-sm font-black text-[var(--sf-ink)]">{{ __('storefront_funnel.add_products') }}</a>
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
                        <p class="text-xs font-black uppercase tracking-[0.2em] text-[var(--sf-accent)]">{{ __('storefront_funnel.no_items') }}</p>
                        <h2 class="mt-2 font-display text-2xl font-black text-[var(--sf-ink)]">{{ __('storefront_funnel.empty_cart') }}</h2>
                        <p class="mt-3 max-w-xl text-sm font-semibold leading-7 text-[var(--sf-muted)]">{{ __('storefront_funnel.one_checkout') }}</p>
                        <a href="{{ $shopUrl }}" class="neo-button mt-6 inline-flex min-h-12 items-center justify-center bg-[var(--sf-accent)] px-5 text-sm font-black text-white">{{ __('storefront_funnel.view_catalog') }}</a>
                    </div>
                @else
                    <div class="mt-8 grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem] lg:items-start">
                        <div class="grid gap-4">
                            @foreach ($cartItems as $item)
                                <article class="neo-panel bg-[var(--sf-surface)] p-4 sm:p-5" data-cart-item="{{ $item->productId }}">
                                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                        <div class="min-w-0">
                                            @if ($item->hasConflict())
                                                <span class="inline-flex rounded-full border-2 border-[var(--sf-border)] bg-[#ffd9df] px-2.5 py-1 text-[10px] font-black uppercase tracking-wide text-[var(--sf-ink)]">{{ __('storefront_funnel.needs_review') }}</span>
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
                                                    <span class="font-black text-[var(--sf-ink)]">{{ __('storefront_funnel.unavailable') }}</span>
                                                @endif
                                                · Mata uang IDR
                                            </p>
                                            @if ($item->usdtUnitPrice !== null)
                                                <p class="mt-1 text-xs font-bold text-[var(--sf-muted)]">{{ __('storefront_funnel.usdt_unit_price') }} <span class="font-black text-[var(--sf-ink)]">{{ $storefront->usdt($item->usdtUnitPrice) }}</span></p>
                                            @endif
                                            @if ($item->hasAppliedQuantityPriceTier())
                                                <p class="mt-1 text-xs font-bold text-[var(--sf-muted)]">{{ __('storefront_funnel.bulk_tier', ['count' => $item->appliedTierMinimum]) }}</p>
                                            @endif
                                            @if ($item->conflictMessage)
                                                <p role="alert" class="mt-3 rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-2 text-sm font-black leading-6 text-[var(--sf-ink)]">{{ $item->conflictMessage }}</p>
                                            @elseif ($item->availableStock !== null)
                                                <p class="mt-2 text-xs font-bold text-[var(--sf-muted)]">{{ __('storefront_funnel.stock_now') }} {{ $item->manualUnlimited ? __('storefront_funnel.manual_stock') : $item->availableStock }}</p>
                                            @endif

                                        </div>
                                        <div class="flex shrink-0 flex-col gap-3 sm:items-end">
                                            <form method="POST" action="{{ route('storefront.shop.cart.items.update', ['product' => $item->productId]) }}" class="flex items-center gap-2">
                                                @csrf
                                                @method('PATCH')
                                                <label for="cart-quantity-{{ $item->productId }}" class="sr-only">{{ __('storefront_funnel.quantity') }} {{ $item->name }}</label>
                                                <input id="cart-quantity-{{ $item->productId }}" name="quantity" type="number" min="0" max="20" value="{{ $item->quantity }}" class="neo-input h-11 w-20 bg-white px-2 text-center text-base font-black text-[var(--sf-ink)]">
                                                <button type="submit" class="neo-button inline-flex min-h-11 items-center justify-center bg-[var(--sf-yellow)] px-3 text-sm font-black text-[var(--sf-ink)]">{{ __('storefront_funnel.update') }}</button>
                                            </form>
                                            <p class="text-right text-sm font-black text-[var(--sf-ink)]">
                                                {{ __('storefront_funnel.effective_subtotal') }} {{ $item->subtotal === null ? '—' : $storefront->idr($item->subtotal) }}
                                            </p>
                                            @if ($item->usdtSubtotal !== null)
                                                <p class="text-right text-xs font-bold text-[var(--sf-muted)]">{{ __('storefront_funnel.usdt_subtotal') }} <span class="font-black text-[var(--sf-ink)]">{{ $storefront->usdt($item->usdtSubtotal) }}</span></p>
                                            @endif
                                            <form method="POST" action="{{ route('storefront.shop.cart.items.destroy', ['product' => $item->productId]) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="inline-flex min-h-11 items-center justify-center px-2 text-sm font-black text-[var(--sf-accent)] underline underline-offset-2">{{ __('storefront_funnel.remove_from_cart') }}</button>
                                            </form>
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>

                        <aside class="neo-panel bg-[var(--sf-surface)] p-5" aria-labelledby="cart-summary-title">
                            <p id="cart-summary-title" class="text-xs font-black uppercase tracking-[0.18em] text-[var(--sf-accent)]">{{ __('storefront_funnel.cart_summary') }}</p>
                            <dl class="mt-4 grid gap-3 text-sm">
                                <div class="flex items-center justify-between gap-3 font-bold text-[var(--sf-muted)]">
                                    <dt>{{ __('storefront_funnel.items') }}</dt>
                                    <dd class="font-black text-[var(--sf-ink)]">{{ __('storefront_funnel.units_count', ['count' => $cartItemCount]) }}</dd>
                                </div>
                                <div class="flex items-center justify-between gap-3 border-t-2 border-dashed border-[var(--sf-border)] pt-3 font-black text-[var(--sf-ink)]">
                                    <dt>{{ __('storefront_funnel.subtotal_idr') }}</dt>
                                    <dd>{{ $storefront->idr($cartSubtotal) }}</dd>
                                </div>
                                @if ($cartUsdtSubtotal !== null)
                                    <div class="flex items-center justify-between gap-3 font-bold text-[var(--sf-muted)]">
                                        <dt>{{ __('storefront_funnel.estimated_usdt') }}</dt>
                                        <dd class="font-black text-[var(--sf-ink)]">{{ $storefront->usdt($cartUsdtSubtotal) }}</dd>
                                    </div>
                                @endif
                            </dl>
                            @if ($cartHasConflicts)
                                <p role="alert" class="mt-4 rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-2 text-sm font-black leading-6 text-[var(--sf-ink)]">{{ __('storefront_funnel.cart_review_changes') }}</p>
                                <span class="mt-4 inline-flex min-h-12 w-full items-center justify-center rounded-[0.7rem] border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] px-4 text-center text-sm font-black text-[var(--sf-muted)]">{{ __('storefront_funnel.checkout_not_ready') }}</span>
                            @else
                                <a href="{{ $checkoutUrl }}" class="neo-button neo-button-stable mt-5 inline-flex min-h-12 w-full items-center justify-center bg-[var(--sf-accent)] px-4 text-center text-sm font-black text-white">{{ __('storefront_funnel.continue_checkout') }}</a>
                            @endif
                            <p class="mt-4 text-xs font-bold leading-5 text-[var(--sf-muted)]">{{ __('storefront_funnel.recheck_at_order') }}</p>
                        </aside>
                    </div>
                @endif
            </section>
        </main>

        @include('storefront.partials.footer')
    </div>
</body>
</html>
