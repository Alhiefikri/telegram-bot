<div class="relative z-50" x-show="productModalOpen" x-cloak aria-labelledby="modal-title" role="dialog" aria-modal="true">
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
                 x-transition:enter="ease-out duration-300"
                 x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                 x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                 x-transition:leave="ease-in duration-200"
                 x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                 x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                 class="neo-panel relative transform overflow-hidden bg-[var(--sf-surface)] text-left shadow-[8px_8px_0_var(--sf-shadow)] transition-all sm:my-8 sm:w-full sm:max-w-lg">

                <template x-if="selectedProduct">
                    <div>
                        <!-- Header with Close Button -->
                        <div class="flex items-center justify-between border-b-[3px] border-[var(--sf-border)] p-4">
                            <h3 class="font-display text-lg font-black text-[var(--sf-ink)]" x-text="selectedProduct.name"></h3>
                            <button type="button" @click="closeProductModal()" class="grid h-10 w-10 place-items-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] shadow-[3px_3px_0_var(--sf-shadow)] transition-transform hover-rubber-band hover:-translate-x-0.5 hover:-translate-y-0.5 active:translate-x-[3px] active:translate-y-[3px] active:shadow-none focus:outline-none text-[var(--sf-ink)] font-black">
                                <span class="text-xl leading-none" aria-hidden="true">×</span>
                                <span class="sr-only">Close panel</span>
                            </button>
                        </div>

                        <!-- Content -->
                        <div class="p-6 space-y-6">
                            <!-- Image/Cover -->
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

                            <!-- Price and Stock -->
                            <div class="flex flex-wrap items-center gap-3 border-b-[3px] border-dashed border-[var(--sf-border)] pb-4">
                                <div class="flex items-center gap-2">
                                    <template x-if="selectedProduct.price.fakeDiscountAmount">
                                        <span class="text-xs font-black text-[var(--sf-muted)] line-through" x-text="formatIdr(selectedProduct.price.fakeDiscountAmount)"></span>
                                    </template>
                                    <span class="rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-1 font-display text-sm font-black text-[var(--sf-ink)]" x-text="formatIdr(selectedProduct.price.amount)"></span>
                                </div>
                                <span class="rounded-full border-2 border-[var(--sf-border)] bg-white px-2 py-1 text-[10px] font-black text-[var(--sf-ink)]" x-text="selectedProduct.stock.label"></span>
                            </div>

                            <!-- Description -->
                            <div class="text-sm font-semibold leading-relaxed text-[var(--sf-muted)] whitespace-pre-line" x-text="selectedProduct.description"></div>

                            <div class="grid gap-3 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-3 sm:grid-cols-[1fr_auto] sm:items-center">
                                <div>
                                    <p class="text-xs font-black uppercase tracking-wider text-[var(--sf-ink)]">Jumlah</p>
                                    <p class="mt-1 text-[11px] font-semibold text-[var(--sf-muted)]">Maksimal mengikuti stok dashboard.</p>
                                </div>
                                <div class="flex items-center justify-between gap-3 sm:justify-end">
                                    <button type="button" x-on:click="decreaseQuantity()" x-bind:disabled="quantity <= 1" class="grid h-10 w-10 place-items-center rounded-md border-[3px] border-[var(--sf-border)] bg-white font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)] transition-transform duration-100 hover:enabled:-translate-x-0.5 hover:enabled:-translate-y-0.5 active:enabled:translate-x-[3px] active:enabled:translate-y-[3px] active:enabled:shadow-none hover:enabled:shadow-[4px_4px_0_var(--sf-shadow)] disabled:cursor-not-allowed disabled:opacity-50">−</button>
                                    <span class="min-w-8 text-center font-display text-lg font-black text-[var(--sf-ink)]" x-text="quantity">1</span>
                                    <button type="button" x-on:click="increaseQuantity()" x-bind:disabled="selectedProduct && quantity >= selectedProduct.stock.available" class="grid h-10 w-10 place-items-center rounded-md border-[3px] border-[var(--sf-border)] bg-white font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)] transition-transform duration-100 hover:enabled:-translate-x-0.5 hover:enabled:-translate-y-0.5 active:enabled:translate-x-[3px] active:enabled:translate-y-[3px] active:enabled:shadow-none hover:enabled:shadow-[4px_4px_0_var(--sf-shadow)] disabled:cursor-not-allowed disabled:opacity-50">+</button>
                                </div>
                                <div class="sm:col-span-2 flex items-center justify-between border-t-2 border-dashed border-[var(--sf-border)] pt-3 text-sm font-black text-[var(--sf-ink)]">
                                    <span>Total produk</span>
                                    <span x-text="formatIdr(selectedTotal())">Rp0</span>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="grid gap-4 pt-2">
                                <form method="POST"
                                      action="{{ route('storefront.shop.checkout') }}"
                                      x-bind:action="selectedProduct && selectedProduct.checkout.websiteQris ? selectedProduct.checkout.websiteQris.url : '{{ route('storefront.shop.checkout') }}'"
                                      class="grid gap-3">
                                    @csrf
                                    <input type="hidden" name="product_id" x-bind:value="selectedProduct?.checkout?.websiteQris?.productId || selectedProduct?.id || ''">
                                    <input type="hidden" name="quantity" x-bind:value="quantity">
                                    <input type="hidden" name="payment_method" x-bind:value="paymentMethod">

                                    <div class="grid gap-2">
                                        <label for="storefront-buyer-handle" class="text-xs font-black uppercase tracking-wider text-[var(--sf-ink)]">{{ $copy['shop']['buyer_handle_label'] }}</label>
                                        <input id="storefront-buyer-handle"
                                               name="buyer_handle"
                                               type="text"
                                               required
                                               x-model.trim="buyerHandle"
                                               placeholder="@username atau Nama Pembeli"
                                               aria-describedby="storefront-buyer-handle-help"
                                               class="neo-input h-12 w-full bg-white px-3 text-sm font-bold text-[var(--sf-ink)] placeholder:text-[var(--sf-muted)]">
                                        <p id="storefront-buyer-handle-help" class="text-[11px] font-semibold leading-relaxed text-[var(--sf-muted)]">
                                            {{ $copy['shop']['buyer_handle_help'] }}
                                        </p>
                                    </div>

                                    <div class="grid gap-2" x-show="selectedProduct?.checkout?.websiteBinancePay || selectedProduct?.checkout?.websiteQris">
                                        <p class="text-xs font-black uppercase tracking-wider text-[var(--sf-ink)]">Metode pembayaran</p>
                                        <template x-for="choice in orderedCheckoutChoices()" :key="choice.key">
                                            <button type="button" x-on:click="paymentMethod = choice.key === 'websiteBinancePay' ? 'binance_pay' : 'qris'" x-bind:class="paymentMethod === (choice.key === 'websiteBinancePay' ? 'binance_pay' : 'qris') ? 'bg-[var(--sf-yellow)]' : 'bg-white'" class="rounded-md border-2 border-[var(--sf-border)] px-3 py-2 text-left text-sm font-black text-[var(--sf-ink)]">
                                                <span x-text="choice.label"></span>
                                                <span class="mt-1 block text-[11px] font-semibold text-[var(--sf-muted)]" x-text="choice.description"></span>
                                            </button>
                                        </template>
                                    </div>
                                    <p x-show="paymentMethod === 'qris'" class="rounded-md bg-[var(--sf-surface-warm)] px-3 py-2 text-[11px] font-bold leading-relaxed text-[var(--sf-muted)]">QRIS hanya untuk aplikasi bank dan e-wallet Indonesia. Simpan link invoice setelah QRIS dibuat.</p>
                                    <p x-show="paymentMethod === 'binance_pay'" class="rounded-md bg-[var(--sf-surface-warm)] px-3 py-2 text-[11px] font-bold leading-relaxed text-[var(--sf-muted)]">Binance Pay dibayar dalam USDT dan diverifikasi manual. Instruksi serta nomor invoice tersedia setelah checkout.</p>

                                    <p x-show="selectedProduct && !selectedProduct.availability.isAvailable" class="rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-2 text-center text-xs font-black text-[var(--sf-ink)]" x-text="selectedProduct?.stock?.emptyLabel || 'Stok kosong'">
                                        Stok kosong
                                    </p>

                                    <button type="submit" x-bind:disabled="!selectedProduct || !selectedCheckoutChoice()?.enabled" class="neo-button hover-rubber-band flex h-12 w-full select-none items-center justify-center gap-2 bg-[var(--sf-accent)] text-sm font-black text-white transition-colors hover:bg-[var(--sf-accent-deep)] disabled:cursor-not-allowed disabled:opacity-60">
                                        <span x-text="selectedCheckoutChoice()?.label || @js($copy['shop']['checkout_cta'])">{{ $copy['shop']['checkout_cta'] }}</span>
                                    </button>
                                </form>

                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>
