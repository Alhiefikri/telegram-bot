@php
    $homeUrl = $homeUrl ?? route('storefront.home');
    $shopUrl = $shopUrl ?? route('storefront.shop.index');
    $supportDestination = $supportDestination ?? $storefront->supportDestination();
@endphp

<header class="sticky top-4 z-50 mx-auto w-[calc(100%-2rem)] max-w-6xl rounded-xl border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] shadow-[6px_6px_0_var(--sf-shadow)] transition-all duration-300">
    <div class="flex min-h-16 items-center justify-between gap-3 px-4 py-3 sm:px-6">
        <a href="{{ $homeUrl }}" class="flex min-w-0 items-center gap-2" aria-label="Beranda {{ $brand }}">
            @include('storefront.components.brand-mark', ['brand' => $brand])
        </a>

        <nav class="hidden items-center gap-1.5 rounded-lg border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] p-1 text-xs font-black text-[var(--sf-ink)] lg:flex" aria-label="Navigasi utama">
            <a href="{{ $shopUrl }}" class="rounded-md px-4 py-2 transition-colors hover:bg-[var(--sf-surface)]">Produk</a>
            <a href="{{ $homeUrl }}#cara-beli" class="rounded-md px-4 py-2 transition-colors hover:bg-[var(--sf-surface)]">Cara Beli</a>
            <a href="{{ route('storefront.login-guide') }}" class="rounded-md px-4 py-2 transition-colors hover:bg-[var(--sf-surface)]">Panduan Login</a>
            <a href="{{ $homeUrl }}#faq" class="rounded-md px-4 py-2 transition-colors hover:bg-[var(--sf-surface)]">FAQ</a>
        </nav>

        <div class="flex shrink-0 items-center gap-2 sm:gap-3">
            <a href="{{ $shopUrl }}" class="neo-button hidden items-center justify-center gap-2 bg-[var(--sf-accent)] px-4 py-2 text-xs font-black text-white sm:inline-flex">
                <span>Belanja Web</span>
            </a>

            <a href="{{ $storefront->telegramBotHref() }}" data-telegram-link data-icon="telegram" class="neo-button hidden items-center justify-center gap-2 bg-[var(--sf-surface)] px-4 py-2 text-xs font-black text-[var(--sf-ink)] sm:inline-flex">
                @include('storefront.components.telegram-icon', ['class' => 'h-4 w-4 shrink-0'])
                <span>Buka Bot Telegram</span>
            </a>

            <button
                id="mobile-menu-btn"
                class="flex h-11 w-11 flex-col items-center justify-center gap-1.5 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] shadow-[4px_4px_0_var(--sf-shadow)] transition-transform duration-200 hover:-translate-x-0.5 hover:-translate-y-0.5 active:translate-x-[2px] active:translate-y-[2px] active:shadow-none focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)] focus:ring-offset-2 focus:ring-offset-[var(--sf-paper)] lg:hidden"
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

    <div id="mobile-menu" class="fixed bottom-0 left-0 top-0 z-50 hidden w-[min(84vw,22rem)] -translate-x-full overflow-y-auto border-r-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] text-[var(--sf-ink)] shadow-[10px_0_0_var(--sf-shadow)] transition-transform duration-300 ease-out lg:hidden" aria-hidden="true" aria-label="Menu navigasi Lhie Store">
        <div class="flex min-h-full flex-col">
            <div class="flex items-center justify-between gap-3 border-b-[3px] border-[var(--sf-border)] px-5 py-4">
                <a href="{{ $homeUrl }}" class="flex min-w-0 items-center gap-2" aria-label="Beranda {{ $brand }}">
                    @include('storefront.components.brand-mark', ['brand' => $brand])
                </a>
                <button id="mobile-menu-close" type="button" class="grid h-10 w-10 place-items-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] text-xl font-black shadow-[3px_3px_0_var(--sf-shadow)] transition-transform active:translate-x-[2px] active:translate-y-[2px] active:shadow-none" aria-label="Tutup menu">
                    <span aria-hidden="true">×</span>
                </button>
            </div>

            <nav class="flex flex-1 flex-col gap-2 px-4 py-5 text-sm font-black" aria-label="Menu mobile">
                <a href="{{ $homeUrl }}" class="flex items-center gap-3 rounded-lg bg-[#ffe8f0] px-4 py-3 text-[#d82e78] transition-colors hover:bg-[var(--sf-yellow)]">
                    <span class="grid h-8 w-8 place-items-center rounded-md border-2 border-[var(--sf-border)] bg-white text-[var(--sf-ink)]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m3 10 9-7 9 7v9a2 2 0 0 1-2 2h-4v-6H9v6H5a2 2 0 0 1-2-2v-9Z" /></svg>
                    </span>
                    <span>Beranda</span>
                </a>

                <a href="{{ $shopUrl }}" class="flex items-center gap-3 rounded-lg px-4 py-3 transition-colors hover:bg-[var(--sf-yellow)]">
                    <span class="grid h-8 w-8 place-items-center rounded-md border-2 border-[var(--sf-border)] bg-white text-[var(--sf-ink)]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z" /></svg>
                    </span>
                    <span>Belanja Web</span>
                </a>

                <a href="{{ $homeUrl }}#cara-beli" class="flex items-center gap-3 rounded-lg px-4 py-3 transition-colors hover:bg-[var(--sf-yellow)]">
                    <span class="grid h-8 w-8 place-items-center rounded-md border-2 border-[var(--sf-border)] bg-white text-[var(--sf-ink)]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 7h14M5 12h14M5 17h9" /></svg>
                    </span>
                    <span>Cara Beli</span>
                </a>

                <a href="{{ route('storefront.login-guide') }}" class="flex items-center gap-3 rounded-lg px-4 py-3 transition-colors hover:bg-[var(--sf-yellow)]">
                    <span class="grid h-8 w-8 place-items-center rounded-md border-2 border-[var(--sf-border)] bg-white text-[var(--sf-ink)]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 5.5A2.5 2.5 0 0 1 7.5 3H20v16H7.5A2.5 2.5 0 0 0 5 21V5.5Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M8 7h8M8 11h7" /></svg>
                    </span>
                    <span>Panduan Login</span>
                </a>

                <a href="{{ $homeUrl }}#faq" class="flex items-center gap-3 rounded-lg px-4 py-3 transition-colors hover:bg-[var(--sf-yellow)]">
                    <span class="grid h-8 w-8 place-items-center rounded-md border-2 border-[var(--sf-border)] bg-white text-[var(--sf-ink)]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18h.01M9.5 9a2.5 2.5 0 1 1 4.2 1.84c-.95.74-1.7 1.3-1.7 2.66" /><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    </span>
                    <span>FAQ</span>
                </a>

                <a href="{{ $supportDestination }}" data-telegram-link data-icon="telegram" class="flex items-center gap-3 rounded-lg px-4 py-3 transition-colors hover:bg-[var(--sf-yellow)]">
                    <span class="grid h-8 w-8 place-items-center rounded-md border-2 border-[var(--sf-border)] bg-[#2d9cff] text-white">
                        @include('storefront.components.telegram-icon', ['class' => 'h-4 w-4 shrink-0'])
                    </span>
                    <span>Support Telegram</span>
                </a>

                <a href="{{ $storefront->telegramBotHref() }}" data-telegram-link data-icon="telegram" class="neo-button mt-2 inline-flex min-h-12 items-center justify-center gap-2 bg-[var(--sf-accent)] px-4 text-center text-xs font-black text-white">
                    @include('storefront.components.telegram-icon', ['class' => 'h-4 w-4 shrink-0'])
                    <span>Buka Bot Telegram</span>
                </a>
            </nav>

            <div class="border-t-[3px] border-[var(--sf-border)] px-5 py-3 text-center text-[10px] font-bold text-[var(--sf-muted)]">
                {{ $brand }} · Web + Telegram checkout
            </div>
        </div>
    </div>
</header>
