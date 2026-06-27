@php
    $brand = 'Lhie Store';
    $copy = config('storefront');
    $pageTitle = $product->name.' | '.$brand;
    $pageDescription = \Illuminate\Support\Str::limit(trim(strip_tags((string) $product->description)), 155);
    $canonicalUrl = route('storefront.products.show', $product);
    $telegramUsername = config('services.telegram.bot_username', 'lhiestore_bot');
    $supportUsername = config('services.telegram.admin_username');
    $supportHref = config('services.telegram.admin_contact_url') ?: ($supportUsername ? 'https://t.me/'.ltrim((string) $supportUsername, '@') : null);
    $telegramLink = 'https://t.me/'.ltrim((string) $telegramUsername, '@').'?start=product_'.$product->id;
    $stockAvailable = max(0, (int) $product->stock - (int) $product->reserved_stock);
    $quantityMax = $stockAvailable;
    $priceLabel = fn () => 'Rp'.number_format((int) $product->price_amount, 0, ',', '.');
    $category = $product->category;
    $getProductCover = function (string $name): array {
        $nameLower = strtolower($name);

        $theme = [
            'bg' => 'from-zinc-900 to-zinc-950',
            'gradient' => 'from-orange-500/25 to-red-500/10',
            'accent' => 'text-orange-500',
            'badge' => 'READY',
            'badge_bg' => 'bg-orange-500 text-white',
            'desc' => 'PREMIUM DIGITAL',
            'logo' => '<svg class="w-20 h-20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.25"><rect x="3" y="3" width="18" height="18" rx="4"/><path d="M12 8v8m-4-4h8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        ];

        if (str_contains($nameLower, 'chat gpt') || str_contains($nameLower, 'chatgpt')) {
            $theme = [
                'bg' => 'from-[#0B1510] to-[#0A261A]',
                'gradient' => 'from-[#10B981]/25 to-[#059669]/10',
                'accent' => 'text-[#10B981]',
                'badge' => 'CHATGPT',
                'badge_bg' => 'bg-emerald-600 text-white',
                'desc' => 'CHAT GPT PLUS',
                'logo' => '<svg class="w-20 h-20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.25"><circle cx="12" cy="12" r="10"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1-4-10z" stroke-dasharray="2 2"/><path d="M12 12h.01" stroke-width="3" stroke-linecap="round"/></svg>',
            ];
        } elseif (str_contains($nameLower, 'gemini') || str_contains($nameLower, 'google')) {
            $theme = [
                'bg' => 'from-[#081226] to-[#040A17]',
                'gradient' => 'from-[#3B82F6]/30 to-transparent',
                'accent' => 'text-[#60A5FA]',
                'badge' => 'GEMINI',
                'badge_bg' => 'bg-blue-600 text-white',
                'desc' => 'GOOGLE GEMINI',
                'logo' => '<svg class="w-20 h-20" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2c0 5.523 4.477 10 10 10-5.523 0-10 4.477-10 10 0-5.523-4.477-10-10-10 5.523 0 10-4.477 10-10z"/></svg>',
            ];
        } elseif (str_contains($nameLower, 'canva')) {
            $theme = [
                'bg' => 'from-[#0F1E36] to-[#081324]',
                'gradient' => 'from-[#06B6D4]/30 to-[#3B82F6]/10',
                'accent' => 'text-[#06B6D4]',
                'badge' => 'CANVA',
                'badge_bg' => 'bg-cyan-600 text-white',
                'desc' => 'CANVA PREMIUM',
                'logo' => '<svg class="w-20 h-20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.25"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="M8 12c2-3 6-3 8 0" stroke-linecap="round"/></svg>',
            ];
        }

        return $theme;
    };
    $cover = $getProductCover($product->name);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $pageDescription }}">
    <link rel="canonical" href="{{ $canonicalUrl }}">
    <meta property="og:type" content="product">
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:ital,wght@0,400..700;1,400..700&family=Plus+Jakarta+Sans:ital,wght@0,300..800;1,300..800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Instrument Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background-color: #fcfcfc; color: #09090b; }
        h1, h2, h3, h4, .font-display { font-family: 'Plus Jakarta Sans', sans-serif; }
        .quantity-stepper button { transition: transform .18s ease, background-color .18s ease, color .18s ease; }
        .quantity-stepper button:hover { transform: translateY(-1px); }
    </style>
