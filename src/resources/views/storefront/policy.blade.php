<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head', ['metadata' => $metadata])
</head>
<body class="relative overflow-x-hidden text-[var(--sf-ink)] antialiased selection:bg-[var(--sf-ink)] selection:text-white">
    <div class="storefront-shell relative z-10 flex min-h-screen flex-col justify-between overflow-x-clip">
        @include('storefront.partials.header')

        <main class="flex-grow px-5 py-14 sm:px-6 sm:py-20">
            <article class="mx-auto max-w-4xl">
                <div class="max-w-3xl">
                    <p class="text-xs font-black uppercase tracking-[0.18em] text-[var(--sf-accent)]">{{ $policy['eyebrow'] }}</p>
                    <h1 class="mt-4 text-balance font-display text-4xl font-black leading-tight text-[var(--sf-ink)] sm:text-5xl">{{ $policy['title'] }}</h1>
                    <p class="mt-4 text-base font-semibold leading-7 text-[var(--sf-muted)] sm:text-lg sm:leading-8">{{ $policy['description'] }}</p>
                    <p class="mt-4 text-sm font-black text-[var(--sf-ink)]">Terakhir diperbarui: {{ $policy['updated'] }}</p>
                </div>

                <div class="mt-10 grid gap-5">
                    @foreach ($policy['sections'] as $section)
                        <section class="rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] p-5 shadow-[5px_5px_0_var(--sf-shadow)] sm:p-6">
                            <h2 class="font-display text-2xl font-black leading-tight text-[var(--sf-ink)]">{{ $section['title'] }}</h2>
                            @if (! empty($section['body']))
                                <p class="mt-3 text-sm font-semibold leading-7 text-[var(--sf-muted)]">{{ $section['body'] }}</p>
                            @endif
                            @if (! empty($section['items']))
                                <ul class="mt-4 grid gap-3 text-sm font-semibold leading-6 text-[var(--sf-muted)]">
                                    @foreach ($section['items'] as $item)
                                        <li class="flex gap-3">
                                            <span class="mt-2 h-2 w-2 shrink-0 rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-accent)]"></span>
                                            <span>{{ $item }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </section>
                    @endforeach
                </div>

                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="{{ route('storefront.order-access.create') }}" class="neo-button inline-flex min-h-12 items-center justify-center bg-[var(--sf-accent)] px-5 text-sm font-black text-white">Akses Pesanan</a>
                    <a href="{{ $storefront->catalogUrl() }}" class="inline-flex min-h-12 items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-white px-5 text-sm font-black text-[var(--sf-ink)] shadow-[4px_4px_0_var(--sf-shadow)]">Katalog</a>
                </div>
            </article>
        </main>

        @include('storefront.partials.footer')
    </div>
</body>
</html>
