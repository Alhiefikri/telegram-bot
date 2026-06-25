@php
    $brand = config('app.name', 'Telegram Bot Store');
    $pageTitle = $brand.' - Toko Produk Digital Instan';
    $pageDescription = 'Toko produk digital dengan katalog kurasi, pembayaran jelas, dan dukungan Telegram untuk bantuan order.';
    $canonicalUrl = url('/');
    $telegramUsername = config('services.telegram.admin_username');
    $supportHref = config('services.telegram.admin_contact_url') ?: ($telegramUsername ? 'https://t.me/'.ltrim($telegramUsername, '@') : null);
    $benefits = [
        ['title' => 'Produk digital siap pakai', 'body' => 'Katalog difokuskan pada kebutuhan produktivitas, akun digital, dan akses layanan yang praktis.'],
        ['title' => 'Pembayaran jelas', 'body' => 'Instruksi pembayaran ditampilkan sebelum konfirmasi sehingga pelanggan tahu langkah berikutnya.'],
        ['title' => 'Bantuan lewat Telegram', 'body' => 'Support order tetap dekat dengan kanal Telegram yang sudah dipakai operasional toko.'],
    ];
    $steps = [
        'Pilih produk dari kategori yang tersedia.',
        'Ikuti instruksi checkout atau hubungi support jika alur pembelian belum tersedia.',
        'Produk digital dikirim setelah pembayaran dan stok tervalidasi.',
    ];
    $faqs = [
        ['question' => 'Apakah produk langsung dikirim?', 'answer' => 'Produk digital dikirim setelah pembayaran terkonfirmasi dan stok tersedia.'],
        ['question' => 'Bagaimana jika produk belum muncul di katalog?', 'answer' => 'Katalog publik sedang dibuka bertahap. Hubungi support untuk rekomendasi atau permintaan khusus.'],
        ['question' => 'Apakah admin panel masih tersedia?', 'answer' => 'Ya. Operator tetap bisa masuk melalui tautan Admin tanpa mengganggu pengalaman pelanggan.'],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
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
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="{{ $pageTitle }}">
    <meta name="twitter:description" content="{{ $pageDescription }}">
    @fonts
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="bg-slate-950 text-slate-100 antialiased">
    <div class="min-h-screen overflow-hidden bg-[radial-gradient(circle_at_top_left,_rgba(34,211,238,0.18),_transparent_32rem),linear-gradient(180deg,_#020617_0%,_#0f172a_52%,_#f8fafc_52%,_#f8fafc_100%)]">
        <header class="mx-auto flex w-full max-w-7xl items-center justify-between px-5 py-5 sm:px-8 lg:px-10">
            <a href="{{ $canonicalUrl }}" class="flex min-h-11 items-center gap-3 rounded-full focus:outline-none focus:ring-2 focus:ring-cyan-300">
                <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-cyan-300 font-black text-slate-950">TB</span>
                <span class="text-sm font-semibold tracking-wide text-white sm:text-base">{{ $brand }}</span>
            </a>
            <nav aria-label="Navigasi utama" class="hidden items-center gap-6 text-sm font-medium text-slate-300 md:flex">
                <a href="#products" class="hover:text-white">Produk</a>
                <a href="#benefits" class="hover:text-white">Cara kerja</a>
                <a href="#faq" class="hover:text-white">FAQ</a>
                <a href="#support" class="hover:text-white">Support</a>
            </nav>
            <a href="{{ url('/admin/login') }}" class="inline-flex min-h-11 items-center rounded-full border border-white/20 px-4 text-sm font-semibold text-white transition hover:border-cyan-300 hover:text-cyan-200 focus:outline-none focus:ring-2 focus:ring-cyan-300">
                Admin
            </a>
        </header>

        <main>
            <section class="mx-auto grid w-full max-w-7xl gap-10 px-5 pb-16 pt-10 sm:px-8 lg:grid-cols-[1.06fr_0.94fr] lg:px-10 lg:pb-24 lg:pt-16">
                <div class="max-w-3xl">
                    <p class="mb-5 inline-flex rounded-full border border-cyan-300/30 bg-cyan-300/10 px-4 py-2 text-sm font-semibold text-cyan-100">Storefront publik pertama untuk produk digital pilihan</p>
                    <h1 class="text-4xl font-black tracking-tight text-white sm:text-5xl lg:text-6xl">Beli produk digital dengan alur sederhana dan support yang jelas.</h1>
                    <p class="mt-6 max-w-2xl text-lg leading-8 text-slate-300">{{ $pageDescription }} Jelajahi katalog, pahami cara order, lalu lanjutkan ke kanal pembelian yang tersedia.</p>
                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <a href="#products" class="inline-flex min-h-12 items-center justify-center rounded-full bg-cyan-300 px-6 text-base font-bold text-slate-950 shadow-lg shadow-cyan-950/30 transition hover:bg-cyan-200 focus:outline-none focus:ring-2 focus:ring-cyan-100">Lihat produk</a>
                        <a href="#support" class="inline-flex min-h-12 items-center justify-center rounded-full border border-white/20 px-6 text-base font-bold text-white transition hover:border-white/50 focus:outline-none focus:ring-2 focus:ring-cyan-100">Butuh bantuan?</a>
                    </div>
                </div>
                <div class="rounded-[2rem] border border-white/10 bg-white/10 p-4 shadow-2xl shadow-slate-950/40 backdrop-blur">
                    <div class="rounded-[1.5rem] bg-slate-950/80 p-6">
                        <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-200">Preview katalog</p>
                        <div class="mt-5 space-y-3">
                            @forelse ($categories as $category)
                                <a href="#products" class="flex min-h-14 items-center justify-between rounded-2xl border border-white/10 bg-white/5 px-4 text-left transition hover:border-cyan-300/50">
                                    <span>
                                        <span class="block font-semibold text-white">{{ $category->name }}</span>
                                        @if ($category->description)
                                            <span class="block text-sm text-slate-400">{{ $category->description }}</span>
                                        @endif
                                    </span>
                                    <span class="text-cyan-200">→</span>
                                </a>
                            @empty
                                <div class="rounded-2xl border border-dashed border-cyan-300/40 bg-cyan-300/10 p-5">
                                    <p class="font-semibold text-white">Kategori akan segera hadir.</p>
                                    <p class="mt-2 text-sm leading-6 text-slate-300">Tim toko sedang menyiapkan kategori publik pertama agar katalog mudah dijelajahi.</p>
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </section>

            <section id="products" class="bg-slate-50 px-5 py-16 text-slate-950 sm:px-8 lg:px-10 lg:py-24">
                <div class="mx-auto max-w-7xl">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-bold uppercase tracking-[0.2em] text-cyan-700">Katalog</p>
                            <h2 class="mt-3 text-3xl font-black tracking-tight sm:text-4xl">Produk digital pilihan</h2>
                        </div>
                        <a href="#support" class="inline-flex min-h-11 items-center rounded-full border border-slate-300 px-5 text-sm font-bold text-slate-700 hover:border-slate-950 hover:text-slate-950">Tanya stok</a>
                    </div>

                    @if ($products->isEmpty())
                        <div class="mt-10 rounded-[2rem] border border-dashed border-cyan-300 bg-white p-8 text-center shadow-sm sm:p-12">
                            <p class="mx-auto flex h-16 w-16 items-center justify-center rounded-3xl bg-cyan-100 text-sm font-black uppercase tracking-[0.18em] text-cyan-800">Produk</p>
                            <h3 class="mt-6 text-2xl font-black">Katalog publik sedang disiapkan.</h3>
                            <p class="mx-auto mt-3 max-w-2xl text-base leading-7 text-slate-600">Produk aktif akan tampil otomatis di sini setelah dipublikasikan dari admin. Sambil menunggu, pelanggan bisa menghubungi support untuk rekomendasi produk digital yang tersedia.</p>
                            <a href="#support" class="mt-6 inline-flex min-h-12 items-center justify-center rounded-full bg-slate-950 px-6 font-bold text-white hover:bg-slate-800">Hubungi support</a>
                        </div>
                    @else
                        <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($products as $product)
                                @php
                                    $availableStock = max(0, (int) $product->stock - (int) $product->reserved_stock);
                                    $imageUrl = $product->image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($product->image_path) : null;
                                @endphp
                                <article class="flex h-full flex-col overflow-hidden rounded-[1.5rem] border {{ $availableStock > 0 ? 'border-slate-200 bg-white' : 'border-amber-200 bg-slate-50 opacity-90' }} shadow-sm">
                                    @if ($imageUrl)
                                        <img src="{{ $imageUrl }}" alt="{{ $product->name }}" class="aspect-[4/3] w-full object-cover" loading="lazy">
                                    @else
                                        <div class="flex aspect-[4/3] w-full items-center justify-center bg-gradient-to-br from-cyan-100 via-white to-slate-100 p-6 text-center text-sm font-bold uppercase tracking-[0.18em] text-cyan-800">Gambar produk menyusul</div>
                                    @endif
                                    <div class="flex flex-1 flex-col p-5">
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                @if ($product->category)
                                                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-cyan-700">{{ $product->category->name }}</p>
                                                @endif
                                                <h3 class="mt-2 text-xl font-black">{{ $product->name }}</h3>
                                            </div>
                                            <span class="rounded-full {{ $availableStock > 0 ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-800' }} px-3 py-1 text-xs font-bold">
                                                {{ $availableStock > 0 ? 'Stok '.$availableStock : 'Stok habis' }}
                                            </span>
                                        </div>
                                        <p class="mt-3 line-clamp-3 text-sm leading-6 text-slate-600">{{ $product->description }}</p>
                                        <div class="mt-5 flex items-center justify-between gap-3">
                                            <p class="text-2xl font-black">{{ \App\Support\Money::idr($product->price_amount) }}</p>
                                            <a href="#support" class="inline-flex min-h-11 items-center rounded-full bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800">{{ $availableStock > 0 ? 'Minat beli' : 'Tanya stok' }}</a>
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>

            <section id="benefits" class="bg-white px-5 py-16 text-slate-950 sm:px-8 lg:px-10 lg:py-24">
                <div class="mx-auto grid max-w-7xl gap-10 lg:grid-cols-[0.85fr_1.15fr]">
                    <div>
                        <p class="text-sm font-bold uppercase tracking-[0.2em] text-cyan-700">Kenapa belanja di sini</p>
                        <h2 class="mt-3 text-3xl font-black tracking-tight sm:text-4xl">Dibuat untuk pembelian digital yang ringan, jelas, dan cepat.</h2>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-3">
                        @foreach ($benefits as $benefit)
                            <div class="rounded-3xl border border-slate-200 bg-slate-50 p-6">
                                <h3 class="font-black">{{ $benefit['title'] }}</h3>
                                <p class="mt-3 text-sm leading-6 text-slate-600">{{ $benefit['body'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="mx-auto mt-10 max-w-7xl rounded-[2rem] bg-slate-950 p-6 text-white sm:p-8">
                    <p class="text-sm font-bold uppercase tracking-[0.2em] text-cyan-200">Cara kerja</p>
                    <div class="mt-5 grid gap-4 md:grid-cols-3">
                        @foreach ($steps as $index => $step)
                            <div class="rounded-3xl border border-white/10 bg-white/5 p-5">
                                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-cyan-300 font-black text-slate-950">{{ $index + 1 }}</span>
                                <p class="mt-4 leading-7 text-slate-200">{{ $step }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <section id="faq" class="bg-slate-50 px-5 py-16 text-slate-950 sm:px-8 lg:px-10 lg:py-24">
                <div class="mx-auto max-w-4xl">
                    <p class="text-sm font-bold uppercase tracking-[0.2em] text-cyan-700">FAQ</p>
                    <h2 class="mt-3 text-3xl font-black tracking-tight sm:text-4xl">Pertanyaan umum pelanggan</h2>
                    <div class="mt-8 space-y-4">
                        @foreach ($faqs as $faq)
                            <details class="group rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" @if ($loop->first) open @endif>
                                <summary class="cursor-pointer list-none font-black">{{ $faq['question'] }}</summary>
                                <p class="mt-3 leading-7 text-slate-600">{{ $faq['answer'] }}</p>
                            </details>
                        @endforeach
                    </div>
                </div>
            </section>

            <section id="support" class="bg-slate-950 px-5 py-16 text-white sm:px-8 lg:px-10 lg:py-24">
                <div class="mx-auto flex max-w-7xl flex-col gap-6 rounded-[2rem] border border-white/10 bg-white/5 p-8 sm:p-10 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <p class="text-sm font-bold uppercase tracking-[0.2em] text-cyan-200">Support</p>
                        <h2 class="mt-3 text-3xl font-black tracking-tight sm:text-4xl">Butuh rekomendasi produk atau bantuan order?</h2>
                        <p class="mt-4 max-w-2xl leading-7 text-slate-300">Gunakan kanal support toko untuk bertanya tentang stok, pembayaran, dan pengiriman produk digital. Admin panel tetap tersedia hanya untuk operator.</p>
                    </div>
                    <div class="flex flex-col gap-3 sm:flex-row lg:flex-col xl:flex-row">
                        @if ($supportHref)
                            <a href="{{ $supportHref }}" class="inline-flex min-h-12 items-center justify-center rounded-full bg-cyan-300 px-6 font-bold text-slate-950 hover:bg-cyan-200">Chat support</a>
                        @else
                            <span class="inline-flex min-h-12 items-center justify-center rounded-full bg-cyan-300/80 px-6 text-center font-bold text-slate-950">Support Telegram segera tersedia</span>
                        @endif
                        <a href="{{ url('/admin/login') }}" class="inline-flex min-h-12 items-center justify-center rounded-full border border-white/20 px-6 font-bold text-white hover:border-white/50">Login admin</a>
                    </div>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
