<section class="storefront-hero-card hero-preview-panel relative mx-auto flex min-h-[calc(100dvh-5rem)] max-w-6xl items-center justify-center px-5 py-10 sm:px-6 lg:min-h-[700px] lg:pb-14 lg:pt-8">
    <div class="floating-ai-field" aria-hidden="true">
        <span class="floating-ai-icon hidden md:inline-flex left-[4%] top-[10%] text-[var(--sf-yellow)]">✦</span>
        <span class="floating-ai-icon hidden sm:inline-flex left-[18%] bottom-[20%] h-8 w-8 rotate-45 rounded-sm bg-[#b7dcff]"></span>
        <span class="floating-ai-icon hidden md:inline-flex right-[8%] top-[18%] text-5xl font-black text-[#ff8eb7]">+</span>
        <span class="floating-ai-icon hidden sm:inline-flex right-[28%] bottom-[22%] h-7 w-7 rounded-full border-[3px] border-[#d8b6ff]"></span>
        <span class="floating-ai-icon hidden sm:inline-flex left-[34%] top-[12%] h-0 w-0 border-x-[10px] border-b-[18px] border-x-transparent border-b-[#ffc1d6]"></span>
        <span class="floating-ai-icon hidden md:inline-flex right-[4%] bottom-[14%] text-3xl text-[#ffb58b]">✦</span>
        <span class="floating-ai-icon hidden md:inline-flex left-[8%] top-[42%] h-6 w-6 rotate-12 rounded-sm border-[3px] border-[var(--sf-border)] bg-[var(--sf-lime)] shadow-[3px_3px_0_var(--sf-shadow)]"></span>
        <span class="floating-ai-icon hidden md:inline-flex left-[24%] bottom-[8%] text-4xl font-black text-[var(--sf-violet)]">✧</span>
        <span class="floating-ai-icon right-[25%] top-[44%] h-10 w-10 border-[3px] border-[#a9efcf] bg-transparent [clip-path:polygon(25%_6%,75%_6%,100%_50%,75%_94%,25%_94%,0_50%)]"></span>
        <span class="floating-ai-icon right-[12%] top-[28%] h-5 w-5 rotate-12 border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] shadow-[3px_3px_0_var(--sf-shadow)]"></span>
    </div>

    <div class="neo-ai-sticker absolute left-[6%] top-[16%] z-10 hidden lg:grid" aria-hidden="true">
        <svg class="neo-spin-slow" viewBox="0 0 100 100" fill="var(--sf-yellow)" stroke="var(--sf-ink)" stroke-width="3" stroke-linejoin="round">
            <polygon points="98,50 84.8,59.3 91.6,74 75.5,75.5 74,91.6 59.3,84.8 50,98 40.7,84.8 26,91.6 24.5,75.5 8.4,74 15.2,59.3 2,50 15.2,40.7 8.4,26 24.5,24.5 26,8.4 40.7,15.2 50,2 59.3,15.2 74,8.4 75.5,24.5 91.6,26 84.8,40.7" />
        </svg>
        <span class="text-xl text-[var(--sf-ink)]">AI</span>
    </div>

    <div class="relative z-10 mx-auto max-w-3xl text-center motion-safe:animate-storefront-reveal">
        <div class="hero-logo-stage" aria-label="Logo Lhie Store">
            <img src="{{ Vite::asset('resources/images/lhie-store-logo.webp') }}" alt="Logo Lhie Store" width="900" height="810" fetchpriority="high" class="hero-logo-image">
        </div>

        <p class="mx-auto mt-4 inline-flex items-center gap-2 rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-surface)] px-4 py-1.5 text-sm font-black text-[var(--sf-accent)] shadow-[3px_3px_0_var(--sf-shadow)]">{{ $catalogPage->productCount() }} produk aktif siap dipilih</p>

        <h1 class="mx-auto mt-4 max-w-3xl font-display text-4xl font-black leading-[0.94] tracking-tight text-[var(--sf-ink)] text-balance sm:text-5xl lg:text-[4.15rem]">
            {{ $copy['hero']['headline'] }}
        </h1>

        <div class="headline-underline mx-auto mt-4 w-40 sm:w-56" aria-hidden="true"></div>

        <div class="mt-5 flex flex-wrap items-center justify-center gap-2 font-display text-xs font-black uppercase tracking-[0.16em] text-[var(--sf-ink)] sm:text-sm" aria-hidden="true">
            <span>Langganan</span>
            <span class="ai-word-rotator rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-2.5 py-1 shadow-[3px_3px_0_var(--sf-shadow)]">
                <span>ChatGPT Plus</span>
                <span>Canva Pro</span>
                <span>Akun Premium</span>
                <span>Tools AI</span>
            </span>
            <span>tanpa ribet</span>
        </div>

        <p class="mx-auto mt-4 max-w-xl text-sm font-bold leading-relaxed text-[var(--sf-muted)] sm:text-base">
            {{ $copy['hero']['body'] }}
            <span class="sr-only">Akun Premium & Produk Digital. Katalog produk digital premium</span>
        </p>

        <div class="mx-auto mt-6 flex max-w-xl flex-col items-stretch justify-center gap-3 sm:flex-row">
            <a href="#products" class="neo-button neo-sheen hover-rubber-band inline-flex h-14 items-center justify-center gap-2 bg-[var(--sf-accent)] px-7 text-sm font-black text-white">
                <span>{{ $copy['hero']['primary_cta'] }}</span>
            </a>
            <a href="#cara-beli" class="neo-button hover-tilt-shake inline-flex h-14 items-center justify-center gap-2 bg-[var(--sf-surface)] px-7 text-sm font-black text-[var(--sf-ink)]">
                <span>{{ $copy['hero']['secondary_cta'] }}</span>
            </a>
        </div>

        <p class="mx-auto mt-5 max-w-md text-xs font-bold leading-relaxed text-[var(--sf-muted)]">Checkout web tidak memerlukan akun. Simpan invoice dan recovery key agar data pesanan bisa dibuka kembali.</p>
    </div>
</section>
