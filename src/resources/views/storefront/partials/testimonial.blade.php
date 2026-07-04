<section class="neo-panel relative mx-auto my-16 max-w-6xl overflow-hidden bg-[var(--sf-blue)] px-5 py-20 text-white sm:px-6 lg:py-24">
    <div class="absolute left-8 top-8 h-12 w-12 rotate-12 border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] shadow-[5px_5px_0_var(--sf-shadow)]"></div>
    <div class="absolute bottom-8 right-8 h-14 w-14 rounded-full border-[3px] border-[var(--sf-border)] bg-[var(--sf-accent)] shadow-[5px_5px_0_var(--sf-shadow)]"></div>

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
