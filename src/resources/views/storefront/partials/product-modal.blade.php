@php
    $modalCheckoutError = session('storefront_checkout_error')
        ?: $errors->first('product_id')
        ?: $errors->first('payment_method')
        ?: $errors->first('support_contact_channel')
        ?: $errors->first('support_contact_value')
        ?: $errors->first('quantity');
    $buyerNameHasError = $errors->has('buyer_name');
    $buyerNameDescribedBy = 'storefront-buyer-name-help'.($buyerNameHasError ? ' storefront-buyer-name-error' : '');
    $supportContactHasError = $errors->has('support_contact_channel') || $errors->has('support_contact_value');
    $supportContactDescribedBy = 'storefront-support-contact-help'.($supportContactHasError ? ' storefront-support-contact-error' : '');
    $checkoutMaintenanceState = $checkoutMaintenance
        ?? (isset($catalogPage) ? $catalogPage->checkoutMaintenance : null);
    $checkoutMaintenanceActive = (bool) ($checkoutMaintenanceState?->active);
    $checkoutMaintenanceNotice = $checkoutMaintenanceNotice
        ?? ($checkoutMaintenanceState ? \App\Storefront\StorefrontCheckoutMaintenanceCopy::notice($checkoutMaintenanceState) : '');
@endphp

