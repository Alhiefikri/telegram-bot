<section class="mx-auto max-w-6xl border-b-[3px] border-[var(--sf-border)] px-5 py-20 sm:px-6">
    <div class="grid grid-cols-1 gap-6 text-center md:grid-cols-3 md:text-left">
        @foreach ($copy['testimonial']['stats'] as $index => $stat)
            @php
                $cleanedVal = preg_replace('/[^0-9]/', '', $stat['value']);
                $isNumeric = !empty($cleanedVal) && is_numeric($cleanedVal);
                $suffix = str_replace($cleanedVal, '', $stat['value']);
            @endphp
            <div class="neo-panel-tight reveal-item bg-[var(--sf-surface)] p-6">
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
                <p class="mt-3 text-[10px] font-black uppercase tracking-wider text-[var(--sf-muted)]">{{ $stat['label'] }}</p>
            </div>
        @endforeach
    </div>
</section>
