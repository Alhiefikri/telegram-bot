<!doctype html>
<html lang="{{ $metadata->htmlLocale() }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head', ['metadata' => $metadata, 'brand' => $brand])
</head>
<body class="min-h-screen bg-[var(--sf-paper)] text-[var(--sf-ink)] antialiased">
    <x-theme-toggle class="fixed right-4 top-4 z-30 h-11 w-11 rounded-lg border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] text-[var(--sf-ink)] shadow-[0_4px_10px_rgb(18_35_63_/_0.08)] transition-transform duration-200 hover:-translate-y-0.5 active:scale-95 focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)]" />
    <main class="mx-auto flex min-h-screen max-w-2xl items-center px-5 py-12 pt-24 sm:px-6">
        <section class="neo-panel w-full bg-[var(--sf-surface)] p-6 sm:p-8">
            <p class="text-xs font-black uppercase tracking-[0.18em] text-[var(--sf-muted)]">{{ $brand }}</p>
            <h1 class="mt-3 font-display text-3xl font-black leading-tight sm:text-4xl">{{ $title }}</h1>
            <p class="mt-4 text-sm font-semibold leading-7 text-[var(--sf-muted)]">{{ $message }}</p>
            <x-storefront.button :href="$homeUrl" class="mt-7">Kembali ke katalog</x-storefront.button>
        </section>
    </main>
</body>
</html>