</head>
<body class="overflow-x-hidden antialiased selection:bg-[var(--sf-orange)] selection:text-white relative text-[var(--sf-ink)]">
    <div class="storefront-shell storefront-grid relative min-h-screen z-10 overflow-x-hidden">
        <span aria-hidden="true" class="storefront-wave motion-safe:animate-storefront-float motion-reduce:animate-none left-[-5rem] top-28 h-48 w-48 sm:h-64 sm:w-64"></span>
        <span aria-hidden="true" class="storefront-wave storefront-wave-soft motion-safe:animate-storefront-float motion-reduce:animate-none right-[-7rem] top-[34rem] h-56 w-56 sm:h-80 sm:w-80"></span>

        <header class="sticky top-4 z-50 mx-auto w-[calc(100%-2rem)] max-w-6xl rounded-2xl bg-white/90 backdrop-blur-xl border border-[var(--sf-border)] shadow-sm shadow-orange-200/30">
            <div class="flex h-14 items-center justify-between px-6">
                <a href="{{ route('storefront.home') }}" class="flex items-center gap-2">
                    <div class="h-7 w-7 rounded-lg bg-[var(--sf-orange)] flex items-center justify-center text-white font-black text-sm select-none shadow-sm shadow-orange-500/20">L</div>
                    <span class="text-sm font-bold tracking-tight text-[var(--sf-ink)]">{{ $brand }}</span>
                </a>

                <a href="{{ route('storefront.home') }}" class="inline-flex items-center rounded-xl bg-[var(--sf-ink)] px-4 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-[var(--sf-orange-deep)]">
                    Kembali ke katalog
                </a>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-6 pb-24 pt-8 sm:pt-10">
            <nav class="text-xs font-semibold text-zinc-500">
                <a href="{{ route('storefront.home') }}" class="hover:text-zinc-900 transition-colors">Beranda</a>
                <span class="mx-2">/</span>
                <span class="text-zinc-900">Detail produk</span>
            </nav>

            <section class="mt-6 grid gap-8 lg:grid-cols-12 lg:items-start">
                <div class="lg:col-span-7">
                    <div class="relative overflow-hidden rounded-[2rem] border border-[var(--sf-border)] bg-white p-5 shadow-xl shadow-orange-200/20">
                        <div class="absolute inset-0 bg-gradient-to-br from-white via-orange-50/60 to-orange-100/40 pointer-events-none"></div>
                        <div class="relative overflow-hidden rounded-[1.6rem] border border-white/60 bg-zinc-950 aspect-[4/3]">
                            <div class="absolute inset-0 bg-grid-pattern opacity-[0.08] pointer-events-none"></div>
                            <div class="absolute inset-0 bg-gradient-to-tr {{ $cover['gradient'] }} opacity-70 blur-2xl"></div>

                            @if ($product->image_path)
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($product->image_path) }}" alt="{{ $product->name }}" class="absolute inset-0 h-full w-full object-cover">
                            @else
                                <div class="absolute inset-0 flex items-center justify-center p-8 text-center">
                                    <div class="space-y-6">
                                        <div class="mx-auto flex h-28 w-28 items-center justify-center rounded-[2rem] border border-white/10 bg-white/5 text-white shadow-2xl shadow-black/20 {{ $cover['accent'] }}">
                                            {!! $cover['logo'] !!}
                                        </div>
                                        <div>
                                            <p class="text-[10px] font-bold uppercase tracking-[0.32em] text-white/70">{{ $cover['desc'] }}</p>
                                            <h1 class="mt-3 text-3xl font-black tracking-tight text-white sm:text-4xl">{{ $product->name }}</h1>
                                        </div>
                                    </div>
                                </div>
                            @endif

                            <div class="absolute left-5 top-5 flex flex-wrap gap-2">
                                <span class="rounded-full bg-white/90 px-3 py-1 text-[10px] font-bold uppercase tracking-widest text-zinc-900 shadow-sm">Detail produk</span>
                                <span class="rounded-full {{ $stockAvailable > 0 ? 'bg-emerald-500/90 text-white' : 'bg-amber-500/90 text-white' }} px-3 py-1 text-[10px] font-bold uppercase tracking-widest shadow-sm">
                                    {{ $stockAvailable > 0 ? 'Stok tersedia' : 'Stok habis' }}
                                </span>
                            </div>

                            <div class="absolute bottom-5 left-5 right-5 flex items-end justify-between gap-4">
                                <div class="max-w-[70%] rounded-2xl bg-white/90 p-3 backdrop-blur-sm shadow-lg">
                                    <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-zinc-500">{{ $category?->name ?: 'Produk publik' }}</p>
                                    <p class="mt-1 text-sm font-bold text-zinc-900">{{ $product->name }}</p>
                                </div>
                                <div class="rounded-2xl bg-zinc-950/90 px-4 py-3 text-right text-white shadow-lg backdrop-blur-sm">
                                    <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-white/60">Harga</p>
                                    <p class="mt-1 text-lg font-black tracking-tight text-white">{{ $priceLabel() }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-5 lg:sticky lg:top-24">
                    <div class="rounded-[2rem] border border-[var(--sf-border)] bg-white p-6 shadow-xl shadow-orange-200/15">
                        @if ($category)
                            <p class="text-[10px] font-bold uppercase tracking-[0.24em] text-orange-700">{{ $category->name }}</p>
                        @endif

                        <div class="mt-3 flex items-start justify-between gap-4">
                            <h1 class="text-3xl font-black leading-tight tracking-tight text-[var(--sf-ink)] sm:text-4xl">{{ $product->name }}</h1>
                        </div>

                        <p class="mt-5 text-base leading-7 text-[var(--sf-muted)]">{{ $product->description }}</p>

                        <div class="mt-6 grid grid-cols-2 gap-3">
                            <div class="rounded-2xl bg-orange-50 p-4">
                                <p class="text-[10px] font-bold uppercase tracking-[0.24em] text-orange-700">Harga</p>
                                <p class="mt-2 text-2xl font-black tracking-tight text-[var(--sf-ink)]">{{ $priceLabel() }}</p>
                            </div>
                            <div class="rounded-2xl bg-zinc-50 p-4">
                                <p class="text-[10px] font-bold uppercase tracking-[0.24em] text-zinc-500">Sisa stok</p>
                                <p class="mt-2 text-2xl font-black tracking-tight text-[var(--sf-ink)]">{{ $stockAvailable }}</p>
                            </div>
                        </div>

                        <div class="mt-6 rounded-2xl border border-[var(--sf-border)] bg-[var(--sf-paper)] p-4">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.22em] text-zinc-500">Jumlah</p>
                                    <p class="mt-1 text-sm text-zinc-500">Pilih jumlah untuk dilihat di checkout Telegram.</p>
                                </div>
                                <span class="rounded-full bg-white px-3 py-1 text-[10px] font-bold uppercase tracking-widest text-zinc-700 shadow-sm">Maks. {{ $quantityMax }}</span>
                            </div>

                            <div class="quantity-stepper mt-4 inline-flex items-center overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm" data-quantity-stepper data-max="{{ $quantityMax }}">
                                <button type="button" class="h-11 w-11 text-lg font-bold text-zinc-500 hover:bg-zinc-50" data-quantity-decrease aria-label="Kurangi jumlah">−</button>
                                <div class="min-w-14 px-4 text-center">
                                    <span class="text-lg font-black text-zinc-900" data-quantity-value>{{ $stockAvailable > 0 ? 1 : 0 }}</span>
                                </div>
                                <button type="button" class="h-11 w-11 text-lg font-bold text-zinc-500 hover:bg-zinc-50" data-quantity-increase aria-label="Tambah jumlah">+</button>
                            </div>
                        </div>

                        <div class="mt-6 rounded-2xl border border-zinc-200 bg-zinc-950 p-5 text-white shadow-lg shadow-zinc-900/10">
                            <p class="text-[10px] font-bold uppercase tracking-[0.24em] text-orange-300">Checkout Telegram</p>
                            <p class="mt-2 text-sm leading-6 text-zinc-300">Kamu akan diarahkan ke bot Telegram untuk checkout.</p>

                            @if ($stockAvailable > 0)
                                <a href="{{ $telegramLink }}" class="mt-4 inline-flex h-12 w-full items-center justify-center rounded-xl bg-[var(--sf-orange)] px-5 text-sm font-bold text-white transition-all hover:-translate-y-0.5 hover:bg-[var(--sf-orange-deep)]">
                                    Beli via Telegram
                                </a>
                            @else
                                <button type="button" disabled class="mt-4 inline-flex h-12 w-full cursor-not-allowed items-center justify-center rounded-xl bg-zinc-700 px-5 text-sm font-bold text-zinc-300">
                                    Stok habis
                                </button>
                                @if ($supportHref)
                                    <a href="{{ $supportHref }}" class="mt-3 inline-flex h-12 w-full items-center justify-center rounded-xl border border-white/10 bg-white/5 px-5 text-sm font-bold text-white transition-colors hover:bg-white/10">
                                        Tanya support Telegram
                                    </a>
                                @endif
                            @endif
                        </div>

                        <div class="mt-5 grid gap-3 sm:grid-cols-3">
                            <div class="rounded-2xl border border-zinc-100 bg-white p-4">
                                <p class="text-[10px] font-bold uppercase tracking-[0.22em] text-zinc-400">Info</p>
                                <p class="mt-2 text-sm font-semibold text-zinc-900">Tanpa reservasi landing</p>
                            </div>
                            <div class="rounded-2xl border border-zinc-100 bg-white p-4">
                                <p class="text-[10px] font-bold uppercase tracking-[0.22em] text-zinc-400">Alur</p>
                                <p class="mt-2 text-sm font-semibold text-zinc-900">Telegram untuk checkout</p>
                            </div>
                            <div class="rounded-2xl border border-zinc-100 bg-white p-4">
                                <p class="text-[10px] font-bold uppercase tracking-[0.22em] text-zinc-400">Stok</p>
                                <p class="mt-2 text-sm font-semibold text-zinc-900">{{ $stockAvailable > 0 ? 'Siap dipilih' : 'Tidak tersedia' }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <script>
        document.querySelectorAll('[data-quantity-stepper]').forEach((root) => {
            const value = root.querySelector('[data-quantity-value]');
            const decrease = root.querySelector('[data-quantity-decrease]');
            const increase = root.querySelector('[data-quantity-increase]');
            const max = Number(root.dataset.max || '1');
            let current = Number(value?.textContent || '1');

            if (max <= 0) {
                if (value) value.textContent = '0';
                decrease.disabled = true;
                increase.disabled = true;
                decrease.classList.add('opacity-40');
                increase.classList.add('opacity-40');
                return;
            }

            const render = () => {
                if (value) value.textContent = String(current);
                decrease.disabled = current <= 1;
                increase.disabled = current >= max;
                decrease.classList.toggle('opacity-40', decrease.disabled);
                increase.classList.toggle('opacity-40', increase.disabled);
            };

            decrease?.addEventListener('click', () => {
                current = Math.max(1, current - 1);
                render();
            });

            increase?.addEventListener('click', () => {
                current = Math.min(max, current + 1);
                render();
            });

            render();
        });
    </script>
</body>
</html>