<div class="relative z-50" x-show="productModalOpen" x-cloak>
    <div x-show="productModalOpen"
         x-transition:enter="ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 bg-black/60 backdrop-blur-sm transition-opacity"
         @click="closeProductModal()"></div>

    <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4 text-center sm:p-0">
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
                 class="neo-panel relative transform overflow-hidden bg-[var(--sf-surface)] text-left shadow-[8px_8px_0_var(--sf-shadow)] transition-all focus:outline-none sm:my-8 sm:w-full sm:max-w-lg">

                <template x-if="selectedProduct">
                    <div>
                        <div class="flex items-center justify-between border-b-[3px] border-[var(--sf-border)] p-4">
                            <h3 id="modal-title" class="font-display text-lg font-black text-[var(--sf-ink)]" x-text="selectedProduct.name"></h3>
                            <button type="button" @click="closeProductModal()" class="grid h-12 w-12 place-items-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)] transition-transform hover-rubber-band hover:-translate-x-0.5 hover:-translate-y-0.5 active:translate-x-[3px] active:translate-y-[3px] active:shadow-none focus:outline-none" aria-label="Tutup detail produk">
                                <span class="text-xl leading-none" aria-hidden="true">×</span>
                            </button>
                        </div>

                        <div class="space-y-6 p-6">
                            <div class="relative flex aspect-[2/1] w-full flex-col items-center justify-center overflow-hidden rounded-lg border-[3px] border-[var(--sf-border)] shadow-[4px_4px_0_var(--sf-shadow)]" :class="selectedProduct.cover.imageUrl ? '' : selectedProduct.cover.theme.bg">
                                <template x-if="selectedProduct.cover.imageUrl">
                                    <img :src="selectedProduct.cover.imageUrl" :alt="selectedProduct.name" class="absolute inset-0 h-full w-full bg-white object-contain object-center">
                                </template>
                                <template x-if="!selectedProduct.cover.imageUrl">
                                    <div class="relative z-10 flex flex-col items-center justify-center space-y-2 text-center text-white">
                                        <div x-html="selectedProduct.cover.theme.logo"></div>
                                        <div :class="selectedProduct.cover.theme.accent" class="font-display text-xl font-black uppercase tracking-tight" x-text="selectedProduct.cover.theme.desc"></div>
                                    </div>
                                </template>
                            </div>

                            <div class="flex flex-wrap items-center gap-3 border-b-[3px] border-dashed border-[var(--sf-border)] pb-4">
                                <div class="flex items-center gap-2">
                                    <span class="rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-1 font-display text-sm font-black text-[var(--sf-ink)]" x-text="formatIdr(selectedProduct.price.amount)"></span>
                                </div>
                                <span class="rounded-full border-2 border-[var(--sf-border)] bg-white px-2 py-1 text-sm font-black text-[var(--sf-ink)]" x-text="selectedProduct.stock.label"></span>
                            </div>

                            <div class="whitespace-pre-line text-base font-semibold leading-7 text-[var(--sf-muted)]" x-text="selectedProduct.description"></div>

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
                                <div class="flex items-center justify-between border-t-2 border-dashed border-[var(--sf-border)] pt-3 text-sm font-black text-[var(--sf-ink)] sm:col-span-2">
                                    <span>Total produk</span>
                                    <span x-text="formatIdr(selectedTotal())">Rp0</span>
                                </div>
                            </div>

                            <div class="grid gap-4 pt-2">
                                @if (! $checkoutMaintenanceActive)
                                <form method="POST"
                                      action="{{ route('storefront.shop.checkout') }}"
                                      x-bind:action="selectedProduct && selectedProduct.checkout.websiteQris ? selectedProduct.checkout.websiteQris.url : '{{ route('storefront.shop.checkout') }}'"
                                      class="grid gap-3">
                                    @csrf
                                    <input type="hidden" name="product_id" x-bind:value="selectedProduct?.checkout?.websiteQris?.productId || selectedProduct?.id || ''">
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

                                    <div class="grid gap-2 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-3">
                                        <label for="storefront-support-contact-channel" class="text-sm font-black uppercase tracking-wider text-[var(--sf-ink)]">{{ $copy['shop']['support_contact_label'] }}</label>
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

                                    <fieldset class="grid gap-2" x-show="selectedProduct?.checkout?.websiteBinancePay || selectedProduct?.checkout?.websiteQris" @if ($errors->has('payment_method')) aria-describedby="storefront-payment-method-error" @endif>
                                        <legend class="text-sm font-black uppercase tracking-wider text-[var(--sf-ink)]">Metode pembayaran</legend>
                                        <template x-for="choice in orderedCheckoutChoices()" :key="choice.key">
                                            <div>
                                                <input type="radio"
                                                       name="payment_method"
                                                       x-model="paymentMethod"
                                                       :id="'storefront-payment-method-' + paymentMethodForChoice(choice)"
                                                       :value="paymentMethodForChoice(choice)"
                                                       :disabled="!choice.enabled"
                                                       class="peer sr-only">
                                                <label :for="'storefront-payment-method-' + paymentMethodForChoice(choice)"
                                                       x-bind:class="paymentMethod === paymentMethodForChoice(choice) ? 'bg-[var(--sf-yellow)]' : 'bg-white'"
                                                       class="block min-h-11 cursor-pointer rounded-md border-2 border-[var(--sf-border)] px-3 py-2 text-left text-sm font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)] transition peer-focus-visible:outline peer-focus-visible:outline-4 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-[var(--sf-blue)] peer-disabled:cursor-not-allowed peer-disabled:opacity-60">
                                                    <span x-text="choice.label"></span>
                                                    <span class="mt-1 block text-sm font-semibold leading-6 text-[var(--sf-muted)]" x-text="choice.description"></span>
                                                </label>
                                            </div>
                                        </template>
                                        @error('payment_method')
                                            <p id="storefront-payment-method-error" role="alert" class="text-sm font-black leading-relaxed text-[#9f1d2d]">{{ $message }}</p>
                                        @enderror
                                    </fieldset>
                                    <p x-show="paymentMethod === 'qris'" class="rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-surface-warm)] px-3 py-2 text-base font-bold leading-7 text-[var(--sf-ink)]">Total produk di atas belum termasuk biaya QRIS. Biaya dan total QRIS final ditentukan provider setelah QRIS dibuat. Bayar hanya nominal total pada invoice QRIS.</p>
                                    <p x-show="paymentMethod === 'binance_pay'" class="rounded-md bg-[var(--sf-surface-warm)] px-3 py-2 text-base font-bold leading-7 text-[var(--sf-muted)]">Binance Pay dibayar dalam USDT dan diverifikasi manual. Instruksi serta nomor invoice tersedia setelah checkout.</p>

                                    <p x-show="selectedProduct && !selectedProduct.availability.isAvailable" class="rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-2 text-center text-sm font-black text-[var(--sf-ink)]" x-text="selectedProduct?.stock?.emptyLabel || 'Stok kosong'">
                                        Stok kosong
                                    </p>

                                    <button type="submit" x-bind:disabled="!selectedProduct || !selectedCheckoutChoice()?.enabled" class="neo-button hover-rubber-band flex h-12 w-full select-none items-center justify-center gap-2 bg-[var(--sf-accent)] text-sm font-black text-white transition-colors hover:bg-[var(--sf-accent-deep)] disabled:cursor-not-allowed disabled:opacity-60">
                                        <span x-text="selectedCheckoutChoice()?.label || @js($copy['shop']['checkout_cta'])">{{ $copy['shop']['checkout_cta'] }}</span>
                                    </button>
                                </form>
                                @else
                                    <div role="status" class="rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] p-4 text-sm font-semibold leading-6 text-[var(--sf-ink)]">
                                        <p class="font-black uppercase tracking-wide">Checkout sedang maintenance</p>
                                        <p class="mt-2 whitespace-pre-line">{{ $checkoutMaintenanceNotice }}</p>
                                    </div>
                                @endif

                                <section class="rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4 shadow-[4px_4px_0_var(--sf-shadow)]" aria-labelledby="product-modal-support-title">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <h4 id="product-modal-support-title" class="font-display text-lg font-black text-[var(--sf-ink)]" x-text="selectedProduct?.support?.title || 'Butuh bantuan?'">Butuh bantuan?</h4>
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
                                </section>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>
