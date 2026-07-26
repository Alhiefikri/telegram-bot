<section class="mx-auto max-w-6xl border-b-[3px] border-[var(--sf-border)] px-5 py-20 sm:px-6">
    <div class="grid grid-cols-1 gap-6 text-center md:grid-cols-3 md:text-left">
        @foreach ($copy['testimonial']['stats'] as $index => $stat)
            @php
                $cleanedVal = preg_replace('/[^0-9]/', '', $stat['value']);
                $isNumeric = !empty($cleanedVal) && is_numeric($cleanedVal);
                $suffix = str_replace($cleanedVal, '', $stat['value']);
            @endphp
            <div class="neo-panel-tight reveal-item p-6 transition-transform duration-300 hover:rotate-0 {{ ['bg-[var(--sf-yellow)] md:-rotate-1', 'bg-[var(--sf-violet-soft)]', 'bg-[#eafff3] md:rotate-1'][$index % 3] }}">
                @if ($isNumeric)
                    <p class="counter-number font-display text-4xl font-black tracking-tight text-[var(--sf-ink)] sm:text-5xl"
                       data-counter="{{ $cleanedVal }}"
                       data-suffix="{{ $suffix }}">
                        0
                    </p>
                @else
                    <p class="font-display text-4xl font-black tracking-tight text-[var(--sf-ink)] sm:text-5xl">
                        {{ $stat['value'] }}
                    </p>
                @endif
                <p class="mt-3 text-[11px] sm:text-xs font-black uppercase tracking-wider text-[var(--sf-ink)]/75">{{ $stat['label'] }}</p>
            </div>
        @endforeach
    </div>
</section>
