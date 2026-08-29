@php
    $modalCheckoutError = session('storefront_checkout_error')
        ?: $errors->first('product_id')
        ?: $errors->first('terms_accepted')
        ?: $errors->first('payment_method')
        ?: $errors->first('email')
        ?: $errors->first('support_contact_channel')
        ?: $errors->first('support_contact_value')
        ?: $errors->first('quantity');
    $buyerNameHasError = $errors->has('buyer_name');
    $buyerNameDescribedBy = 'storefront-buyer-name-help'.($buyerNameHasError ? ' storefront-buyer-name-error' : '');
    $supportContactHasError = $errors->has('support_contact_channel') || $errors->has('support_contact_value');
    $supportContactDescribedBy = 'storefront-support-contact-help'.($supportContactHasError ? ' storefront-support-contact-error' : '');
    $emailHasError = $errors->has('email');
    $emailDescribedBy = 'storefront-email-help'.($emailHasError ? ' storefront-email-error' : '');
    $termsAcceptedHasError = $errors->has('terms_accepted');
    $termsAcceptedDescribedBy = 'storefront-terms-accepted-help'.($termsAcceptedHasError ? ' storefront-terms-accepted-error' : '');
    $checkoutMaintenanceState = $checkoutMaintenance
        ?? (isset($catalogPage) ? $catalogPage->checkoutMaintenance : null);
    $checkoutMaintenanceActive = (bool) ($checkoutMaintenanceState?->active);
    $checkoutMaintenanceNotice = $checkoutMaintenanceNotice
        ?? ($checkoutMaintenanceState ? \App\Storefront\StorefrontCheckoutMaintenanceCopy::notice($checkoutMaintenanceState) : '');
    $modalCheckoutAvailable = isset($presentation)
        ? $presentation->isBuyable
        : (isset($catalogPage) && $catalogPage->products->contains(
            fn ($catalogProduct): bool => $catalogProduct->presentation->isBuyable,
        ));
@endphp

