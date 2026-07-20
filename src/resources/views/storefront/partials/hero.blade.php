<section class="storefront-hero-card hero-preview-panel relative mx-auto flex min-h-[calc(100dvh-5rem)] max-w-6xl items-center justify-center px-5 py-10 sm:px-6 lg:min-h-[700px] lg:pb-14 lg:pt-8">
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

        <p class="mt-4 text-sm font-black text-[var(--sf-accent)]">{{ $catalogPage->productCount() }} produk aktif siap dipilih</p>

        <h1 class="mx-auto mt-3 max-w-3xl font-display text-4xl font-black leading-[0.94] tracking-tight text-[var(--sf-ink)] text-balance sm:text-5xl lg:text-[4.15rem]">
            {{ $copy['hero']['headline'] }}
        </h1>

        <p class="mx-auto mt-4 max-w-xl text-sm font-bold leading-relaxed text-[var(--sf-muted)] sm:text-base">
            {{ $copy['hero']['body'] }}
            <span class="sr-only">Akun Premium & Produk Digital. Katalog produk digital premium</span>
        </p>

        <div class="mx-auto mt-6 flex max-w-xl flex-col items-stretch justify-center gap-3 sm:flex-row">
            <a href="#products" class="neo-button hover-rubber-band inline-flex h-14 items-center justify-center gap-2 bg-[var(--sf-accent)] px-7 text-sm font-black text-white">
                <span>{{ $copy['hero']['primary_cta'] }}</span>
            </a>
            <a href="#cara-beli" class="neo-button hover-tilt-shake inline-flex h-14 items-center justify-center gap-2 bg-[var(--sf-surface)] px-7 text-sm font-black text-[var(--sf-ink)]">
                <span>{{ $copy['hero']['secondary_cta'] }}</span>
            </a>
        </div>

        <p class="mx-auto mt-5 max-w-md text-xs font-bold leading-relaxed text-[var(--sf-muted)]">Checkout web tidak memerlukan akun. Simpan invoice dan recovery key agar data pesanan bisa dibuka kembali.</p>
    </div>
</section>
