@php
    use App\Storefront\StorefrontPresenter;

    /** @var StorefrontPresenter $storefront */
    $storefront = app(StorefrontPresenter::class);
    $brand = $storefront->brand();
    $copy = config('storefront');
    $homeUrl = route('storefront.home');
    $pageTitle = $product->name.' | '.$brand;
    $pageDescription = $storefront->descriptionSnippet($product);
    $canonicalUrl = route('storefront.products.show', $product);
    $supportHref = $storefront->supportHref();
    $supportDestination = $storefront->supportDestination();
    $isAvailable = $stock > 0;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head')
</head>
<body class="overflow-x-hidden antialiased selection:bg-[var(--sf-ink)] selection:text-white relative text-[var(--sf-ink)]">
    <div class="storefront-shell relative min-h-screen z-10 flex flex-col justify-between overflow-x-hidden">
        @include('storefront.partials.header')

        <main class="flex-grow pb-20 pt-12">
            <section class="mx-auto max-w-5xl px-5 sm:px-6">
                <a href="{{ route('storefront.home') }}#product-{{ $product->id }}" class="inline-flex items-center gap-2 text-sm font-black text-[var(--sf-ink)] hover:text-[var(--sf-accent)]">
                    <span aria-hidden="true">&larr;</span> Kembali
                </a>

                @if (session('storefront_notice'))
                    <div class="neo-panel mt-6 bg-[var(--sf-accent-soft)] p-4 text-sm font-black text-[var(--sf-ink)]">
                        {{ session('storefront_notice') }}
                    </div>
                @endif

                <div class="mt-6 grid gap-8 lg:grid-cols-[0.92fr_1.08fr] lg:items-start">
                    <div class="neo-panel overflow-hidden bg-[var(--sf-surface)]">
                        @include('storefront.components.product-cover', [
                            'product' => $product,
                            'isAvailable' => $isAvailable,
                            'isPopular' => false,
                        ])
                    </div>

                    <article class="neo-panel relative overflow-hidden bg-[var(--sf-surface)] p-5 sm:p-7">
                        <div class="pointer-events-none absolute inset-0 bg-grid-pattern opacity-40"></div>
                        <div class="relative z-10">
                            <div class="mb-5 flex flex-wrap items-center gap-2">
                                <span class="rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-1 font-display text-sm font-black text-[var(--sf-ink)]">
                                    {{ $storefront->idr($product->price_amount) }}
                                </span>
                                <span class="rounded-full border-2 border-[var(--sf-border)] {{ $isAvailable ? 'bg-white text-[var(--sf-ink)]' : 'bg-[#c94856] text-white' }} px-3 py-1 text-xs font-black">
                                    {{ $isAvailable ? 'Stok: '.$stock : 'Stok habis' }}
                                </span>
                            </div>

                            <h1 class="font-display text-3xl font-black leading-tight tracking-tight text-[var(--sf-ink)] sm:text-5xl">{{ $product->name }}</h1>
                            <p class="mt-4 max-w-xl text-sm font-semibold leading-7 text-[var(--sf-muted)]">{{ $storefront->descriptionSnippet($product) }}</p>

                            <div class="mt-6 border-y-[3px] border-dashed border-[var(--sf-border)] py-5">
                                <dl class="grid gap-3 text-sm font-bold sm:grid-cols-2">
                                    <div class="rounded-lg border-2 border-[var(--sf-border)] bg-white p-3">
                                        <dt class="text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">Produk</dt>
                                        <dd class="mt-1 text-[var(--sf-ink)]">{{ $product->name }}</dd>
                                    </div>
                                    <div class="rounded-lg border-2 border-[var(--sf-border)] bg-white p-3">
                                        <dt class="text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">Status</dt>
                                        <dd class="mt-1 text-[var(--sf-ink)]">{{ $isAvailable ? 'Siap dibeli' : 'Restock segera' }}</dd>
                                    </div>
                                </dl>
                            </div>

                            <div class="mt-6 flex flex-col gap-3 sm:flex-row">
                                @if ($isAvailable)
                                    <a href="{{ route('storefront.products.payment', $product) }}" class="neo-button inline-flex h-12 flex-1 items-center justify-center bg-[var(--sf-accent)] px-5 text-sm font-black text-white">
                                        Checkout via Admin
                                    </a>
                                @else
                                    <span class="neo-button inline-flex h-12 flex-1 items-center justify-center bg-[#d69aae] px-5 text-sm font-black text-white">
                                        Habis
                                    </span>
                                @endif

                                <a href="{{ $storefront->productSupportLink($product) }}" class="neo-button inline-flex h-12 flex-1 items-center justify-center bg-[var(--sf-surface)] px-5 text-sm font-black text-[var(--sf-ink)]">
                                    Tanya Admin
                                </a>
                            </div>
                        </div>
                    </article>
                </div>
            </section>
        </main>

        @include('storefront.partials.footer')
    </div>
</body>
</html>
