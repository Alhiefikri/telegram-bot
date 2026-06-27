@php
    $pageTitle = trim($brand.' — Mini App Dashboard');
    $pageDescription = 'Dashboard mobile untuk '.$brand.' di Telegram.';
    $shareText = $brand.' — lihat toko ini';

    $money = function ($amount, $currency = 'IDR') {
        $amount = (int) $amount;

        return match (strtoupper((string) $currency)) {
            'IDR', 'RP' => 'Rp'.number_format($amount, 0, ',', '.'),
            default => strtoupper((string) $currency).' '.number_format($amount, 0, ',', '.'),
        };
    };

    $formatCompact = function ($value) {
        $value = (int) $value;

        if ($value >= 1000000000) {
            return number_format($value / 1000000000, 1, ',', '.').'B';
        }

        if ($value >= 1000000) {
            return number_format($value / 1000000, 1, ',', '.').'Jt';
        }

        if ($value >= 1000) {
            return number_format($value / 1000, 1, ',', '.').'K';
        }

        return (string) $value;
    };

    $safeStats = [
        'balance' => (int) ($stats['balance'] ?? 0),
        'escrow' => (int) ($stats['escrow'] ?? 0),
        'gmv' => (int) ($stats['gmv'] ?? 0),
        'product_count' => (int) ($stats['product_count'] ?? 0),
        'order_count' => (int) ($stats['order_count'] ?? 0),
        'pending_withdraw_count' => (int) ($stats['pending_withdraw_count'] ?? 0),
    ];

    $actionUrl = fn (?string $url) => filled($url) ? $url : null;
    $storeShareUrl = 'https://t.me/share/url?url='.urlencode($storefrontUrl).'&text='.urlencode($shareText);
    $availableProducts = collect($products ?? [])->map(function ($product) {
        $stock = (int) ($product->stock ?? 0);
        $reserved = (int) ($product->reserved_stock ?? 0);
        $available = max(0, $stock - $reserved);

        return (object) [
            'name' => (string) ($product->name ?? 'Produk'),
            'description' => (string) ($product->description ?? ''),
            'price_amount' => (int) ($product->price_amount ?? 0),
            'currency' => (string) ($product->currency ?? 'IDR'),
            'stock' => $stock,
            'reserved_stock' => $reserved,
            'available' => $available,
        ];
    });

    $quickActions = [
        ['label' => 'Produk Saya', 'href' => $actionUrl($adminUrl), 'tone' => 'mini-action--yellow'],
        ['label' => 'Tambah Produk', 'href' => $actionUrl($adminUrl), 'tone' => 'mini-action--pink'],
        ['label' => 'Top Up Saldo', 'href' => $actionUrl($adminUrl), 'tone' => 'mini-action--cyan'],
        ['label' => 'Withdraw Saldo', 'href' => $actionUrl($adminUrl), 'tone' => 'mini-action--green'],
        ['label' => 'Statistik', 'href' => $actionUrl($adminUrl), 'tone' => 'mini-action--lilac'],
        ['label' => 'Escrow', 'href' => $actionUrl($adminUrl), 'tone' => 'mini-action--orange'],
        ['label' => 'Riwayat Withdraw', 'href' => $actionUrl($adminUrl), 'tone' => 'mini-action--rose'],
        ['label' => 'Mini App Dashboard', 'href' => $actionUrl($adminUrl), 'tone' => 'mini-action--mint'],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#ffcf3f">
    <meta name="color-scheme" content="light">
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $pageDescription }}">

    @fonts

    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700;700&family=Instrument+Sans:ital,wght@0,400..700;1,400..700&display=swap" rel="stylesheet">

    <style>
        :root {
            --mini-bg: #f5f0e8;
            --mini-ink: #111111;
            --mini-accent: #ffcf3f;
            --mini-hot: #ff6b81;
            --mini-cyan: #64d9ff;
            --mini-lime: #b7f34d;
            --mini-shadow: 5px 5px 0 #111111;
            --mini-shadow-lg: 10px 10px 0 #111111;
            --mini-radius-xl: 28px;
            --mini-radius-lg: 24px;
            --mini-radius-md: 20px;
            --mini-gap: 12px;
        }

        html, body {
            min-height: 100%;
            background: var(--mini-bg);
        }

        body {
            font-family: 'Instrument Sans', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            color: var(--mini-ink);
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
            text-rendering: geometricPrecision;
        }

        h1, h2, h3, .font-display {
            font-family: 'Space Grotesk', 'Instrument Sans', system-ui, sans-serif;
            letter-spacing: -0.04em;
        }

        .mini-grain {
            position: absolute;
            inset: 0;
            pointer-events: none;
        }

        .grain::before {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
            opacity: 0.08;
            background-image: radial-gradient(rgba(17,17,17,.85) 0.8px, transparent 0.8px);
            background-size: 12px 12px;
            mix-blend-mode: multiply;
        }

        .mini-app {
            position: relative;
            min-height: 100dvh;
            overflow: hidden;
        }

        .mini-app__bg {
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at top left, rgba(255, 207, 63, 0.65), transparent 32%), radial-gradient(circle at top right, rgba(100, 217, 255, 0.5), transparent 24%), linear-gradient(180deg, #f8f2e7 0%, #f4ead9 52%, #ece2d2 100%);
        }

        .mini-app__shell {
            position: relative;
            z-index: 1;
            width: min(100%, 390px);
            min-height: 100dvh;
            margin: 0 auto;
            padding: max(14px, env(safe-area-inset-top)) 16px max(16px, env(safe-area-inset-bottom));
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .mini-card {
            position: relative;
            border: 3px solid #111111;
            background: rgba(255, 255, 255, 0.95);
            box-shadow: var(--mini-shadow);
            border-radius: var(--mini-radius-xl);
            overflow: hidden;
        }

        .brutal-card {
            border: 3px solid #111111;
            box-shadow: var(--mini-shadow);
        }

        .brutal-card-lg {
            border: 3px solid #111111;
            box-shadow: var(--mini-shadow-lg);
        }

        .brutal-btn {
            border: 3px solid #111111;
            box-shadow: 4px 4px 0 #111111;
            transition: transform .18s ease, box-shadow .18s ease;
        }

        .brutal-btn:active {
            transform: translate(2px, 2px);
            box-shadow: 2px 2px 0 #111111;
        }

        .brutal-btn[aria-disabled='true'] {
            cursor: not-allowed;
            opacity: .55;
            box-shadow: 4px 4px 0 rgba(17,17,17,.6);
        }

        .mini-hero {
            padding: 16px;
        }

        .mini-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            background: #111111;
            color: white;
            padding: 6px 12px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .mini-badge__dot {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: #b7f34d;
        }

        .mini-title {
            margin: 12px 0 0;
            max-width: 11ch;
            font-size: 2rem;
            line-height: 0.92;
            font-weight: 900;
            letter-spacing: -0.05em;
        }

        .mini-copy {
            margin: 8px 0 0;
            max-width: 28ch;
            font-size: 0.93rem;
            line-height: 1.35;
            color: #2b2b2b;
        }

        .mini-pill {
            border: 3px solid #111111;
            border-radius: 22px;
            padding: 10px 12px;
            box-shadow: 3px 3px 0 #111111;
        }

        .mini-pill--yellow { background: #ffcf3f; }
        .mini-pill--green { background: #b7f34d; }
        .mini-pill--cyan { background: #7ce3ff; }
        .mini-pill--pink { background: #ffdfef; }
        .mini-pill--orange { background: #ffa84d; }

        .mini-pill__label {
            margin: 0;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: 0.22em;
            text-transform: uppercase;
        }

        .mini-pill__value {
            margin: 4px 0 0;
            font-size: 1.1rem;
            font-weight: 900;
            line-height: 1;
        }

        .mini-stats {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .mini-store {
            padding: 16px;
            background: #111111;
            color: white;
        }

        .mini-store__top,
        .mini-footer__top,
        .mini-product__top,
        .mini-section-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }

        .mini-store__label,
        .mini-section-kicker {
            margin: 0;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: 0.22em;
            text-transform: uppercase;
        }

        .mini-store__label { color: #ffcf3f; }

        .mini-store__name,
        .mini-section-title,
        .mini-product__name {
            margin: 4px 0 0;
            font-size: 1.05rem;
            font-weight: 900;
            line-height: 1.05;
            letter-spacing: -0.04em;
        }

        .mini-status {
            flex-shrink: 0;
            border-radius: 999px;
            background: #b7f34d;
            color: #111111;
            padding: 6px 12px;
            font-size: 11px;
            font-weight: 900;
        }

        .mini-kpi-grid {
            margin-top: 14px;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 8px;
        }

        .mini-kpi {
            border-radius: 18px;
            padding: 12px 10px;
            text-align: center;
            color: #111111;
        }

        .mini-kpi--white { background: white; }
        .mini-kpi--yellow { background: #ffcf3f; }
        .mini-kpi--cyan { background: #64d9ff; }

        .mini-kpi__label {
            margin: 0;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .mini-kpi__value {
            margin: 6px 0 0;
            font-size: 0.96rem;
            font-weight: 900;
        }

        .mini-actions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .mini-action {
            min-height: 92px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            border-radius: 22px;
            padding: 12px;
            text-decoration: none;
            color: #111111;
        }

        .mini-action--yellow { background: #ffe66d; }
        .mini-action--pink { background: #ff7a90; }
        .mini-action--cyan { background: #7ce3ff; }
        .mini-action--green { background: #b8ff7a; }
        .mini-action--lilac { background: #c8b6ff; }
        .mini-action--orange { background: #ffa84d; }
        .mini-action--rose { background: #ffdfef; }
        .mini-action--mint { background: #a8f0c5; }

        .mini-action__label {
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .mini-action__title {
            font-size: 1rem;
            font-weight: 900;
            line-height: 1.1;
            letter-spacing: -0.03em;
        }

        .mini-product-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            padding-bottom: 8px;
        }

        .mini-product {
            padding: 16px;
            background: white;
        }

        .mini-product__desc {
            margin: 8px 0 0;
            font-size: 0.92rem;
            line-height: 1.35;
            color: #444;
        }

        .mini-tag {
            flex-shrink: 0;
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 11px;
            font-weight: 900;
        }

        .mini-tag--ready { background: #b7f34d; color: #111111; }
        .mini-tag--empty { background: #ff6b81; color: white; }

        .mini-product__meta {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 12px;
            margin-top: 16px;
        }

        .mini-product__price-label,
        .mini-footer__label {
            margin: 0;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: rgba(17, 17, 17, 0.55);
        }

        .mini-product__price {
            margin: 4px 0 0;
            font-size: 1.2rem;
            font-weight: 900;
        }

        .mini-product__stock {
            margin: 0;
            font-size: 0.82rem;
            line-height: 1.4;
            color: #444;
            text-align: right;
        }

        .mini-footer {
            margin-top: auto;
            padding: 16px;
            background: #111111;
            color: white;
        }

        .mini-footer__label { color: #ffcf3f; }

        .mini-footer__info {
            border-radius: 18px;
            background: white;
            color: #111111;
            padding: 10px 12px;
            text-align: right;
        }

        .mini-footer__buttons {
            margin-top: 14px;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .mini-footer__cta,
        .mini-share {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 48px;
            border: 3px solid #111111;
            border-radius: 18px;
            box-shadow: 4px 4px 0 #111111;
            text-decoration: none;
            font-size: 0.95rem;
            font-weight: 900;
            color: #111111;
            background: #ffcf3f;
            transition: transform .18s ease, box-shadow .18s ease;
        }

        .mini-footer__cta:active,
        .mini-share:active {
            transform: translate(2px, 2px);
            box-shadow: 2px 2px 0 #111111;
        }

        .mini-footer__cta--white { background: white; }
        .mini-footer__support {
            margin-top: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            min-height: 48px;
            border: 3px solid #111111;
            border-radius: 18px;
            box-shadow: 4px 4px 0 #111111;
            text-decoration: none;
            font-size: 0.95rem;
            font-weight: 900;
            color: #111111;
            background: #b7f34d;
        }

        .mini-card--dark {
            background: #111111;
            color: white;
        }

        .mini-share-wrap {
            display: flex;
            gap: 12px;
            margin-top: 14px;
        }

        .mini-share { width: 40%; background: white; }

        .mini-share--store { flex: 1; background: #ffcf3f; }

        .mini-text-muted { margin: 10px 0 0; font-size: 0.82rem; line-height: 1.5; color: rgba(255,255,255,0.75); }

        .mini-hidden { display: none !important; }
        .mini-form { display: grid; gap: 10px; margin-top: 12px; }
        .mini-input, .mini-select, .mini-textarea { width: 100%; border: 3px solid #111; border-radius: 16px; padding: 10px 12px; background: #fff; font: inherit; font-weight: 700; }
        .mini-submit { border: 3px solid #111; border-radius: 16px; box-shadow: 4px 4px 0 #111; background: #ffcf3f; padding: 10px 12px; font-weight: 900; }

        .safe-top {
            padding-top: max(14px, env(safe-area-inset-top));
        }

        .safe-bottom {
            padding-bottom: max(16px, env(safe-area-inset-bottom));
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                scroll-behavior: auto !important;
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body class="safe-top safe-bottom">
    <div class="mini-app">
        <div class="mini-app__bg"></div>
        <div class="grain mini-grain"></div>

        <main class="mini-app__shell">
            <section class="mini-card mini-hero">
                <div style="position:absolute; right:-24px; top:-24px; width:96px; height:96px; border-radius:999px; background:#ff6b81;"></div>
                <div style="position:absolute; left:-16px; bottom:-20px; width:64px; height:64px; border-radius:999px; background:#64d9ff;"></div>

                <div style="position:relative; display:flex; align-items:flex-start; justify-content:space-between; gap:12px;">
                    <div>
                        <span class="mini-badge"><span class="mini-badge__dot"></span> Telegram Mini App</span>
                        <h1 class="mini-title">{{ $brand }}</h1>
                        <p class="mini-copy">Dashboard singkat buat cek toko, saldo, dan produk.</p>
                    </div>

                    <div class="mini-pill mini-pill--yellow" style="min-width: 96px; text-align:right;">
                        <p class="mini-pill__label">Saldo</p>
                        <p id="stat-balance-hero" class="mini-pill__value" data-stat="balance-money">{{ $money($safeStats['balance']) }}</p>
                    </div>
                </div>

                <div class="mini-stats" style="margin-top:14px;">
                    <div class="mini-pill mini-pill--green">
                        <p class="mini-pill__label">Produk</p>
                        <p id="stat-product-count" class="mini-pill__value" data-stat="product-count">{{ $formatCompact($safeStats['product_count']) }}</p>
                        <p style="margin:6px 0 0; font-size:12px;">Aktif di toko</p>
                    </div>
                    <div class="mini-pill mini-pill--cyan">
                        <p class="mini-pill__label">Order</p>
                        <p id="stat-order-count" class="mini-pill__value" data-stat="order-count">{{ $formatCompact($safeStats['order_count']) }}</p>
                        <p style="margin:6px 0 0; font-size:12px;">Masuk hari ini</p>
                    </div>
                    <div class="mini-pill mini-pill--pink">
                        <p class="mini-pill__label">Escrow</p>
                        <p id="stat-escrow" class="mini-pill__value" style="font-size:0.98rem;" data-stat="escrow-money">{{ $money($safeStats['escrow']) }}</p>
                        <p style="margin:6px 0 0; font-size:12px;">Dana tertahan</p>
                    </div>
                    <div class="mini-pill mini-pill--orange">
                        <p class="mini-pill__label">Withdraw</p>
                        <p id="stat-pending-withdraw-count" class="mini-pill__value" data-stat="pending-withdraw-count">{{ $formatCompact($safeStats['pending_withdraw_count']) }}</p>
                        <p style="margin:6px 0 0; font-size:12px;">Menunggu proses</p>
                    </div>
                </div>
            </section>

            <section class="mini-card mini-store mini-card--dark">
                <div class="mini-store__top">
                    <div>
                        <p class="mini-store__label">Reseller / Store</p>
                        <p class="mini-store__name">{{ $brand }}</p>
                    </div>
                    <span class="mini-status">Online</span>
                </div>

                <div class="mini-kpi-grid">
                    <div class="mini-kpi mini-kpi--white">
                        <p class="mini-kpi__label">GMV</p>
                        <p id="stat-gmv" class="mini-kpi__value" data-stat="gmv-money">{{ $money($safeStats['gmv']) }}</p>
                    </div>
                    <div class="mini-kpi mini-kpi--yellow">
                        <p class="mini-kpi__label">Stok</p>
                        <p id="stat-product-count-kpi" class="mini-kpi__value" data-stat="product-count">{{ $formatCompact($safeStats['product_count']) }}</p>
                    </div>
                    <div class="mini-kpi mini-kpi--cyan">
                        <p class="mini-kpi__label">Bantuan</p>
                        <p class="mini-kpi__value">Siap</p>
                    </div>
                </div>

                <div class="mini-share-wrap">
                    <a href="{{ $storefrontUrl }}" class="brutal-btn mini-share mini-share--store">Belanja produk lain</a>
                    <a href="{{ $storeShareUrl }}" class="brutal-btn mini-share">Bagikan toko</a>
                </div>

                <p class="mini-text-muted">Cepat buka store, salin link, atau hubungi support dari sini.</p>
            </section>

            <section id="status-card" class="mini-card mini-product" style="text-align:center;">
                <p class="mini-product__name">Memverifikasi Telegram…</p>
                <p id="status-text" class="mini-product__desc">Buka halaman ini dari Telegram Mini App agar admin bisa diverifikasi.</p>
            </section>

            <section id="admin-shell" class="mini-hidden">
                <div class="mini-section-head" style="margin-bottom:10px; padding:0 4px;">
                    <div>
                        <p class="mini-section-kicker">Aksi cepat</p>
                        <h2 class="mini-section-title">Kelola toko</h2>
                    </div>
                    <span id="stat-pending-withdraw-label" class="mini-pill" style="background:white; padding:6px 12px; font-size:11px; font-weight:800; border-width:2px; box-shadow:none;" data-stat="pending-withdraw-label">{{ $formatCompact($safeStats['pending_withdraw_count']) }} pending</span>
                </div>

                <div class="mini-actions">
                    <a href="#category-form" class="brutal-btn mini-action mini-action--yellow"><span class="mini-action__label">Admin</span><span class="mini-action__title">Tambah Kategori</span></a>
                    <a href="#product-form" class="brutal-btn mini-action mini-action--pink"><span class="mini-action__label">Admin</span><span class="mini-action__title">Tambah Produk</span></a>
                </div>

                <article class="mini-card mini-product" style="margin-top:14px;">
                    <h3 class="mini-product__name">Tambah kategori</h3>
                    <form id="category-form" class="mini-form">
                        <input class="mini-input" name="name" placeholder="Nama kategori" required>
                        <textarea class="mini-textarea" name="description" placeholder="Deskripsi opsional"></textarea>
                        <input class="mini-input" name="sort_order" type="number" placeholder="Urutan (opsional)">
                        <button class="mini-submit" type="submit">Simpan kategori</button>
                    </form>
                </article>

                <article class="mini-card mini-product" style="margin-top:14px;">
                    <h3 class="mini-product__name">Tambah produk</h3>
                    <form id="product-form" class="mini-form">
                        <input class="mini-input" name="name" placeholder="Nama produk" required>
                        <textarea class="mini-textarea" name="description" placeholder="Deskripsi"></textarea>
                        <input class="mini-input" name="price_amount" type="number" min="0" placeholder="Harga IDR" required>
                        <select class="mini-select" name="category_id" id="category-select"><option value="">Tanpa kategori</option></select>
                        <button class="mini-submit" type="submit">Simpan produk</button>
                    </form>
                </article>
            </section>

            <section>
                <div class="mini-section-head" style="margin-bottom:10px; padding:0 4px;">
                    <div>
                        <p class="mini-section-kicker">Produk</p>
                        <h2 class="mini-section-title">Stok terbaru</h2>
                    </div>
                    <span id="stat-product-list-count" class="mini-pill" style="background:#111111; color:white; padding:6px 12px; font-size:11px; font-weight:800; box-shadow:none;" data-stat="product-list-count">{{ $formatCompact($safeStats['product_count']) }} item</span>
                </div>

                <div id="product-list" class="mini-product-list">
                    <article class="mini-card mini-product" style="text-align:center;"><p class="mini-product__name">Terkunci.</p><p class="mini-product__desc">Produk admin dimuat setelah verifikasi Telegram.</p></article>
                </div>
            </section>

            <section class="mini-card mini-footer mini-card--dark">
                <div class="mini-footer__top">
                    <div>
                        <p class="mini-footer__label">Mini App Dashboard</p>
                        <p style="margin:4px 0 0; font-size:0.93rem; line-height:1.4; color: rgba(255,255,255,0.8);">Buka cepat dari Telegram, tanpa terasa seperti halaman desktop.</p>
                    </div>
                    <div class="mini-footer__info">
                        <p class="mini-footer__label">Saldo</p>
                        <p id="stat-balance-footer" style="margin:4px 0 0; font-size:0.95rem; font-weight:900;" data-stat="balance-money">{{ $money($safeStats['balance']) }}</p>
                    </div>
                </div>

                <div class="mini-footer__buttons">
                    <a href="{{ $storefrontUrl }}" class="brutal-btn mini-footer__cta">Buka toko</a>
                    @if ($adminUrl)
                        <a href="{{ $adminUrl }}" class="brutal-btn mini-footer__cta mini-footer__cta--white">Masuk dashboard</a>
                    @else
                        <a href="#" aria-disabled="true" tabindex="-1" class="brutal-btn mini-footer__cta mini-footer__cta--white" style="pointer-events:none; opacity:.7;">Masuk dashboard</a>
                    @endif
                </div>

                @if ($supportUrl)
                    <a href="{{ $supportUrl }}" class="mini-footer__support">Hubungi support</a>
                @endif
            </section>
        </main>
    </div>

    <script>
        (function () {
            const webApp = window.Telegram && window.Telegram.WebApp;
            const csrf = @json(csrf_token());
            const endpoints = {
                bootstrap: @json(route('telegram.mini-app.admin.bootstrap')),
                categories: @json(route('telegram.mini-app.admin.categories.store')),
                products: @json(route('telegram.mini-app.admin.products.store')),
                stock: @json(url('/telegram-mini-app/admin/products/__ID__/stock-adjustments')),
            };
            let initData = '';

            if (!webApp || !webApp.initData) {
                document.getElementById('status-text').textContent = 'Tidak ada konteks Telegram. Buka Mini App ini dari Telegram.';
                return;
            }

            try {
                webApp.ready();
                webApp.expand();

                if (typeof webApp.setHeaderColor === 'function') {
                    webApp.setHeaderColor('#ffcf3f');
                }

                if (typeof webApp.setBackgroundColor === 'function') {
                    webApp.setBackgroundColor('#f5f0e8');
                }
            } catch (error) {
                // No-op: keep the mini app stable in every Telegram shell.
            }

            initData = webApp.initData;

            async function post(url, payload = {}) {
                const response = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify({ ...payload, initData }) });
                const json = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(json.message || 'Request gagal');
                return json;
            }

            const money = value => 'Rp' + Number(value || 0).toLocaleString('id-ID');
            const compact = value => {
                const number = Number(value || 0);
                if (number >= 1000000000) return (number / 1000000000).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + 'B';
                if (number >= 1000000) return (number / 1000000).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + 'Jt';
                if (number >= 1000) return (number / 1000).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + 'K';
                return String(number);
            };
            const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[character]));

            function updateStats(stats = {}) {
                document.querySelectorAll('[data-stat="balance-money"]').forEach(element => { element.textContent = money(stats.balance); });
                document.querySelectorAll('[data-stat="product-count"]').forEach(element => { element.textContent = compact(stats.product_count); });
                document.querySelectorAll('[data-stat="order-count"]').forEach(element => { element.textContent = compact(stats.order_count); });
                document.querySelectorAll('[data-stat="escrow-money"]').forEach(element => { element.textContent = money(stats.escrow); });
                document.querySelectorAll('[data-stat="pending-withdraw-count"]').forEach(element => { element.textContent = compact(stats.pending_withdraw_count); });
                document.querySelectorAll('[data-stat="gmv-money"]').forEach(element => { element.textContent = money(stats.gmv); });
                document.querySelectorAll('[data-stat="pending-withdraw-label"]').forEach(element => { element.textContent = compact(stats.pending_withdraw_count) + ' pending'; });
                document.querySelectorAll('[data-stat="product-list-count"]').forEach(element => { element.textContent = compact(stats.product_count) + ' item'; });
            }

            function render(dashboard) {
                document.getElementById('status-card').classList.add('mini-hidden');
                document.getElementById('admin-shell').classList.remove('mini-hidden');
                updateStats(dashboard.stats || {});
                const categories = dashboard.categories || [];
                document.getElementById('category-select').innerHTML = '<option value="">Tanpa kategori</option>' + categories.map(c => `<option value="${Number(c.id)}">${escapeHtml(c.name)}</option>`).join('');
                document.getElementById('product-list').innerHTML = (dashboard.products || []).map(p => `<article class="mini-card mini-product"><div class="mini-product__top"><div><h3 class="mini-product__name">${escapeHtml(p.name)}</h3><p class="mini-product__desc">${escapeHtml(p.category || 'Tanpa kategori')} · ${p.is_active ? 'Aktif' : 'Nonaktif'}</p></div><span class="mini-tag ${p.available > 0 ? 'mini-tag--ready' : 'mini-tag--empty'}">${Number(p.available || 0)} ready</span></div><div class="mini-product__meta"><div><p class="mini-product__price-label">Harga</p><p class="mini-product__price">${money(p.price)}</p></div><p class="mini-product__stock">Stok: ${Number(p.stock || 0)}<br>Reserved: ${Number(p.reserved || 0)}</p></div><form class="mini-form stock-form" data-id="${Number(p.id)}"><input class="mini-input" name="delta" type="number" min="-1000" max="1000" placeholder="Delta stok" required><input class="mini-input" name="notes" placeholder="Catatan" required><button class="mini-submit" type="submit">Adjust stok</button></form></article>`).join('') || '<article class="mini-card mini-product"><p class="mini-product__name">Belum ada produk.</p></article>';
            }

            post(endpoints.bootstrap).then(data => render(data.dashboard)).catch(error => { document.getElementById('status-text').textContent = error.message === 'Access denied.' ? 'Access denied: akun Telegram ini bukan admin.' : error.message; });

            document.getElementById('category-form').addEventListener('submit', async e => { e.preventDefault(); const fd = new FormData(e.target); const data = await post(endpoints.categories, Object.fromEntries(fd)); render(data.dashboard); e.target.reset(); });
            document.getElementById('product-form').addEventListener('submit', async e => { e.preventDefault(); const fd = new FormData(e.target); const payload = Object.fromEntries(fd); payload.currency = 'IDR'; if (!payload.category_id) payload.category_id = null; const data = await post(endpoints.products, payload); render(data.dashboard); e.target.reset(); });
            document.addEventListener('submit', async e => { if (!e.target.matches('.stock-form')) return; e.preventDefault(); const fd = new FormData(e.target); const payload = Object.fromEntries(fd); payload.idempotency_key = crypto.randomUUID ? crypto.randomUUID() : String(Date.now()); const data = await post(endpoints.stock.replace('__ID__', e.target.dataset.id), payload); render(data.dashboard); });
        })();
    </script>
</body>
</html>
