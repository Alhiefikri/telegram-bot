<section id="products" class="relative border-y border-[var(--sf-border)] bg-[var(--sf-surface)] py-10 sm:py-12">
    <div class="pointer-events-none absolute inset-0 bg-grid-pattern opacity-70"></div>
    <div class="relative z-10 mx-auto max-w-7xl px-5 sm:px-6">
        <div class="reveal-item mx-auto mb-7 max-w-2xl text-center">
            <div class="mx-auto mb-3 inline-flex min-h-11 items-center gap-2 rounded-full border border-[var(--sf-border)] bg-[var(--sf-yellow)] px-4 py-2 text-[11px] font-black text-[var(--sf-ink)] shadow-[0_4px_12px_rgb(18_35_63_/_0.08)]">
                <span class="neo-spin-slow inline-block" aria-hidden="true">✦</span>
                <span>Katalog aktif · {{ $catalogPage->productCount() }} item katalog</span>
            </div>
            <h2 class="font-display text-3xl font-black tracking-tight text-[var(--sf-ink)] sm:text-4xl">{{ $copy['catalog']['title'] }}</h2>
            <p class="mx-auto mt-2 max-w-md text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">{{ $copy['catalog']['body'] }}</p>
        </div>

        <div class="relative mx-auto mb-6 max-w-xl">
            <form action="{{ $catalogPage->searchAction }}" method="GET" class="relative">
                <label for="storefront-search" class="sr-only">Cari produk</label>
                <input id="storefront-search" type="text" name="q" value="{{ $catalogPage->search }}" maxlength="{{ $catalogPage->searchMaxLength }}" placeholder="{{ $copy['catalog']['search_placeholder'] }}" class="neo-input h-14 w-full pl-12 pr-16 text-sm font-bold text-[var(--sf-ink)] placeholder-[var(--sf-muted)] transition-all focus:outline-none">
                <div class="absolute left-4 top-1/2 -translate-y-1/2 text-[var(--sf-ink)]">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>
                @if($catalogPage->search !== '')
                    <a href="{{ $catalogPage->clearSearchUrl }}" class="absolute right-2 top-1/2 inline-flex min-h-11 min-w-11 -translate-y-1/2 items-center justify-center text-xs font-black text-[var(--sf-accent)] transition-colors hover:text-[var(--sf-ink)]">Clear</a>
                @endif
            </form>
        </div>

    @if ($catalogPage->hasPublicCategories)
        @if ($catalogPage->isHome())
            <section id="kategori" class="reveal-item mb-10 rounded-2xl border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4 sm:p-6" aria-labelledby="kategori-title">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.16em] text-[var(--sf-accent)]">Jelajahi toko</p>
                        <h3 id="kategori-title" class="mt-1 font-display text-2xl font-black tracking-tight text-[var(--sf-ink)]">Belanja berdasarkan kategori</h3>
                        <p class="mt-1 max-w-xl text-sm font-semibold leading-6 text-[var(--sf-muted)]">Pilih kebutuhan Anda, lalu lihat produk yang siap dibeli di kategori tersebut.</p>
                    </div>
                    <a href="{{ $catalogPage->allCategoryUrl }}" class="inline-flex min-h-11 shrink-0 items-center rounded-lg border border-[var(--sf-border)] bg-white px-4 py-2 text-xs font-black text-[var(--sf-ink)] transition-colors hover:bg-[var(--sf-yellow)]">Lihat semua produk</a>
                </div>
                <nav class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3" aria-label="Filter kategori produk">
                    @foreach ($catalogPage->categoryFilters as $category)
                        <a href="{{ $category->url }}" class="category-btn group flex min-h-20 items-center gap-3 rounded-xl border border-[var(--sf-border)] bg-white p-3 transition-all hover:-translate-y-0.5 hover:bg-[var(--sf-yellow)] hover:shadow-[0_8px_18px_rgb(18_35_63_/_0.1)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)]" data-filter="{{ $category->id }}" @if ($category->isActive) aria-current="page" @endif aria-controls="storefront-product-grid">
                            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-lg bg-[var(--sf-accent)] font-display text-lg font-black text-white" aria-hidden="true">{{ mb_strtoupper(mb_substr($category->name, 0, 1)) }}</span>
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-black text-[var(--sf-ink)]">{{ $category->name }}</span>
                                <span class="mt-1 block text-xs font-bold text-[var(--sf-accent)]">Jelajahi produk <span aria-hidden="true">→</span></span>
                            </span>
                        </a>
                    @endforeach
                </nav>
            </section>
        @else
            <nav class="mb-7 flex flex-wrap justify-center gap-2" aria-label="Filter kategori produk">
                <a href="{{ $catalogPage->allCategoryUrl }}" @class([
                    'category-btn hover-rubber-band inline-flex min-h-11 items-center justify-center rounded-full border px-4 py-2 text-xs font-black text-center transition-all duration-300 focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)]',
                    'neo-filter-active' => $catalogPage->selectedCategory === null,
                    'neo-filter-inactive hover:-translate-y-0.5 hover:shadow-[4px_4px_0_var(--sf-shadow)]' => $catalogPage->selectedCategory !== null,
                ]) data-filter="all" @if ($catalogPage->selectedCategory === null) aria-current="page" @endif aria-controls="storefront-product-grid">Semua</a>
                @foreach ($catalogPage->categoryFilters as $category)
                    <a href="{{ $category->url }}" @class([
                        'category-btn hover-rubber-band inline-flex min-h-11 items-center justify-center rounded-full border px-4 py-2 text-xs font-black text-center transition-all duration-300 focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)]',
                        'neo-filter-active' => $category->isActive,
                        'neo-filter-inactive hover:-translate-y-0.5 hover:shadow-[4px_4px_0_var(--sf-shadow)]' => ! $category->isActive,
                    ]) data-filter="{{ $category->id }}" @if ($category->isActive) aria-current="page" @endif aria-controls="storefront-product-grid">{{ $category->name }}</a>
                @endforeach
            </nav>
        @endif
    @else
        @if ($catalogPage->entries()->isEmpty())
            <x-storefront.empty-state
                :title="$catalogPage->emptyCategoryTitle"
                :body="$catalogPage->emptyCategoryBody"
                class="mb-8 p-6"
            />
        @endif
    @endif
    @if ($catalogPage->popularProducts->isNotEmpty())
        <section id="trending" class="storefront-trending-panel reveal-item mx-auto mb-10 max-w-7xl overflow-hidden rounded-2xl border border-[var(--sf-trend-border)] bg-[var(--sf-trend-panel)] text-[var(--sf-trend-text)]" aria-labelledby="trending-title" data-storefront-carousel>
            <div class="storefront-trending-header flex items-center justify-between gap-3 border-b border-[var(--sf-trend-border)] px-3 py-2.5 sm:px-4">
                <div class="flex min-w-0 items-center gap-2.5">
                    <span class="storefront-trending-header__icon grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-[var(--sf-trend-accent)] text-[var(--sf-trend-panel)]" aria-hidden="true">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M4 17 9 12l3 3 7-8"/><path d="M15 7h4v4"/></svg>
                    </span>
                    <div class="min-w-0">
                        <p class="truncate text-[9px] font-bold uppercase tracking-[0.14em] text-[var(--sf-trend-muted)]">Paling laku hari ini</p>
                        <h3 id="trending-title" class="truncate font-display text-sm font-black tracking-tight text-[var(--sf-trend-text)] sm:text-base">Best sellers today</h3>
                    </div>
                </div>
                <div class="carousel-controls" data-carousel-controls hidden>
                    <button type="button" data-carousel-prev aria-label="Produk terlaris sebelumnya">←</button>
                    <button type="button" data-carousel-next aria-label="Produk terlaris berikutnya">→</button>
                    <button type="button" data-carousel-pause aria-label="Jeda pergantian otomatis" aria-pressed="false"><svg data-icon="pause" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M9.5 5v14" /><path d="M14.5 5v14" /></svg><svg data-icon="play" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8.5 5.5 18 12l-9.5 6.5z" /></svg></button>
                </div>
            </div>
            <div class="storefront-trending-grid" data-carousel-track role="list" aria-label="Produk trending">
                @foreach ($catalogPage->popularProducts as $catalogProduct)
                    <div role="listitem" data-carousel-slide>
                        @include('storefront.components.trending-card', [
                            'catalogProduct' => $catalogProduct,
                            'rank' => $loop->iteration,
                        ])
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <div class="mb-3 flex items-end justify-between gap-3">
        <div>
            <p class="text-xs font-black uppercase tracking-[0.16em] text-[var(--sf-muted)]">Katalog aktif</p>
            <h3 class="mt-1 font-display text-2xl font-black tracking-tight text-[var(--sf-ink)]">Tersedia sekarang</h3>
        </div>
        <span class="text-xs font-bold text-[var(--sf-muted)]">{{ $catalogPage->productCount() }} item</span>
    </div>
    <div id="storefront-product-grid" @class([
        'storefront-catalog-card storefront-product-grid',
        'storefront-product-grid--single mx-auto max-w-xs' => $catalogPage->productCount() === 1,
    ])>
        @forelse ($catalogPage->entries() as $entry)
            @if ($entry->isFamily())
                @include('storefront.components.family-card', ['entry' => $entry])
            @else
                @include('storefront.components.product-card', [
                    'catalogProduct' => $entry->product,
                ])
            @endif
        @empty
            <x-storefront.empty-state
                :title="$catalogPage->emptyProductsTitle"
                :body="$catalogPage->emptyProductsBody"
                class="reveal-item col-span-full py-12"
            />
        @endforelse
    </div>
    </div>
</section>