<div class="relative z-50" x-show="productModalOpen" x-cloak @keydown.window="if (productModalOpen && $event.key === 'Escape' && !$event.defaultPrevented) { $event.preventDefault(); closeProductModal(); }">
    <div x-show="productModalOpen"
         x-transition:enter="ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 bg-black/60 backdrop-blur-sm transition-opacity"
         @click="closeProductModal()"></div>

    <div class="fixed inset-0 z-10 flex w-screen items-end overflow-hidden sm:items-center">
        <div class="flex max-h-full w-full items-end justify-center text-center sm:min-h-full sm:items-center sm:p-0">
            <div x-show="productModalOpen"
                 x-ref="dialogPanel"
                 tabindex="-1"
                 aria-labelledby="modal-title"
                 role="dialog"
                 aria-modal="true"
                 x-transition:enter="ease-out duration-300"
                 x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                 x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                 x-transition:leave="ease-in duration-200"
                 x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                 x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                 @keydown="handleModalKeydown($event)"
                 class="neo-panel relative flex max-h-[calc(100dvh-1rem)] w-full transform flex-col overflow-hidden bg-[var(--sf-surface)] text-left shadow-[8px_8px_0_var(--sf-shadow)] transition-all focus:outline-none sm:my-8 sm:max-h-[calc(100dvh-4rem)] sm:max-w-lg">

                <template x-if="selectedProduct">
                    <div class="flex min-h-0 flex-1 flex-col">
                        <div class="flex items-center justify-between border-b-[3px] border-[var(--sf-border)] p-4">
                            <h3 id="modal-title" class="font-display text-lg font-black text-[var(--sf-ink)]" x-text="selectedProduct.name"></h3>
                            <button type="button" @click="closeProductModal()" class="grid h-12 w-12 place-items-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)] transition-transform hover-rubber-band hover:-translate-x-0.5 hover:-translate-y-0.5 active:translate-x-[3px] active:translate-y-[3px] active:shadow-none focus:outline-none" aria-label="Tutup detail produk">
                                <span class="text-xl leading-none" aria-hidden="true">×</span>
                            </button>
                        </div>

                        <div data-storefront-checkout-scroll class="min-h-0 flex-1 space-y-5 overflow-y-auto overscroll-contain p-4 sm:p-6">
                            <div class="flex flex-wrap items-center gap-3 border-b-[3px] border-dashed border-[var(--sf-border)] pb-4">
                                <div class="flex items-center gap-2">
                                    <span class="rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-1 font-display text-sm font-black text-[var(--sf-ink)]" x-text="formatIdr(selectedProduct.price.amount)"></span>
                                </div>
                                <span class="rounded-full border-2 border-[var(--sf-border)] bg-white px-2 py-1 text-sm font-black text-[var(--sf-ink)]" x-text="selectedProduct.stock.label"></span>
                            </div>

                            <div class="grid gap-3 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-3 sm:grid-cols-[1fr_auto] sm:items-center">
                                <div>
                                    <p class="text-sm font-black uppercase tracking-wider text-[var(--sf-ink)]">Jumlah</p>
                                    <p class="mt-1 text-sm font-semibold text-[var(--sf-muted)]">Maksimal mengikuti stok dashboard.</p>
                                </div>
                                <div class="flex items-center justify-between gap-3 sm:justify-end">
                                    <button type="button" x-on:click="decreaseQuantity()" x-bind:disabled="quantity <= 1" class="grid h-12 w-12 place-items-center rounded-md border-[3px] border-[var(--sf-border)] bg-white font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)] transition-transform duration-100 hover:enabled:-translate-x-0.5 hover:enabled:-translate-y-0.5 active:enabled:translate-x-[3px] active:enabled:translate-y-[3px] active:enabled:shadow-none hover:enabled:shadow-[4px_4px_0_var(--sf-shadow)] disabled:cursor-not-allowed disabled:opacity-50" aria-label="Kurangi jumlah">−</button>
                                    <span class="min-w-8 text-center font-display text-lg font-black text-[var(--sf-ink)]" x-text="quantity">1</span>
                                    <button type="button" x-on:click="increaseQuantity()" x-bind:disabled="selectedProduct && quantity >= selectedProduct.stock.available" class="grid h-11 w-11 place-items-center rounded-md border-[3px] border-[var(--sf-border)] bg-white font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)] transition-transform duration-100 hover:enabled:-translate-x-0.5 hover:enabled:-translate-y-0.5 active:enabled:translate-x-[3px] active:enabled:shadow-[4px_4px_0_var(--sf-shadow)] disabled:cursor-not-allowed disabled:opacity-50" aria-label="Tambah jumlah">+</button>
                                </div>
                                <p class="sr-only" aria-live="polite" x-text="quantityAnnouncement"></p>
                                <div class="flex items-center justify-between border-t-2 border-dashed border-[var(--sf-border)] pt-3 text-sm font-black text-[var(--sf-ink)]">
                                    <div x-show="paymentMethod === 'qris'" class="w-full">
                                        <div class="flex items-center justify-between gap-3">
                                            <span>Subtotal produk (IDR)</span>
                                            <span x-text="formatIdr(selectedTotal())">Rp0</span>
                                        </div>
                                        <p x-show="paymentMethod === 'qris'" x-cloak class="mt-2 text-sm font-bold leading-6 text-[var(--sf-muted)]">Total produk di atas belum termasuk biaya QRIS. Biaya dan total QRIS final ditentukan provider setelah QRIS dibuat. Bayar hanya nominal total pada invoice QRIS.</p>
                                    </div>
                                    <div x-show="paymentMethod === 'binance_pay'" x-cloak class="w-full">
                                        <div class="flex items-center justify-between gap-3">
                                            <span>USDT dasar / estimasi</span>
                                            <span x-text="formatUsdt(selectedUsdtTotal())">0 USDT</span>
                                        </div>
                                        <p class="mt-2 text-sm font-bold leading-6 text-[var(--sf-muted)]">Nominal USDT final, termasuk penyesuaian unik bila berlaku, ditetapkan pada invoice setelah checkout. Ikuti nominal pada invoice.</p>
                                    </div>
                                </div>

                            <div class="grid gap-4 pt-2">
                                @if (! $checkoutMaintenanceActive && $modalCheckoutAvailable)
                                <form method="POST"
                                      action="{{ route('storefront.shop.checkout') }}"
                                      x-bind:action="selectedCheckoutChoice()?.url || '{{ route('storefront.shop.checkout') }}'"
                                      class="grid gap-3">
                                    @csrf
                                    <input type="hidden" name="product_id" x-bind:value="selectedCheckoutChoice()?.productId || selectedProduct?.id || ''">
                                    <input type="hidden" name="quantity" x-bind:value="quantity">
                                    @if ($modalCheckoutError)
                                        <p id="storefront-checkout-error" role="alert" class="rounded-md border-2 border-[var(--sf-border)] bg-[#ffd9df] px-3 py-2 text-sm font-black leading-6 text-[var(--sf-ink)]">
                                            {{ $modalCheckoutError }}
                                        </p>
                                    @endif

                                    <div class="grid gap-2">
                                        <label for="storefront-buyer-name" class="text-sm font-black uppercase tracking-wider text-[var(--sf-ink)]">{{ $copy['shop']['buyer_name_label'] }}</label>
                                        <input id="storefront-buyer-name"
                                               name="buyer_name"
                                               type="text"
                                               required
                                               x-model.trim="buyerName"
                                               placeholder="Nama pembeli"
                                               aria-describedby="{{ $buyerNameDescribedBy }}"
                                               @if ($buyerNameHasError) aria-invalid="true" @endif
                                               class="neo-input h-12 w-full bg-white px-3 text-base font-bold text-[var(--sf-ink)] placeholder:text-[var(--sf-muted)]">
                                        <p id="storefront-buyer-name-help" class="text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">
                                            {{ $copy['shop']['buyer_name_help'] }}
                                        </p>
                                        @error('buyer_name')
                                            <p id="storefront-buyer-name-error" role="alert" class="text-sm font-black leading-relaxed text-[#9f1d2d]">{{ $message }}</p>
                                        @enderror
                                    </div>
                                    <div class="grid gap-2">
                                        <label for="storefront-email" class="text-sm font-black uppercase tracking-wider text-[var(--sf-ink)]">{{ $copy['shop']['email_label'] }}</label>
                                        <input id="storefront-email"
                                               name="email"
                                               type="email"
                                               autocomplete="email"
                                               x-model.trim="email"
                                               placeholder="nama@contoh.com"
                                               aria-describedby="{{ $emailDescribedBy }}"
                                               @if ($emailHasError) aria-invalid="true" @endif
                                               class="neo-input h-12 w-full bg-white px-3 text-base font-bold text-[var(--sf-ink)] placeholder:text-[var(--sf-muted)]">
                                        <p id="storefront-email-help" class="text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">
                                            {{ $copy['shop']['email_help'] }}
                                        </p>
                                        @error('email')
                                            <p id="storefront-email-error" role="alert" class="text-sm font-black leading-relaxed text-[#9f1d2d]">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <details data-storefront-optional-support class="rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-3" :open="supportContactOpen" @toggle="supportContactOpen = $event.target.open">
                                        <summary class="cursor-pointer text-sm font-black uppercase tracking-wider text-[var(--sf-ink)]">{{ $copy['shop']['support_contact_label'] }} <span class="normal-case text-[var(--sf-muted)]">(opsional)</span></summary>
                                        <div class="mt-3 grid gap-2">
                                        <div class="grid gap-2 sm:grid-cols-[150px_1fr]">
                                            <select id="storefront-support-contact-channel"
                                                    name="support_contact_channel"
                                                    x-model="supportContactChannel"
                                                    aria-describedby="{{ $supportContactDescribedBy }}"
                                                    @if ($supportContactHasError) aria-invalid="true" @endif
                                                    class="neo-input h-12 w-full bg-white px-3 text-base font-bold text-[var(--sf-ink)]">
                                                <option value="">Tidak perlu</option>
                                                <option value="telegram">Telegram</option>
                                                <option value="whatsapp">WhatsApp</option>
                                            </select>
                                            <input id="storefront-support-contact-value"
                                                   name="support_contact_value"
                                                   type="text"
                                                   x-model.trim="supportContactValue"
                                                   placeholder="@username atau nomor WhatsApp"
                                                   aria-describedby="{{ $supportContactDescribedBy }}"
                                                   @if ($supportContactHasError) aria-invalid="true" @endif
                                                   class="neo-input h-12 w-full bg-white px-3 text-base font-bold text-[var(--sf-ink)] placeholder:text-[var(--sf-muted)]">
                                        </div>
                                        <p id="storefront-support-contact-help" class="text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">
                                            {{ $copy['shop']['support_contact_help'] }}
                                        </p>
                                        @if ($supportContactHasError)
                                            <p id="storefront-support-contact-error" role="alert" class="text-sm font-black leading-relaxed text-[#9f1d2d]">{{ $errors->first('support_contact_channel') ?: $errors->first('support_contact_value') }}</p>
                                        @endif
                                        </div>
                                    </details>

                                    <fieldset class="grid gap-2" x-show="selectedProduct?.checkout?.websiteBinancePay || selectedProduct?.checkout?.websiteQris" @if ($errors->has('payment_method')) aria-describedby="storefront-payment-method-error" @endif>
                                        <legend class="text-sm font-black uppercase tracking-wider text-[var(--sf-ink)]">Metode pembayaran</legend>
                                        <input type="hidden" name="payment_method" :value="finalPaymentMethod()">
                                        <template x-for="choice in orderedCheckoutChoices()" :key="choice.key">
                                            <div>
                                                <input type="radio"
                                                       name="payment_choice"
                                                       x-model="paymentMethod"
                                                       x-on:change="selectPaymentMethod(paymentMethodForChoice(choice))"
                                                       :id="'storefront-payment-method-' + paymentMethodForChoice(choice)"
                                                       :value="paymentMethodForChoice(choice)"
                                                       :disabled="!choice.enabled"
                                                       class="peer sr-only">
                                                <label :for="'storefront-payment-method-' + paymentMethodForChoice(choice)"
                                                       x-bind:class="paymentMethod === paymentMethodForChoice(choice) ? 'bg-[var(--sf-yellow)]' : 'bg-white'"
                                                       class="flex min-h-11 cursor-pointer items-start gap-3 rounded-md border-2 border-[var(--sf-border)] px-3 py-2 text-left text-sm font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)] transition peer-focus-visible:outline peer-focus-visible:outline-4 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-[var(--sf-blue)] peer-disabled:cursor-not-allowed peer-disabled:opacity-60">
                                                    <x-storefront.qris-mark
                                                        size="md"
                                                        x-show="choice.key === 'website_qris' && choice.provider === 'klikqris'"
                                                        x-cloak
                                                    />
                                                    <span class="min-w-0 flex-1">
                                                        <span class="flex flex-wrap items-center gap-2" x-text="choice.label"></span>
                                                        <span class="mt-1 block text-sm font-semibold leading-6 text-[var(--sf-muted)]" x-text="choice.description"></span>
                                                    </span>
                                                </label>
                                            </div>
                                        </template>
                                        <div x-show="paymentMethod === 'binance_pay'" x-cloak class="mt-2 rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-3" aria-label="Binance payment rail selector">
                                            <p class="text-sm font-black text-[var(--sf-ink)]">Choose a Binance payment rail</p>
                                            <div class="mt-2 grid gap-2">
                                                <template x-for="choice in binanceRailChoices()" :key="choice.key">
                                                    <label class="flex cursor-pointer items-start gap-2 rounded-md border-2 border-[var(--sf-border)] bg-white px-3 py-2 text-left text-sm font-black text-[var(--sf-ink)]">
                                                        <input type="radio" name="binance_rail" x-model="binanceRail" :value="paymentMethodForChoice(choice)" class="mt-1" :disabled="!choice.enabled">
                                                        <span><span class="block" x-text="choice.label"></span><span class="mt-1 block text-sm font-semibold leading-6 text-[var(--sf-muted)]" x-text="choice.description"></span></span>
                                                    </label>
                                                </template>
                                            </div>
                                            <button type="button" class="mt-3 text-sm font-black text-[var(--sf-accent)] underline" x-on:click="cancelBinanceRailSelection()">Cancel</button>
                                        </div>
                                        @error('payment_method')
                                            <p id="storefront-payment-method-error" role="alert" class="text-sm font-black leading-relaxed text-[#9f1d2d]">{{ $message }}</p>
                                        @enderror
                                    </fieldset>
                                    <div x-show="paymentMethod === 'qris' && selectedCheckoutChoice()" x-cloak class="flex items-start gap-3 rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-surface-warm)] px-3 py-3 text-[var(--sf-ink)]">
                                        <x-storefront.qris-mark size="lg" x-show="selectedCheckoutChoice()?.provider === 'klikqris'" x-cloak />
                                        <div class="min-w-0 pt-0.5">
                                            <p class="font-display text-lg font-black leading-tight" x-text="selectedCheckoutChoice()?.label || 'Pembayaran QR'">Pembayaran QR</p>
                                            <p class="mt-1 text-sm font-bold leading-6 text-[var(--sf-muted)]" x-text="selectedCheckoutChoice()?.description || 'Ikuti instruksi pembayaran pada invoice.'">Ikuti instruksi pembayaran pada invoice.</p>
                                        </div>
                                    </div>
                                    <p x-show="paymentMethod === 'binance_pay'" class="rounded-md bg-[var(--sf-surface-warm)] px-3 py-2 text-base font-bold leading-7 text-[var(--sf-muted)]">Choose a Binance payment rail before submitting. The final rail is sent explicitly with this checkout.</p>
                                    <label for="storefront-terms-accepted" class="flex items-start gap-3 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-3 text-sm font-bold leading-6 text-[var(--sf-ink)]">
                                        <input id="storefront-terms-accepted"
                                               name="terms_accepted"
                                               type="checkbox"
                                               value="1"
                                               required
                                               x-model="termsAccepted"
                                               aria-describedby="{{ $termsAcceptedDescribedBy }}"
                                               @if ($termsAcceptedHasError) aria-invalid="true" @endif
                                               class="mt-1 h-5 w-5 shrink-0 accent-[var(--sf-accent)]">
                                        <span>Saya telah membaca dan menyetujui <a href="{{ route('storefront.policies.terms') }}" target="_blank" rel="noopener noreferrer" class="underline">{{ $copy['policies']['terms']['title'] }}</a> dan <a href="{{ route('storefront.policies.refund') }}" target="_blank" rel="noopener noreferrer" class="underline">{{ $copy['policies']['refund']['title'] }}</a>.</span>
                                    </label>
                                    <p id="storefront-terms-accepted-help" class="text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">{{ $copy['shop']['terms_consent_help'] }}</p>
                                    @error('terms_accepted')
                                        <p id="storefront-terms-accepted-error" role="alert" class="text-sm font-black leading-relaxed text-[#9f1d2d]">{{ $message }}</p>
                                    @enderror

                                    <p x-show="selectedProduct && !selectedProduct.availability.isAvailable" class="rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-2 text-center text-sm font-black text-[var(--sf-ink)]" x-text="selectedProduct?.stock?.emptyLabel || 'Stok kosong'">
                                        Stok kosong
                                    </p>

                                    <button type="submit" data-storefront-checkout-submit x-bind:disabled="!selectedProduct || !selectedCheckoutChoice()?.enabled" class="neo-button neo-button-stable sticky bottom-0 z-10 flex h-12 w-full select-none items-center justify-center gap-2 bg-[var(--sf-accent)] text-sm font-black text-white transition-colors hover:bg-[var(--sf-accent-deep)] disabled:cursor-not-allowed disabled:opacity-60">
                                        <x-storefront.qris-mark size="xs" x-show="paymentMethod === 'qris' && selectedCheckoutChoice()?.provider === 'klikqris'" x-cloak class="!border-white !shadow-none" />
                                        <span x-text="selectedCheckoutChoice()?.label || 'Pilih metode pembayaran'">Pilih metode pembayaran</span>
                                    </button>
                                </form>
                                @elseif ($checkoutMaintenanceActive)
                                    <div role="status" class="rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] p-4 text-sm font-semibold leading-6 text-[var(--sf-ink)]">
                                        <p class="font-black uppercase tracking-wide">Checkout sedang maintenance</p>
                                        <p class="mt-2 whitespace-pre-line">{{ $checkoutMaintenanceNotice }}</p>
                                    </div>
                                @endif

                                <details data-storefront-prepurchase-support class="rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4 shadow-[4px_4px_0_var(--sf-shadow)]">
                                    <summary id="product-modal-support-title" class="cursor-pointer font-display text-lg font-black text-[var(--sf-ink)]">Butuh bantuan sebelum membeli?</summary>
                                    <div class="mt-3" aria-labelledby="product-modal-support-title">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <h4 class="font-display text-lg font-black text-[var(--sf-ink)]" x-text="selectedProduct?.support?.title || 'Butuh bantuan?'">Butuh bantuan?</h4>
                                            <p class="mt-1 text-sm font-bold leading-6 text-[var(--sf-muted)]" x-text="selectedProduct?.support?.body || 'Tanya admin sebelum membeli.'">Tanya admin sebelum membeli.</p>
                                        </div>
                                        <span class="inline-flex w-fit rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-2.5 py-1 text-[11px] font-black text-[var(--sf-ink)]">Support</span>
                                    </div>
                                    <template x-for="category in (selectedProduct?.support?.categories || [])" :key="category.key">
                                        <div class="mt-3">
                                            <p class="text-sm font-black text-[var(--sf-ink)]" x-text="category.label"></p>
                                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                                <template x-for="option in category.options" :key="option.key">
                                                    <template x-if="option.available">
                                                        <a x-bind:href="option.href"
                                                           target="_blank"
                                                           rel="noopener noreferrer"
                                                           class="inline-flex min-h-11 items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-white px-3 py-2 text-center text-sm font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)] transition hover:-translate-y-0.5 focus:outline-none focus:ring-2 focus:ring-[var(--sf-blue)] focus:ring-offset-2"
                                                           x-bind:data-support-channel="option.key"
                                                           x-bind:data-support-category="category.key"
                                                           x-bind:data-support-message="option.message"
                                                           x-text="option.label"></a>
                                                    </template>
                                                </template>
                                                <template x-for="option in category.options" :key="'disabled-' + option.key">
                                                    <template x-if="!option.available">
                                                        <span class="inline-flex min-h-11 items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-white/70 px-3 py-2 text-center text-sm font-black text-[var(--sf-muted)] opacity-80"
                                                              aria-disabled="true"
                                                              x-bind:data-support-channel="option.key"
                                                              x-bind:data-support-category="category.key"
                                                              x-bind:data-support-message="option.message"
                                                              x-text="option.label + ' belum aktif'"></span>
                                                    </template>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                    </div>
                                </details>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>
