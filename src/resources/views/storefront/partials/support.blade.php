<section id="support" class="mx-auto max-w-6xl px-5 py-8 sm:px-6 sm:py-10">
    <div class="neo-panel-pop relative overflow-hidden bg-[var(--sf-accent)] p-5 text-white sm:p-7">
        <div class="relative z-10 flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
            <div class="max-w-2xl">
                <p class="text-[11px] font-black uppercase tracking-[0.16em] text-white/80">Bantuan dan akses pesanan</p>
                <h2 class="mt-2 font-display text-2xl font-black tracking-tight sm:text-3xl">{{ $supportContext['title'] ?? 'Butuh bantuan?' }}</h2>
                <p class="mt-2 text-sm font-semibold leading-relaxed text-white/90">{{ $supportContext['body'] ?? $copy['support']['body'] }}</p>
            </div>

            <div class="flex w-full shrink-0 flex-col gap-2 sm:w-auto sm:flex-row lg:flex-col" aria-label="Pilihan support storefront">
                @foreach (($supportContext['categories'][0]['options'] ?? []) as $option)
                    @if ($option['available'])
                        <a href="{{ $option['href'] }}"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="neo-button hover-rubber-band inline-flex min-h-11 items-center justify-center gap-2 bg-[var(--sf-yellow)] px-5 text-sm font-black text-[var(--sf-ink)]"
                           data-support-channel="{{ $option['key'] }}"
                           data-support-category="general"
                           data-support-message="{{ $option['message'] }}">
                            <span>{{ $option['label'] }}</span>
                        </a>
                    @else
                        <span class="inline-flex min-h-11 items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-white/85 px-5 text-sm font-black text-[var(--sf-ink)] shadow-[4px_4px_0_var(--sf-shadow)] opacity-80"
                              aria-disabled="true"
                              data-support-channel="{{ $option['key'] }}"
                              data-support-category="general"
                              data-support-message="{{ $option['message'] }}">
                            {{ $option['label'] }} belum aktif
                        </span>
                    @endif
                @endforeach
                <a href="{{ route('storefront.order-access.create') }}" class="inline-flex min-h-11 items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-white px-5 text-sm font-black text-[var(--sf-ink)] shadow-[4px_4px_0_var(--sf-shadow)]">
                    Akses Pesanan
                </a>
            </div>
        </div>
    </div>
</section>
