<section class="mx-auto max-w-5xl px-6 pt-8 lg:pt-16 pb-16 relative storefront-hero-card">
    <div class="grid lg:grid-cols-12 gap-12 lg:gap-16 items-center">
        <div class="lg:col-span-7 text-left motion-safe:animate-storefront-reveal">
            <h1 class="text-4xl sm:text-5xl lg:text-[3.5rem] font-black leading-[1.05] tracking-tight text-[var(--sf-ink)] text-balance">
                {{ $copy['hero']['headline'] }}
            </h1>

            <p class="mt-6 max-w-xl text-sm sm:text-base text-[var(--sf-muted)] font-normal leading-relaxed">
                {{ $copy['hero']['body'] }}
                <span class="sr-only">Akun AI Premium Siap Pakai. Katalog akun AI premium</span>
            </p>

            <div class="mt-8 flex flex-col sm:flex-row items-stretch sm:items-center gap-4">
                <a href="#products" class="inline-flex h-12 items-center justify-center rounded-xl bg-[var(--sf-ink)] hover:bg-slate-700 text-sm font-bold text-white transition-all hover:-translate-y-0.5 active:translate-y-0 shadow-lg px-8 text-center select-none">
                    {{ $copy['hero']['primary_cta'] }}
                </a>
                <a href="#cara-beli" class="inline-flex h-12 items-center justify-center rounded-xl border border-[var(--sf-border)] bg-white px-8 text-sm font-bold text-[var(--sf-ink)] transition-all hover:border-slate-300 hover:text-slate-700">
                    {{ $copy['hero']['secondary_cta'] }}
                </a>
            </div>
        </div>

        <div class="lg:col-span-5 flex justify-center w-full">
            <div class="hero-preview-panel w-full max-w-md rounded-2xl border border-[var(--sf-border)] bg-white/95 p-5 shadow-xl">
                <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-widest text-slate-500">Order siap diproses</p>
                        <p class="mt-0.5 text-sm font-bold text-[var(--sf-ink)]">Katalog aktif hari ini</p>
                    </div>
                    <span class="rounded-lg bg-emerald-50 px-2.5 py-1 text-[10px] font-semibold text-emerald-700">Online</span>
                </div>

                <div class="mt-5 space-y-2.5">
                    <div class="hero-flow-row rounded-xl border border-slate-200 bg-slate-50 p-3.5">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-xs font-bold text-zinc-900">ChatGPT Plus</p>
                                <p class="mt-0.5 text-[11px] text-slate-500">Stok siap, pengiriman digital</p>
                            </div>
                            <span class="text-[11px] font-semibold text-slate-700">Ready</span>
                        </div>
                    </div>
                    <div class="hero-flow-row rounded-xl border border-slate-200 bg-white p-3.5">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-xs font-bold text-zinc-900">Claude Pro</p>
                                <p class="mt-0.5 text-[11px] text-slate-500">Support bantu cek detail akun</p>
                            </div>
                            <span class="text-[11px] font-semibold text-slate-700">Ready</span>
                        </div>
                    </div>
                    <div class="hero-flow-row rounded-xl border border-slate-200 bg-white p-3.5">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-xs font-bold text-zinc-900">Canva Premium</p>
                                <p class="mt-0.5 text-[11px] text-slate-500">Harga jelas sebelum order</p>
                            </div>
                            <span class="text-[11px] font-semibold text-zinc-500">Cek stok</span>
                        </div>
                    </div>
                </div>

                <div class="mt-5 grid grid-cols-3 gap-2 text-center">
                    @foreach (array_slice($copy['trust_indicators'], 0, 3) as $indicator)
                        <div class="rounded-xl bg-slate-50 px-3 py-3">
                            <p class="text-[10px] font-bold leading-tight text-slate-900">{{ $indicator['title'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</section>
