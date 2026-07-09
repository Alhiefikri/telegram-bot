<section class="storefront-hero-card hero-preview-panel relative mx-auto flex min-h-[calc(100dvh-5rem)] max-w-6xl items-center justify-center px-5 py-14 sm:px-6 lg:min-h-[760px] lg:pb-16 lg:pt-12">
    <div class="floating-ai-field" aria-hidden="true">
        <span class="floating-ai-icon hidden md:inline-flex left-[4%] top-[10%] text-[var(--sf-yellow)]">✦</span>
        <span class="floating-ai-icon hidden sm:inline-flex left-[18%] bottom-[20%] h-8 w-8 rotate-45 rounded-sm bg-[#b7dcff]"></span>
        <span class="floating-ai-icon hidden md:inline-flex right-[8%] top-[18%] text-5xl font-black text-[#ff8eb7]">+</span>
        <span class="floating-ai-icon hidden sm:inline-flex right-[28%] bottom-[22%] h-7 w-7 rounded-full border-[3px] border-[#d8b6ff]"></span>
        <span class="floating-ai-icon hidden sm:inline-flex left-[34%] top-[12%] h-0 w-0 border-x-[10px] border-b-[18px] border-x-transparent border-b-[#ffc1d6]"></span>
        <span class="floating-ai-icon hidden md:inline-flex right-[4%] bottom-[14%] text-3xl text-[#ffb58b]">✦</span>
        <span class="floating-ai-icon right-[25%] top-[44%] h-10 w-10 border-[3px] border-[#a9efcf] bg-transparent [clip-path:polygon(25%_6%,75%_6%,100%_50%,75%_94%,25%_94%,0_50%)]"></span>
        <span class="floating-ai-icon right-[12%] top-[28%] h-5 w-5 rotate-12 border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] shadow-[3px_3px_0_var(--sf-shadow)]"></span>
    </div>

    <div class="relative z-10 mx-auto max-w-3xl text-center motion-safe:animate-storefront-reveal">
        <div class="hero-logo-stage" aria-label="Logo Lhie Store">
            <img src="{{ Vite::asset('resources/images/lhie-store-logo.webp') }}" alt="Logo Lhie Store" width="900" height="810" fetchpriority="high" class="hero-logo-image">
        </div>

        <div class="neo-chip mt-3 inline-flex items-center gap-2 px-4 py-2 text-[11px] font-black uppercase tracking-[0.14em] text-[var(--sf-ink)]">
            <span class="h-2 w-2 rounded-full bg-[var(--sf-green)]"></span>
            {{ $catalogPage->productCount() }} produk aktif
        </div>

        <h1 class="mx-auto mt-4 max-w-3xl font-display text-4xl font-black leading-[0.94] tracking-tight text-[var(--sf-ink)] text-balance sm:text-5xl lg:text-[4.15rem]">
            {{ $copy['hero']['headline'] }}
        </h1>

        <p class="mx-auto mt-4 max-w-xl text-sm font-bold leading-relaxed text-[var(--sf-muted)] sm:text-base">
            {{ $copy['hero']['body'] }}
            <span class="sr-only">Akun Premium & Produk Digital. Katalog produk digital premium</span>
        </p>

        <div class="mx-auto mt-6 flex max-w-xl flex-col items-stretch justify-center gap-3 sm:flex-row">
            <a href="{{ route('storefront.shop.index') }}" class="neo-button hover-rubber-band inline-flex h-14 items-center justify-center gap-2 bg-[var(--sf-accent)] px-7 text-sm font-black text-white">
                <span>{{ $copy['hero']['primary_cta'] }}</span>
            </a>
            <a href="{{ $storefront->telegramBotHref() }}" data-telegram-link data-icon="telegram" class="neo-button hover-tilt-shake inline-flex h-14 items-center justify-center gap-2 bg-[var(--sf-surface)] px-7 text-sm font-black text-[var(--sf-ink)]">
                @include('storefront.components.telegram-icon', ['class' => 'h-4 w-4 shrink-0'])
                <span>{{ $copy['hero']['secondary_cta'] }}</span>
            </a>
        </div>

        <div class="mx-auto mt-5 grid max-w-sm gap-3">
            <a href="{{ route('storefront.shop.index') }}" class="hero-contact-tile group flex items-center gap-3 rounded-lg border-[3px] border-[var(--sf-border)] bg-[#dff8ea] p-3 text-left shadow-[5px_5px_0_var(--sf-shadow)] transition-transform duration-200 hover:-translate-x-1 hover:-translate-y-1">
                <span class="grid h-10 w-10 place-items-center rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-green)] text-[var(--sf-ink)]">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h10v10H7z" /><path stroke-linecap="round" stroke-linejoin="round" d="M4 12h16" /></svg>
                </span>
                <span>
                    <span class="block text-[10px] font-black uppercase tracking-[0.16em] text-[var(--sf-muted)]">Web + Telegram</span>
                    <span class="block text-sm font-black text-[var(--sf-ink)]">Bayar QRIS web atau lanjut bot</span>
                </span>
            </a>
        </div>

        <a href="{{ route('storefront.shop.index') }}" class="mt-9 inline-flex h-10 w-10 items-center justify-center rounded-full text-[var(--sf-muted)] transition-transform duration-300 hover:translate-y-1 hover:text-[var(--sf-ink)]" aria-label="{{ $copy['hero']['tertiary_cta'] }}">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
        </a>
    </div>
</section>
