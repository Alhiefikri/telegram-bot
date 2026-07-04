<section id="products" class="relative border-y-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] py-20">
    <div class="pointer-events-none absolute inset-0 bg-grid-pattern opacity-70"></div>
    <div class="relative z-10 mx-auto max-w-6xl px-5 sm:px-6">
    <div class="mx-auto mb-12 max-w-2xl text-center">
        <span class="neo-chip inline-flex px-4 py-2 text-[11px] font-black uppercase tracking-[0.18em]">{{ $copy['catalog']['eyebrow'] }}</span>
        <h2 class="mt-5 font-display text-4xl font-black tracking-tight text-[var(--sf-ink)] sm:text-5xl">{{ $copy['catalog']['title'] }}</h2>
        <p class="mx-auto mt-3 max-w-md text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">{{ $copy['catalog']['body'] }}</p>
        <p class="mt-4 text-xs font-black uppercase tracking-[0.18em] text-[var(--sf-muted)]">{{ $catalogProducts->count() }} produk publik</p>
    </div>

    <div class="relative mx-auto mb-10 max-w-xl">
        <form action="{{ url('/') }}#products" method="GET" class="relative">
            <input type="text" name="q" value="{{ $search }}" placeholder="{{ $copy['catalog']['search_placeholder'] }}" class="neo-input h-14 w-full pl-12 pr-16 text-sm font-bold text-[var(--sf-ink)] placeholder-[var(--sf-muted)] transition-all focus:outline-none">
            <div class="absolute left-4 top-1/2 -translate-y-1/2 text-[var(--sf-ink)]">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
            </div>
            @if($search !== '')
                <a href="{{ url('/') }}#products" class="absolute right-4 top-1/2 -translate-y-1/2 text-xs font-black text-[var(--sf-accent)] transition-colors hover:text-[var(--sf-ink)]">Clear</a>
            @endif
        </form>
    </div>

    @if ($categories->isNotEmpty())
        @if ($categoriesWithProducts->isNotEmpty())
            <div class="mb-12 flex flex-wrap justify-center gap-3" aria-label="Filter kategori produk">
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
        <div class="neo-panel mx-auto mb-10 max-w-md border-dashed bg-[var(--sf-surface)] p-6 text-center">
            <p class="font-display font-black text-[var(--sf-ink)]">{{ $copy['catalog']['empty_category_title'] }}</p>
            <p class="mt-2 text-xs font-semibold leading-5 text-[var(--sf-muted)]">{{ $copy['catalog']['empty_category_body'] }}</p>
        </div>
    @endif

    @if ($popularProducts->isEmpty())
        <p class="mb-8 text-center text-xs font-bold text-[var(--sf-muted)]">{{ $copy['hero']['popular_empty'] }}</p>
    @endif

    <div class="neo-zigzag mx-auto mb-10 max-w-lg"></div>

    <div id="storefront-product-grid" @class([
        'storefront-popular-card grid gap-6',
        'mx-auto max-w-xs grid-cols-1' => $catalogProducts->count() === 1,
        'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4' => $catalogProducts->count() !== 1,
    ])>
        @forelse ($catalogProducts as $product)
            @include('storefront.components.product-card', [
                'product' => $product,
                'isPopular' => $popularProducts->contains('id', $product->id),
            ])
        @empty
            <div class="neo-panel reveal-item col-span-full mx-auto max-w-md bg-[var(--sf-surface)] p-8 py-16 text-center">
                <svg class="mx-auto h-10 w-10 text-[var(--sf-ink)]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
                <h3 class="mt-4 font-display text-sm font-black text-[var(--sf-ink)]">{{ $copy['catalog']['empty_title'] }}</h3>
                <p class="mt-2 text-xs font-semibold leading-relaxed text-[var(--sf-muted)]">{{ $copy['catalog']['empty_body'] }}</p>
            </div>
        @endforelse
    </div>
    </div>
</section>
