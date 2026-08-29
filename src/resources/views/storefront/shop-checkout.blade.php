<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head', ['metadata' => $metadata])
</head>
<body class="overflow-x-hidden antialiased selection:bg-[var(--sf-ink)] selection:text-white relative text-[var(--sf-ink)]">
    <div class="storefront-shell relative min-h-screen z-10 flex flex-col justify-between overflow-x-hidden">
        @include('storefront.partials.header')

        <main class="flex-grow pb-24 pt-12">
            <section class="mx-auto max-w-5xl px-5 sm:px-6">
                <a href="{{ $cartUrl }}" class="inline-flex items-center gap-2 text-sm font-black text-[var(--sf-ink)] hover:text-[var(--sf-accent)]"><span aria-hidden="true">&larr;</span> Kembali ke keranjang</a>
                <div class="mt-6">
                    <p class="text-xs font-black uppercase tracking-[0.2em] text-[var(--sf-accent)]">Checkout web</p>
                    <h1 class="mt-2 font-display text-3xl font-black tracking-tight text-[var(--sf-ink)] sm:text-5xl">Review pesanan sebelum bayar.</h1>
                    <p class="mt-3 max-w-2xl text-sm font-semibold leading-7 text-[var(--sf-muted)]">Semua item di bawah akan masuk ke satu order. Stok baru dicadangkan setelah checkout berhasil.</p>
                </div>

                @if (session('storefront_checkout_error'))
                    <x-storefront.notice tone="danger" class="mt-6">
                        {{ session('storefront_checkout_error') }}
                    </x-storefront.notice>
                @endif
                @error('cart')
                    <x-storefront.notice tone="danger" class="mt-6">
                        {{ $message }}
                    </x-storefront.notice>
                @enderror

                <div class="mt-8 grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
                    <div class="grid gap-5">
                        <section class="neo-panel bg-[var(--sf-surface)] p-4 sm:p-6" aria-labelledby="checkout-items-title">
                            <div class="flex items-center justify-between gap-3 border-b-[3px] border-dashed border-[var(--sf-border)] pb-4">
                                <h2 id="checkout-items-title" class="font-display text-2xl font-black text-[var(--sf-ink)]">Isi pesanan</h2>
                                <span class="rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-1 text-xs font-black text-[var(--sf-ink)]">{{ $cartItemCount }} unit</span>
                            </div>
                            <div class="mt-4 grid gap-3">
                                @foreach ($cartItems as $item)
                                    <article class="rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-4" data-checkout-item="{{ $item->productId }}">
                                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                            <div class="min-w-0">
                                                <h3 class="break-words font-display text-lg font-black text-[var(--sf-ink)]">{{ $item->name }}</h3>
                                                <p class="mt-1 text-sm font-bold text-[var(--sf-muted)]">Jumlah {{ $item->quantity }} · Harga satuan {{ $item->unitPrice === null ? '—' : $storefront->idr($item->unitPrice) }}</p>
                                                @if ($item->usdtUnitPrice !== null)
                                                    <p class="mt-1 text-xs font-bold text-[var(--sf-muted)]">Harga USDT satuan {{ $storefront->usdt($item->usdtUnitPrice) }}</p>
                                                @endif
                                                @if ($item->conflictMessage)
                                                    <p role="alert" class="mt-3 rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-2 text-sm font-black leading-6 text-[var(--sf-ink)]">{{ $item->conflictMessage }}</p>
                                                @endif
                                            </div>
                                            <p class="shrink-0 text-right text-sm font-black text-[var(--sf-ink)]">Subtotal<br>{{ $item->subtotal === null ? '—' : $storefront->idr($item->subtotal) }}</p>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                            <dl class="mt-5 grid gap-3 border-t-[3px] border-[var(--sf-border)] pt-4 text-sm">
                                <div class="flex items-center justify-between gap-3 font-bold text-[var(--sf-muted)]">
                                    <dt>Mata uang order</dt>
                                    <dd class="font-black text-[var(--sf-ink)]">{{ $cartCurrency }}</dd>
                                </div>
                                <div class="flex items-center justify-between gap-3 text-base font-black text-[var(--sf-ink)]">
                                    <dt>Subtotal pesanan</dt>
                                    <dd>{{ $storefront->idr($cartSubtotal) }}</dd>
                                </div>
                                @if ($cartUsdtSubtotal !== null)
                                    <div class="flex items-center justify-between gap-3 font-bold text-[var(--sf-muted)]">
                                        <dt>Total dasar USDT</dt>
                                        <dd class="font-black text-[var(--sf-ink)]">{{ $storefront->usdt($cartUsdtSubtotal) }}</dd>
                                    </div>
                                @endif
                            </dl>
                        </section>

                        @if ($cartHasConflicts)
                            <x-storefront.notice tone="danger">
                                Item keranjang berubah atau tidak lagi tersedia. Sesuaikan keranjang terlebih dahulu; tidak ada order yang dibuat dari snapshot ini.
                            </x-storefront.notice>
                        @elseif ($checkoutMaintenance->active)
                            <x-storefront.notice>
                                <p class="font-black">Checkout sedang maintenance.</p>
                                <p class="mt-2 whitespace-pre-line">{{ $checkoutMaintenanceNotice }}</p>
                            </x-storefront.notice>
                        @elseif ($checkoutPaymentOptions === [])
                            <x-storefront.notice tone="danger">
                                Metode pembayaran belum tersedia untuk seluruh item di keranjang. Kembali ke katalog atau hubungi support.
                            </x-storefront.notice>
                        @else
                            @php
                                $selectedPaymentMethod = old('payment_method', $checkoutPaymentOptions[0]['value'] ?? '');
                                $termsAccepted = in_array(old('terms_accepted'), [true, 1, '1', 'on', 'yes', 'true'], true);
                            @endphp
                            <form method="POST" action="{{ route('storefront.shop.checkout') }}" class="neo-panel grid gap-5 bg-[var(--sf-surface)] p-4 sm:p-6">
                                @csrf
                                @if ($checkoutSnapshot)
                                    <input type="hidden" name="cart_snapshot" value="{{ $checkoutSnapshot }}">
                                @endif

                                <fieldset class="grid gap-3" @if ($errors->has('payment_method')) aria-describedby="checkout-payment-error" @endif>
                                    <legend class="font-display text-xl font-black text-[var(--sf-ink)]">Pilih metode pembayaran</legend>
                                    @foreach ($checkoutPaymentOptions as $option)
                                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-3 text-sm font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)] has-[:checked]:bg-[var(--sf-yellow)]">
                                            <input type="radio" name="payment_method" value="{{ $option['value'] }}" @checked($selectedPaymentMethod === $option['value']) class="mt-1 h-5 w-5 accent-[var(--sf-accent)]">
                                            <span>
                                                <span class="block">{{ $option['label'] }}</span>
                                                <span class="mt-1 block text-sm font-semibold leading-6 text-[var(--sf-muted)]">{{ $option['description'] }}</span>
                                            </span>
                                        </label>
                                    @endforeach
                                    @error('payment_method')
                                        <p id="checkout-payment-error" role="alert" class="text-sm font-black leading-relaxed text-[#9f1d2d]">{{ $message }}</p>
                                    @enderror
                                </fieldset>

                                <div class="grid gap-2">
                                    <label for="checkout-buyer-name" class="text-sm font-black uppercase tracking-wider text-[var(--sf-ink)]">{{ $copy['shop']['buyer_name_label'] }}</label>
                                    <input id="checkout-buyer-name" name="buyer_name" type="text" required value="{{ old('buyer_name') }}" autocomplete="name" placeholder="Nama pembeli" class="neo-input h-12 w-full bg-white px-3 text-base font-bold text-[var(--sf-ink)] @error('buyer_name') border-[#9f1d2d] @enderror">
                                    <p class="text-sm font-semibold leading-6 text-[var(--sf-muted)]">{{ $copy['shop']['buyer_name_help'] }}</p>
                                    @error('buyer_name')
                                        <p role="alert" class="text-sm font-black leading-relaxed text-[#9f1d2d]">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="grid gap-2">
                                    <label for="checkout-email" class="text-sm font-black uppercase tracking-wider text-[var(--sf-ink)]">{{ $copy['shop']['email_label'] }}</label>
                                    <input id="checkout-email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" placeholder="nama@contoh.com" class="neo-input h-12 w-full bg-white px-3 text-base font-bold text-[var(--sf-ink)] @error('email') border-[#9f1d2d] @enderror">
                                    <p class="text-sm font-semibold leading-6 text-[var(--sf-muted)]">{{ $copy['shop']['email_help'] }}</p>
                                    @error('email')
                                        <p role="alert" class="text-sm font-black leading-relaxed text-[#9f1d2d]">{{ $message }}</p>
                                    @enderror
                                </div>

                                <details class="rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-3" @if(old('support_contact_channel') || old('support_contact_value')) open @endif>
                                    <summary class="cursor-pointer text-sm font-black uppercase tracking-wider text-[var(--sf-ink)]">{{ $copy['shop']['support_contact_label'] }} <span class="normal-case text-[var(--sf-muted)]">(opsional)</span></summary>
                                    <div class="mt-3 grid gap-2">
                                        <div class="grid gap-2 sm:grid-cols-[150px_1fr]">
                                            <label for="checkout-support-channel" class="sr-only">Kanal bantuan</label>
                                            <select id="checkout-support-channel" name="support_contact_channel" class="neo-input h-12 w-full bg-white px-3 text-base font-bold text-[var(--sf-ink)]">
                                                <option value="">Tidak perlu</option>
                                                <option value="telegram" @selected(old('support_contact_channel') === 'telegram')>Telegram</option>
                                                <option value="whatsapp" @selected(old('support_contact_channel') === 'whatsapp')>WhatsApp</option>
                                            </select>
                                            <label for="checkout-support-value" class="sr-only">Kontak bantuan</label>
                                            <input id="checkout-support-value" name="support_contact_value" type="text" value="{{ old('support_contact_value') }}" placeholder="@username atau nomor WhatsApp" class="neo-input h-12 w-full bg-white px-3 text-base font-bold text-[var(--sf-ink)]">
                                        </div>
                                        <p class="text-sm font-semibold leading-6 text-[var(--sf-muted)]">{{ $copy['shop']['support_contact_help'] }}</p>
                                        @error('support_contact_channel')
                                            <p role="alert" class="text-sm font-black leading-relaxed text-[#9f1d2d]">{{ $message }}</p>
                                        @enderror
                                        @error('support_contact_value')
                                            <p role="alert" class="text-sm font-black leading-relaxed text-[#9f1d2d]">{{ $message }}</p>
                                        @enderror
                                    </div>
                                </details>

                                <label for="checkout-terms-accepted" class="flex items-start gap-3 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-3 text-sm font-bold leading-6 text-[var(--sf-ink)]">
                                    <input id="checkout-terms-accepted" name="terms_accepted" type="checkbox" value="1" required @checked($termsAccepted) class="mt-1 h-5 w-5 shrink-0 accent-[var(--sf-accent)]">
                                    <span>Saya telah membaca dan menyetujui <a href="{{ route('storefront.policies.terms') }}" target="_blank" rel="noopener noreferrer" class="underline">{{ $copy['policies']['terms']['title'] }}</a> dan <a href="{{ route('storefront.policies.refund') }}" target="_blank" rel="noopener noreferrer" class="underline">{{ $copy['policies']['refund']['title'] }}</a>.</span>
                                </label>
                                <p class="text-sm font-semibold leading-6 text-[var(--sf-muted)]">{{ $copy['shop']['terms_consent_help'] }}</p>
                                @error('terms_accepted')
                                    <p role="alert" class="text-sm font-black leading-relaxed text-[#9f1d2d]">{{ $message }}</p>
                                @enderror

                                <button type="submit" class="neo-button neo-button-stable inline-flex min-h-12 w-full items-center justify-center bg-[var(--sf-accent)] px-5 text-sm font-black text-white">Buat satu order dan lanjut bayar</button>
                            </form>
                        @endif
                    </div>

                    <aside class="neo-panel sticky top-24 bg-[var(--sf-surface)] p-5">
                        <p class="text-xs font-black uppercase tracking-[0.2em] text-[var(--sf-accent)]">Butuh bantuan?</p>
                        <h2 class="mt-2 font-display text-xl font-black text-[var(--sf-ink)]">Support tetap tersedia.</h2>
                        <p class="mt-3 text-sm font-semibold leading-6 text-[var(--sf-muted)]">{{ $supportContext['body'] ?? 'Hubungi support bila ada pertanyaan tentang produk atau order.' }}</p>
                        @if ($supportHref)
                            <a href="{{ $supportHref }}" target="_blank" rel="noopener noreferrer" class="neo-button mt-5 inline-flex min-h-11 w-full items-center justify-center bg-[var(--sf-yellow)] px-4 text-center text-sm font-black text-[var(--sf-ink)]">Hubungi support</a>
                        @endif
                        <p class="mt-4 text-xs font-bold leading-5 text-[var(--sf-muted)]">Simpan invoice dan Recovery Key setelah order dibuat. Harga browser tidak pernah dipakai sebagai sumber kebenaran order.</p>
                    </aside>
                </div>
            </section>
        </main>

        @include('storefront.partials.footer')
    </div>
</body>
</html>
