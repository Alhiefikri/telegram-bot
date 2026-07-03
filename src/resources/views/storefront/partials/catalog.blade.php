<section id="products" class="mx-auto max-w-5xl px-6 py-20 relative">
    <div class="text-center max-w-2xl mx-auto mb-12">
        <h2 class="text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900">Katalog Produk</h2>
        <p class="mt-2 text-xs text-zinc-500 leading-relaxed max-w-md mx-auto">Cari ChatGPT, Claude, Gemini, Canva, dan akun AI premium lain yang sedang tersedia.</p>
    </div>

    <div class="max-w-md mx-auto mb-12 relative">
        <form action="{{ url('/') }}#products" method="GET" class="relative">
            <input type="text" name="q" value="{{ $search }}" placeholder="{{ $copy['catalog']['search_placeholder'] }}" class="w-full h-11 pl-11 pr-4 rounded-xl border border-slate-200 bg-white text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-400 focus:ring-1 focus:ring-slate-300 transition-all shadow-sm">
            <div class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
            </div>
            @if($search !== '')
                <a href="{{ url('/') }}#products" class="absolute right-4 top-1/2 -translate-y-1/2 text-xs font-semibold text-slate-500 hover:text-slate-900 transition-colors">Clear</a>
            @endif
        </form>
    </div>

    @if ($categories->isNotEmpty())
        @if ($categoriesWithProducts->isNotEmpty())
            <div class="flex flex-wrap gap-2 justify-center mb-10" aria-label="Filter kategori produk">
                <button type="button" class="category-btn px-4 py-1.5 rounded-full text-xs font-semibold bg-slate-900 text-white border border-slate-900 transition-all duration-300 focus:outline-none" data-filter="all" aria-pressed="true" aria-controls="storefront-product-grid">
                    Semua
                </button>
                @foreach ($categoriesWithProducts as $category)
                    <button type="button" class="category-btn px-4 py-1.5 rounded-full text-xs font-semibold bg-white text-slate-600 border border-slate-200 hover:border-slate-300 transition-all duration-300 focus:outline-none" data-filter="{{ $category->id }}" aria-pressed="false" aria-controls="storefront-product-grid">
                        {{ $category->name }}
                    </button>
                @endforeach
            </div>
        @endif
    @else
        <div class="rounded-2xl border border-dashed border-zinc-200 bg-zinc-50 p-6 text-center max-w-md mx-auto mb-10">
            <p class="font-bold text-zinc-900">Kategori akan segera hadir.</p>
            <p class="mt-2 text-xs leading-5 text-zinc-500">Tim toko sedang menyiapkan kategori publik pertama agar katalog mudah dijelajahi.</p>
        </div>
    @endif

    @if ($popularProducts->isEmpty())
        <p class="mb-8 text-center text-xs text-slate-500">{{ $copy['hero']['popular_empty'] }}</p>
    @endif

    <div id="storefront-product-grid" class="grid gap-6 grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 storefront-popular-card">
        @forelse ($catalogProducts as $product)
            @include('storefront.components.product-card', [
                'product' => $product,
                'isPopular' => $popularProducts->contains('id', $product->id),
            ])
        @empty
            <div class="col-span-full py-16 text-center bg-white border border-zinc-200 rounded-2xl p-8 max-w-md mx-auto reveal-item">
                <svg class="mx-auto h-10 w-10 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
                <h3 class="mt-4 text-sm font-bold text-zinc-900">Katalog publik sedang disiapkan.</h3>
                <p class="mt-2 text-xs text-zinc-500 leading-relaxed">Produk aktif akan tampil otomatis di sini setelah dipublikasikan dari admin. Sambil menunggu, pelanggan bisa menghubungi support untuk rekomendasi akun AI yang tersedia.</p>
            </div>
        @endforelse
    </div>
</section>
