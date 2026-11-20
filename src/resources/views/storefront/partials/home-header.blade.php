<header class="border-b border-[var(--sf-border)] bg-[var(--sf-surface)]">
    <nav class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-x-3 px-4 py-2 sm:px-6" aria-label="Navigasi utama">
        <a href="{{ $homeUrl }}" class="home-catalog-link inline-flex min-h-11 items-center rounded-lg" aria-label="Beranda {{ $brand }}">
            @include('storefront.components.brand-mark', ['brand' => $brand])
        </a>
        <div class="flex flex-wrap items-center gap-x-3 text-sm font-semibold sm:gap-x-5">
            <a href="{{ route('storefront.guides.index') }}" class="home-catalog-link hidden min-h-11 items-center rounded-lg sm:inline-flex">Panduan</a>
            <a href="#support" class="home-catalog-link hidden min-h-11 items-center rounded-lg md:inline-flex">Bantuan</a>
            <a href="{{ route('storefront.order-access.create') }}" class="home-catalog-link inline-flex min-h-11 items-center rounded-lg">Pesanan</a>
            <a href="{{ route('storefront.shop.cart') }}" class="home-catalog-link inline-flex min-h-11 items-center rounded-lg">Keranjang</a>
            <x-theme-toggle class="home-catalog-link inline-flex h-11 w-11 items-center justify-center rounded-lg" />
        </div>
    </nav>
</header>
