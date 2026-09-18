<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head', ['metadata' => $metadata])
</head>
<body class="relative overflow-x-hidden text-[var(--sf-ink)] antialiased selection:bg-[var(--sf-ink)] selection:text-white">
    <div class="storefront-shell family-page relative z-10 flex min-h-screen flex-col justify-between overflow-x-hidden">
        @include('storefront.partials.header')
        @include('storefront.partials.checkout-maintenance', ['maintenance' => $checkoutMaintenance])

        <main class="family-content flex-grow pb-20 pt-8 sm:pt-10">
            @include('storefront.partials.breadcrumbs', ['metadata' => $metadata])
            <section class="family-layout mx-auto max-w-7xl px-5 sm:px-6">
                <a href="{{ $returnUrl }}" class="inline-flex items-center gap-2 text-sm font-black text-[var(--sf-ink)] hover:text-[var(--sf-accent)]">
                    <span aria-hidden="true">&larr;</span> Kembali ke katalog
                </a>

                <header class="family-hero mt-5 overflow-hidden rounded-2xl border border-[var(--sf-border)] bg-[var(--sf-surface)] shadow-[0_12px_30px_rgb(18_35_63_/_0.08)]">
                    <div class="grid gap-5 p-4 sm:p-6 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)] lg:items-center lg:p-7">
                        <div class="family-hero-art relative flex aspect-[4/3] items-center justify-center overflow-hidden rounded-xl border border-[var(--sf-border)] bg-[var(--sf-yellow)]">
                            @if ($family->imageUrl)
                                <img src="{{ $family->imageUrl }}" alt="{{ $family->name }}" loading="eager" decoding="async" class="absolute inset-0 h-full w-full object-cover">
                            @else
                                <span class="font-display text-7xl font-black text-[var(--sf-accent)]" aria-hidden="true">{{ mb_substr($family->name, 0, 1) }}</span>
                                <span class="sr-only">Gambar {{ $family->name }}</span>
                            @endif
                        </div>
                        <div class="family-hero-copy">
                            <p class="text-xs font-black uppercase tracking-[0.18em] text-[var(--sf-accent)]">Pilih paket</p>
                            <h1 class="mt-2 font-display text-3xl font-black leading-tight tracking-tight sm:text-5xl">{{ $family->name }}</h1>
                            @if ($family->description)
                                <p class="mt-4 max-w-3xl whitespace-pre-line break-words text-sm font-semibold leading-7 text-[var(--sf-muted)]">{{ $family->description }}</p>
                            @endif
                            <div class="mt-5 flex flex-wrap gap-2 text-xs font-black">
                                <span class="rounded-full border border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-2">{{ $family->optionCount }} pilihan</span>
                                <span class="rounded-full border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] px-3 py-2">{{ $family->availableOptionCount }} tersedia</span>
                                <span class="rounded-full border border-[var(--sf-border)] bg-white px-3 py-2">Mulai {{ $family->minimumPriceAmount !== null ? $storefront->idr($family->minimumPriceAmount) : '—' }}</span>
                                @if ($family->minimumUsdtPriceAmount !== null)
                                    <span class="rounded-full border border-[var(--sf-border)] bg-white px-3 py-2">{{ $storefront->usdt($family->minimumUsdtPriceAmount) }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </header>

                <div class="family-options-heading mt-8 flex items-end justify-between gap-4">
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.18em] text-[var(--sf-accent)]">Satu langkah lagi</p>
                        <h2 class="mt-1 font-display text-2xl font-black tracking-tight sm:text-3xl">Pilih varian</h2>
                    </div>
                    <span class="hidden rounded-full border border-[var(--sf-border)] bg-white px-3 py-2 text-xs font-black sm:inline-flex">{{ $family->optionCount }} pilihan</span>
                </div>

                <div id="family-options" @class([
                    'family-option-grid mt-5 grid gap-4',
                    'md:grid-cols-2' => $members->count() > 1,
                    'mx-auto max-w-3xl' => $members->count() === 1,
                ])>
                    @php
                        $firstAvailableMember = $members->first(fn ($member): bool => $member->presentation->isAvailable);
                        $firstAvailableMemberId = $firstAvailableMember?->presentation->id;
                    @endphp
                    @foreach ($members as $member)
                        @php
                            $presentation = $member->presentation;
                            $optionLabel = trim((string) ($presentation->familyOptionLabel ?? $presentation->name));
                            $productUrl = $storefront->productUrl($presentation->id, $familyUrl);
                            $checkoutUrl = $storefront->checkoutProductUrl($presentation->id, $familyUrl);
                            $hasSupportingDetails = trim((string) $presentation->description) !== ''
                                || $presentation->merchandising->hasDetails();
                            $isQuickBuyDefault = $firstAvailableMemberId === $presentation->id;
                        @endphp
                        <article id="family-option-{{ $presentation->id }}" aria-labelledby="family-option-title-{{ $presentation->id }}" aria-label="Pilih varian {{ $optionLabel }}" tabindex="0" data-product-id="{{ $presentation->id }}" data-family-option data-family-option-name="{{ $optionLabel }}" data-family-option-price="{{ $presentation->priceAmount }}" data-family-option-stock="{{ $presentation->stockAvailable }}" data-family-checkout-url="{{ $checkoutUrl }}" @if ($isQuickBuyDefault) data-family-selected="true" @endif class="family-option neo-panel reveal-item relative cursor-pointer overflow-hidden bg-[var(--sf-surface)] p-4 sm:p-5">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-[var(--sf-muted)]">Opsi</p>
                                    <h3 id="family-option-title-{{ $presentation->id }}" class="mt-1 font-display text-xl font-black leading-tight sm:text-2xl">
                                        <a href="{{ $productUrl }}" class="inline-flex min-h-11 items-center rounded-sm focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--sf-accent)]">
                                            {{ $optionLabel }}
                                        </a>
                                    </h3>
                                    @if ($optionLabel !== $presentation->name)
                                        <p class="mt-1 text-xs font-bold text-[var(--sf-muted)]">
                                            <a href="{{ $productUrl }}" class="inline-flex min-h-11 items-center rounded-sm focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--sf-accent)]">
                                                {{ $presentation->name }}
                                            </a>
                                        </p>
                                    @endif
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <span class="rounded-full border border-[var(--sf-border)] px-2.5 py-1 text-[10px] font-black {{ $presentation->isAvailable ? 'bg-[var(--sf-yellow)]' : 'bg-[#ffd7d7] text-[#9f1d2d]' }}">{{ $presentation->isAvailable ? 'Tersedia' : 'Stok habis' }}</span>
                                    <input type="radio" name="family_option" value="{{ $presentation->id }}" data-family-select @checked($isQuickBuyDefault) @disabled(! $presentation->isAvailable) tabindex="-1" aria-label="Pilih varian {{ $optionLabel }}" class="sr-only">
                                </div>
                            </div>

                            <div class="mt-3 flex flex-wrap items-center gap-2 text-xs font-black">
                                <span data-family-option-price="idr" class="rounded-full border border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-2">{{ $storefront->idr($presentation->priceAmount) }}</span>
                                @if ($presentation->usdtPriceAmount !== null)
                                    <span data-family-option-price="usdt" class="rounded-full border border-[var(--sf-border)] bg-white px-3 py-2">{{ $storefront->usdt($presentation->usdtPriceAmount) }}</span>
                                @endif
                                @if ($presentation->merchandising->accessDuration)
                                    <span class="rounded-full border border-[var(--sf-border)] bg-white px-3 py-2">Durasi {{ $presentation->merchandising->accessDuration }}</span>
                                @endif
                                <span class="rounded-full border border-[var(--sf-border)] bg-white px-3 py-2">{{ $presentation->stockLabel }}</span>
                                <span class="rounded-full border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] px-3 py-2">{{ $presentation->soldLabel }}</span>
                            </div>

                            <div class="mt-4 flex flex-col gap-3">
                                <div class="flex flex-col gap-2 sm:flex-row">
                                    <a href="{{ $presentation->isAvailable ? $checkoutUrl : $productUrl }}" data-family-direct-checkout class="neo-button inline-flex min-h-11 flex-1 items-center justify-center bg-[var(--sf-accent)] px-4 text-sm font-black text-white hover:bg-[var(--sf-accent-deep)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--sf-accent)]">
                                        {{ $presentation->isAvailable ? 'Beli sekarang' : 'Lihat detail opsi' }}
                                    </a>
                                    @if ($presentation->isAvailable)
                                        <form method="POST" action="{{ route('storefront.shop.cart.items.store') }}" class="flex-1">
                                            @csrf
                                            <input type="hidden" name="product_id" value="{{ $presentation->id }}">
                                            <input type="hidden" name="quantity" value="1">
                                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-[0.7rem] border border-[var(--sf-border)] bg-white px-4 text-sm font-black text-[var(--sf-ink)] hover:bg-[var(--sf-yellow)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--sf-accent)]">Tambah ke keranjang</button>
                                        </form>
                                    @else
                                        <a href="{{ $productUrl }}#waitlist" class="inline-flex min-h-11 flex-1 items-center justify-center rounded-[0.7rem] border border-[var(--sf-border)] bg-[var(--sf-yellow)] px-4 text-sm font-black hover:bg-white focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--sf-accent)]">Beri tahu saat tersedia</a>
                                    @endif
                                </div>
                            </div>

                            @if ($hasSupportingDetails)
                                <details data-family-option-details class="mt-4 rounded-lg border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-2.5">
                                    <summary class="flex min-h-11 cursor-pointer items-center justify-between gap-3 text-sm font-black text-[var(--sf-ink)] focus-visible:outline focus-visible:outline-3 focus-visible:outline-[var(--sf-blue)] focus-visible:outline-offset-2">
                                        <span>Baca detail lengkap</span>
                                        <span aria-hidden="true" class="text-xs font-bold text-[var(--sf-muted)]">+</span>
                                    </summary>
                                    @if (trim((string) $presentation->description) !== '')
                                        <p class="mt-3 whitespace-pre-line break-words text-sm font-semibold leading-6 text-[var(--sf-muted)]">{{ $presentation->description }}</p>
                                    @endif
                                    @if ($presentation->merchandising->hasDetails())
                                        <dl class="mt-5 grid gap-3 border-y border-dashed border-[var(--sf-border)] py-4 text-xs font-bold sm:grid-cols-2">
                                            @if ($presentation->merchandising->productType)
                                                <div><dt class="uppercase tracking-wider text-[var(--sf-muted)]">Tipe</dt><dd class="mt-1">{{ $presentation->merchandising->productType }}</dd></div>
                                            @endif
                                            @if ($presentation->merchandising->accessDuration)
                                                <div><dt class="uppercase tracking-wider text-[var(--sf-muted)]">Durasi</dt><dd class="mt-1">{{ $presentation->merchandising->accessDuration }}</dd></div>
                                            @endif
                                            @if ($presentation->merchandising->region)
                                                <div><dt class="uppercase tracking-wider text-[var(--sf-muted)]">Region</dt><dd class="mt-1">{{ $presentation->merchandising->region }}</dd></div>
                                            @endif
                                            @if ($presentation->merchandising->accountType)
                                                <div><dt class="uppercase tracking-wider text-[var(--sf-muted)]">Tipe akun</dt><dd class="mt-1">{{ $presentation->merchandising->accountType }}</dd></div>
                                            @endif
                                            @if ($presentation->merchandising->deviceLimit)
                                                <div><dt class="uppercase tracking-wider text-[var(--sf-muted)]">Batas perangkat / seat</dt><dd class="mt-1">{{ $presentation->merchandising->deviceLimit }}</dd></div>
                                            @endif
                                        </dl>
                                        @if ($presentation->merchandising->includedItems !== [])
                                            <div class="mt-3 rounded-lg border border-[var(--sf-border)] bg-white p-3 text-xs font-bold">
                                                <p class="uppercase tracking-wider text-[var(--sf-muted)]">Yang termasuk</p>
                                                <ul class="mt-2 list-inside list-disc space-y-1">
                                                    @foreach ($presentation->merchandising->includedItems as $item)
                                                        <li class="break-words">{{ $item }}</li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif
                                        @if ($presentation->merchandising->limitations !== [])
                                            <div class="mt-3 rounded-lg border border-[var(--sf-border)] bg-white p-3 text-xs font-bold">
                                                <p class="uppercase tracking-wider text-[var(--sf-muted)]">Batasan penggunaan</p>
                                                <ul class="mt-2 list-inside list-disc space-y-1">
                                                    @foreach ($presentation->merchandising->limitations as $limitation)
                                                        <li class="break-words">{{ $limitation }}</li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif
                                        @if ($presentation->merchandising->fulfillmentExpectation)
                                            <p class="mt-3 rounded-lg border border-[var(--sf-border)] bg-white p-3 text-xs font-bold"><span class="uppercase tracking-wider text-[var(--sf-muted)]">Pemenuhan:</span> {{ $presentation->merchandising->fulfillmentExpectation }}</p>
                                        @endif
                                        @if ($presentation->merchandising->warrantyPolicy)
                                            <p class="mt-3 rounded-lg border border-[var(--sf-border)] bg-white p-3 text-xs font-bold"><span class="uppercase tracking-wider text-[var(--sf-muted)]">Garansi:</span> {{ $presentation->merchandising->warrantyPolicy }}</p>
                                        @endif
                                        @if ($presentation->merchandising->supportPolicy)
                                            <p class="mt-3 rounded-lg border border-[var(--sf-border)] bg-white p-3 text-xs font-bold"><span class="uppercase tracking-wider text-[var(--sf-muted)]">Dukungan:</span> {{ $presentation->merchandising->supportPolicy }}</p>
                                        @endif
                                    @endif
                                </details>
                            @endif
                        </article>
                    @endforeach
                </div>
                @if ($firstAvailableMemberId !== null)
                    @php($quickBuyPresentation = $members->first(fn ($member): bool => $member->presentation->id === $firstAvailableMemberId)->presentation)
                    <div class="family-quick-buy fixed inset-x-0 bottom-0 z-40 border-t border-[var(--sf-border)] bg-[var(--sf-surface)]/95 px-4 py-3 shadow-[0_-12px_30px_rgb(18_35_63_/_0.12)] backdrop-blur sm:px-6" data-family-quick-buy role="region" aria-label="Pembelian cepat">
                        <div class="mx-auto flex max-w-7xl flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <span class="block text-[10px] font-black uppercase tracking-[0.16em] text-[var(--sf-muted)]">Pilihan Anda</span>
                                <strong data-family-selected-name class="block truncate text-sm font-black text-[var(--sf-ink)]">{{ trim((string) ($quickBuyPresentation->familyOptionLabel ?? $quickBuyPresentation->name)) }}</strong>
                                <span data-family-selected-price class="block text-xs font-bold text-[var(--sf-accent)]">{{ $storefront->idr($quickBuyPresentation->priceAmount) }}</span>
                            </div>
                            <div class="flex w-full items-center gap-2 sm:w-auto">
                                <label for="family-quick-quantity" class="sr-only">Jumlah</label>
                                <div class="family-quantity-control flex h-11 shrink-0 items-center rounded-lg border border-[var(--sf-border)] bg-white">
                                    <button type="button" data-family-quantity-step="-1" class="grid h-10 w-9 place-items-center text-lg font-black text-[var(--sf-muted)]" aria-label="Kurangi jumlah">−</button>
                                    <input id="family-quick-quantity" type="number" value="1" min="1" max="{{ min(20, max(1, $quickBuyPresentation->stockAvailable)) }}" data-family-quantity class="h-10 w-10 border-x border-[var(--sf-border)] bg-transparent text-center text-sm font-black text-[var(--sf-ink)] focus:outline-none">
                                    <button type="button" data-family-quantity-step="1" class="grid h-10 w-9 place-items-center text-lg font-black text-[var(--sf-muted)]" aria-label="Tambah jumlah">+</button>
                                </div>
                                <a href="{{ $storefront->checkoutProductUrl($quickBuyPresentation->id, $familyUrl) }}" data-family-checkout class="neo-button neo-button-stable inline-flex min-h-11 flex-1 items-center justify-center bg-[var(--sf-accent)] px-5 text-sm font-black text-white sm:min-w-44">Beli sekarang</a>
                            </div>
                        </div>
                    </div>
                @endif


                @include('storefront.partials.support-chooser', [
                    'supportContext' => $supportContext,
                    'supportId' => 'family-support',
                    'supportClass' => 'mt-10',
                ])
            </section>
        </main>

        @include('storefront.partials.footer')
    </div>
</body>
</html>
