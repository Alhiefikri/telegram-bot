<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head', ['metadata' => $metadata])
</head>
<body class="overflow-x-hidden antialiased selection:bg-[var(--sf-ink)] selection:text-white relative text-[var(--sf-ink)]">
    <div class="storefront-shell storefront-product-page relative z-10 flex min-h-screen flex-col justify-between overflow-x-clip">
        @include('storefront.partials.header')

            @include('storefront.partials.checkout-maintenance', ['maintenance' => $checkoutMaintenance])
        <main class="storefront-product-content flex-grow pt-12">
            @include('storefront.partials.breadcrumbs', ['metadata' => $metadata])
            @include('storefront.partials.verified-purchase-activity')
            <section class="mx-auto max-w-5xl px-5 sm:px-6">
                <a href="{{ $returnUrl }}" class="inline-flex items-center gap-2 text-sm font-black text-[var(--sf-ink)] hover:text-[var(--sf-accent)]">
                    <span aria-hidden="true">&larr;</span> Kembali ke katalog
                </a>

                @if (session('storefront_notice'))
                    <x-storefront.notice class="mt-6">
                        {{ session('storefront_notice') }}
                    </x-storefront.notice>
                @endif
                @if (session('storefront_waitlist_notice'))
                    <x-storefront.notice class="mt-6" tone="success">
                        {{ session('storefront_waitlist_notice') }}
                    </x-storefront.notice>
                @endif

                <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(17rem,0.82fr)_minmax(0,1.18fr)] lg:items-start">
                    <div class="product-cover-panel neo-panel overflow-hidden bg-[var(--sf-surface)] lg:sticky lg:top-24">
                        @include('storefront.components.product-cover', [
                            'product' => $product,
                            'presentation' => $presentation,
                            'isAvailable' => $isAvailable,
                            'isPopular' => false,
                            'priority' => true,
                            'context' => 'detail',
                        ])
                    </div>

                    <article class="neo-panel relative overflow-hidden bg-[var(--sf-surface)] p-5 sm:p-7" aria-labelledby="product-title">
                        <div class="pointer-events-none absolute inset-0 bg-grid-pattern opacity-30"></div>
                        <div class="relative z-10">
                            @if ($presentation->familyOptionLabel !== null)
                                <p class="text-xs font-black uppercase tracking-[0.18em] text-[var(--sf-accent)]">{{ $presentation->familyOptionLabel }}</p>
                            @endif
                            <h1 id="product-title" class="{{ $presentation->familyOptionLabel !== null ? 'mt-2' : '' }} font-display text-3xl font-black leading-tight tracking-tight text-[var(--sf-ink)] sm:text-5xl">{{ $presentation->name }}</h1>

                            <div class="mt-4 flex flex-wrap items-center gap-2">
                                <strong class="font-display text-2xl font-black text-[var(--sf-accent)] sm:text-3xl">{{ $storefront->idr($presentation->priceAmount) }}</strong>
                                <span class="rounded-full border border-[var(--sf-border)] px-3 py-1.5 text-xs font-black {{ $isAvailable ? 'bg-[var(--sf-yellow)]' : 'bg-[#ffd7d7] text-[#9f1d2d]' }}">
                                    {{ $isAvailable ? 'Stok: '.$presentation->stockAvailable : 'Stok habis' }}
                                </span>
                            </div>

                            <div class="mt-5 flex gap-3 rounded-lg border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-[var(--sf-accent-soft)] text-[var(--sf-accent)]" aria-hidden="true">
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 5.5A2.5 2.5 0 0 1 7.5 3H20v16H7.5A2.5 2.5 0 0 0 5 21V5.5Z"/><path stroke-linecap="round" d="M8 7h8M8 11h7"/></svg>
                                </span>
                                <div class="min-w-0">
                                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-[var(--sf-muted)]">Deskripsi</p>
                                    <p class="mt-1 whitespace-pre-line break-words text-sm font-semibold leading-6 text-[var(--sf-ink)]">{{ trim($presentation->description) !== '' ? $presentation->description : $presentation->descriptionSnippet }}</p>
                                </div>
                            </div>

                            @if ($presentation->merchandising->warrantyPolicy)
                                <aside class="storefront-product-note mt-3 flex gap-3 rounded-lg border border-[#e5b52f] bg-[#fff7d6] p-4" aria-label="Catatan garansi">
                                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-[var(--sf-yellow)]" aria-hidden="true">
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3 5 6v5c0 4.6 2.9 8.4 7 10 4.1-1.6 7-5.4 7-10V6l-7-3Z"/><path stroke-linecap="round" d="m9 12 2 2 4-4"/></svg>
                                    </span>
                                    <div class="min-w-0">
                                        <p class="text-[10px] font-black uppercase tracking-[0.16em] text-[#765800]">Catatan garansi</p>
                                        <p class="mt-1 whitespace-pre-line break-words text-sm font-semibold leading-6">{{ $presentation->merchandising->warrantyPolicy }}</p>
                                    </div>
                                </aside>
                            @endif

                            @if ($presentation->hasReachableQuantityPriceTiers())
                                @php
                                    $lowestReachableTier = $presentation->lowestReachableQuantityPriceTier();
                                    $quantityPriceTiers = $presentation->reachableQuantityPriceTiers;
                                @endphp
                                <details class="mt-3 rounded-lg border border-[var(--sf-border)] bg-white">
                                    <summary class="flex min-h-11 cursor-pointer items-center justify-between gap-3 px-4 py-3 text-sm font-black text-[var(--sf-ink)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--sf-accent)]">
                                        <span>Harga grosir mulai {{ $storefront->idr($lowestReachableTier?->priceAmount ?? $presentation->priceAmount) }}</span>
                                        <span aria-hidden="true">+</span>
                                    </summary>
                                    <div class="border-t border-[var(--sf-border)] px-4 py-3">
                                        <ul class="grid gap-2 text-sm font-bold">
                                            @foreach ($quantityPriceTiers as $tier)
                                                <li class="flex items-center justify-between gap-4">
                                                    <span class="text-[var(--sf-muted)]">Mulai {{ $tier->minimumQuantity }} unit</span>
                                                    <strong>{{ $storefront->idr($tier->priceAmount) }} / unit</strong>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                </details>
                            @endif

                            <div id="product-primary-actions" class="mt-5 flex flex-col gap-3 sm:flex-row">
                                @if ($presentation->isBuyable)
                                    <x-storefront.button :href="$checkoutUrl" id="product-checkout-cta" class="neo-button-stable flex-1">
                                        Beli sekarang
                                    </x-storefront.button>
                                    <form method="POST" action="{{ route('storefront.shop.cart.items.store') }}" class="flex-1">
                                        @csrf
                                        <input type="hidden" name="product_id" value="{{ $product->id }}">
                                        <input type="hidden" name="quantity" value="1">
                                        <button type="submit" class="neo-button inline-flex min-h-12 w-full items-center justify-center rounded-[0.7rem] border border-[var(--sf-border)] bg-[var(--sf-surface)] px-4 text-sm font-black text-[var(--sf-ink)] hover:bg-[var(--sf-yellow)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--sf-accent)]">
                                            Tambah ke keranjang
                                        </button>
                                    </form>
                                @elseif (! $isAvailable)
                                    <x-storefront.button href="#waitlist" variant="danger" class="flex-1" id="product-waitlist-cta">
                                        {{ $presentation->waitlistCtaLabel }}
                                    </x-storefront.button>
                                @elseif ($checkoutMaintenance->active)
                                    <x-storefront.button variant="warning" class="flex-1 cursor-not-allowed opacity-80" disabled>
                                        Checkout sedang maintenance
                                    </x-storefront.button>
                                @else
                                    <x-storefront.button variant="warning" class="flex-1 cursor-not-allowed opacity-80" disabled>
                                        {{ $presentation->checkoutReadiness->label }}
                                    </x-storefront.button>
                                    <p role="status" class="text-sm font-semibold leading-6 text-[var(--sf-muted)]">{{ $presentation->checkoutReadiness->notice }}</p>
                                @endif
                            </div>

                            @if ($presentation->canJoinWaitlist)
                                <section id="waitlist" class="mt-5 rounded-lg border border-[var(--sf-border)] bg-[var(--sf-yellow)] p-4" aria-labelledby="waitlist-title">
                                    <h2 id="waitlist-title" class="font-display text-lg font-black text-[var(--sf-ink)]">{{ $copy['waitlist']['title'] }}</h2>
                                    <p class="mt-2 text-sm font-semibold leading-6 text-[var(--sf-muted)]">{{ $copy['waitlist']['body'] }}</p>
                                    <form method="POST" action="{{ $presentation->waitlistUrl }}" class="mt-4 grid gap-3">
                                        @csrf
                                        <label for="waitlist-email" class="text-sm font-black uppercase tracking-wider text-[var(--sf-ink)]">{{ $copy['waitlist']['email_label'] }}</label>
                                        <input id="waitlist-email" name="email" type="email" required maxlength="254" autocomplete="email" value="{{ old('email') }}" placeholder="{{ $copy['waitlist']['email_placeholder'] }}" class="neo-input h-12 bg-white px-3 text-base font-bold">
                                        @error('email')
                                            <p role="alert" class="text-sm font-black leading-relaxed text-[#9f1d2d]">{{ $message }}</p>
                                        @enderror
                                        <button type="submit" class="neo-button inline-flex h-12 items-center justify-center bg-[var(--sf-accent)] px-5 text-sm font-black text-white">{{ $copy['waitlist']['submit'] }}</button>
                                    </form>
                                </section>
                            @endif
                        </div>
                    </article>
                </div>
            </section>
        </main>
        @if ($presentation->isBuyable)
            <div class="storefront-sticky-action fixed inset-x-0 bottom-0 z-40 items-center justify-between gap-3 border-t border-[var(--sf-border)] bg-[var(--sf-surface)]/95 px-4 shadow-[0_-12px_30px_rgb(18_35_63_/_0.12)] backdrop-blur sm:px-6" data-product-sticky-action role="region" aria-label="Aksi pembelian produk">
                <div class="min-w-0 flex-1">
                    <span class="block text-[10px] font-black uppercase tracking-[0.14em] text-[var(--sf-muted)]">Siap dibeli</span>
                    <strong class="block truncate text-sm font-black text-[var(--sf-ink)]">{{ $storefront->idr($presentation->priceAmount) }}</strong>
                </div>
                <a href="{{ $checkoutUrl }}" class="neo-button inline-flex min-h-11 shrink-0 items-center justify-center whitespace-nowrap bg-[var(--sf-accent)] px-4 text-xs font-black text-white" data-product-sticky-submit aria-label="Beli {{ $presentation->name }} sekarang">Beli sekarang</a>
            </div>
        @endif

        @include('storefront.partials.image-preview', ['previewImage' => $presentation->cover->imageUrl, 'previewName' => $presentation->name])
        @include('storefront.partials.footer')
    </div>
</body>
</html>
