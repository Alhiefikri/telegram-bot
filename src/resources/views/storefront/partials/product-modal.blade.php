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
                            <button type="button" @click="closeProductModal()" class="rounded-md bg-white text-[var(--sf-muted)] hover:text-[var(--sf-ink)] focus:outline-none">
                                <span class="sr-only">Close panel</span>
                                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <!-- Content -->
                        <div class="p-6 space-y-6">
                            <!-- Image/Cover -->
                            <div class="relative flex aspect-[2/1] w-full flex-col items-center justify-center overflow-hidden rounded-lg border-[3px] border-[var(--sf-border)] shadow-[4px_4px_0_var(--sf-shadow)]" :class="selectedProduct.imageUrl ? '' : selectedProduct.coverTheme.bg">
                                <template x-if="selectedProduct.imageUrl">
                                    <img :src="selectedProduct.imageUrl" :alt="selectedProduct.name" class="absolute inset-0 h-full w-full object-cover">
                                </template>
                                <template x-if="!selectedProduct.imageUrl">
                                    <div class="relative z-10 flex flex-col items-center justify-center space-y-2 text-center text-white">
                                        <div x-html="selectedProduct.coverTheme.logo"></div>
                                        <div :class="selectedProduct.coverTheme.accent" class="font-display text-xl font-black uppercase tracking-tight" x-text="selectedProduct.coverTheme.desc"></div>
                                    </div>
                                </template>
                            </div>

                            <!-- Price and Stock -->
                            <div class="flex flex-wrap items-center gap-3 border-b-[3px] border-dashed border-[var(--sf-border)] pb-4">
                                <div class="flex items-center gap-2">
                                    <template x-if="selectedProduct.fake_discount_price">
                                        <span class="text-xs font-black text-[var(--sf-muted)] line-through" x-text="formatIdr(selectedProduct.fake_discount_price)"></span>
                                    </template>
                                    <span class="rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-1 font-display text-sm font-black text-[var(--sf-ink)]" x-text="formatIdr(selectedProduct.price_amount)"></span>
                                </div>
                                <span class="rounded-full border-2 border-[var(--sf-border)] bg-white px-2 py-1 text-[10px] font-black text-[var(--sf-ink)]" x-text="'Stok: ' + selectedProduct.stock"></span>
                            </div>

                            <!-- Description -->
                            <div class="text-sm font-semibold leading-relaxed text-[var(--sf-muted)] whitespace-pre-line" x-text="selectedProduct.description"></div>

                            <div class="grid gap-3 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-3 sm:grid-cols-[1fr_auto] sm:items-center">
                                <div>
                                    <p class="text-xs font-black uppercase tracking-wider text-[var(--sf-ink)]">Jumlah</p>
                                    <p class="mt-1 text-[11px] font-semibold text-[var(--sf-muted)]">Maksimal mengikuti stok dashboard.</p>
                                </div>
                                <div class="flex items-center justify-between gap-3 sm:justify-end">
                                    <button type="button" x-on:click="decreaseQuantity()" x-bind:disabled="quantity <= 1" class="grid h-10 w-10 place-items-center rounded-md border-[3px] border-[var(--sf-border)] bg-white font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)] disabled:cursor-not-allowed disabled:opacity-50">−</button>
                                    <span class="min-w-8 text-center font-display text-lg font-black text-[var(--sf-ink)]" x-text="quantity">1</span>
                                    <button type="button" x-on:click="increaseQuantity()" x-bind:disabled="selectedProduct && quantity >= selectedProduct.stock" class="grid h-10 w-10 place-items-center rounded-md border-[3px] border-[var(--sf-border)] bg-white font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)] disabled:cursor-not-allowed disabled:opacity-50">+</button>
                                </div>
                                <div class="sm:col-span-2 flex items-center justify-between border-t-2 border-dashed border-[var(--sf-border)] pt-3 text-sm font-black text-[var(--sf-ink)]">
                                    <span>Total produk</span>
                                    <span x-text="formatIdr(selectedTotal())">Rp0</span>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="pt-2">
                                <button type="button" @click="checkoutManual()" class="neo-button flex h-12 w-full select-none items-center justify-center gap-2 bg-[var(--sf-accent)] text-sm font-black text-white transition-colors hover:bg-[var(--sf-accent-deep)]">
                                    Beli
                                </button>
                                <p class="mt-3 text-center text-[10px] font-bold text-[var(--sf-muted)]">
                                    <svg class="inline-block h-3 w-3 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                    Lanjut ke halaman scan QR Code
                                </p>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>
