<footer class="border-t-[3px] border-[var(--sf-border)] bg-[#eee6d5] pb-10 pt-16">
    <div class="mx-auto grid max-w-6xl gap-10 px-5 sm:grid-cols-2 sm:px-6 lg:grid-cols-4">
        <div class="space-y-4">
            @include('storefront.components.brand-mark', ['brand' => $brand])
            <p class="text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">
                {{ $copy['footer']['body'] }}
            </p>
        </div>

        <div>
            <h3 class="mb-4 font-display text-sm font-black text-[var(--sf-ink)]">Produk</h3>
            <ul class="space-y-3 text-sm font-semibold text-[var(--sf-muted)]">
                <li><a href="{{ $homeUrl ?? route('storefront.home') }}#products" class="hover:text-[var(--sf-accent)]">Katalog Lengkap</a></li>
                <li><a href="{{ $homeUrl ?? route('storefront.home') }}#cara-beli" class="hover:text-[var(--sf-accent)]">Cara Pembelian</a></li>
                <li><a href="{{ route('storefront.login-guide') }}" class="hover:text-[var(--sf-accent)]">Panduan Login</a></li>
                <li><a href="{{ $homeUrl ?? route('storefront.home') }}#faq" class="hover:text-[var(--sf-accent)]">FAQ</a></li>
            </ul>
        </div>

        <div>
            <h3 class="mb-4 font-display text-sm font-black text-[var(--sf-ink)]">Toko</h3>
            <ul class="space-y-3 text-sm font-semibold text-[var(--sf-muted)]">
                <li><a href="{{ $homeUrl ?? route('storefront.home') }}#support" class="hover:text-[var(--sf-accent)]">Tentang Kami</a></li>
                <li><a href="{{ $homeUrl ?? route('storefront.home') }}#faq" class="hover:text-[var(--sf-accent)]">Kebijakan Privasi</a></li>
                <li><a href="{{ $homeUrl ?? route('storefront.home') }}#faq" class="hover:text-[var(--sf-accent)]">Syarat Ketentuan</a></li>
            </ul>
        </div>

        <div>
            <h3 class="mb-4 font-display text-sm font-black text-[var(--sf-ink)]">Bantuan</h3>
            <ul class="space-y-3 text-sm font-semibold text-[var(--sf-muted)]">
                <li><a href="{{ $supportDestination }}" data-telegram-link data-icon="telegram" class="inline-flex items-center gap-2 font-black text-[var(--sf-accent)] hover:text-[var(--sf-ink)]">@include('storefront.components.telegram-icon', ['class' => 'h-4 w-4 shrink-0']) <span>Support Telegram</span></a></li>
                <li><a href="{{ $storefront->telegramBotHref() }}" data-telegram-link data-icon="telegram" class="inline-flex items-center gap-2 hover:text-[var(--sf-accent)]">@include('storefront.components.telegram-icon', ['class' => 'h-4 w-4 shrink-0']) <span>Bot Telegram</span></a></li>
                <li><span>08.00 - 23.00 WIB</span></li>
            </ul>
        </div>
    </div>

    <div class="mx-auto mt-16 flex max-w-6xl flex-col items-center justify-between border-t-[3px] border-[var(--sf-border)] px-5 pt-8 text-xs font-bold text-[var(--sf-muted)] sm:px-6 md:flex-row">
        <p>&copy; {{ now()->year }} {{ $copy['footer']['copyright_suffix'] }}</p>
        <div class="mt-4 flex items-center gap-4 md:mt-0">
            <span>QR Manual</span>
            <span>QRIS</span>
        </div>
    </div>
</footer>
