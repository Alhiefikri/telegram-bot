<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head', ['metadata' => $metadata])
</head>
<body class="overflow-x-hidden antialiased selection:bg-[var(--sf-ink)] selection:text-white relative text-[var(--sf-ink)]">
    @php
        $checkoutRestore = [
            'productId' => old('product_id'),
            'quantity' => old('quantity', 1),
            'buyerName' => old('buyer_name', ''),
            'supportContactChannel' => old('support_contact_channel', ''),
            'supportContactValue' => old('support_contact_value', ''),
            'email' => old('email', ''),
            'paymentMethod' => old('payment_method'),
            'termsAccepted' => in_array(old('terms_accepted'), [true, 1, '1', 'on', 'yes', 'true'], true),
        ];
        $checkoutPayload = $presentation->isBuyable ? $presentation->modalPayload() : null;
        $checkoutMaintenanceActive = $checkoutMaintenance->active;
    @endphp

    <div class="storefront-shell relative min-h-screen z-10 flex flex-col justify-between overflow-x-hidden"
         x-data="storefrontModal()"
         data-checkout-restore='@json($checkoutRestore, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)'
         @if ($checkoutRequested && $checkoutPayload) data-checkout-product='@json($checkoutPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)' @endif>
        @include('storefront.partials.header')

            @include('storefront.partials.checkout-maintenance', ['maintenance' => $checkoutMaintenance])
        <main class="flex-grow pb-20 pt-12">
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
                            @if (trim($presentation->description) !== '')
                                <p class="mt-4 max-w-2xl whitespace-pre-line break-words text-sm font-semibold leading-7 text-[var(--sf-muted)]">{{ $presentation->description }}</p>
                            @else
                                <p class="mt-4 max-w-xl text-sm font-semibold leading-7 text-[var(--sf-muted)]">{{ $presentation->descriptionSnippet }}</p>
                            @endif

                            @if ($presentation->merchandising->hasDetails())
                                <section class="mt-6 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4" aria-labelledby="product-merchandising-title">
                                    <h2 id="product-merchandising-title" class="font-display text-lg font-black text-[var(--sf-ink)]">Detail produk</h2>
                                    <dl class="mt-3 grid gap-3 text-sm font-bold sm:grid-cols-2">
                                        @if ($presentation->merchandising->productType)
                                            <div class="rounded-lg border-2 border-[var(--sf-border)] bg-white p-3">
                                                <dt class="text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">Tipe produk</dt>
                                                <dd class="mt-1 whitespace-pre-line break-words text-[var(--sf-ink)]">{{ $presentation->merchandising->productType }}</dd>
                                            </div>
                                        @endif
                                        @if ($presentation->merchandising->accessDuration)
                                            <div class="rounded-lg border-2 border-[var(--sf-border)] bg-white p-3">
                                                <dt class="text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">Durasi akses</dt>
                                                <dd class="mt-1 whitespace-pre-line break-words text-[var(--sf-ink)]">{{ $presentation->merchandising->accessDuration }}</dd>
                                            </div>
                                        @endif
                                        @if ($presentation->merchandising->region)
                                            <div class="rounded-lg border-2 border-[var(--sf-border)] bg-white p-3">
                                                <dt class="text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">Region / negara</dt>
                                                <dd class="mt-1 whitespace-pre-line break-words text-[var(--sf-ink)]">{{ $presentation->merchandising->region }}</dd>
                                            </div>
                                        @endif
                                        @if ($presentation->merchandising->accountType)
                                            <div class="rounded-lg border-2 border-[var(--sf-border)] bg-white p-3">
                                                <dt class="text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">Tipe akun</dt>
                                                <dd class="mt-1 whitespace-pre-line break-words text-[var(--sf-ink)]">{{ $presentation->merchandising->accountType }}</dd>
                                            </div>
                                        @endif
                                        @if ($presentation->merchandising->deviceLimit)
                                            <div class="rounded-lg border-2 border-[var(--sf-border)] bg-white p-3">
                                                <dt class="text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">Batas perangkat / seat</dt>
                                                <dd class="mt-1 whitespace-pre-line break-words text-[var(--sf-ink)]">{{ $presentation->merchandising->deviceLimit }}</dd>
                                            </div>
                                        @endif
                                    </dl>

                                    @if ($presentation->merchandising->includedItems !== [])
                                        <div class="mt-4 rounded-lg border-2 border-[var(--sf-border)] bg-white p-3 text-sm font-bold">
                                            <h3 class="text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">Yang termasuk</h3>
                                            <ul class="mt-2 list-inside list-disc space-y-1 text-[var(--sf-ink)]">
                                                @foreach ($presentation->merchandising->includedItems as $item)
                                                    <li class="break-words">{{ $item }}</li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif

                                    @if ($presentation->merchandising->limitations !== [])
                                        <div class="mt-3 rounded-lg border-2 border-[var(--sf-border)] bg-white p-3 text-sm font-bold">
                                            <h3 class="text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">Batasan penggunaan</h3>
                                            <ul class="mt-2 list-inside list-disc space-y-1 text-[var(--sf-ink)]">
                                                @foreach ($presentation->merchandising->limitations as $limitation)
                                                    <li class="break-words">{{ $limitation }}</li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif

                                    @if ($presentation->merchandising->fulfillmentExpectation)
                                        <div class="mt-3 rounded-lg border-2 border-[var(--sf-border)] bg-white p-3 text-sm font-bold">
                                            <dt class="text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">Ekspektasi pemenuhan</dt>
                                            <dd class="mt-1 whitespace-pre-line break-words text-[var(--sf-ink)]">{{ $presentation->merchandising->fulfillmentExpectation }}</dd>
                                        </div>
                                    @endif

                                    @if ($presentation->merchandising->warrantyPolicy)
                                        <div class="mt-3 rounded-lg border-2 border-[var(--sf-border)] bg-white p-3 text-sm font-bold">
                                            <dt class="text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">Kebijakan garansi</dt>
                                            <dd class="mt-1 whitespace-pre-line break-words text-[var(--sf-ink)]">{{ $presentation->merchandising->warrantyPolicy }}</dd>
                                        </div>
                                    @endif

                                    @if ($presentation->merchandising->supportPolicy)
                                        <div class="mt-3 rounded-lg border-2 border-[var(--sf-border)] bg-white p-3 text-sm font-bold">
                                            <dt class="text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">Dukungan pelanggan</dt>
                                            <dd class="mt-1 whitespace-pre-line break-words text-[var(--sf-ink)]">{{ $presentation->merchandising->supportPolicy }}</dd>
                                        </div>
                                    @endif
                                </section>
                            @endif

                            <div class="mt-6 border-y-[3px] border-dashed border-[var(--sf-border)] py-5">
                                <dl class="grid gap-3 text-sm font-bold sm:grid-cols-2">
                                    <div class="rounded-lg border-2 border-[var(--sf-border)] bg-white p-3">
                                        <dt class="text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">Produk</dt>
                                        <dd class="mt-1 text-[var(--sf-ink)]">{{ $product->name }}</dd>
                                    </div>
                                    <div class="rounded-lg border-2 border-[var(--sf-border)] bg-white p-3">
                                        <dt class="text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">Status</dt>
                                        <dd class="mt-1 text-[var(--sf-ink)]">{{ $presentation->statusLabel }}</dd>
                                    </div>
                                </dl>
                            </div>

                            <div class="mt-6 flex flex-col gap-3 sm:flex-row">
                                @if ($presentation->isBuyable)
                                    <x-storefront.button
                                        :href="$checkoutUrl"
                                        id="product-checkout-cta"
                                        class="neo-button-stable flex-1"
                                        x-on:click.prevent="openProductModal(JSON.parse($el.dataset.product), { opener: $el })"
                                        data-product='{{ json_encode($checkoutPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}'
                                    >
                                        {{ $presentation->primaryCheckoutChoice()?->label ?? $copy['shop']['checkout_cta'] }}
                                    </x-storefront.button>
                                @elseif ($checkoutMaintenanceActive)
                                    <x-storefront.button variant="warning" class="flex-1 cursor-not-allowed opacity-80" disabled>
                                        Checkout sedang maintenance
                                    </x-storefront.button>
                                @elseif (! $isAvailable)
                                    <x-storefront.button variant="danger" class="flex-1 cursor-not-allowed opacity-80" disabled>
                                        Habis
                                    </x-storefront.button>
                                @else
                                    <x-storefront.button variant="warning" class="flex-1 cursor-not-allowed opacity-80" disabled>
                                        {{ $presentation->checkoutReadiness->label }}
                                    </x-storefront.button>
                                    <p role="status" class="text-sm font-semibold leading-6 text-[var(--sf-muted)]">
                                        {{ $presentation->checkoutReadiness->notice }}
                                    </p>
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

                            @if ($presentation->isBuyable)
                                <noscript>
                                    <form method="POST" action="{{ route('storefront.shop.cart.items.store') }}" class="mb-3 grid gap-3 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] p-4">
                                        @csrf
                                        <input type="hidden" name="product_id" value="{{ $product->id }}">
                                        <label class="grid gap-2 text-sm font-black uppercase tracking-wider text-[var(--sf-ink)]">
                                            Jumlah untuk keranjang
                                            <input name="quantity" type="number" min="1" max="{{ min($stock, 20) }}" value="{{ old('quantity', 1) }}" class="neo-input h-12 bg-white px-3 text-base font-bold">
                                        </label>
                                        <button type="submit" class="neo-button inline-flex h-12 items-center justify-center bg-white px-5 text-sm font-black text-[var(--sf-ink)]">Tambah ke keranjang</button>
                                    </form>
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
                                        <div class="grid gap-2 rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-3 text-sm font-semibold leading-6 text-[var(--sf-muted)]">
                                            <p class="font-black text-[var(--sf-ink)]">Ringkasan pembayaran</p>
                                            <p>Subtotal produk (IDR): {{ $storefront->idr($product->price_amount) }} × jumlah.</p>
                                            <p>Biaya dan total QRIS final ditentukan provider setelah checkout. Bayar hanya nominal total pada invoice QRIS.</p>
                                        </div>
                                        <label for="storefront-nojs-terms-accepted" class="flex items-start gap-3 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-3 text-sm font-bold leading-6 text-[var(--sf-ink)]">
                                            <input id="storefront-nojs-terms-accepted"
                                                   name="terms_accepted"
                                                   type="checkbox"
                                                   value="1"
                                                   required
                                                   @checked(in_array(old('terms_accepted'), [true, 1, '1', 'on', 'yes', 'true'], true))
                                                   class="mt-1 h-5 w-5 shrink-0 accent-[var(--sf-accent)]">
                                            <span>Saya telah membaca dan menyetujui <a href="{{ route('storefront.policies.terms') }}" target="_blank" rel="noopener noreferrer" class="underline">{{ $copy['policies']['terms']['title'] }}</a> dan <a href="{{ route('storefront.policies.refund') }}" target="_blank" rel="noopener noreferrer" class="underline">{{ $copy['policies']['refund']['title'] }}</a>.</span>
                                        </label>
                                        <p class="text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">{{ $copy['shop']['terms_consent_help'] }}</p>
                                        @error('terms_accepted')
                                            <p role="alert" class="text-sm font-black leading-relaxed text-[#9f1d2d]">{{ $message }}</p>
                                        @enderror
                                        <button type="submit" class="neo-button inline-flex h-12 items-center justify-center bg-[var(--sf-accent)] px-5 text-sm font-black text-white">{{ $presentation->primaryCheckoutChoice()?->label ?? $copy['shop']['checkout_cta'] }}</button>
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
        @include('storefront.partials.verified-purchase-activity')
    </div>
</body>
</html>
