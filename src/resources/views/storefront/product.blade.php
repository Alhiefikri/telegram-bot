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
                    <x-storefront.notice class="mt-6">
                        {{ session('storefront_notice') }}
                    </x-storefront.notice>
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
                                <x-storefront.badge class="font-display text-sm normal-case">
                                    {{ $storefront->idr($product->price_amount) }}
                                </x-storefront.badge>
                                <x-storefront.badge :tone="$isAvailable ? 'surface' : 'danger'" class="text-xs normal-case">
                                    {{ $isAvailable ? 'Stok: '.$stock : 'Stok habis' }}
                                </x-storefront.badge>
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
                                    <x-storefront.button :href="route('storefront.shop.index').'#product-'.$product->id" class="flex-1">
                                        Beli via Web QRIS
                                    </x-storefront.button>
                                @else
                                    <x-storefront.button variant="danger" class="flex-1 cursor-not-allowed opacity-80" disabled>
                                        Habis
                                    </x-storefront.button>
                                @endif

                                <x-storefront.button :href="$storefront->productSupportLink($product)" variant="secondary" class="flex-1" data-telegram-link data-icon="telegram">
                                    Beli lewat Telegram
                                </x-storefront.button>
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
