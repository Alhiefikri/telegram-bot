<section id="features" class="mx-auto max-w-5xl px-6 py-20 border-t border-slate-200 mt-10 relative">
    <div class="max-w-2xl mb-16">
        <h2 class="text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900">Mengapa memilih Lhie Store?</h2>
        <p class="mt-3 text-sm text-zinc-500 leading-relaxed max-w-lg">Kombinasi kemudahan katalog modern dengan efisiensi sistem pengiriman akun.</p>
    </div>

    <div class="grid sm:grid-cols-6 gap-6">
        @foreach ($copy['benefits'] as $index => $benefit)
            @php $spanClass = $index === 2 ? 'sm:col-span-6 lg:col-span-3' : 'sm:col-span-3 lg:col-span-2'; @endphp
            <div class="{{ $spanClass }} @if($index % 2 === 0) lg:mt-8 @endif">
                <div class="bg-white p-8 rounded-2xl border border-zinc-200 shadow-sm transition-all duration-300 hover:shadow-md h-full">
                    <div class="w-10 h-10 rounded-xl bg-slate-100 flex items-center justify-center text-slate-700 mb-6 font-bold text-sm">
                        @if($index === 0)
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        @elseif($index === 1)
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        @else
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                        @endif
                    </div>
                    <h3 class="text-base font-bold text-zinc-900 leading-snug">{{ $benefit['title'] }}</h3>
                    <p class="mt-3 text-sm text-zinc-500 leading-relaxed">{{ $benefit['body'] }}</p>
                </div>
            </div>
        @endforeach
    </div>
</section>
