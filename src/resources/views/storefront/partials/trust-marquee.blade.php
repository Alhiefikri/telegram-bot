<section class="relative z-10 my-10 w-full overflow-hidden border-y-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] py-6">
    @php
        $trustBrands = ['OpenAI', 'Claude', 'Gemini', 'Midjourney', 'Canva', 'Copilot'];
    @endphp
    <div class="animate-marquee gap-16 whitespace-nowrap">
        <div class="flex items-center gap-6 text-[var(--sf-ink)]">
            @foreach ($trustBrands as $brandName)
                <span class="inline-flex h-10 items-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] px-4 font-display text-sm font-black uppercase tracking-[0.1em] shadow-[4px_4px_0_var(--sf-shadow)]">
                    {{ $brandName }}
                </span>
            @endforeach
        </div>
        <div class="flex items-center gap-6 text-[var(--sf-ink)]" aria-hidden="true">
            @foreach ($trustBrands as $brandName)
                <span class="inline-flex h-10 items-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] px-4 font-display text-sm font-black uppercase tracking-[0.1em] shadow-[4px_4px_0_var(--sf-shadow)]">
                    {{ $brandName }}
                </span>
            @endforeach
        </div>
    </div>
</section>
