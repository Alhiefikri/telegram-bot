<header class="sticky top-4 z-50 mx-auto w-[calc(100%-2rem)] max-w-5xl rounded-2xl bg-white/92 backdrop-blur-xl border border-[var(--sf-border)] shadow-sm transition-all duration-300">
    <div class="flex h-14 items-center justify-between px-6">
        <a href="{{ $canonicalUrl }}" class="flex items-center gap-2">
            <div class="h-7 w-7 rounded-lg bg-slate-900 flex items-center justify-center text-white font-black text-sm select-none shadow-sm">L</div>
            <span class="text-sm font-bold tracking-tight text-[var(--sf-ink)]">{{ $brand }}</span>
        </a>

        <nav class="hidden md:flex items-center gap-1.5 p-1 bg-slate-50 rounded-xl text-xs font-semibold text-slate-600 border border-slate-200">
            <a href="#products" class="px-4 py-1.5 rounded-lg hover:bg-white hover:text-slate-900 transition-colors">Produk</a>
            <a href="#cara-beli" class="px-4 py-1.5 rounded-lg hover:bg-white hover:text-slate-900 transition-colors">Cara Beli</a>
        </nav>

        <div class="flex items-center gap-3">
            <a href="#products" class="hidden sm:inline-flex items-center justify-center rounded-xl bg-[var(--sf-ink)] px-4 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-slate-700">
                Lihat Produk
            </a>

            <button id="mobile-menu-btn" class="md:hidden flex flex-col justify-center items-center w-8 h-8 rounded-full border border-slate-200 bg-white shadow-sm gap-1 focus:outline-none" aria-label="Toggle menu">
                <span id="menu-icon-1" class="w-4 h-[2px] bg-slate-700 transition-transform duration-300"></span>
                <span id="menu-icon-2" class="w-4 h-[2px] bg-slate-700 transition-transform duration-300"></span>
            </button>
        </div>
    </div>

    <div id="mobile-menu" class="hidden absolute top-16 left-0 right-0 p-4 mx-2 rounded-2xl bg-white border border-slate-200 shadow-xl opacity-0 scale-95 transition-all duration-300 z-50">
        <nav class="flex flex-col gap-4 text-sm font-bold text-slate-600">
            <a href="#products" class="px-4 py-2 hover:bg-slate-50 rounded-xl transition-colors">Produk</a>
            <a href="#cara-beli" class="px-4 py-2 hover:bg-slate-50 rounded-xl transition-colors">Cara Beli</a>
            <a href="#faq" class="px-4 py-2 hover:bg-slate-50 rounded-xl transition-colors">FAQ</a>
            <a href="#support" class="px-4 py-2 hover:bg-slate-50 rounded-xl transition-colors">Support</a>
            <a href="#products" class="mt-2 inline-flex items-center justify-center rounded-full bg-slate-900 py-3 text-center text-xs font-bold text-white">
                Lihat Produk
            </a>
        </nav>
    </div>
</header>
