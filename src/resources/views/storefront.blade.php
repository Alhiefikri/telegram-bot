@php
    $brand = 'Warung AI';
    $copy = config('storefront');
    $pageTitle = str_replace(':brand', $brand, $copy['meta']['title']);
    $pageDescription = $copy['meta']['description'];
    $canonicalUrl = url('/');
    $telegramUsername = config('services.telegram.admin_username');
    $supportHref = config('services.telegram.admin_contact_url') ?: ($telegramUsername ? 'https://t.me/'.ltrim($telegramUsername, '@') : null);
    $supportDestination = '#support';
    $stockAvailable = fn ($product) => max(0, (int) $product->stock - (int) $product->reserved_stock);
    $idr = fn ($amount) => 'Rp'.number_format((int) $amount, 0, ',', '.');
    $categoryAnchor = fn ($category) => 'category-'.($category->slug ?: $category->id);
    $featuredStock = $featuredProduct ? $stockAvailable($featuredProduct) : 0;
    $featuredImageUrl = $featuredProduct && $featuredProduct->image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($featuredProduct->image_path) : null;
    $categoriesWithProducts = $categories->filter(fn ($category) => $products->contains('category_id', $category->id));
    $featuredCategoryDestination = $featuredProduct && $featuredProduct->category && $categoriesWithProducts->contains('id', $featuredProduct->category_id) ? '#'.$categoryAnchor($featuredProduct->category) : '#products';
    $productsWithoutVisibleCategory = $products->reject(fn ($product) => $product->category_id && $categories->contains('id', $product->category_id));
    $catalogProducts = $products;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-[#09090b]">
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
<body class="overflow-x-clip bg-[#09090b] text-white antialiased selection:bg-orange-500 selection:text-white">
    <div class="storefront-shell storefront-grain relative min-h-screen overflow-x-clip">
        <span aria-hidden="true" class="storefront-ambient-orb motion-safe:animate-storefront-float motion-reduce:animate-none -left-20 top-28 h-56 w-56 bg-orange-500/20 sm:h-72 sm:w-72"></span>
        <span aria-hidden="true" class="storefront-ambient-orb motion-safe:animate-storefront-float motion-reduce:animate-none right-[-7rem] top-[32rem] h-64 w-64 bg-amber-300/10 sm:h-96 sm:w-96"></span>
        <header class="sticky top-0 z-50 border-b border-white/10 bg-[#09090b]/82 backdrop-blur-xl">
            <div class="mx-auto flex min-h-20 w-full max-w-7xl items-center justify-between gap-4 px-5 sm:px-8 lg:px-10">
                <a href="{{ $canonicalUrl }}" class="flex min-h-11 items-center gap-3 rounded-full focus:outline-none focus:ring-2 focus:ring-orange-400">
                    <span class="block h-12 w-44 overflow-hidden">
                        <img src="https://warungai.web.id/assets/mainlogo.png" alt="Warung AI Logo" class="h-44 w-44 max-w-none -translate-y-[3.15rem] object-contain" loading="eager">
                    </span>
                </a>

                <nav aria-label="Navigasi utama" class="hidden items-center gap-1 rounded-full border border-white/10 bg-white/[0.03] px-2 py-1 text-sm font-semibold text-zinc-300 shadow-2xl shadow-black/20 lg:flex">
                    <a href="#products" class="inline-flex min-h-10 items-center rounded-full px-4 transition hover:bg-white/10 hover:text-white focus:outline-none focus:ring-2 focus:ring-orange-400">Products</a>
                    <a href="#features" class="inline-flex min-h-10 items-center rounded-full px-4 transition hover:bg-white/10 hover:text-white focus:outline-none focus:ring-2 focus:ring-orange-400">Features</a>
                    <a href="#pricing" class="inline-flex min-h-10 items-center rounded-full px-4 transition hover:bg-white/10 hover:text-white focus:outline-none focus:ring-2 focus:ring-orange-400">Pricing</a>
                    <a href="{{ url('/admin/login') }}" class="inline-flex min-h-10 items-center rounded-full px-4 transition hover:bg-white/10 hover:text-white focus:outline-none focus:ring-2 focus:ring-orange-400">User Dashboard</a>
                    <a href="#support" class="inline-flex min-h-10 items-center rounded-full px-4 transition hover:bg-white/10 hover:text-white focus:outline-none focus:ring-2 focus:ring-orange-400">Contact Support</a>
                </nav>

                <a href="{{ url('/admin/login') }}" class="inline-flex min-h-11 items-center justify-center rounded-full border border-orange-400/40 bg-orange-500/10 px-5 text-sm font-black text-orange-100 transition hover:border-orange-300 hover:bg-orange-500 hover:text-white focus:outline-none focus:ring-2 focus:ring-orange-400">
                    Dashboard
                </a>
            </div>
        </header>

        <main class="relative z-10">
            <section class="relative mx-auto grid w-full max-w-7xl items-center gap-10 px-5 pb-16 pt-14 sm:px-8 lg:min-h-[calc(100dvh-5rem)] lg:grid-cols-[0.96fr_1.04fr] lg:px-10 lg:pb-24 lg:pt-20">
                <div class="max-w-3xl motion-safe:animate-storefront-reveal motion-reduce:animate-none">
                    <p class="inline-flex rounded-full border border-orange-300/25 bg-white/[0.06] px-4 py-2 text-sm font-black uppercase tracking-[0.24em] text-orange-100 shadow-lg shadow-orange-950/25 backdrop-blur">{{ $copy['hero']['eyebrow'] }}</p>
                    <h1 class="mt-6 max-w-4xl text-balance text-5xl font-black leading-[0.94] tracking-[-0.055em] text-white sm:text-6xl lg:text-7xl">
                        {{ $copy['hero']['headline'] }} <span class="bg-gradient-to-r from-orange-300 via-orange-500 to-red-500 bg-clip-text text-transparent">AI Super</span>
                    </h1>
                    <p class="mt-6 max-w-2xl text-pretty text-lg leading-8 text-zinc-300 sm:text-xl">{{ $pageDescription }} {{ $copy['hero']['body'] }}</p>
                    <div class="mt-9 flex flex-col gap-3 sm:flex-row">
                        <a href="#products" class="group inline-flex min-h-12 items-center justify-center gap-2 rounded-full bg-gradient-to-r from-orange-500 to-red-500 px-7 text-base font-black text-white shadow-2xl shadow-orange-950/40 transition duration-300 hover:scale-[1.02] hover:shadow-orange-800/30 focus:outline-none focus:ring-2 focus:ring-orange-300">
                            {{ $copy['hero']['primary_cta'] }}
                            <span aria-hidden="true" class="transition group-hover:translate-x-1">→</span>
                        </a>
                        <a href="#support" class="inline-flex min-h-12 items-center justify-center rounded-full border border-white/15 bg-white/[0.04] px-7 text-base font-black text-white transition duration-300 hover:border-orange-300/60 hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-orange-300">{{ $copy['hero']['secondary_cta'] }}</a>
                    </div>
                    <div class="mt-8 grid max-w-xl grid-cols-3 gap-3 text-sm text-zinc-300">
                        @foreach ($copy['trust_indicators'] as $indicator)
                            @break($loop->index === 3)
                            <div class="rounded-2xl border border-white/10 bg-white/[0.04] px-4 py-3 backdrop-blur">
                                <span class="block font-black text-white">{{ $indicator['title'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="storefront-hero-card motion-safe:animate-storefront-float motion-reduce:animate-none rounded-[2rem] border border-white/10 bg-white/[0.05] p-4 shadow-2xl shadow-orange-950/30 backdrop-blur">
                    <div class="overflow-hidden rounded-[1.5rem] border border-orange-400/20 bg-[#111114]/95">
                        <div class="border-b border-white/10 px-5 py-4">
                            <p class="text-xs font-black uppercase tracking-[0.28em] text-orange-200">SUPER DEAL</p>
                        </div>
                        <div class="space-y-3 p-5">
                            @forelse ($categories as $category)
                                <a href="{{ $products->contains('category_id', $category->id) ? '#'.$categoryAnchor($category) : '#products' }}" class="flex min-h-16 items-center justify-between gap-4 rounded-2xl border border-white/10 bg-white/[0.035] px-4 text-left transition duration-300 hover:-translate-y-0.5 hover:border-orange-400/60 hover:bg-orange-500/10 focus:outline-none focus:ring-2 focus:ring-orange-400">
                                    <span>
                                        <span class="block font-black text-white">{{ $category->name }}</span>
                                        @if ($category->description)
                                            <span class="mt-1 block text-sm text-zinc-400">{{ $category->description }}</span>
                                        @endif
                                    </span>
                                    <span class="text-xl text-orange-300">→</span>
                                </a>
                            @empty
                                <div class="rounded-2xl border border-dashed border-orange-400/40 bg-orange-500/10 p-5">
                                    <p class="font-black text-white">Kategori akan segera hadir.</p>
                                    <p class="mt-2 text-sm leading-6 text-zinc-300">Tim toko sedang menyiapkan kategori publik pertama agar katalog mudah dijelajahi.</p>
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </section>

            <section id="featured-offer" class="px-5 pb-16 text-white sm:px-8 lg:px-10 lg:pb-24">
                <div class="mx-auto max-w-7xl overflow-hidden rounded-[2.25rem] border border-orange-400/20 bg-[#111114] shadow-2xl shadow-black/50">
                    @if ($featuredProduct)
                        <div class="grid lg:grid-cols-[0.92fr_1.08fr]">
                            <div class="bg-[radial-gradient(circle_at_50%_35%,rgba(255,127,39,0.22),transparent_26rem),linear-gradient(135deg,rgba(255,255,255,0.08),rgba(255,255,255,0.02))] p-5 sm:p-8">
                                @if ($featuredImageUrl)
                                    <img src="{{ $featuredImageUrl }}" alt="{{ $featuredProduct->name }}" class="aspect-[4/3] w-full rounded-[1.75rem] object-cover shadow-2xl shadow-black/40" loading="eager">
                                @else
                                    <div class="flex aspect-[4/3] w-full items-center justify-center rounded-[1.75rem] bg-gradient-to-br from-orange-100 via-white to-orange-200 p-6 text-center text-sm font-black uppercase tracking-[0.28em] text-orange-950 shadow-2xl shadow-black/30">Gambar promo menyusul</div>
                                @endif
                            </div>
                            <div class="flex flex-col justify-center p-6 sm:p-8 lg:p-10">
                                <p class="inline-flex w-fit items-center gap-2 rounded-full bg-orange-500/10 px-4 py-2 text-xs font-black uppercase tracking-[0.24em] text-orange-200">FLASH SALE</p>
                                @if ($featuredProduct->category)
                                    <a href="{{ $featuredCategoryDestination }}" class="mt-5 inline-flex min-h-11 w-fit items-center rounded-full border border-orange-400/30 px-4 text-sm font-black text-orange-100 hover:border-orange-300 focus:outline-none focus:ring-2 focus:ring-orange-400">Kategori {{ $featuredProduct->category->name }}</a>
                                @endif
                                <h2 class="mt-5 text-3xl font-black leading-tight tracking-[-0.04em] sm:text-5xl">{{ $featuredProduct->name }}</h2>
                                <p class="mt-4 max-w-2xl leading-7 text-zinc-300">{{ $featuredProduct->description }}</p>
                                <div class="mt-6 flex flex-wrap items-end gap-3">
                                    <span class="text-4xl font-black text-orange-300">{{ $idr($featuredProduct->price_amount) }}</span>
                                    <span class="rounded-full bg-emerald-400/15 px-4 py-2 text-sm font-black text-emerald-100">Sisa {{ $featuredStock }}</span>
                                </div>
                                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                                    <a href="{{ $supportDestination }}" class="group inline-flex min-h-12 items-center justify-center gap-2 rounded-full bg-gradient-to-r from-orange-500 to-red-500 px-6 font-black text-white shadow-xl shadow-orange-950/30 hover:scale-[1.02] focus:outline-none focus:ring-2 focus:ring-orange-300">
                                        Sikat Sekarang
                                        <span aria-hidden="true" class="transition group-hover:translate-x-1">→</span>
                                    </a>
                                    <a href="#products" class="inline-flex min-h-12 items-center justify-center rounded-full border border-white/15 px-6 font-black text-white hover:border-orange-300/60 focus:outline-none focus:ring-2 focus:ring-orange-300">Lihat katalog</a>
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="p-8 text-center sm:p-12">
                            <p class="text-sm font-black uppercase tracking-[0.24em] text-orange-200">FLASH SALE</p>
                            <h2 class="mt-4 text-3xl font-black tracking-tight">Promo pilihan menunggu stok siap.</h2>
                            <p class="mx-auto mt-4 max-w-2xl leading-7 text-zinc-300">Belum ada produk aktif dengan stok tersedia untuk dijadikan offer utama. Katalog tetap bisa dibaca, dan pelanggan dapat menghubungi support untuk rekomendasi.</p>
                            <a href="{{ $supportDestination }}" class="mt-6 inline-flex min-h-12 items-center justify-center rounded-full bg-orange-500 px-6 font-black text-white hover:bg-orange-400 focus:outline-none focus:ring-2 focus:ring-orange-300">Minta rekomendasi ke support</a>
                        </div>
                    @endif
                </div>
            </section>

            <section id="products" class="px-5 py-16 text-white sm:px-8 lg:px-10 lg:py-24">
                <div class="mx-auto max-w-7xl">
                    <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <p class="text-sm font-black uppercase tracking-[0.24em] text-orange-300">Katalog Produk</p>
                            <h2 class="mt-3 text-4xl font-black tracking-[-0.05em] sm:text-5xl">Produk AI premium siap pakai</h2>
                            <p class="mt-3 max-w-2xl leading-7 text-zinc-400">Pilih tools AI, lisensi premium, dan produk digital pilihan. Stok dan harga tampil jelas sebelum Anda menghubungi support.</p>
                        </div>
                        <form action="#products" method="GET" class="relative w-full max-w-md">
                            <label for="q" class="sr-only">Cari produk</label>
                            <input id="q" name="q" value="{{ $search ?? '' }}" placeholder="Cari produk..." class="min-h-12 w-full rounded-full border border-white/10 bg-white/[0.04] py-3 pl-12 pr-4 text-sm font-semibold text-white placeholder:text-zinc-500 shadow-2xl shadow-black/20 outline-none transition focus:border-orange-400 focus:ring-2 focus:ring-orange-400/30">
                            <span aria-hidden="true" class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-zinc-500">⌕</span>
                        </form>
                    </div>

                    @if ($products->isEmpty())
                        <div class="mt-10 rounded-[2rem] border border-dashed border-orange-400/40 bg-[#111114] p-8 text-center shadow-2xl shadow-black/30 sm:p-12">
                            <p class="mx-auto flex h-16 w-16 items-center justify-center rounded-3xl bg-orange-500/10 text-sm font-black uppercase tracking-[0.18em] text-orange-200">Produk</p>
                            <h3 class="mt-6 text-2xl font-black">Katalog publik sedang disiapkan.</h3>
                            <p class="mx-auto mt-3 max-w-2xl text-base leading-7 text-zinc-400">Produk aktif akan tampil otomatis di sini setelah dipublikasikan dari admin. Sambil menunggu, pelanggan bisa menghubungi support untuk rekomendasi produk digital yang tersedia.</p>
                            <a href="{{ $supportDestination }}" class="mt-6 inline-flex min-h-12 items-center justify-center rounded-full bg-orange-500 px-6 font-black text-white hover:bg-orange-400 focus:outline-none focus:ring-2 focus:ring-orange-300">Hubungi support</a>
                        </div>
                    @else
                        @if ($categoriesWithProducts->isNotEmpty())
                            <nav aria-label="Kategori katalog" class="mt-8 flex flex-wrap gap-3">
                                @foreach ($categoriesWithProducts as $category)
                                    <a href="#{{ $categoryAnchor($category) }}" class="inline-flex min-h-11 items-center rounded-full border border-orange-400/25 bg-orange-500/10 px-5 text-sm font-black text-orange-100 shadow-sm hover:border-orange-300 focus:outline-none focus:ring-2 focus:ring-orange-400">
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
                                <article class="group motion-safe:animate-storefront-reveal motion-reduce:animate-none flex h-full scroll-mt-28 flex-col overflow-hidden rounded-[1.5rem] border {{ $cardStock > 0 ? 'border-white/10 bg-[#111114]/95' : 'border-amber-400/20 bg-[#14110f] opacity-90' }} shadow-2xl shadow-black/25 transition duration-300 hover:-translate-y-1 hover:border-orange-400/40 hover:shadow-orange-950/20">
                                    <div class="relative overflow-hidden bg-white/[0.03]">
                                        @if ($imageUrl)
                                            <img src="{{ $imageUrl }}" alt="{{ $product->name }}" class="aspect-square w-full object-cover transition duration-500 group-hover:scale-105" loading="lazy">
                                        @else
                                            <div class="flex aspect-square w-full items-center justify-center bg-gradient-to-br from-orange-100 via-white to-orange-200 p-6 text-center text-xs font-black uppercase tracking-[0.22em] text-orange-950">Gambar produk menyusul</div>
                                        @endif
                                        @if ($product->category)
                                            <span class="absolute left-3 top-3 rounded-full bg-black/70 px-3 py-1 text-xs font-black uppercase tracking-[0.12em] text-orange-100 backdrop-blur">{{ $product->category->name }}</span>
                                        @endif
                                        <span class="absolute right-3 top-3 rounded-full {{ $cardStock > 0 ? 'bg-emerald-400 text-emerald-950' : 'bg-amber-300 text-amber-950' }} px-3 py-1 text-xs font-black">{{ $cardStock > 0 ? 'Sisa '.$cardStock : 'Stok habis' }}</span>
                                    </div>
                                    <div class="flex flex-1 flex-col p-5">
                                        <h3 class="break-words text-lg font-black leading-tight text-white">{{ $product->name }}</h3>
                                        <p class="mt-3 line-clamp-3 break-words text-sm leading-6 text-zinc-400">{{ $product->description }}</p>
                                        <div class="mt-auto pt-5">
                                            <p class="break-words text-2xl font-black text-orange-300">{{ $idr($product->price_amount) }}</p>
                                            <a href="{{ $supportDestination }}" class="mt-4 inline-flex min-h-12 w-full items-center justify-center rounded-full {{ $cardStock > 0 ? 'bg-gradient-to-r from-orange-500 to-red-500 text-white' : 'bg-white/10 text-zinc-200' }} px-4 text-center text-sm font-black hover:scale-[1.02] focus:outline-none focus:ring-2 focus:ring-orange-300">{{ $cardStock > 0 ? 'Sikat Sekarang' : 'Tanya stok ke support' }}</a>
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>

            <section id="features" class="border-y border-white/10 bg-[#111114] px-5 py-16 text-white sm:px-8 lg:px-10 lg:py-24">
                <div class="mx-auto grid max-w-7xl gap-10 lg:grid-cols-[0.85fr_1.15fr]">
                    <div>
                        <p class="text-sm font-black uppercase tracking-[0.24em] text-orange-300">Features</p>
                        <h2 class="mt-3 text-3xl font-black tracking-[-0.04em] sm:text-5xl">Platform AI premium Indonesia.</h2>
                        <p class="mt-4 leading-7 text-zinc-400">Akses cepat, stok transparan, dan dukungan order yang jelas untuk produk digital.</p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2 md:grid-cols-3">
                        @foreach ($copy['benefits'] as $benefit)
                            <div class="rounded-3xl border border-white/10 bg-white/[0.03] p-6">
                                <h3 class="font-black text-white">{{ $benefit['title'] }}</h3>
                                <p class="mt-3 text-sm leading-6 text-zinc-400">{{ $benefit['body'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <section id="pricing" class="px-5 py-16 text-white sm:px-8 lg:px-10 lg:py-24">
                <div class="mx-auto grid max-w-7xl gap-5 lg:grid-cols-[1.1fr_0.9fr]">
                    <div class="rounded-[2rem] border border-orange-400/20 bg-orange-500/10 p-6 sm:p-8">
                        <p class="text-sm font-black uppercase tracking-[0.24em] text-orange-200">{{ $copy['payment_flow']['eyebrow'] }}</p>
                        <h2 class="mt-3 text-3xl font-black tracking-[-0.04em] sm:text-4xl">{{ $copy['payment_flow']['title'] }}</h2>
                        <p class="mt-4 leading-7 text-zinc-300">{{ $copy['payment_flow']['body'] }}</p>
                        <div class="mt-6 grid gap-4 md:grid-cols-3">
                            @foreach ($copy['payment_flow']['steps'] as $index => $step)
                                <div class="rounded-3xl border border-white/10 bg-black/20 p-5">
                                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-orange-500 font-black text-white">{{ $index + 1 }}</span>
                                    <p class="mt-4 leading-7 text-zinc-200">{{ $step }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="grid gap-5">
                        <div class="rounded-[2rem] border border-white/10 bg-white/[0.03] p-6 sm:p-8">
                            <p class="text-sm font-black uppercase tracking-[0.24em] text-orange-300">{{ $copy['delivery']['eyebrow'] }}</p>
                            <h3 class="mt-3 text-2xl font-black tracking-tight">{{ $copy['delivery']['title'] }}</h3>
                            <p class="mt-4 leading-7 text-zinc-400">{{ $copy['delivery']['body'] }}</p>
                        </div>
                        <div class="rounded-[2rem] border border-white/10 bg-white/[0.03] p-6 sm:p-8">
                            <p class="text-sm font-black uppercase tracking-[0.24em] text-orange-300">{{ $copy['telegram_support']['eyebrow'] }}</p>
                            <h3 class="mt-3 text-2xl font-black tracking-tight">{{ $copy['telegram_support']['title'] }}</h3>
                            <p class="mt-4 leading-7 text-zinc-400">{{ $copy['telegram_support']['body'] }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <section id="faq" class="bg-[#111114] px-5 py-16 text-white sm:px-8 lg:px-10 lg:py-24">
                <div class="mx-auto max-w-4xl">
                    <p class="text-center text-sm font-black uppercase tracking-[0.24em] text-orange-300">Pertanyaan Populer (FAQ)</p>
                    <h2 class="mt-3 text-center text-3xl font-black tracking-[-0.04em] sm:text-4xl">Masih ragu? Temukan jawabannya di sini.</h2>
                    <div class="mt-8 space-y-4">
                        @foreach ($copy['faq'] as $faq)
                            <details class="group rounded-3xl border border-white/10 bg-[#0b0b0d] p-6 shadow-2xl shadow-black/20" @if ($loop->first) open @endif>
                                <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-4 font-black text-white focus:outline-none focus:ring-2 focus:ring-orange-400">
                                    {{ $faq['question'] }}
                                    <span class="text-orange-300 transition group-open:rotate-180">⌄</span>
                                </summary>
                                <p class="mt-3 leading-7 text-zinc-400">{{ $faq['answer'] }}</p>
                            </details>
                        @endforeach
                    </div>
                </div>
            </section>

            <section id="support" class="px-5 py-16 text-white sm:px-8 lg:px-10 lg:py-24">
                <div class="mx-auto flex max-w-7xl flex-col gap-6 overflow-hidden rounded-[2rem] border border-orange-400/20 bg-[radial-gradient(circle_at_20%_20%,rgba(255,106,0,0.28),transparent_24rem),#111114] p-8 shadow-2xl shadow-black/40 sm:p-10 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <p class="text-sm font-black uppercase tracking-[0.24em] text-orange-200">{{ $copy['support']['eyebrow'] }}</p>
                        <h2 class="mt-3 text-3xl font-black tracking-[-0.04em] sm:text-5xl">{{ $copy['support']['title'] }}</h2>
                        <p class="mt-4 max-w-2xl leading-7 text-zinc-300">{{ $copy['support']['body'] }}</p>
                    </div>
                    <div class="flex flex-col gap-3 sm:flex-row lg:flex-col xl:flex-row">
                        @if ($supportHref)
                            <a href="{{ $supportHref }}" class="inline-flex min-h-12 items-center justify-center rounded-full bg-gradient-to-r from-orange-500 to-red-500 px-6 font-black text-white hover:scale-[1.02] focus:outline-none focus:ring-2 focus:ring-orange-300">Contact Support</a>
                        @else
                            <span class="inline-flex min-h-12 items-center justify-center rounded-full bg-orange-500/80 px-6 text-center font-black text-white">Support Telegram segera tersedia</span>
                        @endif
                        <a href="{{ url('/admin/login') }}" class="inline-flex min-h-12 items-center justify-center rounded-full border border-white/15 px-6 font-black text-white hover:border-orange-300/60 focus:outline-none focus:ring-2 focus:ring-orange-300">User Dashboard</a>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-white/10 bg-[#0b0b0d] px-5 py-12 text-zinc-400 sm:px-8 lg:px-10">
            <div class="mx-auto grid max-w-7xl gap-10 md:grid-cols-[1.1fr_0.9fr_0.9fr]">
                <div>
                    <span class="block h-16 w-56 overflow-hidden">
                        <img src="https://warungai.web.id/assets/mainlogo.png" alt="Warung AI Logo" class="h-56 w-56 max-w-none -translate-y-16 object-contain" loading="lazy">
                    </span>
                    <p class="mt-4 max-w-sm leading-7">Elevate your workflow with premium, state-of-the-art AI solutions. All systems operational.</p>
                </div>
                <div>
                    <h3 class="font-black uppercase tracking-[0.18em] text-white">Platform</h3>
                    <ul class="mt-4 space-y-3">
                        <li><a href="#products" class="hover:text-orange-300">Products</a></li>
                        <li><a href="#features" class="hover:text-orange-300">Features</a></li>
                        <li><a href="#pricing" class="hover:text-orange-300">Pricing</a></li>
                        <li><a href="{{ url('/admin/login') }}" class="hover:text-orange-300">User Dashboard</a></li>
                    </ul>
                </div>
                <div>
                    <h3 class="font-black uppercase tracking-[0.18em] text-white">Legal</h3>
                    <ul class="mt-4 space-y-3">
                        <li><a href="#support" class="hover:text-orange-300">Contact Support</a></li>
                        <li><a href="#faq" class="hover:text-orange-300">FAQ</a></li>
                    </ul>
                </div>
            </div>
            <div class="mx-auto mt-10 flex max-w-7xl flex-col gap-3 border-t border-white/10 pt-6 text-sm sm:flex-row sm:items-center sm:justify-between">
                <p>© {{ now()->year }} Warung AI. All rights reserved.</p>
                <p>Powered by WarungAI</p>
            </div>
        </footer>
    </div>
</body>
</html>
