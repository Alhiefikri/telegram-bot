<section id="support" class="px-6 py-12 mx-auto max-w-5xl">
    <div class="relative overflow-hidden rounded-2xl border border-slate-200 bg-slate-900 p-8 sm:p-12 text-white shadow-xl">
        <div class="absolute -right-32 -bottom-32 w-80 h-80 bg-white/5 rounded-full blur-3xl pointer-events-none"></div>

        <div class="relative z-10 flex flex-col lg:flex-row items-center justify-between gap-8">
            <div class="max-w-2xl text-center lg:text-left">
                <h2 class="text-2xl sm:text-3xl font-bold tracking-tight">{{ $copy['support']['title'] }}</h2>
                <p class="mt-3 text-slate-300 leading-relaxed text-sm">{{ $copy['support']['body'] }}</p>
            </div>

            <div class="flex flex-col sm:flex-row gap-3 w-full sm:w-auto shrink-0">
                @if ($supportHref)
                    <a href="{{ $supportHref }}" class="inline-flex items-center justify-center h-11 px-6 rounded-full bg-white text-slate-900 font-bold hover:bg-slate-100 transition-all hover:scale-[1.01] active:scale-[0.98] shadow-md text-sm">
                        {{ $copy['support']['primary_cta'] }}
                    </a>
                @else
                    <span class="inline-flex items-center justify-center h-11 px-6 rounded-full bg-white/10 text-white font-semibold text-sm">
                        Support Telegram segera tersedia
                    </span>
                @endif
                <a href="{{ url('/admin/login') }}" class="inline-flex items-center justify-center h-11 px-6 rounded-full border border-white/20 text-white font-bold hover:bg-white/10 transition-all hover:scale-[1.01] active:scale-[0.98] text-sm">
                    {{ $copy['support']['secondary_cta'] }}
                </a>
            </div>
        </div>
    </div>
</section>
