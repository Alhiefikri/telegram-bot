<section id="faq" class="mx-auto max-w-4xl px-5 py-28 sm:px-6">
    <h2 class="mb-10 text-center font-display text-4xl font-black tracking-tight text-[var(--sf-ink)] sm:text-5xl">
        Pertanyaan Umum (FAQ)
    </h2>

    <div class="space-y-3">
        @foreach ($copy['faq'] as $index => $faq)
            <div class="faq-item overflow-hidden rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] shadow-[5px_5px_0_var(--sf-shadow)] transition-transform duration-300 hover:-translate-y-0.5">
                <button class="faq-toggle flex w-full items-center justify-between px-5 py-4 text-left font-black text-[var(--sf-ink)] focus:outline-none" aria-expanded="false">
                    <span class="text-sm sm:text-base">{{ $faq['question'] }}</span>
                    <svg class="ml-4 h-5 w-5 shrink-0 transform text-[var(--sf-ink)] transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div class="faq-content max-h-0 overflow-hidden transition-all duration-300 ease-in-out">
                    <div class="border-t-[3px] border-[var(--sf-border)] bg-[#fff8dc] px-5 pb-4 pt-3 text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">
                        {{ $faq['answer'] }}
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</section>
