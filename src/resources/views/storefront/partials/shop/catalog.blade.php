<section id="products" class="relative border-y-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] py-12 sm:py-14">
    <div class="pointer-events-none absolute inset-0 bg-grid-pattern opacity-70"></div>

    <div class="relative z-10 mx-auto max-w-6xl px-4 sm:px-6">
        <div class="mb-8 grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,25rem)] lg:items-end">
            <div>
                <div class="mb-4 inline-flex items-center gap-2 rounded-full border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] px-4 py-2 text-[11px] font-black text-[var(--sf-ink)] shadow-[4px_4px_0_var(--sf-shadow)]">
                    <span>Katalog aktif · {{ $catalogProducts->count() }} produk publik</span>
                </div>
                <h2 class="text-balance font-display text-3xl font-black tracking-tight text-[var(--sf-ink)] sm:text-4xl">
                    Pilih akun, cek stok, lanjut checkout.
                </h2>
                <p class="mt-3 max-w-2xl text-sm font-semibold leading-7 text-[var(--sf-muted)]">
                    Produk aktif dari Admin Dashboard muncul otomatis di sini. Filter kategori atau cari produk yang siap dibeli.
                </p>
            </div>

            <form action="{{ $searchAction }}" method="GET" class="relative">
                <label for="shop-search" class="sr-only">Cari produk</label>
                <input id="shop-search" type="text" name="q" value="{{ $search }}" placeholder="{{ $copy['catalog']['search_placeholder'] }}" class="neo-input h-14 w-full pl-12 pr-16 text-sm font-bold text-[var(--sf-ink)] placeholder-[var(--sf-muted)] transition-all focus:outline-none">
                <div class="absolute left-4 top-1/2 -translate-y-1/2 text-[var(--sf-ink)]">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>
                @if($search !== '')
                    <a href="{{ $shopUrl }}#products" class="absolute right-4 top-1/2 -translate-y-1/2 text-xs font-black text-[var(--sf-accent)] transition-colors hover:text-[var(--sf-ink)]">Clear</a>
                @endif
            </form>
        </div>

        @if ($categories->isNotEmpty())
            @if ($categoriesWithProducts->isNotEmpty())
                <div class="mb-7 flex flex-wrap gap-3" aria-label="Filter kategori produk">
                    <button type="button" class="category-btn neo-filter-active rounded-full border-[3px] px-4 py-2 text-xs font-black transition-all duration-300 focus:outline-none" data-filter="all" aria-pressed="true" aria-controls="storefront-product-grid">
                        Semua
                    </button>
                    @foreach ($categoriesWithProducts as $category)
                        <button type="button" class="category-btn neo-filter-inactive rounded-full border-[3px] px-4 py-2 text-xs font-black transition-all duration-300 hover:-translate-y-0.5 hover:shadow-[4px_4px_0_var(--sf-shadow)] focus:outline-none" data-filter="{{ $category->id }}" aria-pressed="false" aria-controls="storefront-product-grid">
                            {{ $category->name }}
                        </button>
                    @endforeach
                </div>
            @endif
        @else
            <x-storefront.empty-state
                :title="$copy['catalog']['empty_category_title']"
                :body="$copy['catalog']['empty_category_body']"
                class="mb-8 p-6"
            />
        @endif

        <div id="storefront-product-grid" @class([
            'storefront-popular-card grid p-1 sm:p-2',
            'mx-auto max-w-xs grid-cols-1 gap-4' => $catalogProducts->count() === 1,
            'grid-cols-2 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4' => $catalogProducts->count() !== 1,
        ])>
            @forelse ($catalogProducts as $product)
                @include('storefront.components.product-card', [
                    'product' => $product,
                    'isPopular' => $popularProducts->contains('id', $product->id),
                ])
            @empty
                <x-storefront.empty-state
                    title="{{ $copy['shop']['empty_title'] }}"
                    body="{{ $copy['shop']['empty_body'] }}"
                    class="reveal-item col-span-full py-16"
                />
            @endforelse
        </div>
    </div>
</section>
