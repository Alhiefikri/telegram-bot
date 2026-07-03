<section class="mx-auto max-w-5xl px-6 py-24 bg-slate-900 text-white rounded-2xl relative overflow-hidden my-16 shadow-2xl">
    <div class="absolute top-0 right-0 w-72 h-72 rounded-full bg-slate-500/10 blur-3xl pointer-events-none"></div>
    <div class="absolute bottom-0 left-0 w-72 h-72 rounded-full bg-slate-400/5 blur-3xl pointer-events-none"></div>

    <div class="max-w-3xl mx-auto text-center relative z-10">
        <span class="text-5xl text-slate-300 font-serif block mb-6 leading-none">"</span>
        <p class="text-lg sm:text-2xl font-medium leading-relaxed text-zinc-100">
            {{ $copy['testimonial']['quote'] }}
        </p>
        <div class="mt-10 flex flex-col sm:flex-row items-center justify-center gap-4">
            <div class="h-11 w-11 rounded-full border border-slate-200 p-[2px] overflow-hidden">
                <div class="w-full h-full rounded-full bg-zinc-800 overflow-hidden">
                    <img src="https://i.pravatar.cc/150?img=11" alt="Customer Avatar" class="w-full h-full object-cover">
                </div>
            </div>
            <div class="text-center sm:text-left">
                <p class="text-xs font-bold text-white">{{ $copy['testimonial']['name'] }}</p>
                <p class="text-[11px] text-zinc-400 mt-0.5">{{ $copy['testimonial']['role'] }}</p>
            </div>
        </div>
    </div>
</section>
