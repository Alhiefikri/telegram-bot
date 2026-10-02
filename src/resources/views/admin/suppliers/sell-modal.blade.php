@php
    $returnQ = $returnQ ?? '';
    $returnStatus = $returnStatus ?? 'semua';
@endphp
<div
    x-cloak
    x-show="open"
    x-transition.opacity
    class="fixed inset-0 z-50 flex items-end justify-center bg-neo-ink/60 p-3 sm:items-center sm:p-4"
    x-on:keydown.escape.window="if (open) close()"
>
    <div class="absolute inset-0" x-on:click="close()" aria-hidden="true"></div>

    <div
        id="supplier-sell-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="supplier-sell-title"
        x-on:keydown.tab="trapModalFocus($event)"
        x-on:focusin.window="if (open) keepModalFocus($event, 'supplier-sell-modal')"
        class="relative max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-[1.25rem] border-[4px] border-neo-ink bg-white p-4 text-neo-ink shadow-neo-xl sm:p-5"
    >
        <button
            type="button"
            class="absolute right-3 top-3 inline-flex h-9 w-9 items-center justify-center rounded-[0.35rem] border-[3px] border-neo-ink bg-white text-neo-ink shadow-neo-xs transition-transform hover:-translate-y-0.5"
            aria-label="Tutup"
            x-on:click="close()"
        >
            <span aria-hidden="true" class="text-lg font-black leading-none">×</span>
        </button>

        <p class="text-[10px] font-black uppercase tracking-[0.2em] text-brand-muted">Jual produk supplier</p>
        <h2 id="supplier-sell-title" class="mt-1 break-words font-display text-xl font-black uppercase text-neo-ink" x-text="item?.name ?? 'Produk supplier'">Produk supplier</h2>
        <p class="mt-2 text-xs font-bold text-brand-muted" x-show="item?.cost">
            Modal: <span class="text-neo-ink" x-text="item?.cost"></span>
            <template x-if="item?.currency_cost">
                <span> · Harga supplier <span x-text="item?.currency_cost"></span></span>
            </template>
        </p>

        <div x-show="item && ! item.sellable" class="mt-3">
            <x-admin-dashboard.notice tone="warning" class="text-xs">
                SKU ini butuh input/aktivasi manual dari operator. Produknya tetap bisa dibuat atau dihubungkan, tetapi belum bisa dijual otomatis sampai supplier mendukung pengiriman instan.
            </x-admin-dashboard.notice>
        </div>

        <div class="mt-4 grid gap-2 sm:grid-cols-2">
            <label
                class="cursor-pointer rounded-[1rem] border-[3px] border-neo-ink p-3 shadow-neo-sm transition"
                x-bind:class="mode === 'new' ? 'bg-[#fee648]' : 'bg-white'"
            >
                <span class="flex items-start gap-3">
                    <input type="radio" name="sell_mode" value="new" x-model="mode" class="mt-1 h-5 w-5">
                    <span>
                        <span class="block font-display text-sm font-black uppercase">Buat produk baru</span>
                        <span class="mt-1 block text-xs font-bold text-brand-muted">Produk toko baru dibuat sebagai draft, lalu kamu atur harga dan tayangkannya.</span>
                    </span>
                </span>
            </label>
            <label
                class="cursor-pointer rounded-[1rem] border-[3px] border-neo-ink p-3 shadow-neo-sm transition"
                x-bind:class="mode === 'link' ? 'bg-[#fee648]' : 'bg-white'"
            >
                <span class="flex items-start gap-3">
                    <input type="radio" name="sell_mode" value="link" x-model="mode" class="mt-1 h-5 w-5">
                    <span>
                        <span class="block font-display text-sm font-black uppercase">Hubungkan ke produk yang ada</span>
                        <span class="mt-1 block text-xs font-bold text-brand-muted">Pakai produk toko yang sudah pernah dibuat dan sudah ekuivalen dengan SKU ini.</span>
                    </span>
                </span>
            </label>
        </div>

        {{-- Buat produk baru --}}
        <form
            x-show="mode === 'new'"
            method="POST"
            x-bind:action="item?.import_url"
            x-on:submit="markSubmitting()"
            class="mt-4 grid gap-3"
        >
            @csrf
            <input type="hidden" name="supplier_version" value="{{ $supplier->version }}">
            <input type="hidden" name="context" value="catalog">
            <input type="hidden" name="context_id" value="{{ $supplier->id }}">
            <input type="hidden" name="return_q" value="{{ $returnQ }}">
            <input type="hidden" name="return_status" value="{{ $returnStatus }}">

            <div class="grid gap-1">
                <x-admin-dashboard.field-label for="supplier-sell-name">Nama produk toko</x-admin-dashboard.field-label>
                <x-admin-dashboard.input id="supplier-sell-name" name="name" maxlength="255" required x-ref="sellName" x-model="name" />
                <p class="text-[10px] font-bold text-neo-ink/60">Nama yang dilihat pembeli. Bisa diedit lagi nanti di tab Produk.</p>
            </div>

            <div class="grid gap-1">
                <x-admin-dashboard.field-label for="supplier-sell-price">Harga jual (IDR)</x-admin-dashboard.field-label>
                <x-admin-dashboard.input id="supplier-sell-price" name="price_amount" type="number" min="1" max="1000000000" inputmode="numeric" required x-model="price" />
                <p class="text-[10px] font-bold text-neo-ink/60">Saran otomatis: modal + untung 20%. Bisa diubah di langkah berikutnya.</p>
            </div>

            <div class="grid gap-1">
                <x-admin-dashboard.field-label for="supplier-sell-description">Deskripsi singkat (opsional)</x-admin-dashboard.field-label>
                <x-admin-dashboard.textarea id="supplier-sell-description" name="description" rows="3" maxlength="10000" x-model="description" />
                <p class="text-[10px] font-bold text-neo-ink/60" x-show="copiedFrom" x-cloak>
                    Deskripsi disalin dari <span class="font-black" x-text="copiedFrom"></span>. Edit dulu agar sesuai sebelum produk tayang.
                </p>
            </div>

            <div class="grid gap-2" x-show="item?.description" x-cloak>
                <button
                    type="button"
                    class="justify-self-start text-xs font-black underline"
                    x-on:click="description = item?.description ?? ''"
                >Salin deskripsi supplier sebagai draf</button>
                <p class="text-[10px] font-bold text-neo-ink/60">Teks supplier hanya draf awal. Wajib diedit dan jangan memuat klaim/branding supplier sebelum produk tayang.</p>
            </div>

            <details class="rounded-[1rem] border-[3px] border-dashed border-neo-ink/40 bg-white/70 p-3">
                <summary class="cursor-pointer text-xs font-black uppercase tracking-wide">Salin deskripsi dari produk yang ada (opsional)</summary>
                <div class="mt-3 grid gap-2">
                    <x-admin-dashboard.field-label for="supplier-copy-search">Cari produk untuk disalin</x-admin-dashboard.field-label>
                    <x-admin-dashboard.input
                        id="supplier-copy-search"
                        type="search"
                        placeholder="Ketik minimal 2 huruf, mis. Netflix"
                        autocomplete="off"
                        x-model="copySearch"
                        x-on:input="queueCopySearch()"
                    />
                    <div x-show="copySearching" class="text-xs font-bold text-brand-muted">Mencari...</div>
                    <div x-show="copyResults.length > 0" class="grid gap-2">
                        <template x-for="product in copyResults" :key="product.id">
                            <button
                                type="button"
                                data-supplier-copy-result
                                class="flex min-h-11 w-full items-center justify-between gap-3 rounded-lg border-2 border-neo-ink bg-white px-3 py-2 text-left text-xs font-bold shadow-neo-xs"
                                x-on:click="applyCopy(product)"
                            >
                                <span class="min-w-0 truncate" x-text="product.name"></span>
                                <span class="shrink-0 text-brand-muted">Salin deskripsi</span>
                            </button>
                        </template>
                    </div>
                </div>
            </details>

            <x-admin-dashboard.button type="submit" variant="primary" x-bind:disabled="submitting" class="w-full">
                Buat & lanjut atur harga
            </x-admin-dashboard.button>
        </form>

        {{-- Hubungkan ke produk yang sudah ada --}}
        <form
            x-show="mode === 'link'"
            method="POST"
            x-bind:action="item?.map_url"
            x-on:submit="markSubmitting()"
            class="mt-4 grid gap-3"
        >
            @csrf
            <input type="hidden" name="supplier_version" value="{{ $supplier->version }}">
            <input type="hidden" name="version" value="0">
            <input type="hidden" name="priority" value="100">
            <input type="hidden" name="context" value="catalog">
            <input type="hidden" name="context_id" value="{{ $supplier->id }}">
            <input type="hidden" name="return_q" value="{{ $returnQ }}">
            <input type="hidden" name="return_status" value="{{ $returnStatus }}">
            <input type="hidden" name="is_active" x-bind:value="item?.sellable ? 1 : 0">
            <input type="hidden" name="product_id" x-bind:value="selected ? selected.id : ''">

            <div class="grid gap-1">
                <x-admin-dashboard.field-label for="supplier-sell-search">Cari produk toko</x-admin-dashboard.field-label>
                <x-admin-dashboard.input
                    id="supplier-sell-search"
                    type="search"
                    placeholder="Ketik minimal 2 huruf, mis. Netflix"
                    autocomplete="off"
                    x-model="search"
                    x-on:input="queueSearch()"
                />
                <p class="text-[10px] font-bold text-neo-ink/60">Cari berdasarkan nama produk atau ID-nya.</p>
            </div>

            <div x-show="searching" class="text-xs font-bold text-brand-muted">Mencari...</div>

            <div x-show="results.length > 0" class="grid gap-2 rounded-[1rem] border-[3px] border-dashed border-neo-ink/40 bg-white p-2">
                <template x-for="product in results" :key="product.id">
                    <button
                        type="button"
                        class="flex min-h-11 w-full items-center justify-between gap-3 rounded-lg border-2 border-neo-ink bg-white px-3 py-2 text-left text-xs font-bold shadow-neo-xs"
                        x-on:click="selectProduct(product)"
                    >
                        <span class="min-w-0 truncate" x-text="product.name"></span>
                        <span class="shrink-0 text-brand-muted" x-text="product.price + (product.is_active ? '' : ' · nonaktif')"></span>
                    </button>
                </template>
            </div>

            <div x-show="selected" class="rounded-[1rem] border-[3px] border-neo-ink bg-neo-info-soft p-3">
                <p class="text-xs font-black uppercase tracking-wide text-brand-muted">Produk terpilih</p>
                <p class="mt-1 break-words font-display text-sm font-black" x-text="selected?.name"></p>
                <p class="mt-1 text-xs font-bold text-brand-muted">
                    Harga jual <span x-text="selected?.price"></span>
                    · status <span x-text="selected?.is_active ? 'tayang' : 'nonaktif'"></span>
                </p>
                <button type="button" class="mt-2 text-xs font-black underline" x-on:click="clearSelected()">Ganti produk</button>
            </div>

            <label class="flex items-start gap-3 rounded-neo-2xl border-[3px] border-neo-ink bg-white px-4 py-3 text-sm font-bold shadow-neo-sm">
                <input type="checkbox" name="equivalent_confirmed" value="1" required x-model="equivalentConfirmed" class="mt-0.5 h-5 w-5 rounded-none border-neo-ink text-neo-focus focus:ring-2 focus:ring-neo-focus focus:ring-offset-2 focus:ring-offset-neo-cream">
                <span>Saya sudah memastikan SKU supplier ini benar-benar setara dengan produk toko yang dipilih.</span>
            </label>

            <x-admin-dashboard.button type="submit" variant="primary" x-bind:disabled="! canSubmitLink()" class="w-full">
                Hubungkan & lanjut atur harga
            </x-admin-dashboard.button>
        </form>
    </div>
</div>
