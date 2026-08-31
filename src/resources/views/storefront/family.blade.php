<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head', ['metadata' => $metadata])
</head>
<body class="relative overflow-x-hidden text-[var(--sf-ink)] antialiased selection:bg-[var(--sf-ink)] selection:text-white">
    <div class="storefront-shell relative z-10 flex min-h-screen flex-col justify-between overflow-x-hidden">
        @include('storefront.partials.header')
        @include('storefront.partials.checkout-maintenance', ['maintenance' => $checkoutMaintenance])

        <main class="flex-grow pb-20 pt-12">
            @include('storefront.partials.breadcrumbs', ['metadata' => $metadata])
            <section class="mx-auto max-w-7xl px-5 sm:px-6">
                <a href="{{ $returnUrl }}" class="inline-flex items-center gap-2 text-sm font-black text-[var(--sf-ink)] hover:text-[var(--sf-accent)]">
                    <span aria-hidden="true">&larr;</span> Kembali ke katalog
                </a>

                <header class="mt-6 overflow-hidden rounded-xl border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] shadow-[7px_7px_0_var(--sf-shadow)]">
                    <div class="grid gap-6 p-5 sm:p-8 lg:grid-cols-[minmax(0,18rem)_minmax(0,1fr)] lg:items-center">
                        <div class="relative flex aspect-[4/3] items-center justify-center overflow-hidden rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)]">
                            @if ($family->imageUrl)
                                <img src="{{ $family->imageUrl }}" alt="{{ $family->name }}" loading="eager" decoding="async" class="absolute inset-0 h-full w-full object-cover">
                            @else
                                <span class="font-display text-7xl font-black text-[var(--sf-accent)]" aria-hidden="true">{{ mb_substr($family->name, 0, 1) }}</span>
                                <span class="sr-only">Gambar {{ $family->name }}</span>
                            @endif
                        </div>
                        <div>
                            <p class="text-xs font-black uppercase tracking-[0.18em] text-[var(--sf-accent)]">Product Family</p>
                            <h1 class="mt-2 font-display text-4xl font-black leading-tight tracking-tight sm:text-6xl">{{ $family->name }}</h1>
                            @if ($family->description)
                                <p class="mt-4 max-w-3xl whitespace-pre-line break-words text-sm font-semibold leading-7 text-[var(--sf-muted)]">{{ $family->description }}</p>
                            @endif
                            <div class="mt-5 flex flex-wrap gap-2 text-xs font-black">
                                <span class="rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-2">{{ $family->optionCount }} opsi</span>
                                <span class="rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-surface-warm)] px-3 py-2">{{ $family->availableOptionCount }} tersedia</span>
                                <span class="rounded-full border-2 border-[var(--sf-border)] bg-white px-3 py-2">Mulai {{ $family->minimumPriceAmount !== null ? $storefront->idr($family->minimumPriceAmount) : '—' }}</span>
                                @if ($family->minimumUsdtPriceAmount !== null)
                                    <span class="rounded-full border-2 border-[var(--sf-border)] bg-white px-3 py-2">{{ $storefront->usdt($family->minimumUsdtPriceAmount) }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </header>

                <div class="mt-10 flex items-end justify-between gap-4">
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.18em] text-[var(--sf-accent)]">Pilih SKU</p>
                        <h2 class="mt-2 font-display text-3xl font-black tracking-tight sm:text-4xl">Pilihan yang tersedia</h2>
                    </div>
                    <span class="hidden rounded-full border-2 border-[var(--sf-border)] bg-white px-3 py-2 text-xs font-black sm:inline-flex">{{ $family->optionCount }} opsi</span>
                </div>

                <div id="family-options" class="mt-6 grid gap-5 md:grid-cols-2">
                    @foreach ($members as $member)
                        @php
                            $presentation = $member->presentation;
                            $optionLabel = trim((string) $presentation->familyOptionLabel);
                            if ($optionLabel === '') {
                                $optionLabel = trim((string) $presentation->name);
                            }
                            $productUrl = $presentation->productUrl.(str_contains($presentation->productUrl, '?') ? '&' : '?').'from='.urlencode($familyUrl);
                            $checkoutUrl = $presentation->productUrl.(str_contains($presentation->productUrl, '?') ? '&' : '?').'checkout=1&from='.urlencode($familyUrl);
                        @endphp
                        <article id="family-option-{{ $presentation->id }}" data-product-id="{{ $presentation->id }}" class="neo-panel relative overflow-hidden bg-[var(--sf-surface)] p-5 sm:p-6">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-[var(--sf-accent)]">SKU #{{ $presentation->id }}</p>
                                    <h3 class="mt-2 font-display text-2xl font-black leading-tight">{{ $optionLabel }}</h3>
                                    @if ($optionLabel !== $presentation->name)
                                        <p class="mt-1 text-xs font-bold text-[var(--sf-muted)]">{{ $presentation->name }}</p>
                                    @endif
                                </div>
                                <span class="rounded-full border-2 border-[var(--sf-border)] px-3 py-1 text-xs font-black {{ $presentation->isAvailable ? 'bg-[var(--sf-yellow)]' : 'bg-[#ffd7d7] text-[#9f1d2d]' }}">
                                    {{ $presentation->isAvailable ? 'Tersedia' : 'Stok habis' }}
                                </span>
                            </div>

                            <p class="mt-4 whitespace-pre-line break-words text-sm font-semibold leading-6 text-[var(--sf-muted)]">{{ $presentation->descriptionSnippet }}</p>

                            @if ($presentation->merchandising->hasDetails())
                                <dl class="mt-5 grid gap-3 border-y-[3px] border-dashed border-[var(--sf-border)] py-4 text-xs font-bold sm:grid-cols-2">
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
                                </dl>
                                @if ($presentation->merchandising->includedItems !== [])
                                    <div class="mt-3 rounded-lg border-2 border-[var(--sf-border)] bg-white p-3 text-xs font-bold">
                                        <p class="uppercase tracking-wider text-[var(--sf-muted)]">Yang termasuk</p>
                                        <ul class="mt-2 list-inside list-disc space-y-1">
                                            @foreach ($presentation->merchandising->includedItems as $item)
                                                <li class="break-words">{{ $item }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                                @if ($presentation->merchandising->limitations !== [])
                                    <div class="mt-3 rounded-lg border-2 border-[var(--sf-border)] bg-white p-3 text-xs font-bold">
                                        <p class="uppercase tracking-wider text-[var(--sf-muted)]">Batasan penggunaan</p>
                                        <ul class="mt-2 list-inside list-disc space-y-1">
                                            @foreach ($presentation->merchandising->limitations as $limitation)
                                                <li class="break-words">{{ $limitation }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                                @if ($presentation->merchandising->fulfillmentExpectation)
                                    <p class="mt-3 rounded-lg border-2 border-[var(--sf-border)] bg-white p-3 text-xs font-bold"><span class="uppercase tracking-wider text-[var(--sf-muted)]">Pemenuhan:</span> {{ $presentation->merchandising->fulfillmentExpectation }}</p>
                                @endif
                                @if ($presentation->merchandising->warrantyPolicy)
                                    <p class="mt-3 rounded-lg border-2 border-[var(--sf-border)] bg-white p-3 text-xs font-bold"><span class="uppercase tracking-wider text-[var(--sf-muted)]">Garansi:</span> {{ $presentation->merchandising->warrantyPolicy }}</p>
                                @endif
                                @if ($presentation->merchandising->supportPolicy)
                                    <p class="mt-3 rounded-lg border-2 border-[var(--sf-border)] bg-white p-3 text-xs font-bold"><span class="uppercase tracking-wider text-[var(--sf-muted)]">Dukungan:</span> {{ $presentation->merchandising->supportPolicy }}</p>
                                @endif
                            @endif

                            <div class="mt-5 flex flex-wrap items-center gap-2 text-xs font-black">
                                <span class="rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-2">{{ $storefront->idr($presentation->priceAmount) }}</span>
                                <span class="rounded-full border-2 border-[var(--sf-border)] bg-white px-3 py-2">{{ $presentation->stockLabel }}</span>
                                <span class="rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-surface-warm)] px-3 py-2">{{ $presentation->soldLabel }}</span>
                            </div>

                            <div class="mt-5 flex flex-col gap-3 sm:flex-row">
                                <a href="{{ $presentation->isAvailable ? $checkoutUrl : $productUrl }}" class="neo-button inline-flex min-h-12 flex-1 items-center justify-center bg-[var(--sf-accent)] px-4 text-sm font-black text-white hover:bg-[var(--sf-accent-deep)]">
                                    {{ $presentation->isAvailable ? 'Pilih opsi & checkout' : 'Lihat detail opsi' }}
                                </a>
                                @if ($presentation->isAvailable)
                                    <form method="POST" action="{{ route('storefront.shop.cart.items.store') }}" class="flex-1">
                                        @csrf
                                        <input type="hidden" name="product_id" value="{{ $presentation->id }}">
                                        <input type="hidden" name="quantity" value="1">
                                        <button type="submit" class="inline-flex min-h-12 w-full items-center justify-center rounded-[0.7rem] border-[3px] border-[var(--sf-border)] bg-white px-4 text-sm font-black text-[var(--sf-ink)] hover:bg-[var(--sf-yellow)]">Tambah ke keranjang</button>
                                    </form>
                                @else
                                    <a href="{{ $productUrl }}#waitlist" class="inline-flex min-h-12 flex-1 items-center justify-center rounded-[0.7rem] border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] px-4 text-sm font-black hover:bg-white">Beri tahu saat tersedia</a>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>

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
