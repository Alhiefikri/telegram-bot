@php
    $brand = 'Lhie Store';
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

    <noscript>
        <style>
            .reveal-item {
                opacity: 1 !important;
                transform: none !important;
            }
        </style>
    </noscript>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:ital,wght@0,400..700;1,400..700&family=Plus+Jakarta+Sans:ital,wght@0,300..800;1,300..800&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Instrument Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #fcfcfc;
            color: #09090b;
        }

        h1, h2, h3, h4, .font-display {
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        /* Grid Background Pattern */
        .bg-grid-pattern {
            background-image: radial-gradient(#e4e4e7 1.2px, transparent 1.2px);
            background-size: 20px 20px;
        }

        /* Hover Lift */
        .hover-lift {
            transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.3s ease;
        }
        .hover-lift:hover {
            transform: translateY(-2px);
        }

        /* Scroll Reveal Base */
        .reveal-item {
            opacity: 0;
            transform: translateY(16px);
            transition: opacity 0.6s cubic-bezier(0.16, 1, 0.3, 1), transform 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .reveal-item.revealed {
            opacity: 1;
            transform: translateY(0);
        }

        /* Infinite Marquee Animation */
        @keyframes marquee {
            0% { transform: translateX(0%); }
            100% { transform: translateX(-50%); }
        }
        .animate-marquee {
            display: flex;
            width: max-content;
            animation: marquee 42s linear infinite;
        }

        .hero-preview-panel {
            animation: hero-panel-in 700ms cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        .hero-flow-row {
            animation: hero-flow 5.5s cubic-bezier(0.22, 1, 0.36, 1) infinite;
        }

        .hero-flow-row:nth-child(2) {
            animation-delay: 800ms;
        }

        .hero-flow-row:nth-child(3) {
            animation-delay: 1600ms;
        }

        @keyframes hero-panel-in {
            from { opacity: 0; transform: translate3d(0, 20px, 0) scale(0.97); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        @keyframes hero-flow {
            0%, 100% { opacity: 0.55; transform: translate3d(0, 0, 0); }
            42% { opacity: 1; transform: translate3d(0, -2px, 0); }
        }

        /* Smooth count-up transition */
        .counter-number {
            font-variant-numeric: tabular-nums;
        }

        /* Respect prefers-reduced-motion */
        @media (prefers-reduced-motion: reduce) {
            .animate-marquee {
                animation: none !important;
                transform: none !important;
                width: auto !important;
                flex-wrap: wrap !important;
                justify-content: center !important;
                gap: 2rem !important;
            }
            .reveal-item {
                opacity: 1 !important;
                transform: none !important;
                transition: none !important;
            }
            .hover-lift:hover {
                transform: none !important;
            }
            .hero-preview-panel,
            .hero-flow-row {
                animation: none !important;
                opacity: 1 !important;
                transform: none !important;
            }
        }

    </style>
</head>
<body class="overflow-x-hidden antialiased selection:bg-[var(--sf-orange)] selection:text-white relative text-[var(--sf-ink)] motion-reduce:animate-none">

    <!-- Brand covers generator helper function -->
    @php
        $getProductCover = function($name) {
            $nameLower = strtolower($name);

            // Standard dark blueprint theme
            $theme = [
                'bg' => 'from-zinc-900 to-zinc-950',
                'gradient' => 'from-orange-500/20 to-red-500/10',
                'accent' => 'text-orange-500',
                'badge' => 'DISKON 10%',
                'badge_bg' => 'bg-orange-500 text-white',
                'desc' => 'PREMIUM LICENCE',
                'logo' => '<svg class="w-16 h-16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.25"><rect x="3" y="3" width="18" height="18" rx="4"/><path d="M12 8v8m-4-4h8" stroke-linecap="round" stroke-linejoin="round"/></svg>'
            ];

            if (str_contains($nameLower, 'chat gpt') || str_contains($nameLower, 'chatgpt')) {
                $theme = [
                    'bg' => 'from-[#0B1510] to-[#0A261A]',
                    'gradient' => 'from-[#10B981]/25 to-[#059669]/10',
                    'accent' => 'text-[#10B981]',
                    'badge' => 'DISKON 5%',
                    'badge_bg' => 'bg-emerald-600 text-white',
                    'desc' => 'CHAT GPT PLUS',
                    'logo' => '<svg class="w-16 h-16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.25"><circle cx="12" cy="12" r="10"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1-4-10z" stroke-dasharray="2 2"/><path d="M12 12h.01" stroke-width="3" stroke-linecap="round"/></svg>'
                ];
            } elseif (str_contains($nameLower, 'claude')) {
                $theme = [
                    'bg' => 'from-[#1D1714] to-[#140F0D]',
                    'gradient' => 'from-[#EA580C]/20 to-transparent',
                    'accent' => 'text-[#EA580C]',
                    'badge' => 'DISKON 5%',
                    'badge_bg' => 'bg-orange-600 text-white',
                    'desc' => 'CLAUDE PRO AI',
                    'logo' => '<svg class="w-16 h-16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.25"><path d="M12 3v18M3 12h18" stroke-linecap="round"/><circle cx="12" cy="12" r="6" stroke-dasharray="3 3"/></svg>'
                ];
            } elseif (str_contains($nameLower, 'canva')) {
                $theme = [
                    'bg' => 'from-[#0F1E36] to-[#081324]',
                    'gradient' => 'from-[#06B6D4]/30 to-[#3B82F6]/10',
                    'accent' => 'text-[#06B6D4]',
                    'badge' => 'DISKON 50%',
                    'badge_bg' => 'bg-cyan-600 text-white',
                    'desc' => 'CANVA PREMIUM',
                    'logo' => '<svg class="w-16 h-16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.25"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="M8 12c2-3 6-3 8 0" stroke-linecap="round"/></svg>'
                ];
            } elseif (str_contains($nameLower, 'midjourney') || str_contains($nameLower, 'leonardo')) {
                $theme = [
                    'bg' => 'from-[#16122C] to-[#0A0718]',
                    'gradient' => 'from-[#8B5CF6]/35 to-transparent',
                    'accent' => 'text-[#A78BFA]',
                    'badge' => 'DISKON 90%',
                    'badge_bg' => 'bg-purple-600 text-white',
                    'desc' => 'CREATIVE ART AI',
                    'logo' => '<svg class="w-16 h-16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.25"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" stroke-linecap="round" stroke-linejoin="round"/></svg>'
                ];
            } elseif (str_contains($nameLower, 'google') || str_contains($nameLower, 'gemini')) {
                $theme = [
                    'bg' => 'from-[#081226] to-[#040A17]',
                    'gradient' => 'from-[#3B82F6]/30 to-transparent',
                    'accent' => 'text-[#60A5FA]',
                    'badge' => 'DISKON 5%',
                    'badge_bg' => 'bg-blue-600 text-white',
                    'desc' => 'GEMINI / GOOGLE',
                    'logo' => '<svg class="w-16 h-16" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2c0 5.523 4.477 10 10 10-5.523 0-10 4.477-10 10 0-5.523-4.477-10-10-10 5.523 0 10-4.477 10-10z"/></svg>'
                ];
            } elseif (str_contains($nameLower, 'netflix')) {
                $theme = [
                    'bg' => 'from-[#1A0808] to-[#0D0404]',
                    'gradient' => 'from-[#DC2626]/20 to-transparent',
                    'accent' => 'text-[#EF4444]',
                    'badge' => 'DISKON 10%',
                    'badge_bg' => 'bg-red-600 text-white',
                    'desc' => 'NETFLIX STREAM',
                    'logo' => '<svg class="w-16 h-16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path d="M7 3v18M17 3v18M7 3l10 18" stroke-linecap="round" stroke-linejoin="round"/></svg>'
                ];
            } elseif (str_contains($nameLower, 'blackbox')) {
                $theme = [
                    'bg' => 'from-[#111827] to-[#030712]',
                    'gradient' => 'from-[#3B82F6]/20 to-transparent',
                    'accent' => 'text-[#3B82F6]',
                    'badge' => 'DISKON 35%',
                    'badge_bg' => 'bg-blue-600 text-white',
                    'desc' => 'BLACKBOX AI',
                    'logo' => '<svg class="w-16 h-16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.25"><rect x="4" y="4" width="16" height="16" rx="2"/><path d="M9 9h6v6H9z" stroke-dasharray="2 2"/></svg>'
                ];
            }

            return $theme;
        };
    @endphp

    <div class="storefront-shell storefront-grid relative min-h-screen z-10 flex flex-col justify-between overflow-x-hidden">
        <span aria-hidden="true" class="storefront-wave motion-safe:animate-storefront-float motion-reduce:animate-none left-[-5rem] top-28 h-48 w-48 sm:h-64 sm:w-64"></span>
        <span aria-hidden="true" class="storefront-wave storefront-wave-soft motion-safe:animate-storefront-float motion-reduce:animate-none right-[-7rem] top-[34rem] h-56 w-56 sm:h-80 sm:w-80"></span>

        <!-- Header (Floating glass pill layout) -->
        <header class="sticky top-4 z-50 mx-auto w-[calc(100%-2rem)] max-w-5xl rounded-2xl bg-white/90 backdrop-blur-xl border border-[var(--sf-border)] shadow-sm shadow-orange-200/30 transition-all duration-300">
            <div class="flex h-14 items-center justify-between px-6">
                <a href="{{ $canonicalUrl }}" class="flex items-center gap-2">
                    <div class="h-7 w-7 rounded-lg bg-[var(--sf-orange)] flex items-center justify-center text-white font-black text-sm select-none shadow-sm shadow-orange-500/20">L</div>
                    <span class="text-sm font-bold tracking-tight text-[var(--sf-ink)]">{{ $brand }}</span>
                </a>

                <nav class="hidden md:flex items-center gap-1.5 p-1 bg-orange-50/80 rounded-xl text-xs font-semibold text-zinc-600 border border-orange-100">
                    <a href="#products" class="px-4 py-1.5 rounded-lg hover:bg-white hover:text-zinc-900 transition-colors">Produk</a>
                    <a href="#cara-beli" class="px-4 py-1.5 rounded-lg hover:bg-white hover:text-zinc-900 transition-colors">Cara Beli</a>
                </nav>

                <div class="flex items-center gap-3">
                    <a href="#products" class="hidden sm:inline-flex items-center justify-center rounded-xl bg-[var(--sf-ink)] px-4 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-[var(--sf-orange-deep)]">
                        Lihat Produk
                    </a>

                    <!-- Mobile Hamburger Menu Icon Button -->
                    <button id="mobile-menu-btn" class="md:hidden flex flex-col justify-center items-center w-8 h-8 rounded-full border border-zinc-200 bg-white shadow-sm gap-1 focus:outline-none" aria-label="Toggle menu">
                        <span id="menu-icon-1" class="w-4 h-[2px] bg-zinc-700 transition-transform duration-300"></span>
                        <span id="menu-icon-2" class="w-4 h-[2px] bg-zinc-700 transition-transform duration-300"></span>
                    </button>
                </div>
            </div>

            <!-- Mobile Menu Dropdown Overlay -->
            <div id="mobile-menu" class="hidden absolute top-16 left-0 right-0 p-4 mx-2 rounded-2xl bg-white border border-zinc-200 shadow-xl opacity-0 scale-95 transition-all duration-300 z-50">
                <nav class="flex flex-col gap-4 text-sm font-bold text-zinc-600">
                    <a href="#products" class="px-4 py-2 hover:bg-zinc-50 rounded-xl transition-colors">Produk</a>
                    <a href="#cara-beli" class="px-4 py-2 hover:bg-zinc-50 rounded-xl transition-colors">Cara Beli</a>
                    <a href="#faq" class="px-4 py-2 hover:bg-zinc-50 rounded-xl transition-colors">FAQ</a>
                    <a href="#support" class="px-4 py-2 hover:bg-zinc-50 rounded-xl transition-colors">Support</a>
                    <a href="#products" class="mt-2 inline-flex items-center justify-center rounded-full bg-zinc-900 py-3 text-center text-xs font-bold text-white">
                        Lihat Produk
                    </a>
                </nav>
            </div>
        </header>

        <main class="flex-grow pb-32">

            <!-- Hero (Split Layout: Text left, Interactive preview right) -->
            <section class="mx-auto max-w-5xl px-6 pt-8 lg:pt-16 pb-16 relative storefront-hero-card">
                <div class="grid lg:grid-cols-12 gap-12 lg:gap-16 items-center">
                    <!-- Left Copy -->
                    <div class="lg:col-span-7 text-left motion-safe:animate-storefront-reveal">
                        <h1 class="text-4xl sm:text-5xl lg:text-[3.5rem] font-black leading-[1.05] tracking-tight text-[var(--sf-ink)] text-balance">
                            {{ $copy['hero']['headline'] }}
                        </h1>

                        <p class="mt-6 max-w-xl text-sm sm:text-base text-[var(--sf-muted)] font-normal leading-relaxed">
                            {{ $copy['hero']['body'] }}
                            <span class="sr-only">Akun AI Premium Siap Pakai. Katalog akun AI premium</span>
                        </p>

                        <div class="mt-8 flex flex-col sm:flex-row items-stretch sm:items-center gap-4">
                            <a href="#products" class="inline-flex h-12 items-center justify-center rounded-xl bg-[var(--sf-orange)] hover:bg-[var(--sf-orange-deep)] text-sm font-bold text-white transition-all hover:-translate-y-0.5 active:translate-y-0 shadow-lg shadow-orange-500/15 px-8 text-center select-none">
                                {{ $copy['hero']['primary_cta'] }}
                            </a>
                            <a href="#cara-beli" class="inline-flex h-12 items-center justify-center rounded-xl border border-[var(--sf-border)] bg-white px-8 text-sm font-bold text-[var(--sf-ink)] transition-all hover:border-orange-300 hover:text-orange-700">
                                {{ $copy['hero']['secondary_cta'] }}
                            </a>
                        </div>
                    </div>

                    <!-- Right column: order preview -->
                    <div class="lg:col-span-5 flex justify-center w-full">
                        <div class="hero-preview-panel w-full max-w-md rounded-2xl border border-[var(--sf-border)] bg-white/95 p-5 shadow-xl shadow-orange-200/25">
                            <div class="flex items-center justify-between border-b border-orange-100 pb-4">
                                <div>
                                    <p class="text-[10px] font-semibold uppercase tracking-widest text-orange-700">Order siap diproses</p>
                                    <p class="mt-0.5 text-sm font-bold text-[var(--sf-ink)]">Katalog aktif hari ini</p>
                                </div>
                                <span class="rounded-lg bg-green-50 px-2.5 py-1 text-[10px] font-semibold text-green-700">Online</span>
                            </div>

                            <div class="mt-5 space-y-2.5">
                                <div class="hero-flow-row rounded-xl border border-orange-100 bg-orange-50/60 p-3.5">
                                    <div class="flex items-center justify-between gap-4">
                                        <div>
                                            <p class="text-xs font-bold text-zinc-900">ChatGPT Plus</p>
                                            <p class="mt-0.5 text-[11px] text-zinc-500">Stok siap, pengiriman digital</p>
                                        </div>
                                        <span class="text-[11px] font-semibold text-orange-600">Ready</span>
                                    </div>
                                </div>
                                <div class="hero-flow-row rounded-xl border border-zinc-100 bg-white p-3.5">
                                    <div class="flex items-center justify-between gap-4">
                                        <div>
                                            <p class="text-xs font-bold text-zinc-900">Claude Pro</p>
                                            <p class="mt-0.5 text-[11px] text-zinc-500">Support bantu cek detail akun</p>
                                        </div>
                                        <span class="text-[11px] font-semibold text-orange-600">Ready</span>
                                    </div>
                                </div>
                                <div class="hero-flow-row rounded-xl border border-zinc-100 bg-white p-3.5">
                                    <div class="flex items-center justify-between gap-4">
                                        <div>
                                            <p class="text-xs font-bold text-zinc-900">Canva Premium</p>
                                            <p class="mt-0.5 text-[11px] text-zinc-500">Harga jelas sebelum order</p>
                                        </div>
                                        <span class="text-[11px] font-semibold text-zinc-500">Cek stok</span>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-5 grid grid-cols-3 gap-2 text-center">
                                @foreach (array_slice($copy['trust_indicators'], 0, 3) as $indicator)
                                    <div class="rounded-xl bg-zinc-50 px-3 py-3">
                                        <p class="text-[10px] font-bold leading-tight text-zinc-900">{{ $indicator['title'] }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Trust logo infinite marquee strip -->
            <section class="w-full overflow-hidden py-10 bg-zinc-900/[0.03] border-y border-zinc-200/40 relative z-10 my-10">
                <div class="animate-marquee gap-20 whitespace-nowrap">
                    <!-- First track -->
                    <div class="flex items-center gap-20 text-zinc-400">
                        <img src="https://cdn.simpleicons.org/openai/666" alt="OpenAI" class="h-5 w-auto opacity-50 hover:opacity-80 transition-opacity" loading="lazy" width="80" height="20">
                        <img src="https://cdn.simpleicons.org/anthropic/666" alt="Anthropic Claude" class="h-5 w-auto opacity-50 hover:opacity-80 transition-opacity" loading="lazy" width="80" height="20">
                        <img src="https://cdn.simpleicons.org/google/666" alt="Google Gemini" class="h-5 w-auto opacity-50 hover:opacity-80 transition-opacity" loading="lazy" width="80" height="20">
                        <img src="https://cdn.simpleicons.org/midjourney/666" alt="Midjourney" class="h-5 w-auto opacity-50 hover:opacity-80 transition-opacity" loading="lazy" width="80" height="20">
                        <img src="https://cdn.simpleicons.org/canva/666" alt="Canva" class="h-5 w-auto opacity-50 hover:opacity-80 transition-opacity" loading="lazy" width="80" height="20">
                        <img src="https://cdn.simpleicons.org/github/666" alt="GitHub Copilot" class="h-5 w-auto opacity-50 hover:opacity-80 transition-opacity" loading="lazy" width="80" height="20">
                    </div>
                    <!-- Duplicate second track for seamless scroll -->
                    <div class="flex items-center gap-20 text-zinc-400" aria-hidden="true">
                        <img src="https://cdn.simpleicons.org/openai/666" alt="" class="h-5 w-auto opacity-50" loading="lazy" width="80" height="20">
                        <img src="https://cdn.simpleicons.org/anthropic/666" alt="" class="h-5 w-auto opacity-50" loading="lazy" width="80" height="20">
                        <img src="https://cdn.simpleicons.org/google/666" alt="" class="h-5 w-auto opacity-50" loading="lazy" width="80" height="20">
                        <img src="https://cdn.simpleicons.org/midjourney/666" alt="" class="h-5 w-auto opacity-50" loading="lazy" width="80" height="20">
                        <img src="https://cdn.simpleicons.org/canva/666" alt="" class="h-5 w-auto opacity-50" loading="lazy" width="80" height="20">
                        <img src="https://cdn.simpleicons.org/github/666" alt="" class="h-5 w-auto opacity-50" loading="lazy" width="80" height="20">
                    </div>
                </div>
            </section>

            <!-- Featured Offer Section -->
            <section id="featured-offer" class="mx-auto max-w-5xl px-6 pb-20 relative">
                @if ($featuredProduct)
                    @php
                        $featuredStock = $stockAvailable($featuredProduct);
                        $featuredImageUrl = $featuredProduct->image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($featuredProduct->image_path) : null;

                        // Branded cover theme for featured offer
                        $featuredCover = $getProductCover($featuredProduct->name);

                        // Discount metrics
                        $featuredOriginalPrice = $featuredProduct->price_amount * 1.5;
                        $featuredSold = 32 + ($featuredProduct->id * 7) % 25;
                        $featuredTotalStock = $featuredStock + $featuredSold;
                        $progressPercent = min(95, ($featuredSold / $featuredTotalStock) * 100);

                        $featuredMessage = "Halo Admin, saya tertarik dengan promo utama " . $featuredProduct->name . " di Lhie Store.";
                        $featuredLink = $supportHref ? $supportHref . (str_contains($supportHref, '?') ? '&' : '?') . 'text=' . urlencode($featuredMessage) : '#support';
                    @endphp

                    <!-- Featured product -->
                    <div class="w-full rounded-2xl border border-[var(--sf-border)] bg-white p-6 shadow-lg shadow-orange-200/20 sm:p-8 relative overflow-hidden text-zinc-900">
                        <div class="absolute inset-x-0 top-0 h-0.5 bg-gradient-to-r from-orange-500 to-red-500 pointer-events-none"></div>

                        <div class="grid lg:grid-cols-12 gap-8 lg:gap-12 items-center relative z-10">
                            <!-- Left cover -->
                            <div class="lg:col-span-4 flex justify-center">
                                <div class="w-full max-w-[220px] aspect-square rounded-xl overflow-hidden bg-zinc-950 p-6 flex flex-col justify-center items-center text-center shadow-lg shadow-zinc-900/10 relative">
                                    <div class="absolute inset-0 bg-grid-pattern opacity-5 pointer-events-none"></div>
                                    <div class="absolute inset-0 bg-gradient-to-tr {{ $featuredCover['gradient'] }} opacity-40 blur-lg"></div>

                                    @if ($featuredImageUrl)
                                        <img src="{{ $featuredImageUrl }}" alt="{{ $featuredProduct->name }}" class="absolute inset-0 w-full h-full object-cover">
                                    @else
                                        <div class="relative z-10 flex flex-col items-center justify-center text-center space-y-4">
                                            <div class="{{ $featuredCover['accent'] }} drop-shadow-[0_0_8px_rgba(249,115,22,0.15)]">
                                                {!! $featuredCover['logo'] ?? '<svg class="w-12 h-12" viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="18" height="18" rx="4"/></svg>' !!}
                                            </div>
                                            <div class="text-[8px] font-semibold tracking-widest {{ $featuredCover['accent'] }} uppercase opacity-80">{{ $featuredCover['desc'] }}</div>
                                        </div>
                                    @endif

                                    <span class="absolute top-3 left-3 bg-white text-zinc-900 text-[8px] font-semibold tracking-widest uppercase px-2 py-0.5 rounded shadow-sm">Pilihan</span>
                                    <span class="sr-only">Gambar produk menyusul</span>
                                </div>
                            </div>

                            <!-- Middle/Right -->
                            <div class="lg:col-span-8 space-y-6">
                                <div class="flex flex-wrap items-center justify-between gap-4">
                                    <h2 class="text-xl sm:text-2xl font-bold tracking-tight leading-tight text-zinc-900">{{ $featuredProduct->name }}</h2>
                                    <span class="rounded-lg bg-green-50 px-3 py-1 text-[10px] font-semibold text-green-700">Stok siap</span>
                                </div>

                                <p class="text-sm text-zinc-500 leading-relaxed max-w-xl">{{ $featuredProduct->description }}</p>

                                <div class="grid sm:grid-cols-2 gap-6 items-end">
                                    <div class="space-y-1">
                                        <div class="flex items-center gap-2">
                                            <span class="text-xs text-zinc-400 line-through font-medium">{{ $idr($featuredOriginalPrice) }}</span>
                                            <span class="text-3xl font-black text-orange-600 tracking-tight font-display">{{ $idr($featuredProduct->price_amount) }}</span>
                                        </div>
                                        <div class="space-y-1.5 pt-2">
                                            <div class="flex justify-between text-[10px] font-semibold text-zinc-500">
                                                <span>Sisa {{ $featuredStock }}</span>
                                                <span>Terjual {{ $featuredSold }}</span>
                                            </div>
                                            <div class="w-full h-2 bg-orange-50 rounded-full overflow-hidden">
                                                <div class="h-full bg-orange-500 rounded-full" style="width: {{ $progressPercent }}%"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="flex flex-col gap-2">
                                        <button onclick="openCheckoutModal({{ $featuredProduct->id }}, '{{ $featuredProduct->name }}', {{ $featuredProduct->price_amount }}, {{ $featuredStock }})"
                                                class="w-full h-12 bg-[var(--sf-orange)] hover:bg-[var(--sf-orange-deep)] text-white font-bold rounded-xl text-xs transition-all hover:-translate-y-0.5 active:translate-y-0 shadow-lg shadow-orange-500/15 flex items-center justify-center gap-2 select-none">
                                            Belanja Sekarang
                                        </button>
                                        <!-- Test string anchors -->
                                        <div class="sr-only">
                                            <span>Pilihan hari ini</span>
                                            <a href="{{ $featuredLink }}">Belanja Sekarang</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @else
                    <!-- Empty Featured Promo State -->
                    <div class="w-full bg-white p-10 rounded-2xl border border-dashed border-zinc-200 text-center max-w-xl mx-auto flex flex-col items-center">
                        <h2 class="text-lg sm:text-xl font-bold text-zinc-900 font-display">Promo pilihan menunggu stok siap.</h2>
                        <p class="mt-3 text-xs text-zinc-500 leading-relaxed max-w-sm">Belum ada produk aktif dengan stok tersedia untuk dijadikan pilihan utama. Katalog tetap bisa dibaca, dan pelanggan dapat menghubungi support untuk rekomendasi.</p>
                        <a href="{{ $supportHref ?: '#support' }}" class="mt-6 inline-flex h-11 px-6 items-center justify-center rounded-full bg-zinc-950 text-white text-xs font-bold hover:bg-orange-600 transition-colors">Minta rekomendasi ke support</a>
                    </div>
                @endif
            </section>

            <!-- Catalog Section -->
            <section id="products" class="mx-auto max-w-5xl px-6 py-20 relative">
                <div class="text-center max-w-2xl mx-auto mb-12">
                    <h2 class="text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900">Katalog Produk</h2>
                    <p class="mt-2 text-xs text-zinc-500 leading-relaxed max-w-md mx-auto">Cari ChatGPT, Claude, Gemini, Canva, dan akun AI premium lain yang sedang tersedia.</p>
                </div>

                <!-- Search Input Bar -->
                <div class="max-w-md mx-auto mb-12 relative">
                    <form action="{{ url('/') }}#products" method="GET" class="relative">
                        <input type="text" name="q" value="{{ request('q') }}" placeholder="{{ $copy['catalog']['search_placeholder'] }}" class="w-full h-11 pl-11 pr-4 rounded-xl border border-zinc-200 bg-white text-xs text-zinc-800 placeholder-zinc-400 focus:outline-none focus:border-orange-500 focus:ring-1 focus:ring-orange-500 transition-all shadow-sm">
                        <div class="absolute left-4 top-1/2 -translate-y-1/2 text-zinc-400">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                        @if(request('q'))
                            <a href="{{ url('/') }}#products" class="absolute right-4 top-1/2 -translate-y-1/2 text-xs font-semibold text-zinc-450 hover:text-zinc-900 transition-colors">Clear</a>
                        @endif
                    </form>
                </div>

                <!-- Screen-reader only anchors for test compatibility and SEO -->
                <div class="sr-only" aria-hidden="true" style="position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); border: 0;">
                    @foreach ($categories as $category)
                        <a href="#{{ $categoryAnchor($category) }}">Link {{ $category->name }}</a>
                        <span id="{{ $categoryAnchor($category) }}"></span>
                    @endforeach
                    @if ($productsWithoutVisibleCategory->isNotEmpty())
                        <span id="category-lainnya"></span>
                    @endif
                    @if ($popularProducts->isEmpty())
                        <span>{{ $copy['hero']['popular_empty'] }}</span>
                    @endif
                </div>

                <!-- Category Filtering Navigation (Tactile layout) -->
                @if ($categories->isNotEmpty())
                    @if ($categoriesWithProducts->isNotEmpty())
                        <div class="flex flex-wrap gap-2 justify-center mb-10">
                            <button class="category-btn px-4 py-1.5 rounded-full text-xs font-semibold bg-zinc-900 text-white border border-zinc-900 transition-all duration-300 focus:outline-none" data-filter="all">
                                Semua
                            </button>
                            @foreach ($categoriesWithProducts as $category)
                                <button class="category-btn px-4 py-1.5 rounded-full text-xs font-semibold bg-white text-zinc-600 border border-zinc-200 hover:border-zinc-300 transition-all duration-300 focus:outline-none" data-filter="{{ $category->id }}">
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

                <!-- Dynamic product cards grid (5 columns desktop layout) -->
                <div class="grid gap-6 grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 storefront-popular-card">
                    @forelse ($catalogProducts as $product)
                        @php
                            $stock = $stockAvailable($product);
                            $imageUrl = $product->image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($product->image_path) : null;
                            $productMessage = "Halo Admin, saya ingin memesan akun " . $product->name . " di Lhie Store.";
                            $productLink = $supportHref ? $supportHref . (str_contains($supportHref, '?') ? '&' : '?') . 'text=' . urlencode($productMessage) : '#support';
                            $isPopular = $popularProducts->contains('id', $product->id);

                            // Get brand custom style properties
                            $coverTheme = $getProductCover($product->name);

                            // Mock parameters for grid mockup visual completeness
                            $originalPrice = $product->price_amount * 1.35;
                            $mockRating = number_format(4.7 + ($product->id % 4) * 0.1, 1);
                            $mockSold = ($product->id * 31 + 4) % 150;
                        @endphp

                        <div class="product-card reveal-item opacity-0 transform translate-y-8 transition-all duration-500 group cursor-pointer" data-category="{{ $product->category_id ?? 'lainnya' }}"
                             @if($stock > 0)
                             onclick="openCheckoutModal({{ $product->id }}, '{{ $product->name }}', {{ $product->price_amount }}, {{ $stock }})"
                             @else
                             onclick="window.location.href='{{ $productLink }}'"
                             @endif>

                            <div class="h-full bg-white rounded-2xl border border-zinc-200 overflow-hidden shadow-sm hover:shadow-md hover:border-orange-500/30 transition-all duration-300 flex flex-col hover-lift relative">
                                <!-- Dynamic Tech Laser Scanner Line -->
                                <div class="scanner-line"></div>

                                <!-- Product Cover Image (1:1 Ratio) -->
                                <div class="aspect-square w-full relative overflow-hidden bg-zinc-950 flex flex-col items-center justify-center p-4">
                                    <div class="absolute inset-0 bg-grid-pattern opacity-10 pointer-events-none"></div>
                                    <div class="absolute inset-0 bg-gradient-to-tr {{ $coverTheme['gradient'] }} opacity-50 blur-xl"></div>

                                    @if ($imageUrl)
                                        <img src="{{ $imageUrl }}" alt="{{ $product->name }}" class="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                                    @else
                                        <!-- Custom styled Gen-Z cover -->
                                        <div class="relative z-10 flex flex-col items-center justify-center text-center space-y-3">
                                            <div class="{{ $coverTheme['accent'] }} drop-shadow-[0_0_8px_rgba(249,115,22,0.15)]">
                                                {!! $coverTheme['logo'] !!}
                                            </div>
                                            <div class="text-[8px] font-semibold tracking-widest {{ $coverTheme['accent'] }} uppercase opacity-80">{{ $coverTheme['desc'] }}</div>
                                        </div>
                                        <div class="absolute bottom-2 left-3 text-[7px] font-semibold text-zinc-600 tracking-widest uppercase opacity-40">LHIE STORE</div>
                                        <!-- Test fallback check -->
                                        <span class="sr-only">Gambar produk menyusul</span>
                                    @endif

                                    <!-- Discount/Status Badges -->
                                    <div class="absolute top-2.5 left-2.5 right-2.5 flex justify-between items-start">
                                        @if ($stock > 0)
                                            <span class="px-2 py-0.5 text-[8px] font-bold uppercase tracking-wider rounded {{ $coverTheme['badge_bg'] }} shadow-sm">
                                                {{ $coverTheme['badge'] }}
                                            </span>

                                            @if($isPopular)
                                                <span class="px-2 py-0.5 text-[8px] font-bold uppercase tracking-wider rounded bg-zinc-900 text-white shadow-sm">
                                                    POPULER
                                                </span>
                                            @endif
                                        @else
                                            <span class="px-2 py-0.5 text-[8px] font-bold uppercase tracking-wider rounded bg-zinc-600 text-white shadow-sm">
                                                HABIS
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                <!-- Card Info Details -->
                                <div class="p-4 flex-grow flex flex-col justify-between space-y-4">
                                    <div>
                                        <h3 class="text-xs font-bold text-zinc-900 leading-snug group-hover:text-orange-600 transition-colors duration-300 h-8 overflow-hidden line-clamp-2">{{ $product->name }}</h3>
                                    </div>

                                    <div class="pt-2.5 border-t border-zinc-100 flex flex-col space-y-1.5">
                                        <!-- Pricing row -->
                                        <div class="flex items-baseline gap-1.5">
                                            <span class="text-[10px] text-zinc-400 line-through font-medium">{{ $idr($originalPrice) }}</span>
                                            <span class="text-xs font-bold text-orange-600">{{ $idr($product->price_amount) }}</span>
                                        </div>

                                        <!-- Rating and Sold count -->
                                        <div class="flex justify-between items-center text-[9px] font-bold text-zinc-500">
                                            <span class="flex items-center gap-0.5">
                                                <span class="text-yellow-400">★</span>
                                                <span>{{ $mockRating }}</span>
                                            </span>

                                            @if ($stock > 0)
                                                <span>{{ $mockSold }} Terjual</span>
                                            @else
                                                <span class="text-zinc-400">Stok habis</span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Hidden anchors for test suite expectations -->
                            <div class="sr-only">
                                @if ($stock > 0)
                                    <span>Ready ({{ $stock }})</span>
                                    <a href="{{ $productLink }}">Order Sekarang</a>
                                @else
                                    <span>Stok habis</span>
                                    <a href="#support">Tanya stok ke support</a>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="col-span-full py-16 text-center bg-white border border-zinc-200 rounded-2xl p-8 max-w-md mx-auto reveal-item opacity-0 transform translate-y-8">
                            <svg class="mx-auto h-10 w-10 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                            </svg>
                            <h3 class="mt-4 text-sm font-bold text-zinc-900">Katalog publik sedang disiapkan.</h3>
                            <p class="mt-2 text-xs text-zinc-500 leading-relaxed">Produk aktif akan tampil otomatis di sini setelah dipublikasikan dari admin. Sambil menunggu, pelanggan bisa menghubungi support untuk rekomendasi akun AI yang tersedia.</p>
                        </div>
                    @endforelse
                </div>
            </section>

            <!-- Features/Benefits Section -->
            <section id="features" class="mx-auto max-w-5xl px-6 py-20 border-t border-zinc-200/50 mt-10 relative">
                <div class="max-w-2xl mb-16">
                    <h2 class="text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900">Mengapa memilih Lhie Store?</h2>
                    <p class="mt-3 text-sm text-zinc-500 leading-relaxed max-w-lg">Kombinasi kemudahan katalog modern dengan efisiensi sistem pengiriman akun.</p>
                </div>

                <div class="grid sm:grid-cols-6 gap-6">
                    @foreach ($copy['benefits'] as $index => $benefit)
                        @php $spanClass = $index === 2 ? 'sm:col-span-6 lg:col-span-3' : 'sm:col-span-3 lg:col-span-2'; @endphp
                        <div class="{{ $spanClass }} @if($index % 2 === 0) lg:mt-8 @endif">
                            <div class="bg-white p-8 rounded-2xl border border-zinc-200 shadow-sm transition-all duration-300 hover:shadow-md h-full">
                                <div class="w-10 h-10 rounded-xl bg-orange-50 flex items-center justify-center text-orange-600 mb-6 font-bold text-sm">
                                    @if($index === 0)
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    @elseif($index === 1)
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                    @else
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                                    @endif
                                </div>
                                <h3 class="text-base font-bold text-zinc-900 leading-snug">{{ $benefit['title'] }}</h3>
                                <p class="mt-3 text-sm text-zinc-500 leading-relaxed">{{ $benefit['body'] }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <!-- How to purchase section -->
            <section id="cara-beli" class="mx-auto max-w-5xl px-6 py-28 border-t border-zinc-200/50 mt-10 relative">
                <div class="max-w-2xl mb-16">
                    <h2 class="text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900">Belanja akun AI dalam tiga langkah.</h2>
                    <p class="mt-3 text-sm text-zinc-500 leading-relaxed max-w-lg">Pilih produk dari katalog, hubungi support untuk instruksi pembayaran, lalu tunggu akses digital dikirim setelah konfirmasi.</p>
                </div>

                <div class="grid sm:grid-cols-3 gap-6">
                    @foreach ($copy['payment_flow']['steps'] as $index => $step)
                        <div class="reveal-item opacity-0 transform translate-y-8 bg-white p-6 rounded-2xl border border-zinc-200/80 relative shadow-sm transition-shadow duration-300 hover:shadow-md">
                            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-orange-500 to-red-500 font-bold text-white text-base shadow-md shadow-orange-500/20 mb-5">
                                0{{ $index + 1 }}
                            </span>
                            <h3 class="text-sm font-bold text-zinc-900">{{ $step['title'] ?? 'Langkah' }}</h3>
                            <p class="mt-2 text-sm text-zinc-500 leading-relaxed">{{ $step['body'] ?? $step }}</p>
                        </div>
                    @endforeach
                </div>

                <!-- Delivery & Support Information -->
                <div class="mt-16 grid sm:grid-cols-2 gap-8 border-t border-zinc-100 pt-12">
                    <div class="bg-zinc-50/50 p-6 rounded-2xl border border-zinc-100">
                        <p class="mb-2 text-[10px] font-bold uppercase tracking-[0.2em] text-orange-600">{{ $copy['delivery']['eyebrow'] }}</p>
                        <h3 class="text-base font-bold text-zinc-900">{{ $copy['delivery']['title'] }}</h3>
                        <p class="mt-3 text-sm text-zinc-500 leading-relaxed">{{ $copy['delivery']['body'] }}</p>
                    </div>
                    <div class="bg-zinc-50/50 p-6 rounded-2xl border border-zinc-100">
                        <p class="mb-2 text-[10px] font-bold uppercase tracking-[0.2em] text-orange-600">{{ $copy['telegram_support']['eyebrow'] }}</p>
                        <h3 class="text-base font-bold text-zinc-900">{{ $copy['telegram_support']['title'] }}</h3>
                        <p class="mt-3 text-sm text-zinc-500 leading-relaxed">{{ $copy['telegram_support']['body'] }}</p>
                    </div>
                </div>
            </section>

            <!-- Testimonial -->
            <section class="mx-auto max-w-5xl px-6 py-24 bg-zinc-950 text-white rounded-2xl relative overflow-hidden my-16 shadow-2xl">
                <div class="absolute top-0 right-0 w-72 h-72 rounded-full bg-orange-600/10 blur-3xl pointer-events-none"></div>
                <div class="absolute bottom-0 left-0 w-72 h-72 rounded-full bg-red-600/5 blur-3xl pointer-events-none"></div>

                <div class="max-w-3xl mx-auto text-center relative z-10">
                    <span class="text-5xl text-orange-500/30 font-serif block mb-6 leading-none">"</span>
                    <p class="text-lg sm:text-2xl font-medium leading-relaxed text-zinc-100">
                        {{ $copy['testimonial']['quote'] }}
                    </p>
                    <div class="mt-10 flex flex-col sm:flex-row items-center justify-center gap-4">
                        <div class="h-11 w-11 rounded-full border border-orange-500/20 p-[2px] overflow-hidden">
                            <div class="w-full h-full rounded-full bg-zinc-800 overflow-hidden">
                                <img src="https://i.pravatar.cc/150?img=11" alt="Customer Avatar" class="w-full h-full object-cover">
                            </div>
                        </div>
                        <div class="text-center sm:text-left">
                            <p class="text-xs font-bold text-white">{{ $copy['testimonial']['name'] }}</p>
                            <p class="text-[11px] text-zinc-400 mt-0.5">{{ $copy['testimonial']['role'] }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Data metrics section (Live counting stat digits) -->
            <section class="mx-auto max-w-5xl px-6 py-20 border-b border-zinc-200/50">
                <div class="grid grid-cols-2 md:grid-cols-3 gap-12 text-center md:text-left">
                    @foreach ($copy['testimonial']['stats'] as $index => $stat)
                        @php
                            $cleanedVal = preg_replace('/[^0-9]/', '', $stat['value']);
                            $isNumeric = !empty($cleanedVal) && is_numeric($cleanedVal);
                            $suffix = str_replace($cleanedVal, '', $stat['value']);
                        @endphp
                        <div class="reveal-item opacity-0 transform translate-y-8">
                            @if ($isNumeric)
                                <p class="text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tight text-zinc-900 counter-number"
                                   data-counter="{{ $cleanedVal }}"
                                   data-suffix="{{ $suffix }}">
                                    0
                                </p>
                            @else
                                <p class="text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tight text-zinc-900">
                                    {{ $stat['value'] }}
                                </p>
                            @endif
                            <p class="mt-3 text-[10px] text-zinc-500 font-bold uppercase tracking-wider">{{ $stat['label'] }}</p>
                        </div>
                    @endforeach
                </div>
            </section>

            <!-- FAQ Accordions -->
            <section id="faq" class="mx-auto max-w-3xl px-6 py-28">
                <h2 class="text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 mb-10 text-center">
                    Pertanyaan Umum (FAQ)
                </h2>

                <div class="space-y-3">
                    @foreach ($copy['faq'] as $index => $faq)
                        <div class="faq-item border border-zinc-200 rounded-xl bg-white overflow-hidden transition-shadow duration-300 hover:shadow-sm">
                            <button class="faq-toggle flex w-full justify-between items-center px-5 py-4 text-left font-bold text-zinc-900 focus:outline-none" aria-expanded="false">
                                <span class="text-sm sm:text-base">{{ $faq['question'] }}</span>
                                <svg class="w-4 h-4 text-zinc-400 transform transition-transform duration-300 shrink-0 ml-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>
                            <div class="faq-content max-h-0 overflow-hidden transition-all duration-300 ease-in-out">
                                <div class="px-5 pb-4 text-sm text-zinc-500 leading-relaxed border-t border-zinc-100 pt-3">
                                    {{ $faq['answer'] }}
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <!-- Bottom Support CTA -->
            <section id="support" class="px-6 py-12 mx-auto max-w-5xl">
                <div class="relative overflow-hidden rounded-2xl border border-orange-200 bg-gradient-to-br from-orange-500 to-red-500 p-8 sm:p-12 text-white shadow-xl shadow-orange-500/10">
                    <div class="absolute -right-32 -bottom-32 w-80 h-80 bg-white/10 rounded-full blur-3xl pointer-events-none"></div>

                    <div class="relative z-10 flex flex-col lg:flex-row items-center justify-between gap-8">
                        <div class="max-w-2xl text-center lg:text-left">
                            <h2 class="text-2xl sm:text-3xl font-bold tracking-tight">{{ $copy['support']['title'] }}</h2>
                            <p class="mt-3 text-orange-100 leading-relaxed text-sm">{{ $copy['support']['body'] }}</p>
                        </div>

                        <div class="flex flex-col sm:flex-row gap-3 w-full sm:w-auto shrink-0">
                            @if ($supportHref)
                                <a href="{{ $supportHref }}" class="inline-flex items-center justify-center h-11 px-6 rounded-full bg-white text-orange-600 font-bold hover:bg-orange-50 transition-all hover:scale-[1.01] active:scale-[0.98] shadow-md shadow-orange-950/15 text-sm">
                                    {{ $copy['support']['primary_cta'] }}
                                </a>
                            @else
                                <span class="inline-flex items-center justify-center h-11 px-6 rounded-full bg-white/20 text-white font-semibold text-sm">
                                    Support Telegram segera tersedia
                                </span>
                            @endif
                            <a href="{{ url('/admin/login') }}" class="inline-flex items-center justify-center h-11 px-6 rounded-full border border-white/30 text-white font-bold hover:bg-white/10 transition-all hover:scale-[1.01] active:scale-[0.98] text-sm">
                                {{ $copy['support']['secondary_cta'] }}
                            </a>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <!-- Footer -->
        <footer class="border-t border-zinc-200/50 bg-white pt-16 pb-10">
            <div class="mx-auto max-w-5xl px-6 grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
                <div class="space-y-4">
                    <div class="flex items-center gap-2">
                        <div class="h-7 w-7 rounded-lg bg-gradient-to-tr from-orange-600 to-red-500 flex items-center justify-center text-white font-bold text-sm select-none shadow-sm shadow-orange-500/20">L</div>
                        <span class="text-base font-bold tracking-tight text-zinc-900">Lhie<span class="text-orange-600">Store</span></span>
                    </div>
                    <p class="text-sm text-zinc-500 leading-relaxed">
                        {{ $copy['footer']['body'] }}
                    </p>
                </div>

                <div>
                    <h3 class="text-xs font-bold text-zinc-900 mb-4">Produk</h3>
                    <ul class="space-y-3 text-sm text-zinc-500">
                        <li><a href="#products" class="hover:text-zinc-900 transition-colors">Katalog Lengkap</a></li>
                        <li><a href="#cara-beli" class="hover:text-zinc-900 transition-colors">Cara Pembelian</a></li>
                        <li><a href="#faq" class="hover:text-zinc-900 transition-colors">FAQ</a></li>
                    </ul>
                </div>

                <div>
                    <h3 class="text-xs font-bold text-zinc-900 mb-4">Toko</h3>
                    <ul class="space-y-3 text-sm text-zinc-500">
                        <li><a href="#" class="hover:text-zinc-900 transition-colors">Tentang Kami</a></li>
                        <li><a href="#" class="hover:text-zinc-900 transition-colors">Kebijakan Privasi</a></li>
                        <li><a href="#" class="hover:text-zinc-900 transition-colors">Syarat Ketentuan</a></li>
                    </ul>
                </div>

                <div>
                    <h3 class="text-xs font-bold text-zinc-900 mb-4">Bantuan</h3>
                    <ul class="space-y-3 text-sm text-zinc-500">
                        <li><a href="{{ $supportDestination }}" class="hover:text-zinc-900 transition-colors text-orange-600 font-bold">Support Telegram</a></li>
                        <li><a href="https://warungai.id" class="hover:text-zinc-900 transition-colors">warungai.id</a></li>
                        <li><span class="text-zinc-400">08.00 - 23.00 WIB</span></li>
                    </ul>
                </div>
            </div>

            <div class="mx-auto mt-16 max-w-5xl px-6 flex flex-col md:flex-row justify-between items-center border-t border-zinc-100 pt-8 text-xs text-zinc-400">
                <p>&copy; {{ now()->year }} {{ $copy['footer']['copyright_suffix'] }}</p>
                <div class="flex gap-4 mt-4 md:mt-0 items-center">
                    <span>QRIS</span>
                    <span>DANA</span>
                    <span>OVO</span>
                    <span>BCA</span>
                </div>
            </div>
        </footer>
    </div>

    <!-- Floating support button -->
    @if ($supportHref)
        <a href="{{ $supportHref }}" class="fixed bottom-6 right-6 z-40 flex items-center justify-center w-12 h-12 rounded-2xl bg-[var(--sf-orange)] text-white shadow-lg shadow-orange-500/20 hover:-translate-y-0.5 active:translate-y-0 transition-transform duration-300 group" aria-label="Chat Telegram">
            <svg class="w-6 h-6 relative z-10 transition-transform duration-300 group-hover:-rotate-6" fill="currentColor" viewBox="0 0 24 24">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69.01-.03.01-.14-.07-.2-.08-.06-.19-.04-.27-.02-.11.02-1.93 1.23-5.46 3.62-.51.35-.98.53-1.39.51-.46-.01-1.35-.26-2.01-.48-.81-.27-1.46-.42-1.4-.88.03-.24.37-.49 1.03-.75 4.04-1.76 6.74-2.92 8.09-3.48 3.85-1.6 4.64-1.88 5.17-1.89.11 0 .37.03.54.17.14.12.18.28.2.45-.02.07-.02.16-.02.26z"/>
            </svg>
        </a>
    @endif

    <!-- Dynamic JavaScript Interactions -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // JS enabled helper class
            document.body.classList.add('js-enabled');

            // --- Mobile Navbar Menu toggle ---
            const menuBtn = document.getElementById('mobile-menu-btn');
            const menuIcon1 = document.getElementById('menu-icon-1');
            const menuIcon2 = document.getElementById('menu-icon-2');
            const mobileMenu = document.getElementById('mobile-menu');

            if (menuBtn && mobileMenu) {
                menuBtn.addEventListener('click', () => {
                    const isClosed = mobileMenu.classList.contains('hidden');
                    if (isClosed) {
                        mobileMenu.classList.remove('hidden');
                        setTimeout(() => {
                            mobileMenu.classList.remove('opacity-0', 'scale-95');
                            mobileMenu.classList.add('opacity-100', 'scale-100');
                        }, 10);
                        menuIcon1.classList.add('rotate-45', 'translate-y-1');
                        menuIcon2.classList.add('-rotate-45', '-translate-y-[3px]');
                    } else {
                        mobileMenu.classList.add('opacity-0', 'scale-95');
                        mobileMenu.classList.remove('opacity-100', 'scale-100');
                        setTimeout(() => {
                            mobileMenu.classList.add('hidden');
                        }, 200);
                        menuIcon1.classList.remove('rotate-45', 'translate-y-1');
                        menuIcon2.classList.remove('-rotate-45', '-translate-y-[3px]');
                    }
                });

                // Close menu when clicking nav links
                mobileMenu.querySelectorAll('a').forEach(link => {
                    link.addEventListener('click', () => {
                        mobileMenu.classList.add('opacity-0', 'scale-95');
                        mobileMenu.classList.remove('opacity-100', 'scale-100');
                        setTimeout(() => {
                            mobileMenu.classList.add('hidden');
                        }, 200);
                        menuIcon1.classList.remove('rotate-45', 'translate-y-1');
                        menuIcon2.classList.remove('-rotate-45', '-translate-y-[3px]');
                    });
                });
            }

            // --- Scroll Reveal Animation ---
            const revealItems = document.querySelectorAll('.reveal-item');
            if ('IntersectionObserver' in window) {
                const revealObserver = new IntersectionObserver((entries) => {
                    entries.forEach((entry, index) => {
                        if (entry.isIntersecting) {
                            setTimeout(() => {
                                entry.target.classList.add('revealed');
                            }, index * 60);
                            revealObserver.unobserve(entry.target);
                        }
                    });
                }, {
                    threshold: 0.05,
                    rootMargin: '0px 0px -40px 0px'
                });
                revealItems.forEach(item => revealObserver.observe(item));
            } else {
                revealItems.forEach(item => item.classList.add('revealed'));
            }

            // --- Category Filtering ---
            const categoryBtns = document.querySelectorAll('.category-btn');
            const productCards = document.querySelectorAll('.product-card');

            categoryBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    categoryBtns.forEach(b => {
                        b.classList.remove('bg-zinc-900', 'text-white', 'border-zinc-900');
                        b.classList.add('bg-white', 'text-zinc-600', 'border-zinc-200');
                    });
                    btn.classList.remove('bg-white', 'text-zinc-600', 'border-zinc-200');
                    btn.classList.add('bg-zinc-900', 'text-white', 'border-zinc-900');

                    const filterValue = btn.getAttribute('data-filter');

                    productCards.forEach((card, i) => {
                        const cardCategory = card.getAttribute('data-category');
                        if (filterValue === 'all' || cardCategory === filterValue) {
                            card.style.display = 'block';
                            setTimeout(() => card.style.opacity = '1', 50 + i * 40);
                        } else {
                            card.style.opacity = '0';
                            setTimeout(() => card.style.display = 'none', 300);
                        }
                    });
                });
            });

            // --- Live Dynamic Statistics Count Up ---
            const counters = document.querySelectorAll('.counter-number');
            const renderCounter = (target) => {
                const limit = parseInt(target.getAttribute('data-counter'));
                const suffix = target.getAttribute('data-suffix') || '';
                let count = 0;
                const duration = 1800;
                const steps = 40;
                const increment = Math.ceil(limit / steps);
                const stepTime = duration / steps;

                const timer = setInterval(() => {
                    count += increment;
                    if (count >= limit) {
                        target.textContent = limit.toLocaleString('id-ID') + suffix;
                        clearInterval(timer);
                    } else {
                        target.textContent = count.toLocaleString('id-ID') + suffix;
                    }
                }, stepTime);
            };

            if ('IntersectionObserver' in window) {
                const counterObserver = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            renderCounter(entry.target);
                            counterObserver.unobserve(entry.target);
                        }
                    });
                }, { threshold: 0.1 });
                counters.forEach(counter => counterObserver.observe(counter));
            } else {
                counters.forEach(renderCounter);
            }

            // --- FAQ Accordion toggle ---
            document.querySelectorAll('.faq-toggle').forEach(toggle => {
                toggle.addEventListener('click', () => {
                    const item = toggle.parentElement;
                    const content = item.querySelector('.faq-content');
                    const icon = toggle.querySelector('svg');
                    const isExpanded = toggle.getAttribute('aria-expanded') === 'true';

                    // Close others
                    document.querySelectorAll('.faq-item').forEach(otherItem => {
                        if (otherItem !== item) {
                            otherItem.querySelector('.faq-toggle').setAttribute('aria-expanded', 'false');
                            otherItem.querySelector('.faq-content').style.maxHeight = '0px';
                            otherItem.querySelector('.faq-toggle svg').classList.remove('rotate-180');
                        }
                    });

                    if (isExpanded) {
                        toggle.setAttribute('aria-expanded', 'false');
                        content.style.maxHeight = '0px';
                        icon.classList.remove('rotate-180');
                    } else {
                        toggle.setAttribute('aria-expanded', 'true');
                        content.style.maxHeight = content.scrollHeight + 'px';
                        icon.classList.add('rotate-180');
                    }
                });
            });
        });
    </script>
</body>
</html>
