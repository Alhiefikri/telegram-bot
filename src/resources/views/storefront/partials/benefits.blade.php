<section id="features" class="relative mx-auto max-w-6xl border-y-[3px] border-[var(--sf-border)] px-5 py-20 sm:px-6">
    <div class="mx-auto mb-14 max-w-2xl text-center">
        <span class="neo-chip inline-flex px-4 py-2 text-[11px] font-black tracking-[0.08em] text-[var(--sf-ink)]">✦ Trust order</span>
        <h2 class="mt-5 font-display text-4xl font-black tracking-tight text-[var(--sf-ink)] sm:text-5xl">Mengapa memilih Lhie Store?</h2>
        <p class="mx-auto mt-3 max-w-lg text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">Katalognya jelas, stoknya terbaca, dan akses pesanan bisa dibuka kembali langsung dari website.</p>
    </div>

    <div class="mx-auto grid max-w-5xl gap-6 md:grid-cols-3">
        @foreach ($copy['benefits'] as $index => $benefit)
            <article class="neo-panel neo-hover reveal-item h-full p-7 {{ ['bg-[#eafff3] md:-rotate-1', 'bg-[#fff8d9] md:translate-y-2', 'bg-[var(--sf-violet-soft)] md:rotate-1'][$index % 3] }}">
                <div class="mb-6 flex h-12 w-12 items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] {{ $index === 0 ? 'bg-[var(--sf-green)]' : ($index === 1 ? 'bg-[var(--sf-yellow)]' : 'bg-[var(--sf-blue)] text-white') }} text-sm font-black shadow-[4px_4px_0_var(--sf-shadow)]">
                    @if($index === 0)
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    @elseif($index === 1)
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    @else
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                    @endif
                </div>
                <h3 class="font-display text-xl font-black leading-snug text-[var(--sf-ink)]">{{ $benefit['title'] }}</h3>
                <p class="mt-3 text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">{{ $benefit['body'] }}</p>
            </article>
        @endforeach
    </div>
</section>
