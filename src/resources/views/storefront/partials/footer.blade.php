<footer class="footer-dark border-t-[3px] border-[var(--sf-border)] bg-[var(--sf-ink)] pb-10 pt-16 text-white">
    <div class="mx-auto grid max-w-6xl gap-10 px-5 sm:grid-cols-2 sm:px-6 lg:grid-cols-4">
        <div class="space-y-4">
            @include('storefront.components.brand-mark', ['brand' => $brand])
            <p class="text-sm font-semibold leading-relaxed text-white/70">
                {{ $copy['footer']['body'] }}
            </p>
            <div class="flex items-center gap-2" aria-hidden="true">
                <span class="h-3 w-3 rotate-12 rounded-sm border-2 border-white/40 bg-[var(--sf-yellow)]"></span>
                <span class="h-3 w-3 rounded-full border-2 border-white/40 bg-[var(--sf-violet)]"></span>
                <span class="h-3 w-3 -rotate-12 rounded-sm border-2 border-white/40 bg-[var(--sf-lime)]"></span>
            </div>
        </div>

        <div>
            <h3 class="mb-4 font-display text-sm font-black uppercase tracking-[0.14em] text-[var(--sf-yellow)]">Produk</h3>
            <ul class="space-y-3 text-sm font-semibold text-white/70">
                <li><a href="{{ $shopUrl ?? $storefront->catalogUrl() }}" class="transition-colors hover:text-[var(--sf-yellow)]">Katalog Lengkap</a></li>
                <li><a href="{{ $homeUrl ?? route('storefront.home') }}#cara-beli" class="transition-colors hover:text-[var(--sf-yellow)]">Cara Pembelian</a></li>
                <li><a href="{{ route('storefront.guides.index') }}" class="transition-colors hover:text-[var(--sf-yellow)]">Pusat Panduan</a></li>
                <li><a href="{{ $homeUrl ?? route('storefront.home') }}#faq" class="transition-colors hover:text-[var(--sf-yellow)]">FAQ</a></li>
            </ul>
        </div>

        <div>
            <h3 class="mb-4 font-display text-sm font-black uppercase tracking-[0.14em] text-[var(--sf-yellow)]">Toko</h3>
            <ul class="space-y-3 text-sm font-semibold text-white/70">
                <li><a href="{{ $homeUrl ?? route('storefront.home') }}#support" class="transition-colors hover:text-[var(--sf-yellow)]">Tentang Kami</a></li>
                <li><a href="{{ route('storefront.policies.privacy') }}" class="transition-colors hover:text-[var(--sf-yellow)]">Kebijakan Privasi</a></li>
                <li><a href="{{ route('storefront.policies.terms') }}" class="transition-colors hover:text-[var(--sf-yellow)]">Syarat Ketentuan</a></li>
                <li><a href="{{ route('storefront.policies.refund') }}" class="transition-colors hover:text-[var(--sf-yellow)]">Kebijakan Refund</a></li>
            </ul>
        </div>

        <div>
            <h3 class="mb-4 font-display text-sm font-black uppercase tracking-[0.14em] text-[var(--sf-yellow)]">Bantuan</h3>
            <ul class="space-y-3 text-sm font-semibold text-white/70">
                <li><a href="{{ route('storefront.order-access.create') }}" class="font-black text-[var(--sf-yellow)] transition-colors hover:text-white">Akses Pesanan</a></li>
                <li><a href="{{ route('storefront.policies.recovery') }}" class="transition-colors hover:text-[var(--sf-yellow)]">Pemulihan Pesanan</a></li>
                <li><a href="{{ route('storefront.guides.index') }}" class="transition-colors hover:text-[var(--sf-yellow)]">Pusat Panduan</a></li>
                <li><span>Simpan invoice dan recovery key</span></li>
            </ul>
        </div>
    </div>

    <div class="mx-auto mt-16 flex max-w-6xl flex-col items-center justify-between border-t-[3px] border-white/15 px-5 pt-8 text-xs font-bold text-white/60 sm:px-6 md:flex-row">
        <p>&copy; {{ now()->year }} {{ $copy['footer']['copyright_suffix'] }}</p>
        <div class="mt-4 flex items-center gap-4 md:mt-0">
            <span>QRIS website</span>
            <span>Akses pesanan mandiri</span>
        </div>
    </div>
</footer>
