<section id="featured-offer" class="mx-auto max-w-5xl px-6 pb-20 relative">
    @if ($featuredProduct)
        @php
            $featuredStock = $storefront->stockAvailable($featuredProduct);
            $featuredLink = $storefront->featuredLink($featuredProduct);
        @endphp

        <div class="w-full rounded-2xl border border-[var(--sf-border)] bg-white p-6 shadow-lg sm:p-8 relative overflow-hidden text-zinc-900">
            <div class="grid lg:grid-cols-12 gap-8 lg:gap-12 items-center relative z-10">
                <div class="lg:col-span-4 flex justify-center">
                    @include('storefront.components.product-cover', [
                        'product' => $featuredProduct,
                        'context' => 'featured',
                    ])
                </div>

                <div class="lg:col-span-8 space-y-6">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <h2 class="text-xl sm:text-2xl font-bold tracking-tight leading-tight text-zinc-900">{{ $featuredProduct->name }}</h2>
                        <span class="rounded-lg bg-emerald-50 px-3 py-1 text-[10px] font-semibold text-emerald-700">Stok siap</span>
                    </div>

                    <p class="text-sm text-slate-600 leading-relaxed max-w-xl">{{ $featuredProduct->description }}</p>

                    <div class="grid sm:grid-cols-2 gap-6 items-end">
                        <div class="space-y-1">
                            <span class="text-3xl font-black text-slate-900 tracking-tight font-display">{{ $storefront->idr($featuredProduct->price_amount) }}</span>
                            <div class="pt-2">
                                <span class="inline-flex rounded-lg bg-slate-50 px-3 py-1 text-[10px] font-semibold text-slate-600">Sisa {{ $featuredStock }}</span>
                            </div>
                        </div>

                        <div class="flex flex-col gap-2">
                            <a href="{{ $featuredLink }}"
                                    class="w-full h-12 bg-[var(--sf-ink)] hover:bg-slate-700 text-white font-bold rounded-xl text-xs transition-all hover:-translate-y-0.5 active:translate-y-0 shadow-lg flex items-center justify-center gap-2 select-none">
                                Belanja Sekarang
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="w-full bg-white p-10 rounded-2xl border border-dashed border-slate-200 text-center max-w-xl mx-auto flex flex-col items-center">
            <h2 class="text-lg sm:text-xl font-bold text-zinc-900 font-display">Promo pilihan menunggu stok siap.</h2>
            <p class="mt-3 text-xs text-slate-500 leading-relaxed max-w-sm">Belum ada produk aktif dengan stok tersedia untuk dijadikan pilihan utama. Katalog tetap bisa dibaca, dan pelanggan dapat menghubungi support untuk rekomendasi.</p>
            <a href="{{ $supportHref ?: '#support' }}" class="mt-6 inline-flex h-11 px-6 items-center justify-center rounded-full bg-slate-900 text-white text-xs font-bold hover:bg-slate-700 transition-colors">Minta rekomendasi ke support</a>
        </div>
    @endif
</section>
