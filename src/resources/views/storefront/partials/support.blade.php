<section id="support" class="mx-auto max-w-6xl px-5 py-12 sm:px-6">
    <div class="neo-panel relative overflow-hidden bg-[var(--sf-accent)] p-8 text-white sm:p-12">
        <div class="pointer-events-none absolute -bottom-8 -left-8 h-32 w-32 -rotate-12 border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] shadow-[7px_7px_0_var(--sf-shadow)]"></div>

        <div class="relative z-10 flex flex-col items-center justify-between gap-8 lg:flex-row">
            <div class="max-w-2xl text-center lg:text-left">
                <h2 class="font-display text-4xl font-black tracking-tight sm:text-5xl">{{ $copy['support']['title'] }}</h2>
                <p class="mt-3 text-sm font-semibold leading-relaxed text-white/90">{{ $copy['support']['body'] }}</p>
            </div>

            <div class="flex w-full shrink-0 flex-col gap-3 sm:w-auto">
                <a href="{{ route('storefront.order-access.create') }}" class="neo-button hover-rubber-band inline-flex h-12 items-center justify-center gap-2 bg-[var(--sf-yellow)] px-6 text-sm font-black text-[var(--sf-ink)]">
                    <span>Akses Pesanan</span>
                </a>
            </div>
        </div>
    </div>
</section>
