<section class="neo-panel-pop-accent relative mx-auto my-16 max-w-6xl overflow-hidden bg-[var(--sf-violet)] px-5 py-20 text-white sm:px-6 lg:py-24">
    <div class="absolute left-8 top-8 h-12 w-12 rotate-12 border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] shadow-[5px_5px_0_var(--sf-shadow)]"></div>
    <div class="absolute bottom-8 right-8 h-14 w-14 rounded-full border-[3px] border-[var(--sf-border)] bg-[var(--sf-lime)] shadow-[5px_5px_0_var(--sf-shadow)]"></div>
    <div class="neo-ai-sticker absolute right-10 top-10 z-10 hidden !h-16 !w-16 lg:grid" aria-hidden="true">
        <svg class="neo-spin-slow" viewBox="0 0 100 100" fill="var(--sf-yellow)" stroke="var(--sf-ink)" stroke-width="3" stroke-linejoin="round">
            <polygon points="98,50 84.8,59.3 91.6,74 75.5,75.5 74,91.6 59.3,84.8 50,98 40.7,84.8 26,91.6 24.5,75.5 8.4,74 15.2,59.3 2,50 15.2,40.7 8.4,26 24.5,24.5 26,8.4 40.7,15.2 50,2 59.3,15.2 74,8.4 75.5,24.5 91.6,26 84.8,40.7" />
        </svg>
        <span class="text-lg text-[var(--sf-ink)]">✦</span>
    </div>

    <div class="relative z-10 mx-auto max-w-3xl text-center">
        <span class="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] font-display text-5xl font-black leading-none text-[var(--sf-ink)] shadow-[5px_5px_0_var(--sf-shadow)]">"</span>
        <p class="font-display text-2xl font-black leading-tight text-white sm:text-4xl">
            {{ $copy['testimonial']['quote'] }}
        </p>
        <div class="mt-10 flex flex-col items-center justify-center gap-4 sm:flex-row">
            <div class="flex h-12 w-12 items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] font-display text-lg font-black text-[var(--sf-ink)] shadow-[4px_4px_0_var(--sf-shadow)]">
                LS
            </div>
            <div class="text-center sm:text-left">
                <p class="text-xs font-black text-white">{{ $copy['testimonial']['name'] }}</p>
                <p class="mt-0.5 text-[11px] font-semibold text-white/80">{{ $copy['testimonial']['role'] }}</p>
            </div>
        </div>
    </div>
</section>
