<section class="storefront-hero-card hero-preview-panel relative mx-auto max-w-6xl px-5 py-7 sm:px-6 sm:py-9">
    <div class="relative z-10 mx-auto max-w-3xl text-center motion-safe:animate-storefront-reveal">
        <div class="hero-logo-stage" aria-label="Logo Lhie Store">
            <img src="{{ Vite::asset('resources/images/lhie-store-logo.webp') }}" alt="Logo Lhie Store" width="900" height="810" fetchpriority="high" class="hero-logo-image">
        </div>


        <h1 class="mx-auto mt-3 max-w-3xl font-display text-3xl font-black leading-tight tracking-tight text-[var(--sf-ink)] text-balance sm:text-5xl">
            {{ $copy['hero']['headline'] }}
        </h1>


        <p class="mx-auto mt-4 max-w-xl text-sm font-bold leading-relaxed text-[var(--sf-muted)] sm:text-base">
            {{ $copy['hero']['body'] }}
        </p>

        <div class="mx-auto mt-5 flex max-w-xl flex-col items-stretch justify-center gap-3 sm:flex-row">
            <a href="#products" class="neo-button neo-sheen hover-rubber-band inline-flex min-h-12 items-center justify-center gap-2 bg-[var(--sf-accent)] px-7 text-sm font-black text-white">
                <span>{{ $copy['hero']['primary_cta'] }}</span>
            </a>
            <a href="#cara-beli" class="neo-button hover-tilt-shake inline-flex min-h-12 items-center justify-center gap-2 bg-[var(--sf-surface)] px-7 text-sm font-black text-[var(--sf-ink)]">
                <span>{{ $copy['hero']['secondary_cta'] }}</span>
            </a>
        </div>
    </div>
</section>
