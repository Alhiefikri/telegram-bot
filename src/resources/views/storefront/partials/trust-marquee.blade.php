<section class="w-full overflow-hidden py-10 bg-slate-50 border-y border-slate-200 relative z-10 my-10">
    @php
        $trustBrands = ['OpenAI', 'Claude', 'Gemini', 'Midjourney', 'Canva', 'Copilot'];
    @endphp
    <div class="animate-marquee gap-20 whitespace-nowrap">
        <div class="flex items-center gap-10 text-slate-500">
            @foreach ($trustBrands as $brandName)
                <span class="inline-flex h-9 items-center rounded-full border border-slate-200 bg-white px-4 text-xs font-black uppercase tracking-[0.18em] shadow-sm">
                    {{ $brandName }}
                </span>
            @endforeach
        </div>
        <div class="flex items-center gap-10 text-slate-500" aria-hidden="true">
            @foreach ($trustBrands as $brandName)
                <span class="inline-flex h-9 items-center rounded-full border border-slate-200 bg-white px-4 text-xs font-black uppercase tracking-[0.18em] shadow-sm">
                    {{ $brandName }}
                </span>
            @endforeach
        </div>
    </div>
</section>
