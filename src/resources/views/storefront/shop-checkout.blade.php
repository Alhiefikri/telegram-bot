<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head', ['metadata' => $metadata])
</head>
<body class="relative overflow-x-hidden text-[var(--sf-ink)] antialiased selection:bg-[var(--sf-ink)] selection:text-white motion-reduce:animate-none">
    <div class="storefront-shell relative z-10 flex min-h-screen flex-col justify-between overflow-x-hidden">
        @include('storefront.partials.header')

        <main class="flex-grow pb-16 pt-4 sm:pt-12">
            <section class="mx-auto max-w-5xl px-4 sm:px-6">
                @php
                    $checkoutValidationMessages = $errors->getMessages();
                    $checkoutSessionError = session('storefront_checkout_error');
                    $checkoutErrorMessages = collect($errors->all());
                    $checkoutSessionErrorIsDuplicate = is_string($checkoutSessionError) && $checkoutErrorMessages->contains($checkoutSessionError);

                    if (is_string($checkoutSessionError) && ! $checkoutSessionErrorIsDuplicate) {
                        $checkoutErrorMessages->prepend($checkoutSessionError);
                    }

                    $checkoutErrorTargets = [
                        'quantity' => 'checkout-quantity',
                        'buyer_name' => 'checkout-buyer-name',
                        'payment_method' => 'checkout-payment-method',
                        'email' => 'checkout-email',
                        'support_contact_channel' => 'checkout-support-contact-channel',
                        'support_contact_value' => 'checkout-support-contact-value',
                        'terms_accepted' => 'checkout-terms-accepted',
                    ];
                    $checkoutFirstErrorField = array_key_first($checkoutValidationMessages);
                    $checkoutErrorTarget = $checkoutErrorTargets[$checkoutFirstErrorField] ?? 'checkout-recovery-action';
                @endphp

                <a id="checkout-recovery-action" href="{{ $checkoutReturnUrl }}" class="inline-flex min-h-11 items-center gap-2 text-sm font-black text-[var(--sf-ink)] underline-offset-4 hover:text-[var(--sf-accent)] hover:underline focus-visible:outline focus-visible:outline-3 focus-visible:outline-[var(--sf-blue)] focus-visible:outline-offset-2">
                    <span aria-hidden="true">&larr;</span> {{ $checkoutReturnLabel }}
                </a>

                <div class="mt-4 max-w-3xl">
                    <p class="text-xs font-black uppercase tracking-[0.18em] text-[var(--sf-accent)]">Checkout web</p>
                    <h1 class="mt-2 font-display text-3xl font-black tracking-tight text-[var(--sf-ink)] sm:text-4xl">{{ $checkoutSource === 'direct' ? 'Review produk sebelum bayar.' : 'Review pesanan sebelum bayar.' }}</h1>
                    <p class="mt-2 max-w-2xl text-sm font-semibold leading-6 text-[var(--sf-muted)]">{{ $checkoutSource === 'direct' ? 'Pastikan produk, jumlah, dan metode pembayaran sudah benar. Stok baru dicadangkan setelah checkout berhasil.' : 'Pastikan item, jumlah, dan metode pembayaran sudah benar. Stok baru dicadangkan setelah checkout berhasil.' }}</p>
                </div>

                @if ($checkoutErrorMessages->isNotEmpty())
                    <section id="checkout-error-summary" tabindex="-1" autofocus data-checkout-error-summary data-focus-target="{{ $checkoutErrorTarget }}" role="alert" aria-labelledby="checkout-error-summary-title" class="mt-5 rounded-lg border-[3px] border-[#9f1d2d] bg-[#ffd9df] p-4 text-sm font-bold leading-6 text-[var(--sf-ink)] focus-visible:outline focus-visible:outline-3 focus-visible:outline-[var(--sf-blue)] focus-visible:outline-offset-2">
                        <h2 id="checkout-error-summary-title" class="font-display text-lg font-black">Perlu diperbaiki sebelum checkout</h2>
                        <ul class="mt-2 grid list-disc gap-1.5 pl-5">
                            @foreach ($checkoutValidationMessages as $field => $messages)
                                @foreach ($messages as $message)
                                    <li><a href="#{{ $checkoutErrorTargets[$field] ?? 'checkout-recovery-action' }}" class="underline underline-offset-2">{{ $message }}</a></li>
                                @endforeach
                            @endforeach
                            @if (is_string($checkoutSessionError) && ! $checkoutSessionErrorIsDuplicate)
                                <li><a href="#checkout-recovery-action" class="underline underline-offset-2">{{ $checkoutSessionError }}</a></li>
                            @endif
                        </ul>
                    </section>
                @endif

                <div class="mt-4 grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem] lg:items-start">
                    <div class="grid min-w-0 grid-cols-1 gap-5">
                        <section class="neo-panel bg-[var(--sf-surface)] p-4 sm:p-5" aria-labelledby="checkout-summary-title">
                            <div class="flex flex-wrap items-start justify-between gap-3 border-b-[3px] border-dashed border-[var(--sf-border)] pb-4">
                                <div>
                                    <p class="text-xs font-black uppercase tracking-[0.16em] text-[var(--sf-accent)]">Ringkasan</p>
                                    <h2 id="checkout-summary-title" class="mt-1 font-display text-2xl font-black text-[var(--sf-ink)]">{{ $checkoutSource === 'direct' ? 'Produk yang dipilih' : 'Item keranjang' }}</h2>
                                </div>
                                <span class="rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-1 text-xs font-black text-[var(--sf-ink)]">{{ $cartItemCount }} unit</span>
                            </div>

                            <div class="mt-4 grid gap-3">
                                @foreach ($cartItems as $item)
                                    <article class="min-w-0 rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-3 sm:p-4" data-checkout-item="{{ $item->productId }}" data-checkout-unit-price="{{ $item->unitPrice ?? '' }}" data-checkout-unit-usdt-price="{{ $item->usdtUnitPrice ?? '' }}">
                                        <div class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                            <div class="min-w-0">
                                                <h3 class="break-words font-display text-lg font-black leading-6 text-[var(--sf-ink)]">{{ $item->name }}</h3>
                                                <p class="mt-1 break-words text-sm font-bold leading-6 text-[var(--sf-muted)]">
                                                    <span data-checkout-item-quantity>Jumlah {{ $item->quantity }}</span>
                                                    <span aria-hidden="true"> · </span>
                                                    Harga satuan {{ $item->unitPrice === null ? '—' : $storefront->idr($item->unitPrice) }}
                                                </p>
                                                @if ($item->usdtUnitPrice !== null)
                                                    <p class="mt-1 text-xs font-bold leading-5 text-[var(--sf-muted)]">Harga USDT satuan {{ $storefront->usdt($item->usdtUnitPrice) }}</p>
                                                @endif
                                                @if ($item->conflictMessage)
                                                    <p role="alert" class="mt-2 rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-2 text-sm font-black leading-6 text-[var(--sf-ink)]">{{ $item->conflictMessage }}</p>
                                                @endif
                                            </div>
                                            <p class="shrink-0 text-left text-sm font-black leading-6 text-[var(--sf-ink)] sm:text-right">
                                                <span class="block text-xs uppercase tracking-wide text-[var(--sf-muted)]">Subtotal review</span>
                                                <span data-checkout-item-subtotal>{{ $item->subtotal === null ? '—' : $storefront->idr($item->subtotal) }}</span>
                                            </p>
                                        </div>
                                    </article>
                                @endforeach
                            </div>

                            @if ($checkoutSource === 'cart')
                                <a href="{{ $cartUrl }}" class="mt-4 inline-flex min-h-11 items-center text-sm font-black text-[var(--sf-accent)] underline underline-offset-4 focus-visible:outline focus-visible:outline-3 focus-visible:outline-[var(--sf-blue)] focus-visible:outline-offset-2">Ubah jumlah di keranjang</a>
                            @endif

                            <dl class="mt-4 grid gap-2 border-t-[3px] border-[var(--sf-border)] pt-3 text-sm">
                                <div class="flex items-center justify-between gap-3 text-base font-black text-[var(--sf-ink)]">
                                    <dt>Subtotal review (IDR)</dt>
                                    <dd data-checkout-order-subtotal>{{ $storefront->idr($cartSubtotal) }}</dd>
                                </div>
                                @if ($cartUsdtSubtotal !== null)
                                    <div class="flex items-center justify-between gap-3 font-bold text-[var(--sf-muted)]">
                                        <dt>Total dasar USDT / estimasi</dt>
                                        <dd class="font-black text-[var(--sf-ink)]" data-checkout-order-usdt-subtotal>{{ $storefront->usdt($cartUsdtSubtotal) }}</dd>
                                    </div>
                                @endif
                            </dl>
                            <p class="mt-2 text-xs font-bold leading-5 text-[var(--sf-muted)]">Review saja; nominal final QRIS atau USDT tampil di invoice provider.</p>
                        </section>

                        @if ($cartHasConflicts)
                            <section class="rounded-lg border-[3px] border-[#9f1d2d] bg-[#ffd9df] p-4 text-sm font-bold leading-6 text-[var(--sf-ink)]" role="alert" aria-labelledby="checkout-unavailable-title">
                                <h2 id="checkout-unavailable-title" class="font-display text-lg font-black">Checkout belum siap</h2>
                                <p class="mt-1">{{ $checkoutSource === 'direct' ? ($checkoutUnavailableNotice ?? 'Produk ini berubah atau tidak lagi tersedia. Periksa kembali sebelum checkout.') : 'Item keranjang berubah atau tidak lagi tersedia. Sesuaikan keranjang terlebih dahulu; tidak ada order yang dibuat dari snapshot ini.' }}</p>
                                <a href="{{ $checkoutReturnUrl }}" class="neo-button neo-button-stable mt-4 inline-flex min-h-11 items-center justify-center bg-white px-4 text-sm font-black text-[var(--sf-ink)]">{{ $checkoutReturnLabel }}</a>
                            </section>
                        @elseif ($checkoutMaintenance->active)
                            <section class="rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] p-4 text-sm font-bold leading-6 text-[var(--sf-ink)]" role="status" aria-labelledby="checkout-maintenance-title">
                                <h2 id="checkout-maintenance-title" class="font-display text-lg font-black">Checkout sedang maintenance</h2>
                                <p class="mt-1 whitespace-pre-line">{{ $checkoutMaintenanceNotice }}</p>
                                <a href="{{ $checkoutReturnUrl }}" class="neo-button neo-button-stable mt-4 inline-flex min-h-11 items-center justify-center bg-white px-4 text-sm font-black text-[var(--sf-ink)]">{{ $checkoutReturnLabel }}</a>
                            </section>
                        @elseif ($checkoutPaymentOptions === [] || ! $checkoutCanSubmit)
                            <section class="rounded-lg border-[3px] border-[#9f1d2d] bg-[#ffd9df] p-4 text-sm font-bold leading-6 text-[var(--sf-ink)]" role="alert" aria-labelledby="checkout-unavailable-title">
                                <h2 id="checkout-unavailable-title" class="font-display text-lg font-black">Checkout belum tersedia</h2>
                                <p class="mt-1">{{ $checkoutUnavailableNotice ?? 'Metode pembayaran belum tersedia untuk seluruh item di keranjang. Kembali ke katalog atau hubungi support.' }}</p>
                                <a href="{{ $checkoutReturnUrl }}" class="neo-button neo-button-stable mt-4 inline-flex min-h-11 items-center justify-center bg-white px-4 text-sm font-black text-[var(--sf-ink)]">{{ $checkoutReturnLabel }}</a>
                            </section>
                        @else
                            @php
                                $selectedPaymentMethod = old('payment_method', $checkoutDefaultPaymentMethod ?? '');
                                $selectedPaymentOption = collect($checkoutPaymentOptions)->first(
                                    static fn (array $option): bool => $option['value'] === $selectedPaymentMethod,
                                ) ?? ($checkoutPaymentOptions[0] ?? null);
                                $selectedPaymentMethod = $selectedPaymentOption['value'] ?? '';
                                $selectedPaymentProvider = $selectedPaymentOption['provider'] ?? null;
                                $paymentOptionCount = count($checkoutPaymentOptions);
                                $paymentDisclosureOpen = old('payment_method') !== null || $errors->has('payment_method');
                                $contactDisclosureOpen = filled(old('email'))
                                    || filled(old('support_contact_channel'))
                                    || filled(old('support_contact_value'))
                                    || $errors->has('email')
                                    || $errors->has('support_contact_channel')
                                    || $errors->has('support_contact_value')
                                    || $selectedPaymentProvider === 'sakurupiah';
                                $termsAccepted = in_array(old('terms_accepted'), [true, 1, '1', 'on', 'yes', 'true'], true);
                                $paymentCopy = static function (array $option): array {
                                    if ($option['value'] === 'qris') {
                                        $providerLabel = match ($option['provider'] ?? null) {
                                            'manual' => 'QR Manual',
                                            'sakurupiah' => 'Sakurupiah',
                                            default => 'KlikQRIS',
                                        };

                                        return [
                                            'amount_kind' => 'idr',
                                            'amount_label' => 'Subtotal review (IDR)',
                                            'amount_help' => 'Total QRIS final ditentukan '.$providerLabel.' pada invoice. Bayar nominal invoice.',
                                            'submit_label' => 'Buat invoice '.$providerLabel.' & lanjut bayar',
                                        ];
                                    }

                                    return [
                                        'amount_kind' => 'usdt',
                                        'amount_label' => 'Total dasar USDT / estimasi',
                                        'amount_help' => 'Nominal USDT final ditetapkan pada invoice; ikuti nominal invoice.',
                                        'submit_label' => $option['value'] === 'binance_pay_id'
                                            ? 'Buat invoice Binance Pay & lanjut bayar'
                                            : 'Buat invoice USDT BSC & lanjut bayar',
                                    ];
                                };
                                $selectedPaymentCopy = $selectedPaymentOption === null
                                    ? null
                                    : $paymentCopy($selectedPaymentOption);
                            @endphp

                            <form id="checkout-form" method="POST" action="{{ $checkoutSubmitUrl }}" class="neo-panel grid grid-cols-1 min-w-0 gap-4 bg-[var(--sf-surface)] p-4 sm:p-5">
                                @csrf
                                @if ($checkoutSource === 'direct' && $checkoutProduct)
                                    <input type="hidden" name="product_id" value="{{ old('product_id', $checkoutProduct->id) }}">
                                @elseif ($checkoutSnapshot)
                                    <input type="hidden" name="cart_snapshot" value="{{ $checkoutSnapshot }}">
                                @endif

                                @if ($checkoutSource === 'direct' && $checkoutProduct)
                                    <div class="grid gap-2">
                                        <label for="checkout-quantity" class="text-sm font-black uppercase tracking-wider text-[var(--sf-ink)]">Jumlah</label>
                                        <input id="checkout-quantity" name="quantity" type="number" min="1" max="{{ $checkoutQuantityMax }}" required value="{{ old('quantity', $checkoutQuantity) }}" data-checkout-quantity data-unit-price="{{ $cartItems->first()?->unitPrice ?? 0 }}" data-unit-usdt-price="{{ $cartItems->first()?->usdtUnitPrice ?? 0 }}" @if($errors->has('quantity')) aria-invalid="true" aria-describedby="checkout-quantity-help checkout-quantity-error checkout-quantity-announcement" @else aria-describedby="checkout-quantity-help checkout-quantity-announcement" @endif class="neo-input h-12 w-full bg-white px-3 text-base font-bold text-[var(--sf-ink)] @error('quantity') border-[#9f1d2d] @enderror">
                                        <p id="checkout-quantity-help" class="text-sm font-semibold leading-6 text-[var(--sf-muted)]">Maksimal {{ $checkoutQuantityMax }} unit. Jumlah dan subtotal hanya review; server memvalidasi checkout.</p>
                                        <p id="checkout-quantity-announcement" data-checkout-quantity-announcement class="sr-only" aria-live="polite" aria-atomic="true"></p>
                                        @error('quantity')
                                            <p id="checkout-quantity-error" role="alert" class="text-sm font-black leading-6 text-[#9f1d2d]">{{ $message }}</p>
                                        @enderror
                                    </div>
                                @endif

                                <div class="grid gap-2">
                                    <label for="checkout-buyer-name" class="text-sm font-black uppercase tracking-wider text-[var(--sf-ink)]">{{ $copy['shop']['buyer_name_label'] }}</label>
                                    <input id="checkout-buyer-name" name="buyer_name" type="text" required value="{{ old('buyer_name') }}" autocomplete="name" placeholder="Nama pembeli" @if($errors->has('buyer_name')) aria-invalid="true" aria-describedby="checkout-buyer-name-help checkout-buyer-name-error" @else aria-describedby="checkout-buyer-name-help" @endif class="neo-input h-12 w-full bg-white px-3 text-base font-bold text-[var(--sf-ink)] @error('buyer_name') border-[#9f1d2d] @enderror">
                                    <p id="checkout-buyer-name-help" class="text-sm font-semibold leading-6 text-[var(--sf-muted)]">{{ $copy['shop']['buyer_name_help'] }}</p>
                                    @error('buyer_name')
                                        <p id="checkout-buyer-name-error" role="alert" class="text-sm font-black leading-6 text-[#9f1d2d]">{{ $message }}</p>
                                    @enderror
                                </div>

                                <section id="checkout-payment-method" data-checkout-payment class="grid gap-2" aria-labelledby="checkout-payment-title" @if ($errors->has('payment_method')) aria-describedby="checkout-payment-error" @endif>
                                    <div>
                                        <p class="text-xs font-black uppercase tracking-[0.16em] text-[var(--sf-accent)]">Pembayaran</p>
                                        <h2 id="checkout-payment-title" class="mt-1 font-display text-xl font-black text-[var(--sf-ink)]">Metode pembayaran dipilih</h2>
                                    </div>

                                    @if ($selectedPaymentOption && $selectedPaymentCopy)
                                        @php($selectedPaymentOptionCopy = $selectedPaymentCopy)
                                        <div data-checkout-payment-option data-checkout-selected-option data-payment-value="{{ $selectedPaymentOption['value'] }}" data-payment-label="{{ $selectedPaymentOption['label'] }}" data-payment-description="{{ $selectedPaymentOption['description'] }}" data-payment-provider="{{ $selectedPaymentOption['provider'] ?? '' }}" data-payment-amount-kind="{{ $selectedPaymentOptionCopy['amount_kind'] }}" data-payment-amount-label="{{ $selectedPaymentOptionCopy['amount_label'] }}" data-payment-amount-help="{{ $selectedPaymentOptionCopy['amount_help'] }}" data-payment-submit-label="{{ $selectedPaymentOptionCopy['submit_label'] }}" class="rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] p-2.5 shadow-[3px_3px_0_var(--sf-shadow)]">
                                            <p class="text-xs font-black uppercase tracking-wide text-[var(--sf-muted)]">Dipilih untuk review</p>
                                            <p class="mt-1 break-words text-base font-black text-[var(--sf-ink)]" data-checkout-selected-payment-label>{{ $selectedPaymentOption['label'] }}</p>
                                            <p class="mt-1 break-words text-sm font-semibold leading-6 text-[var(--sf-muted)]" data-checkout-selected-payment-description>{{ $selectedPaymentOption['description'] }}</p>
                                        </div>
                                    @endif

                                    @if ($paymentOptionCount === 1)
                                        <input type="hidden" name="payment_method" value="{{ $selectedPaymentMethod }}">
                                    @else
                                        <details id="checkout-payment-disclosure" data-checkout-payment-disclosure class="rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-2.5" @if ($paymentDisclosureOpen) open @endif>
                                            <summary class="flex min-h-11 cursor-pointer items-center justify-between gap-3 text-sm font-black text-[var(--sf-ink)] focus-visible:outline focus-visible:outline-3 focus-visible:outline-[var(--sf-blue)] focus-visible:outline-offset-2">
                                                <span>Ubah metode pembayaran</span>
                                                <span class="text-xs font-bold text-[var(--sf-muted)]">{{ $paymentOptionCount }} pilihan</span>
                                            </summary>
                                            <fieldset class="mt-3 grid gap-2" aria-label="Alternatif metode pembayaran">
                                                @foreach ($checkoutPaymentOptions as $option)
                                                    @php($optionCopy = $paymentCopy($option))
                                                    <label data-checkout-payment-option data-payment-value="{{ $option['value'] }}" data-payment-label="{{ $option['label'] }}" data-payment-description="{{ $option['description'] }}" data-payment-provider="{{ $option['provider'] ?? '' }}" data-payment-amount-kind="{{ $optionCopy['amount_kind'] }}" data-payment-amount-label="{{ $optionCopy['amount_label'] }}" data-payment-amount-help="{{ $optionCopy['amount_help'] }}" data-payment-submit-label="{{ $optionCopy['submit_label'] }}" class="flex min-h-11 cursor-pointer items-start gap-3 rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-3 text-sm font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)] has-[:checked]:bg-[var(--sf-yellow)]">
                                                        <input type="radio" name="payment_method" value="{{ $option['value'] }}" @checked($selectedPaymentMethod === $option['value']) class="mt-1 h-5 w-5 shrink-0 accent-[var(--sf-accent)]">
                                                        <span class="min-w-0">
                                                            <span class="block break-words">{{ $option['label'] }}</span>
                                                            <span class="mt-1 block break-words text-sm font-semibold leading-6 text-[var(--sf-muted)]">{{ $option['description'] }}</span>
                                                        </span>
                                                    </label>
                                                @endforeach
                                            </fieldset>
                                        </details>
                                    @endif

                                    @if ($selectedPaymentOption && $selectedPaymentCopy)
                                        <div class="rounded-lg border-2 border-dashed border-[var(--sf-border)] bg-white p-2.5 text-sm font-bold leading-6 text-[var(--sf-muted)]">
                                            <div class="flex items-center justify-between gap-3">
                                                <span data-checkout-payment-amount-label>{{ $selectedPaymentCopy['amount_label'] }}</span>
                                                <strong class="shrink-0 text-[var(--sf-ink)]" data-checkout-payment-amount-value>{{ $selectedPaymentCopy['amount_kind'] === 'usdt' ? ($cartUsdtSubtotal === null ? '—' : $storefront->usdt($cartUsdtSubtotal)) : $storefront->idr($cartSubtotal) }}</strong>
                                            </div>
                                            <p class="mt-1 text-sm font-semibold leading-5" data-checkout-payment-amount-help>{{ $selectedPaymentCopy['amount_help'] }}</p>
                                        </div>
                                    @endif

                                    @error('payment_method')
                                        <p id="checkout-payment-error" role="alert" class="text-sm font-black leading-6 text-[#9f1d2d]">{{ $message }}</p>
                                    @enderror
                                </section>

                                <details id="checkout-contact-details" data-checkout-contact-details class="rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-2.5" @if ($contactDisclosureOpen) open @endif>
                                    <summary class="flex min-h-11 cursor-pointer items-center justify-between gap-3 text-sm font-black text-[var(--sf-ink)] focus-visible:outline focus-visible:outline-3 focus-visible:outline-[var(--sf-blue)] focus-visible:outline-offset-2">
                                        <span>Tambahkan email atau kontak bantuan</span>
                                        <span class="text-xs font-bold text-[var(--sf-muted)]" data-checkout-contact-requirement>{{ $selectedPaymentProvider === 'sakurupiah' ? 'WhatsApp wajib untuk Sakurupiah' : 'Opsional' }}</span>
                                    </summary>
                                    <div class="mt-2 grid gap-3">
                                        <div class="grid gap-2">
                                            <label for="checkout-email" class="text-sm font-black uppercase tracking-wider text-[var(--sf-ink)]">{{ $copy['shop']['email_label'] }}</label>
                                            <input id="checkout-email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" placeholder="nama@contoh.com" @if($errors->has('email')) aria-invalid="true" aria-describedby="checkout-email-help checkout-email-error" @else aria-describedby="checkout-email-help" @endif class="neo-input h-12 w-full bg-white px-3 text-base font-bold text-[var(--sf-ink)] @error('email') border-[#9f1d2d] @enderror">
                                            <p id="checkout-email-help" class="text-sm font-semibold leading-6 text-[var(--sf-muted)]">{{ $copy['shop']['email_help'] }}</p>
                                            @error('email')
                                                <p id="checkout-email-error" role="alert" class="text-sm font-black leading-6 text-[#9f1d2d]">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div class="grid gap-2">
                                            <label for="checkout-support-contact-channel" class="text-sm font-black uppercase tracking-wider text-[var(--sf-ink)]">{{ $copy['shop']['support_contact_label'] }}</label>
                                            <div class="grid gap-2 sm:grid-cols-[150px_minmax(0,1fr)]">
                                                <select id="checkout-support-contact-channel" name="support_contact_channel" @if($errors->has('support_contact_channel')) aria-invalid="true" aria-describedby="checkout-support-contact-help checkout-support-contact-channel-error" @else aria-describedby="checkout-support-contact-help" @endif class="neo-input h-12 w-full bg-white px-3 text-base font-bold text-[var(--sf-ink)]">
                                                    <option value="">Tidak perlu</option>
                                                    <option value="telegram" @selected(old('support_contact_channel') === 'telegram')>Telegram</option>
                                                    <option value="whatsapp" @selected(old('support_contact_channel') === 'whatsapp')>WhatsApp</option>
                                                </select>
                                                <input id="checkout-support-contact-value" name="support_contact_value" type="text" value="{{ old('support_contact_value') }}" placeholder="@username atau nomor WhatsApp" @if($errors->has('support_contact_value')) aria-invalid="true" aria-describedby="checkout-support-contact-help checkout-support-contact-value-error" @else aria-describedby="checkout-support-contact-help" @endif class="neo-input h-12 w-full bg-white px-3 text-base font-bold text-[var(--sf-ink)] @error('support_contact_value') border-[#9f1d2d] @enderror">
                                            </div>
                                            <p id="checkout-support-contact-help" class="text-sm font-semibold leading-6 text-[var(--sf-muted)]" data-checkout-contact-help data-default-contact-help="{{ $copy['shop']['support_contact_help'] }}">{{ $selectedPaymentProvider === 'sakurupiah' ? 'Nomor WhatsApp diperlukan untuk checkout Sakurupiah. Email dan kontak bantuan tetap bukan bukti kepemilikan pesanan.' : $copy['shop']['support_contact_help'] }}</p>
                                            @error('support_contact_channel')
                                                <p id="checkout-support-contact-channel-error" role="alert" class="text-sm font-black leading-6 text-[#9f1d2d]">{{ $message }}</p>
                                            @enderror
                                            @error('support_contact_value')
                                                <p id="checkout-support-contact-value-error" role="alert" class="text-sm font-black leading-6 text-[#9f1d2d]">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    </div>
                                </details>

                                <div class="grid gap-2">
                                    <label for="checkout-terms-accepted" class="flex min-h-12 items-start gap-3 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-3 text-sm font-bold leading-6 text-[var(--sf-ink)]">
                                        <input id="checkout-terms-accepted" name="terms_accepted" type="checkbox" value="1" required @checked($termsAccepted) @if($errors->has('terms_accepted')) aria-invalid="true" aria-describedby="checkout-terms-accepted-help checkout-terms-accepted-error" @else aria-describedby="checkout-terms-accepted-help" @endif class="mt-1 h-5 w-5 shrink-0 accent-[var(--sf-accent)]">
                                        <span>Saya menyetujui <a href="{{ route('storefront.policies.terms') }}" target="_blank" rel="noopener noreferrer" class="underline underline-offset-2">{{ $copy['policies']['terms']['title'] }}</a> dan <a href="{{ route('storefront.policies.refund') }}" target="_blank" rel="noopener noreferrer" class="underline underline-offset-2">{{ $copy['policies']['refund']['title'] }}</a>.</span>
                                    </label>
                                    <p id="checkout-terms-accepted-help" class="text-sm font-semibold leading-6 text-[var(--sf-muted)]">Persetujuan dicatat bersama order.</p>
                                    @error('terms_accepted')
                                        <p id="checkout-terms-accepted-error" role="alert" class="text-sm font-black leading-6 text-[#9f1d2d]">{{ $message }}</p>
                                    @enderror
                                </div>

                                <button type="submit" data-checkout-submit class="neo-button neo-button-stable inline-flex min-h-12 w-full items-center justify-center bg-[var(--sf-accent)] px-5 text-sm font-black text-white">{{ $selectedPaymentCopy['submit_label'] ?? 'Buat invoice & lanjut bayar' }}</button>
                            </form>
                        @endif
                    </div>

                    <aside class="neo-panel bg-[var(--sf-surface)] p-4 sm:p-5 lg:self-start" aria-labelledby="checkout-support-title">
                        <p class="text-xs font-black uppercase tracking-[0.18em] text-[var(--sf-accent)]">Bantuan</p>
                        <h2 id="checkout-support-title" class="mt-1 font-display text-xl font-black text-[var(--sf-ink)]">Butuh bantuan?</h2>
                        <p class="mt-2 text-sm font-semibold leading-6 text-[var(--sf-muted)]">{{ $supportContext['body'] ?? 'Hubungi support bila ada pertanyaan tentang produk atau order.' }}</p>
                        @if ($supportHref)
                            <a href="{{ $supportHref }}" target="_blank" rel="noopener noreferrer" class="neo-button neo-button-stable mt-4 inline-flex min-h-11 w-full items-center justify-center bg-[var(--sf-yellow)] px-4 text-center text-sm font-black text-[var(--sf-ink)]">Hubungi support</a>
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
