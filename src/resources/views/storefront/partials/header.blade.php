@php
    $homeUrl = $homeUrl ?? route('storefront.home');
    $shopUrl = $shopUrl ?? route('storefront.shop.index');
    $cartUrl = $cartUrl ?? route('storefront.shop.cart');
    $supportDestination = $supportDestination ?? $storefront->supportDestination();
    $headerSearch = request()->query('q');
    $headerSearch = is_string($headerSearch) ? $headerSearch : '';
    $cartCount = isset($cartItemCount) ? max(0, (int) $cartItemCount) : null;
    $cartLabel = $cartCount === null
        ? 'Buka keranjang'
        : 'Buka keranjang dengan '.$cartCount.' item';
    $accountUrl = route('customer.account');
    $searchMaxLength = \App\Storefront\StorefrontCatalogSearch::MAX_LENGTH;
@endphp

<header class="storefront-header sticky top-4 z-50 mx-auto mb-4 mt-4 w-[calc(100%-2rem)] max-w-6xl rounded-2xl border border-[var(--sf-border)] bg-[var(--sf-surface)] shadow-[0_12px_30px_rgb(18_35_63_/_0.1)] transition-all duration-300" data-storefront-header>
    <div class="flex min-h-[88px] w-full flex-wrap items-center gap-3 px-4 py-3 sm:px-6 lg:flex-nowrap">
        <a href="{{ $homeUrl }}" class="order-1 flex min-w-0 shrink-0 items-center gap-2 lg:w-[10rem] xl:w-[9rem]" aria-label="Beranda {{ $brand }}">
            @include('storefront.components.brand-mark', ['brand' => $brand])
        </a>

        <form action="{{ $shopUrl }}" method="GET" class="order-3 basis-full md:order-2 md:flex md:min-w-0 md:flex-1 md:basis-auto lg:min-w-[8rem]" role="search">
            <label for="header-search" class="sr-only">Cari produk</label>
            <div class="storefront-search relative flex h-14 w-full items-center rounded-2xl border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-1 shadow-[0_6px_18px_rgb(18_35_63_/_0.08)]">
                <button type="submit" class="inline-flex h-12 w-11 shrink-0 items-center justify-center rounded-xl text-[var(--sf-muted)] transition-colors hover:bg-[var(--sf-surface)] hover:text-[var(--sf-accent)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)]" aria-label="Cari produk">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35m1.35-5.15a6.5 6.5 0 1 1-13 0Z" />
                    </svg>
                </button>
                <input id="header-search" type="search" name="q" value="{{ $headerSearch }}" maxlength="{{ $searchMaxLength }}" placeholder="Cari produk digital, template, lisensi..." class="h-12 min-w-0 flex-1 border-0 bg-transparent px-2 text-sm font-bold text-[var(--sf-ink)] placeholder-[var(--sf-muted)] shadow-none focus:outline-none focus:ring-0">
                <button type="submit" class="inline-flex h-12 shrink-0 items-center justify-center rounded-xl bg-[var(--sf-accent)] px-4 text-xs font-black text-white shadow-[0_4px_10px_rgb(18_35_63_/_0.12)] transition-colors hover:bg-[var(--sf-accent-deep)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)] focus:ring-offset-2 focus:ring-offset-[var(--sf-surface-warm)]">
                    Cari
                </button>
            </div>
        </form>

        <nav class="order-4 hidden shrink-0 items-center gap-1 rounded-2xl border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-1 text-[11px] font-black text-[var(--sf-ink)] lg:order-3 lg:flex xl:p-2" aria-label="Navigasi utama">
            <a href="{{ $shopUrl }}" class="storefront-nav-link group relative grid h-9 w-9 shrink-0 place-items-center rounded-xl transition-colors hover:bg-[var(--sf-surface)] xl:h-10 xl:w-10" aria-label="Produk" title="Produk">
                <svg class="h-5 w-5 transition-transform duration-180 group-hover:-translate-y-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z" />
                </svg>
                <span class="sr-only">Produk</span>
            </a>
            <a href="{{ $homeUrl }}#kategori" class="storefront-nav-link group relative grid h-9 w-9 shrink-0 place-items-center rounded-xl transition-colors hover:bg-[var(--sf-surface)] xl:h-10 xl:w-10" aria-label="Kategori" title="Kategori">
                <svg class="h-5 w-5 transition-transform duration-180 group-hover:-translate-y-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4 6 6-2 10 10-6 6L4 10V6Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 8h.01" />
                </svg>
                <span class="sr-only">Kategori</span>
            </a>
            <a href="{{ $homeUrl }}#trending" class="storefront-nav-link group relative grid h-9 w-9 shrink-0 place-items-center rounded-xl transition-colors hover:bg-[var(--sf-surface)] xl:h-10 xl:w-10" aria-label="Trending" title="Trending">
                <svg class="h-5 w-5 transition-transform duration-180 group-hover:-translate-y-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 3c.5 3-1.5 4.5-3 6.5C8.5 11.5 9 14 11 15c-.5-2 1-3.5 2.5-4.5C15 12 16 14 16 16a4 4 0 1 1-7.5-1.9C6.8 16.8 8.8 21 13 21a6 6 0 0 0 5.8-7.5C18.1 10.3 15.6 6 13 3Z" />
                </svg>
                <span class="sr-only">Trending</span>
            </a>
            <a href="{{ $homeUrl }}#cara-beli" class="storefront-nav-link group relative grid h-9 w-9 shrink-0 place-items-center rounded-xl transition-colors hover:bg-[var(--sf-surface)] xl:h-10 xl:w-10" aria-label="Cara beli" title="Cara beli">
                <svg class="h-5 w-5 transition-transform duration-180 group-hover:-translate-y-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 6h14M5 12h14M5 18h9" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="m16 16 2 2 3-3" />
                </svg>
                <span class="sr-only">Cara beli</span>
            </a>
            <a href="{{ route('storefront.order-access.create') }}" class="storefront-nav-link group relative grid h-9 w-9 shrink-0 place-items-center rounded-xl transition-colors hover:bg-[var(--sf-surface)] xl:h-10 xl:w-10" aria-label="Akses pesanan" title="Akses pesanan">
                <svg class="h-5 w-5 transition-transform duration-180 group-hover:-translate-y-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 3h10v18l-2-1.5L12 21l-3-1.5L7 21V3Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 8h4M10 12h4" />
                </svg>
                <span class="sr-only">Akses pesanan</span>
            </a>
            <a href="{{ $cartUrl }}" class="storefront-nav-link group relative grid h-9 w-9 shrink-0 place-items-center rounded-xl transition-colors hover:bg-[var(--sf-surface)] xl:h-10 xl:w-10" aria-label="{{ $cartLabel }}" title="Keranjang">
                <svg class="h-5 w-5 transition-transform duration-180 group-hover:-translate-y-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12l-1 13H7L6 7Zm3 0a3 3 0 0 1 6 0" />
                </svg>
                <span class="sr-only">Keranjang</span>
                @if ($cartCount !== null)
                    <span class="storefront-badge-pulse absolute -right-1 -top-1 min-w-5 rounded-full bg-[var(--sf-accent)] px-1 py-0.5 text-center text-[10px] font-black leading-4 text-white">{{ $cartCount }}</span>
                @endif
            </a>
            <a href="{{ route('storefront.guides.index') }}" class="storefront-nav-link group relative grid h-9 w-9 shrink-0 place-items-center rounded-xl transition-colors hover:bg-[var(--sf-surface)] xl:h-10 xl:w-10" aria-label="Panduan" title="Panduan">
                <svg class="h-5 w-5 transition-transform duration-180 group-hover:-translate-y-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 5.5A2.5 2.5 0 0 1 7.5 3H20v16H7.5A2.5 2.5 0 0 0 5 21V5.5Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h8M8 11h7" />
                </svg>
                <span class="sr-only">Panduan</span>
            </a>
            <x-theme-toggle class="h-9 w-9 shrink-0 rounded-xl text-[var(--sf-ink)] transition-colors hover:bg-[var(--sf-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)] xl:h-10 xl:w-10" />
        </nav>

        <div class="order-2 ml-auto flex shrink-0 items-center gap-2 lg:order-4">
            <x-theme-toggle class="h-11 w-11 shrink-0 rounded-xl border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] text-[var(--sf-ink)] shadow-[0_4px_10px_rgb(18_35_63_/_0.08)] transition-transform duration-200 hover:-translate-y-0.5 active:scale-95 focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)] lg:hidden" />
            <a href="{{ route('storefront.order-access.create') }}" class="hidden min-h-11 shrink-0 items-center justify-center gap-1.5 rounded-xl border border-[var(--sf-accent)] bg-transparent px-2 text-[10px] font-black text-[var(--sf-accent)] transition-colors hover:bg-[var(--sf-accent-soft)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)] lg:inline-flex xl:px-3 xl:text-[11px]" aria-label="Akses pesanan">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 3h10v18l-2-1.5L12 21l-3-1.5L7 21V3Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 8h4M10 12h4" />
                </svg>
                <span>Akses Pesanan</span>
            </a>
            <a href="{{ $accountUrl }}" class="neo-button hidden min-h-11 shrink-0 items-center justify-center gap-1.5 whitespace-nowrap bg-[var(--sf-accent)] px-2 text-[10px] font-black text-white lg:inline-flex xl:px-3 xl:text-[11px]" aria-label="Daftar atau masuk ke akun pelanggan">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm8-7v6m3-3h-6" />
                </svg>
                <span>Daftar / Masuk</span>
            </a>
            <a href="{{ $cartUrl }}" class="relative inline-flex h-11 w-11 items-center justify-center rounded-xl border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] text-[var(--sf-ink)] shadow-[0_4px_10px_rgb(18_35_63_/_0.08)] transition-transform duration-200 hover:-translate-y-0.5 active:scale-95 focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)] lg:hidden" aria-label="{{ $cartLabel }}">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12l-1 13H7L6 7Zm3 0a3 3 0 0 1 6 0" />
                </svg>
                @if ($cartCount !== null)
                    <span class="storefront-badge-pulse absolute -right-1 -top-1 min-w-5 rounded-full bg-[var(--sf-accent)] px-1 py-0.5 text-center text-[10px] font-black leading-4 text-white">{{ $cartCount }}</span>
                @endif
            </a>
            <button
                id="mobile-menu-btn"
                class="flex h-11 w-11 flex-col items-center justify-center gap-1.5 rounded-xl border border-[var(--sf-border)] bg-[var(--sf-yellow)] shadow-[0_4px_10px_rgb(18_35_63_/_0.08)] transition-transform duration-200 hover:-translate-y-0.5 active:translate-y-0 active:scale-95 focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)] focus:ring-offset-2 focus:ring-offset-[var(--sf-paper)] lg:hidden"
                type="button"
                aria-label="Buka menu navigasi"
                aria-controls="mobile-menu"
                aria-expanded="false"
            >
                <span id="menu-icon-1" class="h-[3px] w-5 rounded-full bg-[var(--sf-ink)] transition-transform duration-300"></span>
                <span id="menu-icon-2" class="h-[3px] w-5 rounded-full bg-[var(--sf-ink)] transition-transform duration-300"></span>
                <span id="menu-icon-3" class="h-[3px] w-5 rounded-full bg-[var(--sf-ink)] transition-opacity duration-300"></span>
            </button>
        </div>

    </div>

    <div id="mobile-menu-backdrop" class="fixed inset-0 z-40 hidden bg-[var(--sf-ink)]/45 opacity-0 backdrop-blur-[2px] transition-opacity duration-200 lg:hidden" aria-hidden="true"></div>

    <div id="mobile-menu" class="fixed bottom-0 left-0 top-0 z-50 hidden w-[min(84vw,22rem)] -translate-x-full overflow-y-auto border-r border-[var(--sf-border)] bg-[var(--sf-surface)] text-[var(--sf-ink)] shadow-[0_12px_30px_rgb(18_35_63_/_0.12)] transition-transform duration-300 ease-out lg:hidden" role="dialog" aria-modal="true" inert aria-hidden="true" aria-label="Menu navigasi Lhie Store">
        <div class="flex min-h-full flex-col">
            <div class="flex items-center justify-between gap-3 border-b border-[var(--sf-border)] px-5 py-4">
                <a href="{{ $homeUrl }}" class="flex min-w-0 items-center gap-2" aria-label="Beranda {{ $brand }}">
                    @include('storefront.components.brand-mark', ['brand' => $brand])
                </a>
                <button id="mobile-menu-close" type="button" class="grid h-10 w-10 place-items-center rounded-lg border border-[var(--sf-border)] bg-[var(--sf-yellow)] text-xl font-black shadow-[0_4px_10px_rgb(18_35_63_/_0.08)] transition-transform active:translate-y-0" aria-label="Tutup menu">
                    <span aria-hidden="true">×</span>
                </button>
            </div>

            <nav class="flex flex-1 flex-col gap-2 px-4 py-5 text-sm font-black" aria-label="Menu mobile">
                <form action="{{ $shopUrl }}" method="GET" class="mb-2" role="search">
                    <label for="mobile-header-search" class="sr-only">Cari produk</label>
                    <div class="storefront-search relative">
                        <input id="mobile-header-search" type="search" name="q" value="{{ $headerSearch }}" maxlength="{{ $searchMaxLength }}" placeholder="Cari produk" class="neo-input h-12 w-full pl-12 pr-3 text-sm font-bold text-[var(--sf-ink)] placeholder-[var(--sf-muted)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)]">
                        <button type="submit" class="absolute left-1 top-1/2 z-10 inline-flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-md text-[var(--sf-muted)] transition-colors hover:bg-[var(--sf-accent-soft)] hover:text-[var(--sf-accent)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)]" aria-label="Cari produk">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35m1.35-5.15a6.5 6.5 0 1 1-13 0Z" />
                            </svg>
                        </button>
                    </div>
                </form>
                <a href="{{ $homeUrl }}" class="flex items-center gap-3 rounded-lg bg-[var(--sf-surface-warm)] px-4 py-3 text-[var(--sf-accent)] transition-colors hover:bg-[var(--sf-surface)]">
                    <span class="grid h-8 w-8 place-items-center rounded-md border border-[var(--sf-border)] bg-white text-[var(--sf-ink)]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m3 10 9-7 9 7v9a2 2 0 0 1-2 2h-4v-6H9v6H5a2 2 0 0 1-2-2v-9Z" /></svg>
                    </span>
                    <span>Beranda</span>
                </a>

                <a href="{{ $shopUrl }}" class="flex items-center gap-3 rounded-lg px-4 py-3 transition-colors hover:bg-[var(--sf-surface-warm)]">
                    <span class="grid h-8 w-8 place-items-center rounded-md border border-[var(--sf-border)] bg-white text-[var(--sf-ink)]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z" /></svg>
                    </span>
                    <span>Katalog Web</span>
                </a>
                <a href="{{ $homeUrl }}#kategori" class="flex items-center gap-3 rounded-lg px-4 py-3 transition-colors hover:bg-[var(--sf-surface-warm)]">
                    <span class="grid h-8 w-8 place-items-center rounded-md border border-[var(--sf-border)] bg-white text-[var(--sf-ink)]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 5h6v6H4zM14 5h6v6h-6zM4 15h6v6H4zM14 15h6v6h-6z" /></svg>
                    </span>
                    <span>Kategori</span>
                </a>
                <a href="{{ $homeUrl }}#trending" class="flex items-center gap-3 rounded-lg px-4 py-3 transition-colors hover:bg-[var(--sf-surface-warm)]">
                    <span class="grid h-8 w-8 place-items-center rounded-md border border-[var(--sf-border)] bg-white text-[var(--sf-ink)]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 3c.5 3-1.5 4.5-3 6.5C8.5 11.5 9 14 11 15c-.5-2 1-3.5 2.5-4.5C15 12 16 14 16 16a4 4 0 1 1-7.5-1.9C6.8 16.8 8.8 21 13 21a6 6 0 0 0 5.8-7.5C18.1 10.3 15.6 6 13 3Z" /></svg>
                    </span>
                    <span>Trending</span>
                </a>
                <a href="{{ $cartUrl }}" class="flex items-center gap-3 rounded-lg px-4 py-3 transition-colors hover:bg-[var(--sf-surface-warm)]">
                    <span class="grid h-8 w-8 place-items-center rounded-md border border-[var(--sf-border)] bg-white text-[var(--sf-ink)]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12l-1 13H7L6 7Zm3 0a3 3 0 0 1 6 0" /></svg>
                    </span>
                    <span>
                        Keranjang
                        @if ($cartCount !== null)
                            ({{ $cartCount }})
                        @endif
                    </span>
                </a>

                <a href="{{ $homeUrl }}#cara-beli" class="flex items-center gap-3 rounded-lg px-4 py-3 transition-colors hover:bg-[var(--sf-surface-warm)]">
                    <span class="grid h-8 w-8 place-items-center rounded-md border border-[var(--sf-border)] bg-white text-[var(--sf-ink)]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 7h14M5 12h14M5 17h9" /></svg>
                    </span>
                    <span>Cara Beli</span>
                </a>

                <a href="{{ route('storefront.guides.index') }}" class="flex items-center gap-3 rounded-lg px-4 py-3 transition-colors hover:bg-[var(--sf-surface-warm)]">
                    <span class="grid h-8 w-8 place-items-center rounded-md border border-[var(--sf-border)] bg-white text-[var(--sf-ink)]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 5.5A2.5 2.5 0 0 1 7.5 3H20v16H7.5A2.5 2.5 0 0 0 5 21V5.5Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M8 7h8M8 11h7" /></svg>
                    </span>
                    <span>Panduan</span>
                </a>

                <a href="{{ $homeUrl }}#faq" class="flex items-center gap-3 rounded-lg px-4 py-3 transition-colors hover:bg-[var(--sf-surface-warm)]">
                    <span class="grid h-8 w-8 place-items-center rounded-md border border-[var(--sf-border)] bg-white text-[var(--sf-ink)]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18h.01M9.5 9a2.5 2.5 0 1 1 4.2 1.84c-.95.74-1.7 1.3-1.7 2.66" /><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    </span>
                    <span>FAQ</span>
                </a>

                <a href="{{ route('storefront.order-access.create') }}" class="neo-button mt-2 inline-flex min-h-12 items-center justify-center bg-[var(--sf-accent)] px-4 text-center text-xs font-black text-white">
                    <span>Akses Pesanan</span>
                </a>
                <a href="{{ $accountUrl }}" class="flex items-center justify-center rounded-lg border border-[var(--sf-border)] bg-[var(--sf-accent)] px-4 py-3 text-sm font-black text-white">Daftar / Masuk</a>
                <div class="mt-2 flex items-center justify-between gap-3 rounded-lg border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] px-4 py-2">
                    <span class="text-xs font-black uppercase tracking-wide">Tampilan</span>
                    <x-theme-toggle showLabel class="min-h-10 rounded-md border border-[var(--sf-border)] bg-[var(--sf-surface)] px-3 text-xs font-black text-[var(--sf-ink)] transition-colors hover:bg-[var(--sf-accent-soft)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)]" />
                </div>
            </nav>

            <div class="border-t border-[var(--sf-border)] px-5 py-3 text-center text-[10px] font-bold text-[var(--sf-muted)]">
                {{ $brand }} · Checkout dan akses pesanan mandiri
            </div>
        </div>
    </div>

    <noscript>
        <nav class="border-t border-[var(--sf-border)] px-4 py-3 lg:hidden" aria-label="Navigasi tanpa JavaScript">
            <div class="flex flex-wrap gap-2 text-xs font-black">
                <a href="{{ $shopUrl }}" class="inline-flex min-h-11 items-center rounded-lg bg-[var(--sf-surface-warm)] px-3 py-2">Produk</a>
                <a href="{{ $homeUrl }}#kategori" class="inline-flex min-h-11 items-center rounded-lg bg-[var(--sf-surface-warm)] px-3 py-2">Kategori</a>
                <a href="{{ $homeUrl }}#trending" class="inline-flex min-h-11 items-center rounded-lg bg-[var(--sf-surface-warm)] px-3 py-2">Trending</a>
                <a href="{{ $cartUrl }}" class="inline-flex min-h-11 items-center rounded-lg bg-[var(--sf-surface-warm)] px-3 py-2">Keranjang @if ($cartCount !== null)({{ $cartCount }})@endif</a>
                <a href="{{ route('storefront.order-access.create') }}" class="inline-flex min-h-11 items-center rounded-lg bg-[var(--sf-surface-warm)] px-3 py-2">Akses Pesanan</a>
                <a href="{{ $accountUrl }}" class="inline-flex min-h-11 items-center rounded-lg bg-[var(--sf-accent)] px-3 py-2 text-white">Daftar / Masuk</a>
                <a href="{{ route('storefront.guides.index') }}" class="inline-flex min-h-11 items-center rounded-lg bg-[var(--sf-surface-warm)] px-3 py-2">Panduan</a>
                <a href="{{ $homeUrl }}#faq" class="inline-flex min-h-11 items-center rounded-lg bg-[var(--sf-surface-warm)] px-3 py-2">FAQ</a>
            </div>
        </nav>
    </noscript>
</header>
