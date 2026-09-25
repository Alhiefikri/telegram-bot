@php
    $pageTitle = $pageTitle ?? 'Terjadi kendala';
    $status = $status ?? '500';
    $title = $title ?? 'Terjadi kendala.';
    $body = $body ?? 'Silakan kembali ke katalog atau hubungi support bila masalah berlanjut.';
    $primaryHref = $primaryHref ?? app(\App\Storefront\StorefrontUrl::class)->catalogUrl();
    $primaryLabel = $primaryLabel ?? 'Kembali ke katalog';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="bg-[var(--sf-paper)]">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>{{ $pageTitle }} | Lhie Store</title>
    @include('partials.theme-init')
    @vite(['resources/css/app.css', 'resources/js/storefront-entry.js'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400..800;1,400..800&family=Space+Grotesk:wght@400..700&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen overflow-x-hidden bg-[var(--sf-paper)] text-[var(--sf-ink)] antialiased">
    <x-theme-toggle class="fixed right-4 top-4 z-30 h-11 w-11 rounded-lg border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] text-[var(--sf-ink)] shadow-[0_4px_10px_rgb(18_35_63_/_0.08)] transition-transform duration-200 hover:-translate-y-0.5 active:scale-95 focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)]" />
    <main class="storefront-shell grid min-h-screen place-items-center px-5 py-8 pt-24 sm:px-6">
        <section class="neo-panel w-full max-w-xl bg-[var(--sf-surface)] p-6 sm:p-9" aria-labelledby="error-title">
            <a href="{{ route('storefront.home') }}" class="inline-flex items-center gap-2 text-sm font-black text-[var(--sf-ink)] transition-colors hover:text-[var(--sf-accent)]">
                @include('storefront.components.brand-mark', ['brand' => 'Lhie Store'])
            </a>

            <p class="mt-10 font-display text-6xl font-black leading-none text-[var(--sf-accent)]" aria-hidden="true">{{ $status }}</p>
            <h1 id="error-title" class="mt-4 max-w-md font-display text-3xl font-black leading-tight text-[var(--sf-ink)] sm:text-4xl">{{ $title }}</h1>
            <p class="mt-4 max-w-prose text-sm font-semibold leading-7 text-[var(--sf-muted)]">{{ $body }}</p>

            @isset($retryAfter)
                <p class="mt-5 rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-4 py-3 text-sm font-black text-[var(--sf-ink)]">Coba lagi dalam sekitar {{ $retryAfter }} detik.</p>
            @endisset

            <div class="mt-8">
                <a href="{{ $primaryHref }}" class="neo-button inline-flex min-h-12 w-full items-center justify-center bg-[var(--sf-accent)] px-5 text-sm font-black text-white sm:w-auto">{{ $primaryLabel }}</a>
            </div>
        </section>
    </main>
</body>
</html>
