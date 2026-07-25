<section id="products" class="relative border-y-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] py-20">
    <div class="pointer-events-none absolute inset-0 bg-grid-pattern opacity-70"></div>
    <div class="relative z-10 mx-auto max-w-6xl px-5 sm:px-6">
        <div class="mx-auto mb-12 max-w-2xl text-center">
            <div class="mx-auto mb-5 inline-flex items-center gap-2 rounded-full border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] px-4 py-2 text-[11px] font-black text-[var(--sf-ink)] shadow-[4px_4px_0_var(--sf-shadow)]">
                <span>✦</span>
                <span>Katalog aktif · {{ $catalogPage->productCount() }} produk publik</span>
            </div>
            <h2 class="font-display text-4xl font-black tracking-tight text-[var(--sf-ink)] sm:text-5xl">{{ $copy['catalog']['title'] }}</h2>
            <p class="mx-auto mt-3 max-w-md text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">{{ $copy['catalog']['body'] }}</p>
        </div>

        <div class="relative mx-auto mb-10 max-w-xl">
            <form action="{{ $catalogPage->searchAction }}" method="GET" class="relative">
                <label for="storefront-search" class="sr-only">Cari produk</label>
                <input id="storefront-search" type="text" name="q" value="{{ $catalogPage->search }}" maxlength="{{ $catalogPage->searchMaxLength }}" placeholder="{{ $copy['catalog']['search_placeholder'] }}" class="neo-input h-14 w-full pl-12 pr-16 text-sm font-bold text-[var(--sf-ink)] placeholder-[var(--sf-muted)] transition-all focus:outline-none">
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

    @if ($catalogPage->hasPublicCategories)
        @if ($catalogPage->categoryFilters->isNotEmpty())
            <div class="mb-12 flex flex-wrap justify-center gap-3" aria-label="Filter kategori produk">
                <button type="button" class="category-btn hover-rubber-band neo-filter-active rounded-full border-[3px] px-4 py-2 text-xs font-black transition-all duration-300 focus:outline-none" data-filter="all" aria-pressed="true" aria-controls="storefront-product-grid">
                    Semua
                </button>
                @foreach ($catalogPage->categoryFilters as $category)
                    <button type="button" class="category-btn hover-rubber-band neo-filter-inactive rounded-full border-[3px] px-4 py-2 text-xs font-black transition-all duration-300 hover:-translate-y-0.5 hover:shadow-[4px_4px_0_var(--sf-shadow)] focus:outline-none" data-filter="{{ $category->id }}" aria-pressed="false" aria-controls="storefront-product-grid">
                        {{ $category->name }}
                    </button>
                @endforeach
            </div>
        @endif
    @else
        <x-storefront.empty-state
            :title="$catalogPage->emptyCategoryTitle"
            :body="$catalogPage->emptyCategoryBody"
            class="mb-10 p-6"
        />
    @endif

    @if ($catalogPage->popularProducts->isEmpty() && $catalogPage->popularEmptyMessage)
        <p class="mb-8 text-center text-xs font-bold text-[var(--sf-muted)]">{{ $catalogPage->popularEmptyMessage }}</p>
    @endif

    <div class="neo-zigzag mx-auto mb-10 max-w-lg"></div>

    <div id="storefront-product-grid" @class([
        'storefront-popular-card storefront-product-grid',
        'mx-auto max-w-xs grid-cols-1' => $catalogPage->productCount() === 1,
        'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4' => $catalogPage->productCount() !== 1,
    ])>
        @forelse ($catalogPage->products as $catalogProduct)
            @include('storefront.components.product-card', [
                'catalogProduct' => $catalogProduct,
            ])
        @empty
            <x-storefront.empty-state
                :title="$catalogPage->emptyProductsTitle"
                :body="$catalogPage->emptyProductsBody"
                class="reveal-item col-span-full py-16"
            />
        @endforelse
    </div>
    </div>
</section>
