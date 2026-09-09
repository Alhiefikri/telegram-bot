<section id="cara-beli" class="mx-auto max-w-6xl px-5 py-8 sm:px-6 sm:py-10">
    <div class="neo-panel bg-[var(--sf-surface)] p-5 sm:p-7">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
            <div class="max-w-3xl">
                <p class="text-[11px] font-black uppercase tracking-[0.18em] text-[var(--sf-accent)]">{{ $copy['payment_flow']['eyebrow'] }}</p>
                <h2 class="mt-2 font-display text-2xl font-black tracking-tight text-[var(--sf-ink)] sm:text-3xl">{{ $copy['payment_flow']['title'] }}</h2>
                <p class="mt-2 text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">{{ $copy['payment_flow']['body'] }}</p>
            </div>

            <div class="flex shrink-0 flex-col gap-2 sm:flex-row lg:flex-col">
                <a href="{{ route('storefront.guides.index') }}" class="neo-button inline-flex min-h-11 items-center justify-center bg-[var(--sf-accent)] px-5 text-sm font-black text-white">
                    Pusat Panduan
                </a>
                <a href="{{ route('storefront.order-access.create') }}" class="inline-flex min-h-11 items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-white px-5 text-sm font-black text-[var(--sf-ink)] shadow-[4px_4px_0_var(--sf-shadow)]">
                    Akses Pesanan
                </a>
            </div>
        </div>

        <details class="mt-5 border-t-[3px] border-dashed border-[var(--sf-border)] pt-3">
            <summary class="flex min-h-11 cursor-pointer items-center justify-between gap-4 text-sm font-black text-[var(--sf-ink)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--sf-accent)]">
                <span>Lihat ringkasan checkout dan akses ulang</span>
                <span aria-hidden="true" class="text-xl leading-none">+</span>
            </summary>

            <div class="mt-4 grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(16rem,24rem)]">
                <ol class="grid gap-3 sm:grid-cols-3">
                    @foreach ($copy['payment_flow']['steps'] as $index => $step)
                        <li class="rounded-lg border-2 border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4">
                            <span class="font-display text-sm font-black text-[var(--sf-accent)]">0{{ $index + 1 }}</span>
                            <h3 class="mt-2 text-sm font-black text-[var(--sf-ink)]">{{ $step['title'] ?? 'Langkah' }}</h3>
                            <p class="mt-1 text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">{{ $step['body'] ?? $step }}</p>
                        </li>
                    @endforeach
                </ol>

                <div class="rounded-lg border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] p-4">
                    <h3 class="text-sm font-black text-[var(--sf-ink)]">Checkout aman untuk diingat</h3>
                    <p class="mt-2 text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">{{ $copy['qris']['body'] }}</p>
                    <p class="mt-2 text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">{{ $copy['recovery_access']['body'] }}</p>
                </div>
            </div>
        </details>
    </div>
</section>
