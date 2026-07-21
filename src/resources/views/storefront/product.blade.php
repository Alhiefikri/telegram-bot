<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head')
</head>
<body class="overflow-x-hidden antialiased selection:bg-[var(--sf-ink)] selection:text-white relative text-[var(--sf-ink)]">
    @php
        $checkoutRestore = [
            'productId' => old('product_id'),
            'quantity' => old('quantity', 1),
            'buyerName' => old('buyer_name', ''),
            'supportContactChannel' => old('support_contact_channel', ''),
            'supportContactValue' => old('support_contact_value', ''),
            'paymentMethod' => old('payment_method'),
        ];
        $checkoutPayload = $isAvailable ? $presentation->modalPayload() : null;
    @endphp

    <div class="storefront-shell relative min-h-screen z-10 flex flex-col justify-between overflow-x-hidden"
         x-data="storefrontModal()"
         data-checkout-restore='@json($checkoutRestore, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)'
         @if ($checkoutRequested && $checkoutPayload) data-checkout-product='@json($checkoutPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)' @endif>
        @include('storefront.partials.header')

        <main class="flex-grow pb-20 pt-12">
            <section class="mx-auto max-w-5xl px-5 sm:px-6">
                <a href="{{ $returnUrl }}" class="inline-flex items-center gap-2 text-sm font-black text-[var(--sf-ink)] hover:text-[var(--sf-accent)]">
                    <span aria-hidden="true">&larr;</span> Kembali ke katalog
                </a>

                @if (session('storefront_notice'))
                    <x-storefront.notice class="mt-6">
                        {{ session('storefront_notice') }}
                    </x-storefront.notice>
                @endif

                <div class="mt-6 grid gap-8 lg:grid-cols-[0.92fr_1.08fr] lg:items-start">
                    <div class="neo-panel overflow-hidden bg-[var(--sf-surface)]">
                        @include('storefront.components.product-cover', [
                            'product' => $product,
                            'isAvailable' => $isAvailable,
                            'isPopular' => false,
                        ])
                    </div>

                    <article class="neo-panel relative overflow-hidden bg-[var(--sf-surface)] p-5 sm:p-7">
                        <div class="pointer-events-none absolute inset-0 bg-grid-pattern opacity-40"></div>
                        <div class="relative z-10">
                            <div class="mb-5 flex flex-wrap items-center gap-2">
                                <x-storefront.badge class="font-display text-sm normal-case">
                                    {{ $storefront->idr($product->price_amount) }}
                                </x-storefront.badge>
                                <x-storefront.badge :tone="$isAvailable ? 'surface' : 'danger'" class="text-xs normal-case">
                                    {{ $isAvailable ? 'Stok: '.$stock : 'Stok habis' }}
                                </x-storefront.badge>
                            </div>

                            <h1 class="font-display text-3xl font-black leading-tight tracking-tight text-[var(--sf-ink)] sm:text-5xl">{{ $product->name }}</h1>
                            <p class="mt-4 max-w-xl text-sm font-semibold leading-7 text-[var(--sf-muted)]">{{ $storefront->descriptionSnippet($product) }}</p>

                            <div class="mt-6 border-y-[3px] border-dashed border-[var(--sf-border)] py-5">
                                <dl class="grid gap-3 text-sm font-bold sm:grid-cols-2">
                                    <div class="rounded-lg border-2 border-[var(--sf-border)] bg-white p-3">
                                        <dt class="text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">Produk</dt>
                                        <dd class="mt-1 text-[var(--sf-ink)]">{{ $product->name }}</dd>
                                    </div>
                                    <div class="rounded-lg border-2 border-[var(--sf-border)] bg-white p-3">
                                        <dt class="text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">Status</dt>
                                        <dd class="mt-1 text-[var(--sf-ink)]">{{ $isAvailable ? 'Siap dibeli' : 'Restock segera' }}</dd>
                                    </div>
                                </dl>
                            </div>

                            <div class="mt-6 flex flex-col gap-3 sm:flex-row">
                                @if ($isAvailable)
                                    <x-storefront.button
                                        :href="$checkoutUrl"
                                        id="product-checkout-cta"
                                        class="flex-1"
                                        x-on:click.prevent="openProductModal(JSON.parse($el.dataset.product), { opener: $el })"
                                        data-product='@json($checkoutPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)'
                                    >
                                        Beli via Web QRIS
                                    </x-storefront.button>
                                @else
                                    <x-storefront.button variant="danger" class="flex-1 cursor-not-allowed opacity-80" disabled>
                                        Habis
                                    </x-storefront.button>
                                @endif

                                <x-storefront.button :href="route('storefront.order-access.create')" variant="secondary" class="flex-1">
                                    Akses Pesanan
                                </x-storefront.button>
                            </div>

                            @include('storefront.partials.support-chooser', [
                                'supportContext' => $supportContext,
                                'supportId' => 'product-support',
                                'supportClass' => 'mt-6',
                            ])

                            @if ($isAvailable)
                                <noscript>
                                    <form method="POST" action="{{ route('storefront.shop.checkout') }}" class="mt-6 grid gap-3 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4">
                                        @csrf
                                        <input type="hidden" name="product_id" value="{{ $product->id }}">
                                        <input type="hidden" name="payment_method" value="qris">
                                        <label class="grid gap-2 text-sm font-black uppercase tracking-wider text-[var(--sf-ink)]">
                                            Jumlah
                                            <input name="quantity" type="number" min="1" max="{{ min($stock, 20) }}" value="{{ old('quantity', 1) }}" class="neo-input h-12 bg-white px-3 text-base font-bold">
                                        </label>
                                        <label class="grid gap-2 text-sm font-black uppercase tracking-wider text-[var(--sf-ink)]">
                                            {{ $copy['shop']['buyer_name_label'] }}
                                            <input name="buyer_name" type="text" required value="{{ old('buyer_name') }}" placeholder="Nama pembeli" class="neo-input h-12 bg-white px-3 text-base font-bold">
                                        </label>
                                        <p class="text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">{{ $copy['shop']['buyer_name_help'] }}</p>
                                        <fieldset class="grid gap-2 rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-3">
                                            <legend class="text-sm font-black uppercase tracking-wider text-[var(--sf-ink)]">{{ $copy['shop']['support_contact_label'] }}</legend>
                                            <select name="support_contact_channel" class="neo-input h-12 bg-white px-3 text-base font-bold">
                                                <option value="">Tidak perlu</option>
                                                <option value="telegram" @selected(old('support_contact_channel') === 'telegram')>Telegram</option>
                                                <option value="whatsapp" @selected(old('support_contact_channel') === 'whatsapp')>WhatsApp</option>
                                            </select>
                                            <input name="support_contact_value" type="text" value="{{ old('support_contact_value') }}" placeholder="@username atau nomor WhatsApp" class="neo-input h-12 bg-white px-3 text-base font-bold">
                                            <p class="text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">{{ $copy['shop']['support_contact_help'] }}</p>
                                        </fieldset>
                                        <button type="submit" class="neo-button inline-flex h-12 items-center justify-center bg-[var(--sf-accent)] px-5 text-sm font-black text-white">Bayar QRIS di Website</button>
                                    </form>
                                </noscript>
                            @endif
                        </div>
                    </article>
                </div>
            </section>
        </main>

        @include('storefront.partials.footer')
        @include('storefront.partials.product-modal')
    </div>
</body>
</html>
