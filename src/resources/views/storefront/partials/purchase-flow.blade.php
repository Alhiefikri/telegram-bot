<section id="cara-beli" class="relative mx-auto mt-10 max-w-6xl border-t-[3px] border-[var(--sf-border)] px-5 py-28 sm:px-6">
    <div class="mb-16 max-w-2xl">
        <div class="mb-4 inline-flex rounded-full border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] px-4 py-2 text-[11px] font-black text-[var(--sf-ink)] shadow-[4px_4px_0_var(--sf-shadow)]">Cara beli</div>
        <h2 class="font-display text-4xl font-black tracking-tight text-[var(--sf-ink)] sm:text-5xl">{{ $copy['payment_flow']['title'] }}</h2>
        <p class="mt-3 max-w-lg text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">{{ $copy['payment_flow']['body'] }}</p>
    </div>

    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_360px] lg:items-start">
        <div class="grid gap-6 sm:grid-cols-3 lg:grid-cols-1">
            @foreach ($copy['payment_flow']['steps'] as $index => $step)
                <div class="neo-panel neo-hover reveal-item relative bg-[var(--sf-surface)] p-6">
                    <span class="mb-5 flex h-12 w-12 items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-accent)] font-display text-base font-black text-white shadow-[4px_4px_0_var(--sf-shadow)]">
                        0{{ $index + 1 }}
                    </span>
                    <h3 class="font-display text-lg font-black text-[var(--sf-ink)]">{{ $step['title'] ?? 'Langkah' }}</h3>
                    <p class="mt-2 text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">{{ $step['body'] ?? $step }}</p>
                </div>
            @endforeach
        </div>

        @include('storefront.components.qris-card')
    </div>

    <div class="mt-16 grid gap-8 border-t-[3px] border-dashed border-[var(--sf-border)] pt-12 sm:grid-cols-2">
        <div class="neo-panel-tight bg-[var(--sf-yellow)] p-6">
            <p class="mb-2 text-[10px] font-black uppercase tracking-[0.2em] text-[var(--sf-ink)]">{{ $copy['delivery']['eyebrow'] }}</p>
            <h3 class="font-display text-lg font-black text-[var(--sf-ink)]">{{ $copy['delivery']['title'] }}</h3>
            <p class="mt-3 text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">{{ $copy['delivery']['body'] }}</p>
        </div>
        <div class="neo-panel-tight bg-[var(--sf-blue)] p-6 text-[var(--sf-ink)]">
            <p class="mb-2 text-[10px] font-black uppercase tracking-[0.2em] text-[var(--sf-ink)]/80">{{ $copy['telegram_support']['eyebrow'] }}</p>
            <h3 class="font-display text-lg font-black text-[var(--sf-ink)]">{{ $copy['telegram_support']['title'] }}</h3>
            <p class="mt-3 text-sm font-semibold leading-relaxed text-[var(--sf-ink)]/90">{{ $copy['telegram_support']['body'] }}</p>
        </div>
    </div>

    <div class="neo-panel mt-8 flex flex-col gap-4 bg-[var(--sf-surface)] p-6 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <p class="mb-2 text-[10px] font-black uppercase tracking-[0.2em] text-[var(--sf-muted)]">Panduan login</p>
            <h3 class="font-display text-lg font-black text-[var(--sf-ink)]">Bingung cara masuk atau amankan akun?</h3>
            <p class="mt-2 text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">Setelah akun dikirim, ikuti panduan login dan pengamanan akun agar akses tetap aman.</p>
        </div>
        <a href="{{ route('storefront.login-guide') }}" class="neo-button inline-flex h-12 shrink-0 items-center justify-center bg-[var(--sf-accent)] px-5 text-sm font-black text-white">
            Lihat Panduan
        </a>
    </div>
</section>
