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
            @php
                $firstAvailableMember = $members->first(fn ($member): bool => $member->presentation->isAvailable);
                $firstAvailableMemberId = $firstAvailableMember?->presentation->id;
                $quickBuyPresentation = $firstAvailableMember?->presentation;
                $selectedMemberId = $firstAvailableMemberId ?? $members->first()?->presentation->id;
                $familyQuickBuyOptions = $members->map(function ($member) use ($storefront, $familyUrl, $selectedMemberId): array {
                    $presentation = $member->presentation;

                    return [
                        'id' => $presentation->id,
                        'name' => trim((string) ($presentation->familyOptionLabel ?? $presentation->name)),
                        'priceLabel' => $storefront->idr($presentation->priceAmount),
                        'stock' => $presentation->stockAvailable,
                        'selected' => $selectedMemberId === $presentation->id,
                        'productUrl' => $storefront->productUrl($presentation->id, $familyUrl),
                        'checkoutUrl' => $storefront->checkoutProductUrl($presentation->id, $familyUrl),
                    ];
                })->values()->all();
            @endphp
            <section class="family-layout mx-auto max-w-7xl px-5 sm:px-6" x-data="familyQuickBuy(@js($familyQuickBuyOptions))">
                <a href="{{ $returnUrl }}" class="inline-flex items-center gap-2 text-sm font-black text-[var(--sf-ink)] hover:text-[var(--sf-accent)]">
                    <span aria-hidden="true">&larr;</span> Kembali ke katalog
                </a>

                <section class="family-product-panel mt-5 overflow-hidden rounded-2xl border border-[var(--sf-border)] bg-[var(--sf-surface)] shadow-[0_12px_30px_rgb(18_35_63_/_0.08)]" aria-labelledby="family-title">
                    <div class="grid lg:grid-cols-[minmax(17rem,0.82fr)_minmax(0,1.18fr)]">
                        <div class="family-hero-art relative flex aspect-[16/10] items-center justify-center overflow-hidden border-b border-[var(--sf-border)] bg-[var(--sf-yellow)] lg:aspect-auto lg:border-b-0 lg:border-r">
                            @if ($family->imageUrl)
                                <img src="{{ $family->imageUrl }}" alt="{{ $family->name }}" loading="eager" decoding="async" class="absolute inset-0 h-full w-full object-cover">
                            @else
                                <span class="font-display text-7xl font-black text-[var(--sf-accent)]" aria-hidden="true">{{ mb_substr($family->name, 0, 1) }}</span>
                                <span class="sr-only">Gambar {{ $family->name }}</span>
                            @endif
                        </div>

                        <div class="min-w-0 p-5 sm:p-7">
                            <p class="text-xs font-black uppercase tracking-[0.18em] text-[var(--sf-accent)]">Pilih paket</p>
                            <h1 id="family-title" class="mt-2 font-display text-3xl font-black leading-tight tracking-tight sm:text-5xl">{{ $family->name }}</h1>
                            @if ($family->description)
                                <p class="mt-3 max-w-3xl whitespace-pre-line break-words text-sm font-semibold leading-6 text-[var(--sf-muted)]">{{ $family->description }}</p>
                            @endif

                            <div class="mt-4 flex flex-wrap items-center gap-2 text-xs font-black">
                                <span class="rounded-full border border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-1.5">{{ $family->optionCount }} varian</span>
                                <span class="rounded-full border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] px-3 py-1.5">{{ $family->availableOptionCount }} tersedia</span>
                                <span class="rounded-full border border-[var(--sf-border)] bg-white px-3 py-1.5">Mulai {{ $family->minimumPriceAmount !== null ? $storefront->idr($family->minimumPriceAmount) : '—' }}</span>
                            </div>

                            <div class="mt-6 border-t border-dashed border-[var(--sf-border)] pt-5">
                                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-[var(--sf-muted)]">Pilih varian</p>
                                <div id="family-options" class="family-option-picker mt-3 flex flex-wrap items-center gap-2" role="radiogroup" aria-orientation="horizontal" aria-label="Pilih varian {{ $family->name }}">
                                    @foreach ($members as $member)
                                        @php
                                            $presentation = $member->presentation;
                                            $option = $familyQuickBuyOptions[$loop->index];
                                            $optionLabel = $option['name'];
                                            $productUrl = $option['productUrl'];
                                            $checkoutUrl = $option['checkoutUrl'];
                                            $detailId = 'family-option-detail-'.$presentation->id;
                                            $isSelected = $option['selected'];
                                            $description = trim((string) $presentation->description);
                                        @endphp
                                        <article
                                            id="family-option-{{ $presentation->id }}"
                                            aria-labelledby="family-option-title-{{ $presentation->id }}"
                                            data-product-id="{{ $presentation->id }}"
                                            data-family-option
                                            data-family-option-name="{{ $optionLabel }}"
                                            data-family-option-price="{{ $presentation->priceAmount }}"
                                            data-family-option-stock="{{ $presentation->stockAvailable }}"
                                            data-family-option-product-url="{{ $productUrl }}"
                                            data-family-checkout-url="{{ $checkoutUrl }}"
                                            data-family-detail-id="{{ $detailId }}"
                                            @if ($isSelected) data-family-selected="true" @endif
                                            x-bind:data-family-selected="isSelected('{{ $presentation->id }}') ? 'true' : null"
                                            x-bind:class="{ 'family-option-selected': isSelected('{{ $presentation->id }}') }"
                                            class="family-option {{ $isSelected ? 'family-option-selected' : '' }} relative min-w-0"
                                        >
                                            <a
                                                href="{{ $productUrl }}"
                                                id="family-option-button-{{ $presentation->id }}"
                                                x-ref="family-option-button-{{ $presentation->id }}"
                                                data-family-option-button
                                                role="radio"
                                                aria-controls="{{ $detailId }}"
                                                aria-checked="{{ $isSelected ? 'true' : 'false' }}"
                                                x-bind:aria-checked="isSelected('{{ $presentation->id }}') ? 'true' : 'false'"
                                                x-bind:tabindex="isSelected('{{ $presentation->id }}') ? 0 : -1"
                                                aria-label="Pilih varian {{ $optionLabel }}"
                                                @click.prevent="selectOption('{{ $presentation->id }}')"
                                                @keydown="handleOptionKeydown($event, '{{ $presentation->id }}', $refs)"
                                                class="family-option-button group inline-flex min-h-11 min-w-[7.5rem] max-w-full items-center justify-between gap-2 rounded-lg border border-[var(--sf-border)] bg-[var(--sf-surface)] px-3 py-2 text-left transition"
                                            >
                                                <span id="family-option-title-{{ $presentation->id }}" class="min-w-0 truncate font-display text-sm font-black leading-tight text-[var(--sf-ink)]">{{ $optionLabel }}</span>
                                                <span class="h-2 w-2 shrink-0 rounded-full {{ $presentation->isAvailable ? 'bg-[var(--sf-success)]' : 'bg-[#c94856]' }}" aria-hidden="true"></span>
                                            </a>

                                            <section
                                                id="{{ $detailId }}"
                                                data-family-option-detail
                                                data-family-detail-product-id="{{ $presentation->id }}"
                                                aria-labelledby="family-option-detail-title-{{ $presentation->id }}"
                                                aria-hidden="{{ $isSelected ? 'false' : 'true' }}"
                                                x-bind:aria-hidden="isSelected('{{ $presentation->id }}') ? 'false' : 'true'"
                                                x-bind:hidden="!isSelected('{{ $presentation->id }}')"
                                                @if (! $isSelected) hidden @endif
                                                class="family-option-detail mt-4 rounded-xl border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4"
                                            >

                                                <div class="flex flex-wrap items-center justify-between gap-2">
                                                    <strong class="font-display text-xl text-[var(--sf-accent)]">{{ $option['priceLabel'] }}</strong>
                                                    <span class="rounded-full border border-[var(--sf-border)] px-2.5 py-1 text-[10px] font-black {{ $presentation->isAvailable ? 'bg-[var(--sf-yellow)]' : 'bg-[#ffd7d7] text-[#9f1d2d]' }}">{{ $presentation->isAvailable ? $presentation->stockLabel : 'Stok habis' }}</span>
                                                </div>

                                                <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                                                    @if ($presentation->isAvailable)
                                                        <a href="{{ $checkoutUrl }}" data-family-direct-checkout class="neo-button neo-button-stable inline-flex min-h-11 flex-1 items-center justify-center bg-[var(--sf-accent)] px-4 text-sm font-black text-white hover:bg-[var(--sf-accent-deep)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--sf-accent)]">Beli sekarang</a>
                                                        <form method="POST" action="{{ route('storefront.shop.cart.items.store') }}" class="flex-1">
                                                            @csrf
                                                            <input type="hidden" name="product_id" value="{{ $presentation->id }}">
                                                            <input type="hidden" name="quantity" value="1">
                                                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-[0.7rem] border border-[var(--sf-border)] bg-white px-4 text-sm font-black text-[var(--sf-ink)] hover:bg-[var(--sf-yellow)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--sf-accent)]">Tambah ke keranjang</button>
                                                        </form>
                                                    @else
                                                        <a href="{{ $productUrl }}#waitlist" class="inline-flex min-h-11 flex-1 items-center justify-center rounded-[0.7rem] border border-[var(--sf-border)] bg-[var(--sf-yellow)] px-4 text-sm font-black hover:bg-white focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--sf-accent)]">{{ $presentation->waitlistCtaLabel }}</a>
                                                    @endif
                                                </div>
                                                <div class="mt-4 grid gap-3">
                                                    <div class="flex gap-3 rounded-lg border border-[var(--sf-border)] bg-white p-3">
                                                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-[var(--sf-accent-soft)] text-[var(--sf-accent)]" aria-hidden="true">
                                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 5.5A2.5 2.5 0 0 1 7.5 3H20v16H7.5A2.5 2.5 0 0 0 5 21V5.5Z"/><path stroke-linecap="round" d="M8 7h8M8 11h7"/></svg>
                                                        </span>
                                                        <div class="min-w-0">
                                                            <p class="text-[10px] font-black uppercase tracking-[0.14em] text-[var(--sf-muted)]">Deskripsi</p>
                                                            <p class="mt-1 whitespace-pre-line break-words text-sm font-semibold leading-6 text-[var(--sf-ink)]">{{ $description !== '' ? $description : $presentation->descriptionSnippet }}</p>
                                                        </div>
                                                    </div>

                                                    @if ($presentation->merchandising->warrantyPolicy)
                                                        <div class="storefront-product-note flex gap-3 rounded-lg border border-[#e5b52f] bg-[#fff7d6] p-3">
                                                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-[var(--sf-yellow)]" aria-hidden="true">
                                                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3 5 6v5c0 4.6 2.9 8.4 7 10 4.1-1.6 7-5.4 7-10V6l-7-3Z"/><path stroke-linecap="round" d="m9 12 2 2 4-4"/></svg>
                                                            </span>
                                                            <div class="min-w-0">
                                                                <p class="text-[10px] font-black uppercase tracking-[0.14em] text-[#765800]">Catatan garansi</p>
                                                                <p class="mt-1 whitespace-pre-line break-words text-sm font-semibold leading-6">{{ $presentation->merchandising->warrantyPolicy }}</p>
                                                            </div>
                                                        </div>
                                                    @endif
                                                </div>

                                            </section>
                                        </article>
                                    @endforeach
                                </div>
                                <p class="sr-only" aria-live="polite" aria-atomic="true" x-text="announcement"></p>
                            </div>
                        </div>
                    </div>
                </section>

                @if ($quickBuyPresentation !== null)
                    <div class="family-quick-buy fixed inset-x-0 bottom-0 z-40 border-t border-[var(--sf-border)] bg-[var(--sf-surface)]/95 px-4 py-3 shadow-[0_-12px_30px_rgb(18_35_63_/_0.12)] backdrop-blur sm:px-6" data-family-quick-buy role="region" aria-label="Pembelian cepat" x-bind:data-family-selected-unavailable="isAvailable ? null : 'true'">
                        <div class="mx-auto flex max-w-7xl flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <span class="block text-[10px] font-black uppercase tracking-[0.16em] text-[var(--sf-muted)]">Pilihan Anda</span>
                                <strong data-family-selected-name class="block truncate text-sm font-black text-[var(--sf-ink)]" x-text="selectedOption ? selectedOption.name : ''">{{ trim((string) ($quickBuyPresentation->familyOptionLabel ?? $quickBuyPresentation->name)) }}</strong>
                                <span data-family-selected-price class="block text-xs font-bold text-[var(--sf-accent)]" x-text="selectedOption ? selectedOption.priceLabel : ''">{{ $storefront->idr($quickBuyPresentation->priceAmount) }}</span>
                            </div>
                            <div class="flex w-full items-center gap-2 sm:w-auto">
                                <label for="family-quick-quantity" class="sr-only">Jumlah</label>
                                <div class="family-quantity-control flex h-11 shrink-0 items-center rounded-lg border border-[var(--sf-border)] bg-white">
                                    <button type="button" data-family-quantity-step="-1" class="grid h-10 w-9 place-items-center text-lg font-black text-[var(--sf-muted)]" aria-label="Kurangi jumlah" x-bind:disabled="!isAvailable" @click="adjustQuantity(-1)">−</button>
                                    <input id="family-quick-quantity" type="number" value="1" min="1" max="{{ min(20, max(1, $quickBuyPresentation->stockAvailable)) }}" step="1" data-family-quantity class="h-10 w-10 border-x border-[var(--sf-border)] bg-transparent text-center text-sm font-black text-[var(--sf-ink)] focus:outline-none" x-bind:min="quantityMinimum" x-bind:max="quantityMaximum" x-bind:value="quantity" x-bind:disabled="!isAvailable" @input="setQuantity($event.target.value)">
                                    <button type="button" data-family-quantity-step="1" class="grid h-10 w-9 place-items-center text-lg font-black text-[var(--sf-muted)]" aria-label="Tambah jumlah" x-bind:disabled="!isAvailable" @click="adjustQuantity(1)">+</button>
                                </div>
                                <a href="{{ $storefront->checkoutProductUrl($quickBuyPresentation->id, $familyUrl) }}" data-family-checkout class="neo-button neo-button-stable inline-flex min-h-11 flex-1 items-center justify-center bg-[var(--sf-accent)] px-5 text-sm font-black text-white sm:min-w-44" x-bind:href="checkoutHref" x-bind:aria-label="checkoutAriaLabel" x-text="checkoutLabel">Beli sekarang</a>
                            </div>
                        </div>
                    </div>
                @endif
            </section>
        </main>

        @include('storefront.partials.footer')
    </div>
</body>
</html>
