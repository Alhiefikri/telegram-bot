<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head', ['metadata' => $metadata])
</head>
<body class="relative overflow-x-hidden text-[var(--sf-ink)] antialiased selection:bg-[var(--sf-ink)] selection:text-white">
    <div class="storefront-shell relative z-10 flex min-h-screen flex-col justify-between overflow-x-hidden">
        @include('storefront.partials.header')

        <main class="flex-grow px-5 py-14 sm:px-6 sm:py-20">
            <section class="mx-auto max-w-6xl">
                <div class="max-w-3xl">
                    <x-storefront.badge class="px-4 py-2 text-xs tracking-[0.18em]">Bantuan penggunaan produk</x-storefront.badge>
                    <h1 class="mt-5 text-balance font-display text-4xl font-black leading-tight text-[var(--sf-ink)] sm:text-6xl">Pilih panduan yang Anda butuhkan.</h1>
                    <p class="mt-5 text-base font-semibold leading-7 text-[var(--sf-muted)] sm:text-lg">Setiap produk dapat memiliki cara login dan pengamanan yang berbeda. Buka panduan yang sesuai dengan produk serta metode akses Anda.</p>
                </div>

                <div class="mt-10 grid gap-5 md:grid-cols-2" aria-label="Daftar panduan produk">
                    @foreach ($guides as $guide)
                        <article class="rounded-xl border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] p-6 shadow-[6px_6px_0_var(--sf-shadow)]">
                            <p class="text-xs font-black uppercase tracking-[0.2em] text-[var(--sf-accent)]">{{ $guide['eyebrow'] }}</p>
                            <h2 class="mt-3 font-display text-2xl font-black text-[var(--sf-ink)]">{{ $guide['title'] }}</h2>
                            <p class="mt-3 text-sm font-semibold leading-7 text-[var(--sf-muted)]">{{ $guide['description'] }}</p>
                            <ul class="mt-5 flex flex-wrap gap-2" aria-label="Metode yang dibahas">
                                @foreach ($guide['methods'] as $method)
                                    <li class="rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-1 text-xs font-black text-[var(--sf-ink)]">{{ $method }}</li>
                                @endforeach
                            </ul>
                            <a href="{{ $guide['url'] }}" class="neo-button mt-6 inline-flex min-h-12 items-center justify-center bg-[var(--sf-accent)] px-5 text-sm font-black text-white">Buka {{ $guide['title'] }}</a>
                        </article>
                    @endforeach
                </div>
            </section>
        </main>

        @include('storefront.partials.footer')
    </div>
</body>
</html>
