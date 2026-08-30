<!doctype html>
<html lang="{{ $metadata->htmlLocale() }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head', ['metadata' => $metadata, 'brand' => $brand])
</head>
<body class="min-h-screen bg-[var(--sf-paper)] text-[var(--sf-ink)] antialiased">
    <main class="mx-auto flex min-h-screen max-w-2xl items-center px-5 py-12 sm:px-6">
        <section class="neo-panel w-full bg-[var(--sf-surface)] p-6 sm:p-8">
            <p class="text-xs font-black uppercase tracking-[0.18em] text-[var(--sf-muted)]">{{ $brand }}</p>
            <h1 class="mt-3 font-display text-3xl font-black leading-tight sm:text-4xl">{{ $title }}</h1>
            <p class="mt-4 text-sm font-semibold leading-7 text-[var(--sf-muted)]">{{ $message }}</p>
            <x-storefront.button :href="$homeUrl" class="mt-7">Kembali ke katalog</x-storefront.button>
        </section>
    </main>
</body>
</html>
