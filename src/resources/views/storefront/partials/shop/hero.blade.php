<section class="relative px-5 pb-12 pt-16 sm:px-6 sm:pb-14 sm:pt-20">
    <div class="relative z-10 mx-auto max-w-6xl">
        <div class="neo-panel overflow-hidden bg-[var(--sf-surface)]">
            <div class="grid gap-6 border-b-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] px-5 py-4 sm:grid-cols-[1fr_auto] sm:items-center sm:px-7">
                <div>
                    <p class="text-[11px] font-black uppercase tracking-[0.16em] text-[var(--sf-ink)]">Shop web</p>
                    <h1 class="mt-2 max-w-3xl text-balance font-display text-3xl font-black tracking-tight text-[var(--sf-ink)] sm:text-5xl">
                        {{ $copy['shop']['title'] }}
                    </h1>
                </div>
                <div class="flex flex-wrap gap-2 text-[10px] font-black uppercase tracking-[0.12em] text-[var(--sf-ink)] sm:justify-end">
                    <span class="rounded-full border-2 border-[var(--sf-border)] bg-white px-3 py-1.5 shadow-[3px_3px_0_var(--sf-shadow)]">QRIS web</span>
                    <span class="rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-green)] px-3 py-1.5 shadow-[3px_3px_0_var(--sf-shadow)]">Telegram tetap ada</span>
                </div>
            </div>

            <div class="grid gap-6 px-5 py-6 sm:px-7 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
                <p class="max-w-2xl text-pretty text-sm font-semibold leading-7 text-[var(--sf-muted)] sm:text-base">
                    {{ $copy['shop']['body'] }}
                </p>

                <div class="flex flex-col gap-3 sm:flex-row lg:justify-end">
                    <a href="#products" class="neo-button inline-flex min-h-12 items-center justify-center bg-[var(--sf-accent)] px-5 text-sm font-black text-white transition-colors hover:bg-[var(--sf-accent-deep)]">
                        Mulai belanja
                    </a>
                    <a href="{{ route('storefront.home') }}" class="neo-button inline-flex min-h-12 items-center justify-center bg-[var(--sf-surface)] px-5 text-sm font-black text-[var(--sf-ink)] transition-colors hover:bg-[var(--sf-accent-soft)]">
                        Tentang Lhie Store
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>
