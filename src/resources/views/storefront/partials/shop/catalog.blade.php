<section id="products" class="relative border-y-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] py-12 sm:py-14">
    <div class="pointer-events-none absolute inset-0 bg-grid-pattern opacity-70"></div>

    <div class="relative z-10 mx-auto max-w-7xl px-4 sm:px-6">
        <div class="mb-8 grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,25rem)] lg:items-end">
            <div>
                <div class="mb-4 inline-flex items-center gap-2 rounded-full border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] px-4 py-2 text-[11px] font-black text-[var(--sf-ink)] shadow-[4px_4px_0_var(--sf-shadow)]">
                    <span>Katalog aktif · {{ $catalogPage->totalProductCount() }} produk publik</span>
                </div>
                <h2 class="text-balance font-display text-3xl font-black tracking-tight text-[var(--sf-ink)] sm:text-4xl">
                    Pilih akun, cek stok, lanjut checkout.
                </h2>
                <p class="mt-3 max-w-2xl text-sm font-semibold leading-7 text-[var(--sf-muted)]">
                    Produk aktif dari Admin Dashboard muncul otomatis di sini. Filter kategori atau cari produk yang siap dibeli.
                </p>
            </div>

            <form action="{{ $catalogPage->searchAction }}" method="GET" class="relative">
                <label for="shop-search" class="sr-only">Cari produk</label>
                <input id="shop-search" type="text" name="q" value="{{ $catalogPage->search }}" maxlength="{{ $catalogPage->searchMaxLength }}" placeholder="{{ $copy['catalog']['search_placeholder'] }}" class="neo-input h-14 w-full pl-12 pr-16 text-sm font-bold text-[var(--sf-ink)] placeholder-[var(--sf-muted)] transition-all focus:outline-none">
                @if ($catalogPage->selectedCategory)
                    <input type="hidden" name="category" value="{{ $catalogPage->selectedCategory }}">
                @endif
                @if ($catalogPage->selectedSort !== \App\Storefront\StorefrontCatalogQuery::DEFAULT_SORT)
                    <input type="hidden" name="sort" value="{{ $catalogPage->selectedSort }}">
                @endif
                <div class="absolute left-4 top-1/2 -translate-y-1/2 text-[var(--sf-ink)]">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>
                @if($catalogPage->search !== '')
                    <a href="{{ $catalogPage->clearSearchUrl }}" class="absolute right-4 top-1/2 -translate-y-1/2 text-xs font-black text-[var(--sf-accent)] transition-colors hover:text-[var(--sf-ink)]">Clear</a>
                @endif
            </form>
        </div>

        <div class="mb-7 flex flex-col gap-4 border-y-[3px] border-dashed border-[var(--sf-border)] py-5 lg:flex-row lg:items-center lg:justify-between">
            @if ($catalogPage->hasPublicCategories)
                <nav class="flex flex-wrap gap-3" aria-label="Filter kategori produk">
                    <a href="{{ $catalogPage->allCategoryUrl }}" @class([
                        'category-btn rounded-full border-[3px] px-4 py-2 text-xs font-black transition-all duration-300 focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)]',
                        'neo-filter-active' => $catalogPage->selectedCategory === null,
                        'neo-filter-inactive hover:-translate-y-0.5 hover:shadow-[4px_4px_0_var(--sf-shadow)]' => $catalogPage->selectedCategory !== null,
                    ]) data-filter="all" @if ($catalogPage->selectedCategory === null) aria-current="page" @endif aria-controls="storefront-product-grid">Semua</a>
                    @foreach ($catalogPage->categoryFilters as $category)
                        <a href="{{ $category->url }}" @class([
                            'category-btn rounded-full border-[3px] px-4 py-2 text-xs font-black transition-all duration-300 focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)]',
                            'neo-filter-active' => $category->isActive,
                            'neo-filter-inactive hover:-translate-y-0.5 hover:shadow-[4px_4px_0_var(--sf-shadow)]' => ! $category->isActive,
                        ]) data-filter="{{ $category->id }}" @if ($category->isActive) aria-current="page" @endif aria-controls="storefront-product-grid">{{ $category->name }}</a>
                    @endforeach
                </nav>
            @else
                <x-storefront.empty-state
                    :title="$catalogPage->emptyCategoryTitle"
                    :body="$catalogPage->emptyCategoryBody"
                    class="p-6"
                />
            @endif

            <form action="{{ $catalogPage->searchAction }}" method="GET" class="flex shrink-0 items-center gap-2">
                @if ($catalogPage->search !== '')
                    <input type="hidden" name="q" value="{{ $catalogPage->search }}">
                @endif
                @if ($catalogPage->selectedCategory)
                    <input type="hidden" name="category" value="{{ $catalogPage->selectedCategory }}">
                @endif
                <label for="shop-sort" class="text-xs font-black text-[var(--sf-ink)]">Urutkan</label>
                <select id="shop-sort" name="sort" class="neo-input h-11 min-w-40 px-3 text-xs font-black text-[var(--sf-ink)] focus:outline-none">
                    <option value="recommended" @selected($catalogPage->selectedSort === 'recommended')>Direkomendasikan</option>
                    <option value="newest" @selected($catalogPage->selectedSort === 'newest')>Terbaru</option>
                    <option value="price_asc" @selected($catalogPage->selectedSort === 'price_asc')>Harga terendah</option>
                    <option value="price_desc" @selected($catalogPage->selectedSort === 'price_desc')>Harga tertinggi</option>
                </select>
                <button type="submit" class="neo-button inline-flex h-11 items-center justify-center bg-[var(--sf-ink)] px-4 text-xs font-black text-white">Terapkan</button>
            </form>
        </div>

        <div id="storefront-product-grid" @class([
            'storefront-popular-card storefront-product-grid p-0 sm:p-1',
            'storefront-product-grid--single mx-auto max-w-xs' => $catalogPage->productCount() === 1,
        ])>
            @forelse ($catalogPage->products as $catalogProduct)
                @include('storefront.components.product-card', [
                    'catalogProduct' => $catalogProduct,
                ])
            @empty
                <x-storefront.empty-state
                    title="{{ $catalogPage->emptyProductsTitle }}"
                    body="{{ $catalogPage->emptyProductsBody }}"
                    class="reveal-item col-span-full py-16"
                />
            @endforelse
        </div>

        @include('storefront.partials.catalog-pagination')
    </div>
</section>
