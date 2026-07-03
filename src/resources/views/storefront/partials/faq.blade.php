<section id="faq" class="mx-auto max-w-3xl px-6 py-28">
    <h2 class="text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 mb-10 text-center">
        Pertanyaan Umum (FAQ)
    </h2>

    <div class="space-y-3">
        @foreach ($copy['faq'] as $index => $faq)
            <div class="faq-item border border-zinc-200 rounded-xl bg-white overflow-hidden transition-shadow duration-300 hover:shadow-sm">
                <button class="faq-toggle flex w-full justify-between items-center px-5 py-4 text-left font-bold text-zinc-900 focus:outline-none" aria-expanded="false">
                    <span class="text-sm sm:text-base">{{ $faq['question'] }}</span>
                    <svg class="w-4 h-4 text-zinc-400 transform transition-transform duration-300 shrink-0 ml-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div class="faq-content max-h-0 overflow-hidden transition-all duration-300 ease-in-out">
                    <div class="px-5 pb-4 text-sm text-zinc-500 leading-relaxed border-t border-zinc-100 pt-3">
                        {{ $faq['answer'] }}
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</section>
