<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head', ['metadata' => $metadata])
</head>
<body class="overflow-x-hidden antialiased selection:bg-[var(--sf-ink)] selection:text-white relative text-[var(--sf-ink)]">
    <div class="storefront-shell storefront-product-page relative z-10 flex min-h-screen flex-col justify-between overflow-x-hidden">
        @include('storefront.partials.header')

            @include('storefront.partials.checkout-maintenance', ['maintenance' => $checkoutMaintenance])
        <main class="storefront-product-content flex-grow pt-12">
            @include('storefront.partials.breadcrumbs', ['metadata' => $metadata])
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

                <div class="mt-6 grid gap-8 lg:grid-cols-[0.92fr_1.08fr] lg:items-start">
                    <div class="neo-panel overflow-hidden bg-[var(--sf-surface)]">
                        @include('storefront.components.product-cover', [
                            'product' => $product,
                            'presentation' => $presentation,
                            'isAvailable' => $isAvailable,
                            'isPopular' => false,
                            'priority' => true,
                        ])
                    </div>

                    <article class="neo-panel relative overflow-hidden bg-[var(--sf-surface)] p-5 sm:p-7" aria-labelledby="product-title">
                        <div class="pointer-events-none absolute inset-0 bg-grid-pattern opacity-40"></div>
                        <div class="relative z-10">
                            <h1 id="product-title" class="font-display text-3xl font-black leading-tight tracking-tight text-[var(--sf-ink)] sm:text-5xl">{{ $presentation->name }}</h1>
                            @if ($presentation->familyOptionLabel !== null)
                                <p class="mt-2 text-sm font-bold text-[var(--sf-muted)]">
                                    <span class="font-black uppercase tracking-wider text-[10px]">Pilihan</span>
                                    <span class="mx-1" aria-hidden="true">·</span>
                                    {{ $presentation->familyOptionLabel }}
                                </p>
                            @endif

                            <div class="mt-5 flex flex-wrap items-center gap-2">
                                <x-storefront.badge class="font-display text-sm normal-case">
                                    {{ $storefront->idr($presentation->priceAmount) }}
                                </x-storefront.badge>
                                <x-storefront.badge :tone="$isAvailable ? 'surface' : 'danger'" class="text-xs normal-case">
                                    {{ $isAvailable ? 'Stok: '.$presentation->stockAvailable : 'Stok habis' }}
                                </x-storefront.badge>
                            </div>

                            @if ($presentation->hasReachableQuantityPriceTiers())
                                @php
                                    $lowestReachableTier = $presentation->lowestReachableQuantityPriceTier();
                                    $quantityPriceTiers = $presentation->reachableQuantityPriceTiers;
                                    $tierHasUsdtPrice = collect($quantityPriceTiers)->contains(static fn ($tier): bool => $tier->usdtPriceAmount !== null);
                                @endphp
                                <section class="mt-5 rounded-lg border-2 border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4" aria-labelledby="product-quantity-pricing-title">
                                    <p class="text-sm font-black leading-6 text-[var(--sf-ink)]">Harga grosir mulai {{ $storefront->idr($lowestReachableTier?->priceAmount ?? $presentation->priceAmount) }} per unit untuk minimal {{ $lowestReachableTier?->minimumQuantity ?? 2 }} unit.</p>
                                    <h2 id="product-quantity-pricing-title" class="mt-4 text-xs font-black uppercase tracking-[0.16em] text-[var(--sf-accent)]">Jadwal harga berdasarkan jumlah</h2>
                                    <div class="mt-2 overflow-x-auto">
                                        <table class="w-full min-w-[20rem] text-left text-sm">
                                            <thead class="border-b-2 border-[var(--sf-border)] text-xs uppercase tracking-wide text-[var(--sf-muted)]">
                                                <tr>
                                                    <th scope="col" class="px-2 py-2">Jumlah minimum</th>
                                                    <th scope="col" class="px-2 py-2">Harga satuan (IDR)</th>
                                                    @if ($tierHasUsdtPrice)
                                                        <th scope="col" class="px-2 py-2">Harga satuan (USDT)</th>
                                                    @endif
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-[var(--sf-border)]">
                                                @foreach ($quantityPriceTiers as $tier)
                                                    <tr>
                                                        <th scope="row" class="px-2 py-2 font-black text-[var(--sf-ink)]">Mulai {{ $tier->minimumQuantity }} unit</th>
                                                        <td class="px-2 py-2 font-bold text-[var(--sf-ink)]">{{ $storefront->idr($tier->priceAmount) }}</td>
                                                        @if ($tierHasUsdtPrice)
                                                            <td class="px-2 py-2 font-bold text-[var(--sf-ink)]">{{ $tier->usdtPriceAmount === null ? '—' : $storefront->usdt($tier->usdtPriceAmount) }}</td>
                                                        @endif
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                    <p class="mt-3 text-xs font-bold leading-5 text-[var(--sf-muted)]">Harga berlaku sesuai jumlah pembelian dan ketersediaan stok saat checkout.</p>
                                </section>
                            @endif

                            <div id="product-primary-actions" class="mt-6 flex flex-col gap-3 sm:flex-row">
                                @if ($presentation->isBuyable)
                                    <x-storefront.button
                                        :href="$checkoutUrl"
                                        id="product-checkout-cta"
                                        class="neo-button-stable flex-1"
                                    >
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
                                    <p role="status" class="text-sm font-semibold leading-6 text-[var(--sf-muted)]">
                                        {{ $presentation->checkoutReadiness->notice }}
                                    </p>
                                @endif
                            </div>

                            @if (trim($presentation->description) !== '')
                                <p class="mt-5 max-w-2xl whitespace-pre-line break-words text-sm font-semibold leading-7 text-[var(--sf-muted)]">{{ $presentation->description }}</p>
                            @else
                                <p class="mt-5 max-w-xl text-sm font-semibold leading-7 text-[var(--sf-muted)]">{{ $presentation->descriptionSnippet }}</p>
                            @endif

                            @if ($presentation->merchandising->hasDetails())
                                <section class="reveal-item mt-6 rounded-lg border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4" aria-labelledby="product-merchandising-title">
                                    <h2 id="product-merchandising-title" class="font-display text-lg font-black text-[var(--sf-ink)]">Detail produk</h2>
                                    <dl class="mt-3 grid gap-3 text-sm font-bold sm:grid-cols-2">
                                        @if ($presentation->merchandising->productType)
                                            <div class="rounded-lg border border-[var(--sf-border)] bg-white p-3">
                                                <dt class="text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">Tipe produk</dt>
                                                <dd class="mt-1 whitespace-pre-line break-words text-[var(--sf-ink)]">{{ $presentation->merchandising->productType }}</dd>
                                            </div>
                                        @endif
                                        @if ($presentation->merchandising->accessDuration)
                                            <div class="rounded-lg border border-[var(--sf-border)] bg-white p-3">
                                                <dt class="text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">Durasi akses</dt>
                                                <dd class="mt-1 whitespace-pre-line break-words text-[var(--sf-ink)]">{{ $presentation->merchandising->accessDuration }}</dd>
                                            </div>
                                        @endif
                                        @if ($presentation->merchandising->region)
                                            <div class="rounded-lg border border-[var(--sf-border)] bg-white p-3">
                                                <dt class="text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">Region / negara</dt>
                                                <dd class="mt-1 whitespace-pre-line break-words text-[var(--sf-ink)]">{{ $presentation->merchandising->region }}</dd>
                                            </div>
                                        @endif
                                        @if ($presentation->merchandising->accountType)
                                            <div class="rounded-lg border border-[var(--sf-border)] bg-white p-3">
                                                <dt class="text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">Tipe akun</dt>
                                                <dd class="mt-1 whitespace-pre-line break-words text-[var(--sf-ink)]">{{ $presentation->merchandising->accountType }}</dd>
                                            </div>
                                        @endif
                                        @if ($presentation->merchandising->deviceLimit)
                                            <div class="rounded-lg border border-[var(--sf-border)] bg-white p-3">
                                                <dt class="text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">Batas perangkat / seat</dt>
                                                <dd class="mt-1 whitespace-pre-line break-words text-[var(--sf-ink)]">{{ $presentation->merchandising->deviceLimit }}</dd>
                                            </div>
                                        @endif
                                    </dl>

                                    @if ($presentation->merchandising->includedItems !== [])
                                        <div class="mt-4 rounded-lg border border-[var(--sf-border)] bg-white p-3 text-sm font-bold">
                                            <h3 class="text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">Yang termasuk</h3>
                                            <ul class="mt-2 list-inside list-disc space-y-1 text-[var(--sf-ink)]">
                                                @foreach ($presentation->merchandising->includedItems as $item)
                                                    <li class="break-words">{{ $item }}</li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif

                                    @if ($presentation->merchandising->limitations !== [])
                                        <div class="mt-3 rounded-lg border border-[var(--sf-border)] bg-white p-3 text-sm font-bold">
                                            <h3 class="text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">Batasan penggunaan</h3>
                                            <ul class="mt-2 list-inside list-disc space-y-1 text-[var(--sf-ink)]">
                                                @foreach ($presentation->merchandising->limitations as $limitation)
                                                    <li class="break-words">{{ $limitation }}</li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif

                                    @if ($presentation->merchandising->fulfillmentExpectation)
                                        <div class="mt-3 rounded-lg border border-[var(--sf-border)] bg-white p-3 text-sm font-bold">
                                            <dt class="text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">Ekspektasi pemenuhan</dt>
                                            <dd class="mt-1 whitespace-pre-line break-words text-[var(--sf-ink)]">{{ $presentation->merchandising->fulfillmentExpectation }}</dd>
                                        </div>
                                    @endif

                                    @if ($presentation->merchandising->warrantyPolicy)
                                        <div class="mt-3 rounded-lg border border-[var(--sf-border)] bg-white p-3 text-sm font-bold">
                                            <dt class="text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">Kebijakan garansi</dt>
                                            <dd class="mt-1 whitespace-pre-line break-words text-[var(--sf-ink)]">{{ $presentation->merchandising->warrantyPolicy }}</dd>
                                        </div>
                                    @endif

                                    @if ($presentation->merchandising->supportPolicy)
                                        <div class="mt-3 rounded-lg border border-[var(--sf-border)] bg-white p-3 text-sm font-bold">
                                            <dt class="text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">Dukungan pelanggan</dt>
                                            <dd class="mt-1 whitespace-pre-line break-words text-[var(--sf-ink)]">{{ $presentation->merchandising->supportPolicy }}</dd>
                                        </div>
                                    @endif
                                </section>
                            @endif

                            <div class="mt-6 border-y border-dashed border-[var(--sf-border)] py-5">
                                <dl class="grid gap-3 text-sm font-bold sm:grid-cols-2">
                                    <div class="rounded-lg border border-[var(--sf-border)] bg-white p-3">
                                        <dt class="text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">Produk</dt>
                                        <dd class="mt-1 text-[var(--sf-ink)]">{{ $product->name }}</dd>
                                    </div>
                                    <div class="rounded-lg border border-[var(--sf-border)] bg-white p-3">
                                        <dt class="text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">Status</dt>
                                        <dd class="mt-1 text-[var(--sf-ink)]">{{ $presentation->statusLabel }}</dd>
                                    </div>
                                </dl>
                            </div>

                            @include('storefront.partials.support-chooser', [
                                'supportContext' => $supportContext,
                                'supportId' => 'product-support',
                                'supportClass' => 'mt-6',
                            ])

                            @if ($presentation->canJoinWaitlist)
                                <section id="waitlist" class="reveal-item mt-6 rounded-lg border border-[var(--sf-border)] bg-[var(--sf-yellow)] p-4" aria-labelledby="waitlist-title">
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

        @include('storefront.partials.footer')
        @include('storefront.partials.verified-purchase-activity')
    </div>
</body>
</html>
