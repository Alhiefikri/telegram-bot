<section class="storefront-hero-card hero-preview-panel relative mx-auto flex min-h-[calc(100dvh-5rem)] max-w-6xl items-center justify-center px-5 py-14 sm:px-6 lg:min-h-[760px] lg:pb-16 lg:pt-12">
    <div class="floating-ai-field" aria-hidden="true">
        <span class="floating-ai-icon left-[4%] top-[10%] text-[var(--sf-yellow)]">✦</span>
        <span class="floating-ai-icon left-[18%] bottom-[20%] h-8 w-8 rotate-45 rounded-sm bg-[#b7dcff]"></span>
        <span class="floating-ai-icon right-[8%] top-[18%] text-5xl font-black text-[#ff8eb7]">+</span>
        <span class="floating-ai-icon right-[28%] bottom-[22%] h-7 w-7 rounded-full border-[3px] border-[#d8b6ff]"></span>
        <span class="floating-ai-icon left-[34%] top-[12%] h-0 w-0 border-x-[10px] border-b-[18px] border-x-transparent border-b-[#ffc1d6]"></span>
        <span class="floating-ai-icon right-[4%] bottom-[14%] text-3xl text-[#ffb58b]">✦</span>
        <span class="floating-ai-icon right-[25%] top-[44%] h-10 w-10 border-[3px] border-[#a9efcf] bg-transparent [clip-path:polygon(25%_6%,75%_6%,100%_50%,75%_94%,25%_94%,0_50%)]"></span>
        <span class="floating-ai-icon right-[12%] top-[28%] h-5 w-5 rotate-12 border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] shadow-[3px_3px_0_var(--sf-shadow)]"></span>
    </div>

    <div class="relative z-10 mx-auto max-w-3xl text-center motion-safe:animate-storefront-reveal">
        <div class="hero-logo-stage" aria-label="Logo Lhie Store">
            <img src="{{ Vite::asset('resources/images/lhie-store-logo.webp') }}" alt="Logo Lhie Store" width="900" height="810" fetchpriority="high" class="hero-logo-image">
        </div>

        <div class="neo-chip mt-3 inline-flex items-center gap-2 px-4 py-2 text-[11px] font-black uppercase tracking-[0.14em] text-[var(--sf-ink)]">
            <span class="h-2 w-2 rounded-full bg-[var(--sf-green)]"></span>
            {{ $catalogProducts->count() }} produk AI aktif
        </div>

        <h1 class="mx-auto mt-4 max-w-3xl font-display text-4xl font-black leading-[0.94] tracking-tight text-[var(--sf-ink)] text-balance sm:text-5xl lg:text-[4.15rem]">
            {{ $copy['hero']['headline'] }}
        </h1>

        <p class="mx-auto mt-4 max-w-xl text-sm font-bold leading-relaxed text-[var(--sf-muted)] sm:text-base">
            {{ $copy['hero']['body'] }}
            <span class="sr-only">Akun AI Premium Siap Pakai. Katalog akun AI premium</span>
        </p>

        <div class="mx-auto mt-6 flex max-w-xl flex-col items-stretch justify-center gap-3 sm:flex-row">
            <a href="{{ $storefront->telegramBotHref() }}" class="neo-button inline-flex h-14 items-center justify-center bg-[var(--sf-accent)] px-7 text-sm font-black text-white">
                {{ $copy['hero']['primary_cta'] }}
            </a>
            <a href="{{ $supportDestination }}" class="neo-button inline-flex h-14 items-center justify-center bg-[var(--sf-surface)] px-7 text-sm font-black text-[var(--sf-ink)]">
                {{ $copy['hero']['secondary_cta'] }}
            </a>
        </div>

        <div class="mx-auto mt-5 grid max-w-xl gap-3 sm:grid-cols-2">
            <a href="{{ $storefront->adminLoginHref() }}" class="hero-contact-tile group flex items-center gap-3 rounded-lg border-[3px] border-[var(--sf-border)] bg-[#d9efff] p-3 text-left shadow-[5px_5px_0_var(--sf-shadow)] transition-transform duration-200 hover:-translate-x-1 hover:-translate-y-1">
                <span class="grid h-10 w-10 place-items-center rounded-md border-2 border-[var(--sf-border)] bg-[#39a8ff] text-white">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7.5A2.5 2.5 0 0 1 6.5 5h11A2.5 2.5 0 0 1 20 7.5v9A2.5 2.5 0 0 1 17.5 19h-11A2.5 2.5 0 0 1 4 16.5v-9Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M8 9h8M8 13h5"/></svg>
                </span>
                <span>
                    <span class="block text-[10px] font-black uppercase tracking-[0.16em] text-[var(--sf-muted)]">Admin</span>
                    <span class="block text-sm font-black text-[var(--sf-ink)]">Dashboard toko</span>
                </span>
            </a>

            <a href="{{ $storefront->telegramBotHref() }}" class="hero-contact-tile group flex items-center gap-3 rounded-lg border-[3px] border-[var(--sf-border)] bg-[#dff8ea] p-3 text-left shadow-[5px_5px_0_var(--sf-shadow)] transition-transform duration-200 hover:-translate-x-1 hover:-translate-y-1">
                <span class="grid h-10 w-10 place-items-center rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-green)] text-[var(--sf-ink)]">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 8h6M9 12h3m-7 7 3.4-2.4A4 4 0 0 1 10.7 16H17a3 3 0 0 0 3-3V7a3 3 0 0 0-3-3H7a3 3 0 0 0-3 3v6a3 3 0 0 0 3 3h.5"/></svg>
                </span>
                <span>
                    <span class="block text-[10px] font-black uppercase tracking-[0.16em] text-[var(--sf-muted)]">Bot</span>
                    <span class="block text-sm font-black text-[var(--sf-ink)]">Order via Telegram</span>
                </span>
            </a>
        </div>

        <a href="#products" class="mt-9 inline-flex h-10 w-10 items-center justify-center rounded-full text-[var(--sf-muted)] transition-transform duration-300 hover:translate-y-1 hover:text-[var(--sf-ink)]" aria-label="Lihat produk">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
        </a>
    </div>
</section>
