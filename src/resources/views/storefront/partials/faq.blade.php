<section id="faq" class="mx-auto max-w-4xl px-5 py-8 sm:px-6 sm:py-10">
    <h2 class="mb-5 text-center font-display text-2xl font-black tracking-tight text-[var(--sf-ink)] sm:text-3xl">
        Pertanyaan Umum (FAQ)
    </h2>

    <div class="space-y-2">
        @foreach ($copy['faq'] as $index => $faq)
            <div class="faq-item overflow-hidden rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] shadow-[4px_4px_0_var(--sf-shadow)] transition-transform duration-300 motion-reduce:transition-none hover:-translate-y-0.5">
                <button id="faq-question-{{ $index }}" class="faq-toggle flex min-h-11 w-full items-center justify-between gap-4 px-4 py-3 text-left text-sm font-black text-[var(--sf-ink)] focus:outline-none motion-reduce:transition-none" aria-controls="faq-answer-{{ $index }}" aria-expanded="false">
                    <span>{{ $faq['question'] }}</span>
                    <svg class="h-5 w-5 shrink-0 transform text-[var(--sf-ink)] transition-transform duration-300 motion-reduce:transition-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div id="faq-answer-{{ $index }}" class="faq-content max-h-0 overflow-hidden transition-all duration-300 ease-in-out motion-reduce:transition-none" role="region" aria-labelledby="faq-question-{{ $index }}">
                    <div class="border-t-[3px] border-[var(--sf-border)] bg-[#fff8dc] px-4 pb-4 pt-3 text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">
                        {{ $faq['answer'] }}
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</section>
