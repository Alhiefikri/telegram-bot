@php
    $brand = 'Warung AI';
    $copy = config('storefront');
    $pageTitle = str_replace(':brand', $brand, $copy['meta']['title']);
    $pageDescription = $copy['meta']['description'];
    $canonicalUrl = url('/');
    $telegramUsername = config('services.telegram.admin_username');
    $supportHref = config('services.telegram.admin_contact_url') ?: ($telegramUsername ? 'https://t.me/'.ltrim($telegramUsername, '@') : null);
    $supportDestination = $supportHref ?: '#support';
    $stockAvailable = fn ($product) => max(0, (int) $product->stock - (int) $product->reserved_stock);
    $idr = fn ($amount) => 'Rp'.number_format((int) $amount, 0, ',', '.');
    $categoryAnchor = fn ($category) => 'category-'.($category->slug ?: $category->id);
    $featuredStock = $featuredProduct ? $stockAvailable($featuredProduct) : 0;
    $featuredImageUrl = $featuredProduct && $featuredProduct->image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($featuredProduct->image_path) : null;
    $categoriesWithProducts = $categories->filter(fn ($category) => $products->contains('category_id', $category->id));
    $featuredCategoryDestination = $featuredProduct && $featuredProduct->category && $categoriesWithProducts->contains('id', $featuredProduct->category_id) ? '#'.$categoryAnchor($featuredProduct->category) : '#products';
    $productsWithoutVisibleCategory = $products->reject(fn ($product) => $product->category_id && $categories->contains('id', $product->category_id));
    $catalogProducts = $products;
    $popularProducts = $products->filter(fn ($product) => $stockAvailable($product) > 0)->take(3);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $pageDescription }}">
    <link rel="canonical" href="{{ $canonicalUrl }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $brand }}">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $pageDescription }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    <meta property="og:image" content="https://warungai.web.id/assets/mainlogo.png">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $pageTitle }}">
    <meta name="twitter:description" content="{{ $pageDescription }}">
    @fonts
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="overflow-x-clip bg-[var(--sf-paper)] text-[var(--sf-ink)] antialiased selection:bg-[var(--sf-orange)] selection:text-white">
    <div class="storefront-shell storefront-grid relative min-h-screen overflow-x-clip">
        <span aria-hidden="true" class="storefront-wave motion-safe:animate-storefront-float motion-reduce:animate-none left-[-5rem] top-28 h-56 w-56 sm:h-72 sm:w-72"></span>
        <span aria-hidden="true" class="storefront-wave storefront-wave-soft motion-safe:animate-storefront-float motion-reduce:animate-none right-[-8rem] top-[34rem] h-64 w-64 sm:h-96 sm:w-96"></span>

        <header class="sticky top-0 z-50 border-b border-[var(--sf-border)] bg-white/[0.92] backdrop-blur-xl">
            <div class="mx-auto flex min-h-20 w-full max-w-7xl items-center justify-between gap-4 px-5 sm:px-8 lg:px-10">
                <a href="{{ $canonicalUrl }}" class="group inline-flex min-h-12 items-center gap-3 rounded-full focus:outline-none focus:ring-2 focus:ring-[var(--sf-orange-deep)] focus:ring-offset-2 focus:ring-offset-white">
                    <span class="flex h-12 w-12 items-center justify-center overflow-hidden rounded-2xl border border-[var(--sf-border)] bg-white shadow-sm shadow-orange-200/50">
                        <img src="https://warungai.web.id/assets/mainlogo.png" alt="Warung AI Logo" width="160" height="160" class="h-20 w-20 max-w-none object-contain" loading="eager" fetchpriority="high" onerror="this.classList.add('hidden');this.nextElementSibling.classList.remove('hidden')">
                        <span class="hidden text-sm font-black text-[var(--sf-orange)]">AI</span>
                    </span>
                    <span class="leading-tight">
                        <span class="block text-base font-black tracking-tight text-[var(--sf-ink)]">Warung AI</span>
                        <span class="block text-xs font-bold text-[var(--sf-muted)]">Akun premium siap pakai</span>
                    </span>
                </a>

                <nav aria-label="Navigasi utama" class="hidden items-center gap-1 rounded-full border border-[var(--sf-border)] bg-white px-2 py-1 text-sm font-black text-[var(--sf-muted)] shadow-sm shadow-orange-200/40 lg:flex">
                    @foreach ($copy['nav'] as $item)
                        <a href="{{ $item['href'] }}" class="inline-flex min-h-10 items-center rounded-full px-4 transition duration-200 hover:bg-orange-50 hover:text-[var(--sf-orange-deep)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-orange-deep)]">{{ $item['label'] }}</a>
                    @endforeach
                </nav>

                <a href="{{ url('/admin/login') }}" class="inline-flex min-h-11 items-center justify-center rounded-full border border-[var(--sf-orange)] bg-white px-5 text-sm font-black text-[var(--sf-orange-deep)] shadow-sm shadow-orange-200/60 transition duration-200 motion-safe:hover:-translate-y-0.5 hover:bg-orange-50 focus:outline-none focus:ring-2 focus:ring-[var(--sf-orange-deep)] focus:ring-offset-2 focus:ring-offset-white">
                    Login
                </a>
            </div>
        </header>

        <main class="relative z-10">
            <section class="mx-auto grid w-full max-w-7xl items-center gap-10 px-5 pb-14 pt-12 sm:px-8 lg:min-h-[calc(100dvh-5rem)] lg:grid-cols-[0.95fr_1.05fr] lg:px-10 lg:pb-20 lg:pt-16">
                <div class="max-w-3xl motion-safe:animate-storefront-reveal motion-reduce:animate-none">
                    <p class="inline-flex rounded-full border border-orange-200 bg-white px-4 py-2 text-xs font-black uppercase tracking-[0.18em] text-[var(--sf-orange-deep)] shadow-sm shadow-orange-200/50">{{ $copy['hero']['eyebrow'] }}</p>
                    <h1 class="mt-6 max-w-4xl text-balance text-5xl font-black leading-[0.92] tracking-[-0.055em] text-[var(--sf-ink)] sm:text-6xl lg:text-7xl">
                        {{ $copy['hero']['headline'] }}
                    </h1>
                    <p class="mt-6 max-w-2xl text-pretty text-lg leading-8 text-[var(--sf-muted)] sm:text-xl">{{ $copy['hero']['body'] }}</p>
                    <div class="mt-9 flex flex-col gap-3 sm:flex-row">
                        <a href="#products" class="group inline-flex min-h-12 items-center justify-center gap-2 rounded-full bg-[var(--sf-orange)] px-7 text-base font-black text-white shadow-lg shadow-orange-300/50 transition duration-200 motion-safe:hover:-translate-y-0.5 hover:bg-[var(--sf-orange-deep)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-orange-deep)] focus:ring-offset-2 focus:ring-offset-[var(--sf-paper)]">
                            {{ $copy['hero']['primary_cta'] }}
                            <span aria-hidden="true" class="transition duration-200 motion-safe:group-hover:translate-x-1">-&gt;</span>
                        </a>
                        <a href="#cara-beli" class="inline-flex min-h-12 items-center justify-center rounded-full border border-[var(--sf-border)] bg-white px-7 text-base font-black text-[var(--sf-ink)] shadow-sm shadow-orange-200/50 transition duration-200 motion-safe:hover:-translate-y-0.5 hover:border-orange-300 hover:text-[var(--sf-orange-deep)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-orange-deep)] focus:ring-offset-2 focus:ring-offset-[var(--sf-paper)]">{{ $copy['hero']['secondary_cta'] }}</a>
                    </div>
                </div>

                <div class="storefront-hero-card motion-safe:animate-storefront-float motion-reduce:animate-none rounded-[2rem] border border-[var(--sf-border)] bg-white p-4 shadow-2xl shadow-orange-200/50">
                    <div class="rounded-[1.5rem] bg-[var(--sf-surface-warm)] p-4 sm:p-5">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-xs font-black uppercase tracking-[0.2em] text-[var(--sf-orange-deep)]">{{ $copy['hero']['popular_label'] }}</p>
                                <h2 class="mt-1 text-2xl font-black tracking-tight text-[var(--sf-ink)]">Akun favorit pembeli</h2>
                            </div>
                            <a href="#products" class="hidden rounded-full bg-white px-4 py-2 text-sm font-black text-[var(--sf-orange-deep)] shadow-sm transition motion-safe:hover:-translate-y-0.5 sm:inline-flex">Lihat semua</a>
                        </div>

                        <div class="mt-5 space-y-3">
                            @forelse ($popularProducts as $product)
                                @php
                                    $popularStock = $stockAvailable($product);
                                    $popularImageUrl = $product->image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($product->image_path) : null;
                                @endphp
                                <a href="#products" class="storefront-popular-card flex min-h-24 items-center gap-4 rounded-3xl border border-orange-100 bg-white p-3 text-left shadow-sm shadow-orange-200/50 transition duration-200 motion-safe:hover:-translate-y-0.5 hover:border-orange-300 focus:outline-none focus:ring-2 focus:ring-[var(--sf-orange-deep)]">
                                    <span class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-orange-50 text-center text-[0.65rem] font-black uppercase tracking-[0.12em] text-[var(--sf-orange-deep)]">
                                        @if ($popularImageUrl)
                                            <img src="{{ $popularImageUrl }}" alt="{{ $product->name }}" width="128" height="128" class="h-full w-full object-cover" loading="lazy">
                                        @else
                                            AI
                                        @endif
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-black text-[var(--sf-ink)]">{{ $product->name }}</span>
                                        <span class="mt-1 block text-sm font-black text-[var(--sf-orange-deep)]">{{ $idr($product->price_amount) }}</span>
                                        <span class="mt-1 inline-flex rounded-full bg-green-50 px-3 py-1 text-xs font-black text-[var(--sf-success)]">Sisa {{ $popularStock }}</span>
                                    </span>
                                </a>
                            @empty
                                <div class="storefront-popular-card rounded-3xl border border-dashed border-orange-200 bg-white p-5">
                                    <p class="font-black text-[var(--sf-ink)]">{{ $copy['catalog']['empty_category_title'] }}</p>
                                    <p class="mt-2 text-sm leading-6 text-[var(--sf-muted)]">{{ $copy['catalog']['empty_category_body'] }}</p>
                                    <p class="mt-4 font-black text-[var(--sf-orange-deep)]">{{ $copy['hero']['popular_empty'] }}</p>
                                </div>
                            @endforelse
                        </div>

                        @if ($categories->isNotEmpty())
                            <div class="mt-5 border-t border-orange-100 pt-5">
                                <p class="text-xs font-black uppercase tracking-[0.18em] text-[var(--sf-orange-deep)]">Kategori tersedia</p>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @foreach ($categories as $category)
                                        <a href="{{ $products->contains('category_id', $category->id) ? '#'.$categoryAnchor($category) : '#products' }}" class="rounded-full border border-orange-100 bg-white px-3 py-2 text-sm font-black text-[var(--sf-muted)] transition hover:border-orange-300 hover:text-[var(--sf-orange-deep)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-orange-deep)]">
                                            {{ $category->name }}
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @elseif ($popularProducts->isNotEmpty())
                            <div class="mt-5 rounded-3xl border border-dashed border-orange-200 bg-white p-5">
                                <p class="font-black text-[var(--sf-ink)]">{{ $copy['catalog']['empty_category_title'] }}</p>
                                <p class="mt-2 text-sm leading-6 text-[var(--sf-muted)]">{{ $copy['catalog']['empty_category_body'] }}</p>
                            </div>
                        @endif
                    </div>
                </div>
            </section>

            <section aria-label="Kepercayaan toko" class="px-5 pb-12 sm:px-8 lg:px-10">
                <div class="mx-auto grid max-w-7xl gap-3 rounded-[2rem] border border-[var(--sf-border)] bg-white p-3 shadow-sm shadow-orange-200/50 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($copy['trust_indicators'] as $indicator)
                        <div class="rounded-[1.35rem] bg-orange-50/70 p-5">
                            <h2 class="text-base font-black text-[var(--sf-ink)]">{{ $indicator['title'] }}</h2>
                            <p class="mt-2 text-sm leading-6 text-[var(--sf-muted)]">{{ $indicator['body'] }}</p>
                        </div>
                    @endforeach
                </div>
            </section>

            <section id="featured-offer" class="px-5 py-12 sm:px-8 lg:px-10 lg:py-16">
                <div class="mx-auto max-w-7xl overflow-hidden rounded-[2rem] border border-[var(--sf-border)] bg-white shadow-2xl shadow-orange-200/50">
                    @if ($featuredProduct)
                        <div class="grid lg:grid-cols-[0.9fr_1.1fr]">
                            <div class="bg-[var(--sf-surface-warm)] p-5 sm:p-8">
                                @if ($featuredImageUrl)
                                    <img src="{{ $featuredImageUrl }}" alt="{{ $featuredProduct->name }}" width="720" height="540" class="aspect-[4/3] w-full rounded-[1.5rem] object-cover shadow-xl shadow-orange-200/60" loading="eager" fetchpriority="high">
                                @else
                                    <div class="flex aspect-[4/3] w-full items-center justify-center rounded-[1.5rem] bg-white p-6 text-center text-sm font-black uppercase tracking-[0.18em] text-[var(--sf-orange-deep)] shadow-xl shadow-orange-200/60">Gambar promo menyusul</div>
                                @endif
                            </div>
                            <div class="flex flex-col justify-center p-6 sm:p-8 lg:p-10">
                                <p class="inline-flex w-fit rounded-full bg-orange-50 px-4 py-2 text-xs font-black uppercase tracking-[0.18em] text-[var(--sf-orange-deep)]">{{ $copy['featured']['eyebrow'] }}</p>
                                @if ($featuredProduct->category)
                                    <a href="{{ $featuredCategoryDestination }}" class="mt-5 inline-flex min-h-11 w-fit items-center rounded-full border border-orange-200 px-4 text-sm font-black text-[var(--sf-orange-deep)] hover:border-orange-300 focus:outline-none focus:ring-2 focus:ring-[var(--sf-orange-deep)]">Kategori {{ $featuredProduct->category->name }}</a>
                                @endif
                                <h2 class="mt-5 text-3xl font-black leading-tight tracking-[-0.04em] text-[var(--sf-ink)] sm:text-5xl">{{ $featuredProduct->name }}</h2>
                                <p class="mt-4 max-w-2xl leading-7 text-[var(--sf-muted)]">{{ $featuredProduct->description }}</p>
                                <div class="mt-6 flex flex-wrap items-end gap-3">
                                    <span class="text-4xl font-black text-[var(--sf-orange-deep)]">{{ $idr($featuredProduct->price_amount) }}</span>
                                    <span class="rounded-full bg-green-50 px-4 py-2 text-sm font-black text-[var(--sf-success)]">Sisa {{ $featuredStock }}</span>
                                </div>
                                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                                    <a href="{{ $supportDestination }}" class="inline-flex min-h-12 items-center justify-center rounded-full bg-[var(--sf-orange)] px-6 font-black text-white shadow-lg shadow-orange-300/50 transition duration-200 motion-safe:hover:-translate-y-0.5 hover:bg-[var(--sf-orange-deep)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-orange-deep)] focus:ring-offset-2 focus:ring-offset-white">Belanja Sekarang</a>
                                    <a href="#products" class="inline-flex min-h-12 items-center justify-center rounded-full border border-[var(--sf-border)] px-6 font-black text-[var(--sf-ink)] transition duration-200 motion-safe:hover:-translate-y-0.5 hover:border-orange-300 hover:text-[var(--sf-orange-deep)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-orange-deep)]">Lihat katalog</a>
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="p-8 text-center sm:p-12">
                            <p class="text-sm font-black uppercase tracking-[0.18em] text-[var(--sf-orange-deep)]">{{ $copy['featured']['eyebrow'] }}</p>
                            <h2 class="mt-4 text-3xl font-black tracking-tight text-[var(--sf-ink)]">{{ $copy['featured']['empty_title'] }}</h2>
                            <p class="mx-auto mt-4 max-w-2xl leading-7 text-[var(--sf-muted)]">{{ $copy['featured']['empty_body'] }}</p>
                            <a href="{{ $supportDestination }}" class="mt-6 inline-flex min-h-12 items-center justify-center rounded-full bg-[var(--sf-orange)] px-6 font-black text-white hover:bg-[var(--sf-orange-deep)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-orange-deep)]">{{ $copy['featured']['empty_cta'] }}</a>
                        </div>
                    @endif
                </div>
            </section>

            <section id="products" class="px-5 py-14 sm:px-8 lg:px-10 lg:py-20">
                <div class="mx-auto max-w-7xl">
                    <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <p class="text-sm font-black uppercase tracking-[0.18em] text-[var(--sf-orange-deep)]">{{ $copy['catalog']['eyebrow'] }}</p>
                            <h2 class="mt-3 text-4xl font-black tracking-[-0.05em] text-[var(--sf-ink)] sm:text-5xl">{{ $copy['catalog']['title'] }}</h2>
                            <p class="mt-3 max-w-2xl leading-7 text-[var(--sf-muted)]">{{ $copy['catalog']['body'] }}</p>
                        </div>
                        <form action="#products" method="GET" class="relative w-full max-w-md">
                            <label for="q" class="sr-only">Cari produk</label>
                            <input id="q" name="q" value="{{ $search ?? '' }}" placeholder="{{ $copy['catalog']['search_placeholder'] }}" class="min-h-12 w-full rounded-full border border-[var(--sf-border)] bg-white py-3 pl-12 pr-4 text-sm font-semibold text-[var(--sf-ink)] shadow-sm shadow-orange-200/50 outline-none transition focus:border-[var(--sf-orange)] focus:ring-2 focus:ring-orange-200">
                            <span aria-hidden="true" class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-[var(--sf-orange-deep)]">Cari</span>
                        </form>
                    </div>

                    @if ($products->isEmpty())
                        <div class="mt-10 rounded-[2rem] border border-dashed border-orange-200 bg-white p-8 text-center shadow-sm shadow-orange-200/50 sm:p-12">
                            <p class="mx-auto flex h-16 w-16 items-center justify-center rounded-3xl bg-orange-50 text-xs font-black uppercase tracking-[0.14em] text-[var(--sf-orange-deep)]">Produk</p>
                            <h3 class="mt-6 text-2xl font-black text-[var(--sf-ink)]">{{ $copy['catalog']['empty_title'] }}</h3>
                            <p class="mx-auto mt-3 max-w-2xl text-base leading-7 text-[var(--sf-muted)]">{{ $copy['catalog']['empty_body'] }}</p>
                            <a href="{{ $supportDestination }}" class="mt-6 inline-flex min-h-12 items-center justify-center rounded-full bg-[var(--sf-orange)] px-6 font-black text-white hover:bg-[var(--sf-orange-deep)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-orange-deep)]">Hubungi support</a>
                        </div>
                    @else
                        @if ($categoriesWithProducts->isNotEmpty())
                            <nav aria-label="Kategori katalog" class="mt-8 flex flex-wrap gap-3">
                                @foreach ($categoriesWithProducts as $category)
                                    <a href="#{{ $categoryAnchor($category) }}" class="inline-flex min-h-11 items-center rounded-full border border-orange-200 bg-white px-5 text-sm font-black text-[var(--sf-orange-deep)] shadow-sm shadow-orange-200/40 transition motion-safe:hover:-translate-y-0.5 hover:border-orange-300 focus:outline-none focus:ring-2 focus:ring-[var(--sf-orange-deep)]">
                                        {{ $category->name }}
                                    </a>
                                @endforeach
                            </nav>
                            <div class="sr-only" aria-hidden="true">
                                @foreach ($categoriesWithProducts as $category)
                                    <span id="{{ $categoryAnchor($category) }}"></span>
                                @endforeach
                                @if ($productsWithoutVisibleCategory->isNotEmpty())
                                    <span id="category-lainnya"></span>
                                @endif
                            </div>
                        @endif

                        <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            @foreach ($catalogProducts as $product)
                                @php
                                    $cardStock = $stockAvailable($product);
                                    $imageUrl = $product->image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($product->image_path) : null;
                                @endphp
                                <article class="group motion-safe:animate-storefront-reveal motion-reduce:animate-none flex h-full scroll-mt-28 flex-col overflow-hidden rounded-[1.5rem] border {{ $cardStock > 0 ? 'border-[var(--sf-border)] bg-white' : 'border-orange-200 bg-orange-50/80 opacity-90' }} shadow-sm shadow-orange-200/60 transition duration-200 motion-safe:hover:-translate-y-1 hover:border-orange-300 hover:shadow-xl hover:shadow-orange-200/70">
                                    <div class="relative overflow-hidden bg-orange-50">
                                        @if ($imageUrl)
                                            <img src="{{ $imageUrl }}" alt="{{ $product->name }}" width="512" height="512" class="aspect-square w-full object-cover transition duration-300 motion-safe:group-hover:scale-[1.03]" loading="lazy">
                                        @else
                                            <div class="flex aspect-square w-full items-center justify-center bg-[var(--sf-surface-warm)] p-6 text-center text-xs font-black uppercase tracking-[0.18em] text-[var(--sf-orange-deep)]">Gambar produk menyusul</div>
                                        @endif
                                        @if ($product->category)
                                            <span class="absolute left-3 top-3 rounded-full bg-white/95 px-3 py-1 text-xs font-black uppercase tracking-[0.1em] text-[var(--sf-orange-deep)] shadow-sm">{{ $product->category->name }}</span>
                                        @endif
                                        <span class="absolute right-3 top-3 rounded-full {{ $cardStock > 0 ? 'bg-green-50 text-[var(--sf-success)]' : 'bg-orange-100 text-[var(--sf-warning)]' }} px-3 py-1 text-xs font-black shadow-sm">{{ $cardStock > 0 ? 'Sisa '.$cardStock : 'Stok habis' }}</span>
                                    </div>
                                    <div class="flex flex-1 flex-col p-5">
                                        <h3 class="break-words text-lg font-black leading-tight text-[var(--sf-ink)]">{{ $product->name }}</h3>
                                        <p class="mt-3 line-clamp-3 break-words text-sm leading-6 text-[var(--sf-muted)]">{{ $product->description }}</p>
                                        <div class="mt-auto pt-5">
                                            <p class="break-words text-2xl font-black text-[var(--sf-orange-deep)]">{{ $idr($product->price_amount) }}</p>
                                            <a href="{{ $supportDestination }}" class="mt-4 inline-flex min-h-12 w-full items-center justify-center rounded-full {{ $cardStock > 0 ? 'bg-[var(--sf-orange)] text-white hover:bg-[var(--sf-orange-deep)]' : 'border border-orange-200 bg-white text-[var(--sf-warning)] hover:border-orange-300' }} px-4 text-center text-sm font-black transition duration-200 motion-safe:hover:-translate-y-0.5 focus:outline-none focus:ring-2 focus:ring-[var(--sf-orange-deep)]">{{ $cardStock > 0 ? 'Belanja Sekarang' : 'Tanya stok ke support' }}</a>
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>

            <section id="cara-beli" class="px-5 py-14 sm:px-8 lg:px-10 lg:py-20">
                <div class="mx-auto grid max-w-7xl gap-8 rounded-[2rem] border border-[var(--sf-border)] bg-white p-6 shadow-sm shadow-orange-200/50 sm:p-8 lg:grid-cols-[0.82fr_1.18fr] lg:p-10">
                    <div>
                        <p class="text-sm font-black uppercase tracking-[0.18em] text-[var(--sf-orange-deep)]">{{ $copy['payment_flow']['eyebrow'] }}</p>
                        <h2 class="mt-3 text-3xl font-black tracking-[-0.04em] text-[var(--sf-ink)] sm:text-5xl">{{ $copy['payment_flow']['title'] }}</h2>
                        <p class="mt-4 leading-7 text-[var(--sf-muted)]">{{ $copy['payment_flow']['body'] }}</p>
                    </div>
                    <div class="grid gap-4 md:grid-cols-3">
                        @foreach ($copy['payment_flow']['steps'] as $index => $step)
                            <div class="rounded-3xl border border-orange-100 bg-orange-50/70 p-5">
                                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-[var(--sf-orange)] font-black text-white">{{ $index + 1 }}</span>
                                <h3 class="mt-5 font-black text-[var(--sf-ink)]">{{ $step['title'] }}</h3>
                                <p class="mt-3 text-sm leading-6 text-[var(--sf-muted)]">{{ $step['body'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <section id="why-buy" class="px-5 py-14 sm:px-8 lg:px-10 lg:py-20">
                <div class="mx-auto grid max-w-7xl gap-5 lg:grid-cols-[1fr_1fr]">
                    <div class="rounded-[2rem] border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-6 sm:p-8">
                        <p class="text-sm font-black uppercase tracking-[0.18em] text-[var(--sf-orange-deep)]">{{ $copy['delivery']['eyebrow'] }}</p>
                        <h2 class="mt-3 text-3xl font-black tracking-[-0.04em] text-[var(--sf-ink)] sm:text-4xl">Beli akun AI tanpa bingung alur.</h2>
                        <p class="mt-4 leading-7 text-[var(--sf-muted)]">{{ $copy['delivery']['body'] }}</p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-1">
                        @foreach ($copy['benefits'] as $benefit)
                            <div class="rounded-3xl border border-[var(--sf-border)] bg-white p-6 shadow-sm shadow-orange-200/50">
                                <h3 class="font-black text-[var(--sf-ink)]">{{ $benefit['title'] }}</h3>
                                <p class="mt-3 text-sm leading-6 text-[var(--sf-muted)]">{{ $benefit['body'] }}</p>
                            </div>
                        @endforeach
                        <div class="rounded-3xl border border-[var(--sf-border)] bg-white p-6 shadow-sm shadow-orange-200/50 sm:col-span-3 lg:col-span-1">
                            <p class="text-xs font-black uppercase tracking-[0.16em] text-[var(--sf-orange-deep)]">{{ $copy['telegram_support']['eyebrow'] }}</p>
                            <h3 class="mt-3 font-black text-[var(--sf-ink)]">{{ $copy['telegram_support']['title'] }}</h3>
                            <p class="mt-3 text-sm leading-6 text-[var(--sf-muted)]">{{ $copy['telegram_support']['body'] }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <section id="testimonial" class="px-5 py-14 sm:px-8 lg:px-10 lg:py-20">
                <div class="mx-auto grid max-w-7xl gap-5 lg:grid-cols-[0.95fr_1.05fr]">
                    <figure class="rounded-[2rem] border border-[var(--sf-border)] bg-white p-6 shadow-sm shadow-orange-200/60 sm:p-8">
                        <blockquote class="text-2xl font-black leading-tight tracking-[-0.03em] text-[var(--sf-ink)] sm:text-3xl">"{{ $copy['testimonial']['quote'] }}"</blockquote>
                        <figcaption class="mt-6">
                            <p class="font-black text-[var(--sf-orange-deep)]">{{ $copy['testimonial']['name'] }}</p>
                            <p class="mt-1 text-sm font-semibold text-[var(--sf-muted)]">{{ $copy['testimonial']['role'] }}</p>
                        </figcaption>
                    </figure>
                    <div class="grid gap-4 sm:grid-cols-3">
                        @foreach ($copy['testimonial']['stats'] as $stat)
                            <div class="rounded-[1.5rem] border border-orange-100 bg-orange-50/80 p-6">
                                <p class="text-3xl font-black text-[var(--sf-orange-deep)]">{{ $stat['value'] }}</p>
                                <p class="mt-2 text-sm font-bold leading-6 text-[var(--sf-muted)]">{{ $stat['label'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <section id="faq" class="px-5 py-14 sm:px-8 lg:px-10 lg:py-20">
                <div class="mx-auto max-w-4xl">
                    <p class="text-center text-sm font-black uppercase tracking-[0.18em] text-[var(--sf-orange-deep)]">Pertanyaan Populer (FAQ)</p>
                    <h2 class="mt-3 text-center text-3xl font-black tracking-[-0.04em] text-[var(--sf-ink)] sm:text-4xl">Jawaban singkat sebelum order.</h2>
                    <div class="mt-8 space-y-4">
                        @foreach ($copy['faq'] as $faq)
                            <details class="group rounded-3xl border border-[var(--sf-border)] bg-white p-6 shadow-sm shadow-orange-200/50" @if ($loop->first) open @endif>
                                <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-4 font-black text-[var(--sf-ink)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-orange-deep)]">
                                    {{ $faq['question'] }}
                                    <span aria-hidden="true" class="flex h-8 w-8 items-center justify-center rounded-full bg-orange-50 text-[var(--sf-orange-deep)] transition duration-200 group-open:rotate-45">+</span>
                                </summary>
                                <p class="mt-3 leading-7 text-[var(--sf-muted)]">{{ $faq['answer'] }}</p>
                            </details>
                        @endforeach
                    </div>
                </div>
            </section>

            <section id="support" class="px-5 py-14 sm:px-8 lg:px-10 lg:py-20">
                <div class="mx-auto flex max-w-7xl flex-col gap-6 overflow-hidden rounded-[2rem] border border-orange-200 bg-[var(--sf-orange)] p-8 text-white shadow-2xl shadow-orange-300/60 sm:p-10 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <p class="text-sm font-black uppercase tracking-[0.18em] text-orange-100">{{ $copy['support']['eyebrow'] }}</p>
                        <h2 class="mt-3 text-3xl font-black tracking-[-0.04em] sm:text-5xl">{{ $copy['support']['title'] }}</h2>
                        <p class="mt-4 max-w-2xl leading-7 text-orange-50">{{ $copy['support']['body'] }}</p>
                    </div>
                    <div class="flex flex-col gap-3 sm:flex-row lg:flex-col xl:flex-row">
                        @if ($supportHref)
                            <a href="{{ $supportHref }}" class="inline-flex min-h-12 items-center justify-center rounded-full bg-white px-6 font-black text-[var(--sf-orange-deep)] shadow-lg shadow-orange-700/20 transition duration-200 motion-safe:hover:-translate-y-0.5 focus:outline-none focus:ring-2 focus:ring-white focus:ring-offset-2 focus:ring-offset-[var(--sf-orange)]">{{ $copy['support']['primary_cta'] }}</a>
                        @else
                            <span class="inline-flex min-h-12 items-center justify-center rounded-full bg-white/90 px-6 text-center font-black text-[var(--sf-orange-deep)]">Support Telegram segera tersedia</span>
                        @endif
                        <a href="{{ url('/admin/login') }}" class="inline-flex min-h-12 items-center justify-center rounded-full border border-white/70 px-6 font-black text-white transition duration-200 motion-safe:hover:-translate-y-0.5 hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-white">{{ $copy['support']['secondary_cta'] }}</a>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-[var(--sf-border)] bg-white px-5 py-12 text-[var(--sf-muted)] sm:px-8 lg:px-10">
            <div class="mx-auto grid max-w-7xl gap-10 md:grid-cols-[1.1fr_0.9fr_0.9fr]">
                <div>
                    <a href="{{ $canonicalUrl }}" class="inline-flex items-center gap-3 rounded-full focus:outline-none focus:ring-2 focus:ring-[var(--sf-orange-deep)]">
                        <span class="flex h-12 w-12 items-center justify-center overflow-hidden rounded-2xl border border-[var(--sf-border)] bg-white shadow-sm shadow-orange-200/50">
                            <img src="https://warungai.web.id/assets/mainlogo.png" alt="Warung AI Logo" width="160" height="160" class="h-20 w-20 max-w-none object-contain" loading="lazy" onerror="this.classList.add('hidden');this.nextElementSibling.classList.remove('hidden')">
                            <span class="hidden text-sm font-black text-[var(--sf-orange)]">AI</span>
                        </span>
                        <span class="text-lg font-black text-[var(--sf-ink)]">Warung AI</span>
                    </a>
                    <p class="mt-4 max-w-sm leading-7">{{ $copy['footer']['body'] }}</p>
                </div>
                <div>
                    <h3 class="font-black uppercase tracking-[0.16em] text-[var(--sf-ink)]">{{ $copy['footer']['platform_title'] }}</h3>
                    <ul class="mt-4 space-y-3">
                        @foreach ($copy['nav'] as $item)
                            <li><a href="{{ $item['href'] }}" class="hover:text-[var(--sf-orange-deep)]">{{ $item['label'] }}</a></li>
                        @endforeach
                        <li><a href="{{ url('/admin/login') }}" class="hover:text-[var(--sf-orange-deep)]">Login Admin</a></li>
                    </ul>
                </div>
                <div>
                    <h3 class="font-black uppercase tracking-[0.16em] text-[var(--sf-ink)]">{{ $copy['footer']['help_title'] }}</h3>
                    <ul class="mt-4 space-y-3">
                        <li><a href="#support" class="hover:text-[var(--sf-orange-deep)]">Support</a></li>
                        <li><a href="#faq" class="hover:text-[var(--sf-orange-deep)]">FAQ</a></li>
                    </ul>
                </div>
            </div>
            <div class="mx-auto mt-10 flex max-w-7xl flex-col gap-3 border-t border-[var(--sf-border)] pt-6 text-sm sm:flex-row sm:items-center sm:justify-between">
                <p>© {{ now()->year }} {{ $copy['footer']['copyright_suffix'] }}</p>
                <p>{{ $copy['footer']['powered_by'] }}</p>
            </div>
        </footer>
    </div>
</body>
</html>
