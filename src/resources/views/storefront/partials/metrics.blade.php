<section class="mx-auto max-w-5xl px-6 py-20 border-b border-slate-200">
    <div class="grid grid-cols-2 md:grid-cols-3 gap-12 text-center md:text-left">
        @foreach ($copy['testimonial']['stats'] as $index => $stat)
            @php
                $cleanedVal = preg_replace('/[^0-9]/', '', $stat['value']);
                $isNumeric = !empty($cleanedVal) && is_numeric($cleanedVal);
                $suffix = str_replace($cleanedVal, '', $stat['value']);
            @endphp
            <div class="reveal-item">
                @if ($isNumeric)
                    <p class="text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tight text-zinc-900 counter-number"
                       data-counter="{{ $cleanedVal }}"
                       data-suffix="{{ $suffix }}">
                        0
                    </p>
                @else
                    <p class="text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tight text-zinc-900">
                        {{ $stat['value'] }}
                    </p>
                @endif
                <p class="mt-3 text-[10px] text-zinc-500 font-bold uppercase tracking-wider">{{ $stat['label'] }}</p>
            </div>
        @endforeach
    </div>
</section>
