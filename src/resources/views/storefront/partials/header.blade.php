<header class="sticky top-4 z-50 mx-auto w-[calc(100%-2rem)] max-w-6xl rounded-xl border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] shadow-[6px_6px_0_var(--sf-shadow)] transition-all duration-300">
    <div class="flex min-h-16 items-center justify-between gap-4 px-4 py-3 sm:px-6">
        <a href="{{ $canonicalUrl }}" class="flex items-center gap-2">
            @include('storefront.components.brand-mark', ['brand' => $brand])
        </a>

        <nav class="hidden items-center gap-1.5 rounded-lg border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] p-1 text-xs font-black text-[var(--sf-ink)] md:flex">
            <a href="#products" class="rounded-md px-4 py-2 transition-colors hover:bg-[var(--sf-surface)]">Produk</a>
            <a href="#cara-beli" class="rounded-md px-4 py-2 transition-colors hover:bg-[var(--sf-surface)]">Cara Beli</a>
            <a href="{{ route('storefront.login-guide') }}" class="rounded-md px-4 py-2 transition-colors hover:bg-[var(--sf-surface)]">Panduan Login</a>
        </nav>

        <div class="flex items-center gap-3">
            <a href="{{ $storefront->telegramBotHref() }}" class="neo-button hidden items-center justify-center bg-[var(--sf-accent)] px-4 py-2 text-xs font-black text-white sm:inline-flex">
                Buka Bot
            </a>
            <a href="{{ $storefront->adminLoginHref() }}" class="hidden text-xs font-black text-[var(--sf-ink)] underline decoration-[3px] underline-offset-4 hover:text-[var(--sf-accent)] lg:inline-flex">
                Admin
            </a>

            <button id="mobile-menu-btn" class="flex h-10 w-10 flex-col items-center justify-center gap-1 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] shadow-[4px_4px_0_var(--sf-shadow)] focus:outline-none md:hidden" aria-label="Toggle menu">
                <span id="menu-icon-1" class="h-[3px] w-5 bg-[var(--sf-ink)] transition-transform duration-300"></span>
                <span id="menu-icon-2" class="h-[3px] w-5 bg-[var(--sf-ink)] transition-transform duration-300"></span>
            </button>
        </div>
    </div>

    <div id="mobile-menu" class="absolute left-0 right-0 top-20 z-50 mx-2 hidden rounded-xl border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] p-4 opacity-0 shadow-[7px_7px_0_var(--sf-shadow)] scale-95 transition-all duration-300">
        <nav class="flex flex-col gap-2 text-sm font-black text-[var(--sf-ink)]">
            <a href="#products" class="rounded-lg border-2 border-transparent px-4 py-3 hover:border-[var(--sf-border)] hover:bg-[var(--sf-yellow)]">Produk</a>
            <a href="#cara-beli" class="rounded-lg border-2 border-transparent px-4 py-3 hover:border-[var(--sf-border)] hover:bg-[var(--sf-yellow)]">Cara Beli</a>
            <a href="{{ route('storefront.login-guide') }}" class="rounded-lg border-2 border-transparent px-4 py-3 hover:border-[var(--sf-border)] hover:bg-[var(--sf-yellow)]">Panduan Login</a>
            <a href="#faq" class="rounded-lg border-2 border-transparent px-4 py-3 hover:border-[var(--sf-border)] hover:bg-[var(--sf-yellow)]">FAQ</a>
            <a href="#support" class="rounded-lg border-2 border-transparent px-4 py-3 hover:border-[var(--sf-border)] hover:bg-[var(--sf-yellow)]">Support</a>
            <a href="{{ $storefront->telegramBotHref() }}" class="neo-button mt-2 inline-flex items-center justify-center bg-[var(--sf-accent)] py-3 text-center text-xs font-black text-white">
                Buka Bot Telegram
            </a>
            <a href="{{ $storefront->adminLoginHref() }}" class="rounded-lg border-2 border-[var(--sf-border)] px-4 py-3 text-center hover:bg-[var(--sf-yellow)]">
                Login Admin
            </a>
        </nav>
    </div>
</header>
